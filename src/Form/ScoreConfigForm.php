<?php

namespace Drupal\score\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\score\FieldDetectorService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\score\ScoreCalculatorService;

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
        '#description' => $this->t('Enter the label for the numeric field that will store the final score.'),
        '#required' => TRUE,
      ];
      $form['field_wrapper']['final_score_field'] = [
        '#type' => 'machine_name',
        '#title' => $this->t('Final Score Field Machine Name'),
        '#default_value' => $definition['final_score_field'] ?? 'field_final_score',
        '#machine_name' => [
          'source' => ['field_wrapper', 'final_score_field_label'],
          'exists' => function ($machine_name) use ($first_bundle) {
            $fields = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', $first_bundle);
            return isset($fields[$machine_name]);
          },
          'replace_pattern' => '[^a-z0-9_]+',
          'callback' => 'strtolower',
        ],
        '#required' => TRUE,
      ];

      // Components fieldset
      $form['field_wrapper']['components'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Score components'),
        '#tree' => TRUE,
      ];

      $this->addComponentFields($form['field_wrapper']['components'], $first_bundle, $definition['components'] ?? []);
    }

    // Max score and decimal places
    $form['max_score'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum score'),
      '#default_value' => $definition['max_score'] ?? 100,
      '#min' => 1,
      '#step' => 1,
    ];

    $form['decimal_places'] = [
      '#type' => 'number',
      '#title' => $this->t('Decimal places'),
      '#default_value' => $definition['decimal_places'] ?? 1,
      '#min' => 0,
      '#max' => 5,
      '#step' => 1,
    ];

    return parent::buildForm($form, $form_state);
  }

  public function updateFieldOptions(array &$form, FormStateInterface $form_state) {
    return $form['field_wrapper'];
  }

  public function scoreNameExists(string $value): bool {
    $config = $this->config('score.settings');
    $score_definitions = $config->get('score_definitions') ?: [];
    return isset($score_definitions[$value]);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('score.settings');
    $score_definitions = $config->get('score_definitions') ?: [];

    $score_name = $form_state->getValue('score_name');
    $selected_bundle = $form_state->getValue('bundles');
    $first_bundle = $selected_bundle;

    // These will now be present with #tree => TRUE!
    $final_score_field = $form_state->getValue(['field_wrapper', 'final_score_field']);
    $final_score_field_label = $form_state->getValue(['field_wrapper', 'final_score_field_label']);

    // Ensure the field exists as a decimal numeric field.
    if ($final_score_field) {
      $this->fieldDetector->createScoreField('node', $first_bundle, $final_score_field, 'decimal', [
        'precision' => 10,
        'scale' => 2,
      ]);
    }

    // Handle components
    $components = $form_state->getValue(['field_wrapper', 'components']) ?? [];
    foreach ($components as $key => &$component) {
      if (!empty($component['mappings']) && is_string($component['mappings'])) {
        $component['mappings'] = $this->mappingTextToArray($component['mappings']);
      }
    } unset($component);

    $definition = [
      'entity_type' => 'node',
      'bundles' => [$selected_bundle],
      'final_score_field_label' => $final_score_field_label,
      'final_score_field' => $final_score_field,
      'max_score' => (int) $form_state->getValue('max_score'),
      'decimal_places' => (int) $form_state->getValue('decimal_places'),
      'components' => $components,
    ];

    $score_definitions[$score_name] = $definition;
    $config->set('score_definitions', $score_definitions)->save();

    $this->messenger()->addStatus($this->t('Score system @name has been saved.', ['@name' => $score_name]));
    $form_state->setRedirect('score.admin');
  }

 protected function addComponentFields(array &$form, string $bundle, array $existing_components): void {
    // Number fields
    try {
      $number_fields = $this->fieldDetector->getFieldsForComponentType('node', $bundle, 'percentage_calculation');
      $number_field_options = $this->formatFieldOptions($number_fields ?? []);
    } catch (\Exception $e) {
      $number_field_options = [];
      $this->messenger()->addWarning($this->t('Error loading number fields: @error', ['@error' => $e->getMessage()]));
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
      '#default_value' => (float) ($existing_components['percentage_calc']['weight'] ?? 1),
      '#step' => 0.1,
      '#min' => 0,
      '#max' => 2,
    ];

    // Direct Percentage Component
    $form['direct_percentage'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Direct Percentage'),
      '#tree' => TRUE,
    ];
    $form['direct_percentage']['field'] = [
      '#type' => 'select',
      '#title' => $this->t('Percentage field'),
      '#options' => $number_field_options,
      '#default_value' => (string) ($existing_components['direct_percentage']['field'] ?? ''),
      '#empty_option' => $this->t('- Select field -'),
    ];
    $form['direct_percentage']['weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Weight'),
      '#default_value' => (float) ($existing_components['direct_percentage']['weight'] ?? 1),
      '#step' => 0.1,
      '#min' => 0,
      '#max' => 2,
    ];

    // Taxonomy Score Component
    $form['taxonomy_score'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Taxonomy Field Score'),
      '#tree' => TRUE,
    ];
    $form['taxonomy_score']['field'] = [
      '#type' => 'select',
      '#title' => $this->t('Taxonomy reference field'),
      '#options' => $taxonomy_field_options,
      '#default_value' => (string) ($existing_components['taxonomy_score']['field'] ?? ''),
      '#empty_option' => $this->t('- Select field -'),
    ];
    $form['taxonomy_score']['taxonomy_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Score field name on taxonomy term (0-15 points)'),
      '#default_value' => (string) ($existing_components['taxonomy_score']['taxonomy_field'] ?? 'field_score'),
      '#description' => $this->t('Machine name of the field on taxonomy terms that contains score values (0-15)'),
    ];
    $form['taxonomy_score']['weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Weight'),
      '#default_value' => (float) ($existing_components['taxonomy_score']['weight'] ?? 1.0),
      '#min' => 0,
      '#max' => 2,
      '#step' => 0.1,
    ];
    $form['taxonomy_score']['is_bonus'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Add as bonus points (not weighted)'),
      '#default_value' => (bool) ($existing_components['taxonomy_score']['is_bonus'] ?? TRUE),
      '#description' => $this->t('If checked, points are added directly. If unchecked, points are multiplied by weight.'),
    ];
  }

  protected function formatFieldOptions(array $fields): array {
    $options = [];
    foreach ($fields as $field_name => $field_info) {
      $label = isset($field_info['label']) && !empty($field_info['label'])
        ? (string) $field_info['label']
        : (isset($field_info['type']) && !empty($field_info['type'])
            ? ucfirst(str_replace('_', ' ', (string) $field_info['type']))
            : ucfirst(str_replace('_', ' ', $field_name)));
      $options[$field_name] = $label . ' (' . $field_name . ')';
    }
    return $options;
  }

  protected function arrayToMappingText(array $mappings): string {
    $lines = [];
    foreach ($mappings as $key => $value) {
      $lines[] = $key . '|' . $value;
    }
    return implode("\n", $lines);
  }

  protected function mappingTextToArray(string $text): array {
    $mappings = [];
    $lines = array_filter(array_map('trim', explode("\n", $text)));
    foreach ($lines as $line) {
      if (strpos($line, '|') !== FALSE) {
        list($key, $value) = explode('|', $line, 2);
        $mappings[trim($key)] = (int) trim($value);
      }
    }
    return $mappings;
  }
}
