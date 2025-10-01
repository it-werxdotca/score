<?php

namespace Drupal\score\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\score\ScoreCalculatorService;
use Drupal\score\FieldDetectorService;
use Drupal\Core\Language\LanguageManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for score systems.
 */
class ScoreConfigForm extends ConfigFormBase {

  protected ScoreCalculatorService $scoreCalculator;
  protected FieldDetectorService $fieldDetector;
  protected LanguageManagerInterface $languageManager;

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    ScoreCalculatorService $score_calculator,
    FieldDetectorService $field_detector,
    LanguageManagerInterface $language_manager
  ) {
    parent::__construct($config_factory, $typed_config_manager);
    $this->scoreCalculator = $score_calculator;
    $this->fieldDetector = $field_detector;
    $this->languageManager = $language_manager;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('score.calculator'),
      $container->get('score.field_detector'),
      $container->get('language_manager')
    );
  }

  public function getFormId(): string {
    return 'score_config_form';
  }

  protected function getEditableConfigNames(): array {
    return ['score.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state, $score_name = null): array {
    $config = $this->config('score.settings');
    $score_definitions = $config->get('score_definitions') ?: [];
    $definition = $score_name ? ($score_definitions[$score_name] ?? []) : [];

    // Score system name
    $form['score_name'] = [
      '#type' => 'machine_name',
      '#title' => $this->t('Score system name'),
      '#default_value' => $score_name ?? '',
      '#disabled' => !empty($score_name),
      '#machine_name' => [
        'exists' => [$this, 'scoreNameExists'],
      ],
    ];

    // Content type selection
    $content_types = $this->fieldDetector->getAvailableContentTypes();
    $content_type_options = [];
    foreach ($content_types as $type => $info) {
      $label = isset($info['label']) && $info['label'] ? (string) $info['label'] : ucfirst(str_replace('_', ' ', $type));
      $content_type_options[$type] = $label;
    }

    $form['bundles'] = [
      '#type' => 'select',
      '#title' => $this->t('Content types to score'),
      '#options' => $content_type_options,
      '#default_value' => isset($definition['bundles'][0]) ? $definition['bundles'][0] : '',
      '#ajax' => [
        'callback' => '::updateFieldOptions',
        'wrapper' => 'field-options-wrapper',
      ],
    ];

    // Add #tree => TRUE here so nested values are preserved!
    $form['field_wrapper'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#attributes' => ['id' => 'field-options-wrapper'],
    ];

    $selected_bundle = $form_state->getValue('bundles', $definition['bundles'][0] ?? '');
    if ($selected_bundle) {
      $first_bundle = $selected_bundle;

      // Score field label and machine name
      $form['field_wrapper']['final_score_field_label'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Final Score Field Label'),
        '#default_value' => $definition['final_score_field_label'] ?? 'Final Score',
      ];

      $form['field_wrapper']['final_score_field'] = [
        '#type' => 'machine_name',
        '#title' => $this->t('Final Score Field Machine Name'),
        '#default_value' => $definition['final_score_field'] ?? 'field_final_score',
        '#machine_name' => [
          'exists' => [$this->fieldDetector, 'scoreFieldExists'],
        ],
      ];

      // Max score and decimal places
      $form['field_wrapper']['max_score'] = [
        '#type' => 'number',
        '#title' => $this->t('Maximum Score'),
        '#default_value' => $definition['max_score'] ?? 100,
        '#min' => 1,
      ];

      $form['field_wrapper']['decimal_places'] = [
        '#type' => 'number',
        '#title' => $this->t('Decimal Places'),
        '#default_value' => $definition['decimal_places'] ?? 1,
        '#min' => 0,
        '#max' => 5,
      ];

      // Component fields
      $form['field_wrapper']['components'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Scoring Components (JSON)'),
        '#description' => $this->t('Define the scoring components in JSON format.'),
        '#default_value' => isset($definition['components']) ? json_encode($definition['components'], JSON_PRETTY_PRINT) : '',
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    parent::submitForm($form, $form_state);

    $score_name = $form_state->getValue('score_name');
    $selected_bundle = $form_state->getValue('bundles');
    $final_score_field = $form_state->getValue(['field_wrapper', 'final_score_field']);
    $final_score_field_label = $form_state->getValue(['field_wrapper', 'final_score_field_label']);
    $max_score = $form_state->getValue(['field_wrapper', 'max_score']);
    $decimal_places = $form_state->getValue(['field_wrapper', 'decimal_places']);
    $components_raw = $form_state->getValue(['field_wrapper', 'components']);

    $components = [];
    if (!empty($components_raw)) {
      try {
        $components = json_decode($components_raw, TRUE, 512, JSON_THROW_ON_ERROR);
      } catch (\JsonException $e) {
        $this->messenger()->addError($this->t('Error parsing components JSON: @error', ['@error' => (string) $e->getMessage()]));
        return;
      }
    }

    // Ensure the field exists as a decimal numeric field.
    if ($final_score_field) {
      $this->fieldDetector->createScoreField('node', $selected_bundle, $final_score_field, 'decimal', [
        'precision' => 10,
        'scale' => 2,
      ]);
    }

    $config = $this->config('score.settings');
    $score_definitions = $config->get('score_definitions') ?: [];

    $definition = [
      'entity_type' => 'node',
      'bundles' => [$selected_bundle],
      'final_score_field_label' => $final_score_field_label,
      'final_score_field' => $final_score_field,
      'max_score' => (int) $max_score,
      'decimal_places' => (int) $decimal_places,
      'components' => $components,
    ];

    $score_definitions[$score_name] = $definition;
    $config->set('score_definitions', $score_definitions)->save();

    $this->messenger()->addStatus($this->t('Score system @name has been saved.', ['@name' => (string) $score_name]));
    $form_state->setRedirect('score.admin');
  }

  protected function addComponentFields(array &$form, string $bundle, array $existing_components): void {
    // Number fields
    try {
      $number_fields = $this->fieldDetector->getFieldsForComponentType('node', $bundle, 'percentage_calculation');
      $number_field_options = $this->formatFieldOptions($number_fields ?? []);
    } catch (\Exception $e) {
      $number_field_options = [];
      $this->messenger()->addWarning($this->t('Error loading number fields: @error', ['@error' => (string) $e->getMessage()]));
    }
    // Taxonomy fields
    try {
      $taxonomy_fields = $this->fieldDetector->getFieldsForComponentType('node', $bundle, 'taxonomy_field_value');
      $taxonomy_field_options = $this->formatFieldOptions($taxonomy_fields ?? []);
    } catch (\Exception $e) {
      $taxonomy_field_options = [];
    }

    // Percentage Calculation Component
    $form['percentage_calc'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Percentage Calculation'),
      '#tree' => TRUE,
    ];
    $form['percentage_calc']['numerator_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Numerator field'),
      '#options' => $number_field_options,
      '#default_value' => (string) ($existing_components['percentage_calc']['numerator_field'] ?? ''),
      '#empty_option' => $this->t('- Select field -'),
    ];
    $form['percentage_calc']['denominator_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Denominator field'),
      '#options' => $number_field_options,
      '#default_value' => (string) ($existing_components['percentage_calc']['denominator_field'] ?? ''),
      '#empty_option' => $this->t('- Select field -'),
    ];
    $form['percentage_calc']['weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Weight'),
      '#default_value' => (string) ($existing_components['percentage_calc']['weight'] ?? '1'),
      '#min' => 0,
      '#step' => 0.01,
    ];

    // Add similar logic for taxonomy fields if needed.
  }

  protected function formatFieldOptions(array $fields): array {
    $options = [];
    foreach ($fields as $field_name => $label) {
      $options[$field_name] = (string) $label;
    }
    return $options;
  }

  public function scoreNameExists($name) {
    $config = $this->config('score.settings');
    $score_definitions = $config->get('score_definitions') ?: [];
    return isset($score_definitions[$name]);
  }
}
