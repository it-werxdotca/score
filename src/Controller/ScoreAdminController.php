<?php

namespace Drupal\score\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\score\ScoreCalculatorService;

/**
 * Admin controller for score systems.
 */
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
    \Drupal::logger('score')->debug('ScoreAdminController::__construct called');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    \Drupal::logger('score')->debug('ScoreAdminController::create called');
    return new static(
      $container->get('score.calculator')
    );
  }

  /**
   * Admin page listing all score systems.
   */
  public function adminPage() {
    \Drupal::logger('score')->notice('adminPage() called');
    $config = $this->config('score.settings');
    $score_definitions = $config->get('score_definitions') ?: [];
    \Drupal::logger('score')->notice('Loaded @count score definitions', ['@count' => count($score_definitions)]);

    $header = [
      'name' => $this->t('Score System'),
      'operations' => $this->t('Operations'),
    ];

    $rows = [];
    foreach ($score_definitions as $name => $definition) {
      \Drupal::logger('score')->notice('Building row for score system: @name', ['@name' => $name]);

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
    \Drupal::logger('score')->notice('adminPage() returning render array');
    return $build;
  }

  /**
   * Delete score system.
   *
   * @param string $score_name
   *   The score system name.
   */
  public function delete($score_name) {
    \Drupal::logger('score')->notice('delete() called for @score_name', ['@score_name' => $score_name]);
    $config = \Drupal::configFactory()->getEditable('score.settings');
    $score_definitions = $config->get('score_definitions') ?: [];

    if (isset($score_definitions[$score_name])) {
      \Drupal::logger('score')->notice('Deleting score system: @score_name', ['@score_name' => $score_name]);
      unset($score_definitions[$score_name]);
      $config->set('score_definitions', $score_definitions)->save();
      $this->messenger()->addStatus($this->t('Score system @name has been deleted.', ['@name' => $score_name]));
    }
    else {
      \Drupal::logger('score')->warning('Attempted to delete non-existent score system: @score_name', ['@score_name' => $score_name]);
      $this->messenger()->addWarning($this->t('Score system @name does not exist.', ['@name' => $score_name]));
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
    \Drupal::logger('score')->notice('Route reached for @score', ['@score' => $score_name]);
    $config = $this->config('score.settings');
    $score_definitions = $config->get('score_definitions') ?: [];
    \Drupal::logger('score')->notice('Loaded @count score definitions', ['@count' => count($score_definitions)]);

    if (!isset($score_definitions[$score_name])) {
      \Drupal::logger('score')->warning('Score system @name does not exist.', ['@name' => $score_name]);
      $this->messenger()->addWarning($this->t('Score system @name does not exist.', ['@name' => $score_name]));
      return $this->redirect('score.admin');
    }

    $definition = $score_definitions[$score_name];
    \Drupal::logger('score')->notice('Score definition loaded for @score_name: @definition', [
      '@score_name' => $score_name,
      '@definition' => print_r($definition, TRUE),
    ]);

    // Check if the final_score_field exists on the content type
    $field_detector = \Drupal::service('score.field_detector');
    $bundles = (array) $definition['bundles'];
    $score_field_exists = FALSE;
    $final_score_field = $definition['final_score_field'] ?? '';
    \Drupal::logger('score')->notice('Checking for score field @field on bundles: @bundles', [
      '@field' => $final_score_field,
      '@bundles' => implode(', ', $bundles),
    ]);
    foreach ($bundles as $bundle) {
      $fields = $field_detector->getScoreFields('node', $bundle);
      \Drupal::logger('score')->notice('Fields for bundle @bundle: @fields', [
        '@bundle' => $bundle,
        '@fields' => implode(', ', array_keys($fields)),
      ]);
      if (isset($fields[$final_score_field])) {
        $score_field_exists = TRUE;
        \Drupal::logger('score')->notice('Score field @field exists on bundle @bundle', [
          '@field' => $final_score_field,
          '@bundle' => $bundle,
        ]);
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

    \Drupal::logger('score')->notice('Invoking recalculateScoreSystem on ScoreCalculatorService for @score_name', [
      '@score_name' => $score_name,
    ]);
    $count = $this->scoreCalculator->recalculateScoreSystem($score_name);

    \Drupal::logger('score')->notice('Recalculate complete for @score_name: @count entities updated', [
      '@score_name' => $score_name,
      '@count' => $count,
    ]);

    $this->messenger()->addStatus(
      $this->t('Score system @name has been recalculated for @count entities.', [
        '@name' => $score_name,
        '@count' => $count,
      ])
    );

    return $this->redirect('score.admin');
  }

}
