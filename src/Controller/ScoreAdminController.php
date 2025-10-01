<?php

namespace Drupal\score\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\score\ScoreCalculatorService;

class ScoreAdminController extends ControllerBase {

  /**
   * The score calculator service.
   *
   * @var \Drupal\score\ScoreCalculatorService
   */
  protected $scoreCalculator;

  /**
   * Constructs a ScoreAdminController object.
   *
   * @param \Drupal\score\ScoreCalculatorService $score_calculator
   *   The score calculator service.
   */
  public function __construct(ScoreCalculatorService $score_calculator) {
    $this->scoreCalculator = $score_calculator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('score.calculator')
    );
  }

  /**
   * Admin page listing all score systems.
   */
  public function adminPage() {
    $config = $this->config('score.settings');
    $score_definitions = $config->get('score_definitions') ?: [];

    $header = [
      'name' => $this->t('Score System'),
      'operations' => $this->t('Operations'),
    ];

    $rows = [];
    foreach ($score_definitions as $name => $definition) {
      $operations = [
        'configure' => [
          'title' => $this->t('Configure'),
          'url' => Url::fromRoute('score.config', ['score_name' => $name]),
        ],
        'recalculate' => [
          'title' => $this->t('Recalculate'),
          'url' => Url::fromRoute('score.recalculate', ['score_name' => $name]),
        ],
        'delete' => [
          'title' => $this->t('Delete'),
          'url' => Url::fromRoute('score.delete', ['score_name' => $name]),
          'attributes' => [
            'onclick' => "return confirm('" . $this->t('Are you sure you want to delete @name?', ['@name' => $name]) . "');",
          ],
        ],
      ];

      $rows[] = [
        'name' => $name,
        'operations' => [
          'data' => [
            '#type' => 'operations',
            '#links' => $operations,
          ],
        ],
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No score systems found.'),
    ];

    $build['add'] = [
      '#type' => 'link',
      '#title' => $this->t('Add Score System'),
      '#url' => Url::fromRoute('score.add'),
      '#attributes' => ['class' => ['button', 'button--primary']],
    ];

    $build['#title'] = $this->t('Score Systems Administration');
    return $build;
  }

  /**
   * Delete score system.
   *
   * @param string $score_name
   *   The score system name.
   */
  public function delete($score_name) {
    $config = $this->config('score.settings');
    $score_definitions = $config->get('score_definitions') ?: [];

    if (isset($score_definitions[$score_name])) {
      unset($score_definitions[$score_name]);
      $config->set('score_definitions', $score_definitions)->save();
      $this->messenger()->addStatus($this->t('Score system @name has been deleted.', ['@name' => (string) $score_name]));
    }
    else {
      \Drupal::logger('score')->warning('Attempted to delete non-existent score system: @score_name', ['@score_name' => $score_name]);
      $this->messenger()->addWarning($this->t('Score system @name does not exist.', ['@name' => (string) $score_name]));
    }

    return $this->redirect('score.admin');
  }

  /**
   * Recalculate a single score system.
   *
   * @param string $score_name
   *   The score system name.
   */
  public function recalculateScoreSystem($score_name) {
    $config = $this->config('score.settings');
    $score_definitions = $config->get('score_definitions') ?: [];

    if (!isset($score_definitions[$score_name])) {
      \Drupal::logger('score')->warning('Score system @name does not exist.', ['@name' => $score_name]);
      $this->messenger()->addWarning($this->t('Score system @name does not exist.', ['@name' => (string) $score_name]));
      return $this->redirect('score.admin');
    }

    $definition = $score_definitions[$score_name];

    // Check if the final_score_field exists on the content type
    $field_detector = \Drupal::service('score.field_detector');
    $bundles = (array) $definition['bundles'];
    $score_field_exists = FALSE;
    $final_score_field = $definition['final_score_field'] ?? '';
    foreach ($bundles as $bundle) {
      $fields = $field_detector->getScoreFields('node', $bundle);
      if (isset($fields[$final_score_field])) {
        $score_field_exists = TRUE;
        break;
      }
    }

    if (!$score_field_exists) {
      \Drupal::logger('score')->error('Score field @field does not exist. Aborting recalculation.', [
        '@field' => (string) $final_score_field,
      ]);
      $this->messenger()->addError($this->t(
        'Score field @field does not exist. Please create it before recalculating.',
        ['@field' => (string) $final_score_field]
      ));
      return $this->redirect('score.admin');
    }

    // Recalculate scores (calls ScoreCalculatorService)
    $updated = $this->scoreCalculator->recalculateScoreSystem($score_name);

    $this->messenger()->addStatus($this->t(
      'Recalculated scores for @name. Updated @count nodes.',
      [
        '@name' => (string) $score_name,
        '@count' => (string) $updated,
      ]
    ));

    return $this->redirect('score.admin');
  }
}
