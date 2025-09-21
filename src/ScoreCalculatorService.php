<?php

namespace Drupal\score;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\taxonomy\Entity\Term;

/**
 * Service for calculating entity scores based on Drupal config.
 */
class ScoreCalculatorService {

  protected ConfigFactoryInterface $configFactory;

  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
    \Drupal::logger('score')->debug('ScoreCalculatorService::__construct called');
  }

  /**
   * Get the final score field name for an entity from config definitions.
   */
  public function getFinalScoreField(EntityInterface $entity): ?string {
    $config = $this->configFactory->get('score.settings');
    $definitions = $config->get('score_definitions') ?? [];
    foreach ($definitions as $key => $definition) {
      if (
        ($entity->getEntityTypeId() === ($definition['entity_type'] ?? 'node')) &&
        (in_array($entity->bundle(), $definition['bundles'] ?? []))
      ) {
        return $definition['final_score_field'] ?? 'field_final_score';
      }
    }
    return null;
  }

  /**
   * Calculate scores for an entity based on config.
   */
  public function calculateScores(EntityInterface $entity): void {
    $config = $this->configFactory->get('score.settings');
    $definitions = $config->get('score_definitions') ?? [];
    \Drupal::logger('score')->notice('calculateScores() entity id: @id, bundle: @bundle, type: @type', [
      '@id' => $entity->id(),
      '@bundle' => $entity->bundle(),
      '@type' => $entity->getEntityTypeId(),
    ]);
    $matched = FALSE;
    foreach ($definitions as $key => $definition) {
      // Match entity_type and bundle.
      if (
        ($entity->getEntityTypeId() === ($definition['entity_type'] ?? 'node')) &&
        (in_array($entity->bundle(), $definition['bundles'] ?? []))
      ) {
        $score_field = $definition['final_score_field'] ?? 'field_final_score';
        \Drupal::logger('score')->debug('Matched definition for entity @id, score field: @field', [
          '@id' => $entity->id(),
          '@field' => $score_field,
        ]);
        if (!$entity->hasField($score_field)) {
          \Drupal::logger('score')->warning('Score field @field does not exist on entity @id', [
            '@field' => $score_field,
            '@id' => $entity->id(),
          ]);
          continue;
        }

        $score = $this->calculateScore(
          $entity,
          $definition['components'] ?? [],
          $definition['decimal_places'] ?? 1
        );

        \Drupal::logger('score')->notice('Setting score field @field to @score on entity @id', [
          '@field' => $score_field,
          '@score' => $score,
          '@id' => $entity->id(),
        ]);

        $entity->set($score_field, $score);

        \Drupal::logger('score')->notice('Calculated score @score for entity @id (@bundle)', [
          '@score' => $score,
          '@id' => $entity->id(),
          '@bundle' => $entity->bundle(),
        ]);
        $matched = TRUE;
        break;
      }
    }

    if (!$matched) {
      \Drupal::logger('score')->notice('No scoring configuration found for entity @id (@bundle)', [
        '@id' => $entity->id(),
        '@bundle' => $entity->bundle(),
      ]);
    }
  }

  /**
   * Calculate score for an entity based on components.
   */
  protected function calculateScore(EntityInterface $entity, array $components, int $decimal_places): float {
    $max_points_per_component = 15; // This could be a config
    $weighted_total = 0;
    $total_weight = 0;
    $bonus_total = 0;

    \Drupal::logger('score')->debug('calculateScore() on entity @id: components: @components', [
      '@id' => $entity->id(),
      '@components' => print_r($components, TRUE),
    ]);

    foreach ($components as $i => $component) {
      $weight = $component['weight'] ?? 1.0;

      $component_score = $this->calculateComponent($entity, $component);

      \Drupal::logger('score')->notice('Component @i score: @score (weight: @weight)', [
        '@i' => $i,
        '@score' => $component_score,
        '@weight' => $weight,
      ]);

      if (!empty($component['is_bonus'])) {
        $bonus_total += $component_score;
      }
      else {
        $weighted_total += $component_score * $weight;
        $total_weight += $weight;
      }
    }

    $weighted_avg = $total_weight ? ($weighted_total / $total_weight) : 0;
    $final_score = min($weighted_avg + $bonus_total, $max_points_per_component);

    \Drupal::logger('score')->notice('Weighted total: @wt, Total weight: @tw, Bonus: @bt, Weighted average: @wa, Final: @fs', [
      '@wt' => $weighted_total,
      '@tw' => $total_weight,
      '@bt' => $bonus_total,
      '@wa' => $weighted_avg,
      '@fs' => $final_score,
    ]);

    $percentage = ($final_score / $max_points_per_component) * 100;
    $rounded = round($percentage, $decimal_places);
    \Drupal::logger('score')->notice('Returning percentage @pct rounded @rounded', [
      '@pct' => $percentage,
      '@rounded' => $rounded,
    ]);
    return $rounded;
  }

  /**
   * Extract the value from a field in a robust way (handles most field types).
   */
  protected function getFieldValue(EntityInterface $entity, $field_name) {
    if (!$entity->hasField($field_name)) {
      \Drupal::logger('score')->warning('getFieldValue: Entity @id does not have field @field', [
        '@id' => $entity->id(),
        '@field' => $field_name,
      ]);
      return null;
    }
    $field = $entity->get($field_name);
    if ($field->isEmpty()) {
      \Drupal::logger('score')->debug('getFieldValue: Field @field on entity @id is empty', [
        '@field' => $field_name,
        '@id' => $entity->id(),
      ]);
      return null;
    }
    // Correct check for multiple value fields.
    if ($field->getFieldDefinition()->getFieldStorageDefinition()->isMultiple()) {
      $item = $field->first();
      if ($item && isset($item->value)) {
        return $item->value;
      }
      return null;
    }
    // Entity reference: get referenced entity ID or value
    if ($field->getFieldDefinition()->getType() === 'entity_reference') {
      $target = $field->entity;
      // If you want the referenced entity, return $target
      // If you want the target ID, use $field->target_id
      return $field->target_id ?? null;
    }
    // Boolean fields
    if ($field->getFieldDefinition()->getType() === 'boolean') {
      return (int) $field->value;
    }
    // Numeric, text, list, etc.
    return $field->value;
  }

  /**
   * Calculate individual component score.
   */
  protected function calculateComponent(EntityInterface $entity, array $component): float {
    $type = $component['type'] ?? 'direct_percentage';
    $field_name = $component['field'] ?? null;
    \Drupal::logger('score')->debug('calculateComponent() type: @type, field: @field', [
      '@type' => $type,
      '@field' => $field_name,
    ]);

    switch ($type) {
      case 'direct_percentage':
        $value = $this->getFieldValue($entity, $field_name);
        \Drupal::logger('score')->notice('direct_percentage: extracted value @value from field @field', [
          '@value' => $value,
          '@field' => $field_name,
        ]);
        if ($value === null || $value === '') {
          return 0;
        }
        return min(($value / 100) * 15, 15);

      case 'percentage_calculation':
        $numerator = $this->getFieldValue($entity, $component['numerator_field'] ?? '');
        $denominator = $this->getFieldValue($entity, $component['denominator_field'] ?? '');
        \Drupal::logger('score')->notice('percentage_calculation: numerator @numerator, denominator @denominator', [
          '@numerator' => $numerator,
          '@denominator' => $denominator,
        ]);
        if (empty($denominator) || $denominator == 0) return 0;
        $percentage = ($numerator / $denominator) * 100;
        return min(($percentage / 100) * 15, 15);

      case 'list_mapping':
        $value = $this->getFieldValue($entity, $field_name);
        $mappings = $component['mappings'] ?? [];
        \Drupal::logger('score')->notice('list_mapping: value @value, mappings @mappings', [
          '@value' => $value,
          '@mappings' => print_r($mappings, TRUE),
        ]);
        return min($mappings[$value] ?? 0, 15);

      case 'capped_linear':
        $value = $this->getFieldValue($entity, $field_name);
        $max_value = $component['max_value'] ?? 25;
        \Drupal::logger('score')->notice('capped_linear: value @value, max_value @max', [
          '@value' => $value,
          '@max' => $max_value,
        ]);
        if ($value === null || $value === '' || $max_value == 0) {
          return 0;
        }
        return ($value > $max_value ? $max_value : $value) / $max_value * 15;

      case 'taxonomy_field_value':
        $term_id = $this->getFieldValue($entity, $field_name);
        if (!$term_id) {
          \Drupal::logger('score')->warning('taxonomy_field_value: missing or empty field @field', ['@field' => $field_name]);
          return 0;
        }
        $term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($term_id);
        if (!$term instanceof Term) {
          \Drupal::logger('score')->warning('taxonomy_field_value: term is not a Term object');
          return 0;
        }
        $taxonomy_field = $component['taxonomy_field'] ?? null;
        $taxonomy_value = $term->get($taxonomy_field)->value ?? 0;
        \Drupal::logger('score')->notice('taxonomy_field_value: taxonomy_value @value', ['@value' => $taxonomy_value]);
        return min($taxonomy_value, 15);

      case 'boolean_points':
        $value = $this->getFieldValue($entity, $field_name);
        $points = $component['points'] ?? 5;
        \Drupal::logger('score')->notice('boolean_points: value @value, points @points', [
          '@value' => $value,
          '@points' => $points,
        ]);
        return $value ? $points : 0;

      default:
        \Drupal::logger('score')->warning('Unknown component type: @type', ['@type' => $type]);
        return 0;
    }
  }

 public function recalculateScoreSystem(string $score_system_name): int {
     $config = $this->configFactory->get('score.settings');
     $definitions = $config->get('score_definitions') ?? [];
     $count = 0;
     if (!isset($definitions[$score_system_name])) {
         \Drupal::logger('score')->warning('No score definition for @name', ['@name' => $score_system_name]);
         return 0;
     }
     $definition = $definitions[$score_system_name];
     $entity_type = $definition['entity_type'] ?? 'node';
     $bundles = $definition['bundles'] ?? [];
     $storage = \Drupal::entityTypeManager()->getStorage($entity_type);

     foreach ($bundles as $bundle) {
         $query = $storage->getQuery()->accessCheck(FALSE)->condition('type', $bundle);
         $ids = $query->execute();
         if ($ids) {
             $entities = $storage->loadMultiple($ids);
             foreach ($entities as $entity) {
                 $fresh_entity = \Drupal::entityTypeManager()->getStorage($entity->getEntityTypeId())->load($entity->id());
                 if ($fresh_entity) {
                     $this->calculateScores($fresh_entity);
                     $count++;
                 }
             }
         }
     }
     \Drupal::logger('score')->notice('Recalculation finished for score system @name, total updated: @count', [
         '@name' => $score_system_name,
         '@count' => $count,
     ]);
     return $count;
 }

  /**
   * Format score for display.
   */
  public function formatScoreForDisplay(float $score, int $decimals = 0): string {
    return round($score, $decimals) . '%';
  }
}
