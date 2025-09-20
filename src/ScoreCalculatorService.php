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
    foreach ($definitions as $definition) {
      \Drupal::logger('score')->debug('Checking definition: @definition', [
        '@definition' => print_r($definition, TRUE),
      ]);
      // Match entity_type and bundle
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
        $score = $this->calculateScore($entity, $definition['components'] ?? [], $definition['decimal_places'] ?? 1);
        \Drupal::logger('score')->notice('Setting score field @field to @score on entity @id', [
          '@field' => $score_field,
          '@score' => $score,
          '@id' => $entity->id(),
        ]);
        $entity->set($score_field, $score);
        $entity->save();
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
      // Allow for numerator_field style as well
      $has_field = !empty($component['field']) && $entity->hasField($component['field']);
      $has_numerator = !empty($component['numerator_field']) && $entity->hasField($component['numerator_field']);
      \Drupal::logger('score')->debug('Component @i: @component', [
        '@i' => $i,
        '@component' => print_r($component, TRUE),
      ]);
      if (!$has_field && !$has_numerator) {
        \Drupal::logger('score')->warning('Component @i: Skipping, no field found on entity @id', [
          '@i' => $i,
          '@id' => $entity->id(),
        ]);
        continue;
      }

      $weight = isset($component['weight']) ? (float) $component['weight'] : 1.0;
      $is_bonus = !empty($component['is_bonus']);

      $component_score = $this->calculateComponent($entity, $component);

      \Drupal::logger('score')->notice('Component @i: Score @score (bonus: @bonus, weight: @weight)', [
        '@i' => $i,
        '@score' => $component_score,
        '@bonus' => (int)$is_bonus,
        '@weight' => $weight,
      ]);

      if ($is_bonus) {
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
        if (!$field_name || !$entity->hasField($field_name)) {
          \Drupal::logger('score')->warning('direct_percentage: missing field @field', ['@field' => $field_name]);
          return 0;
        }
        $value = $entity->get($field_name)->value ?? 0;
        \Drupal::logger('score')->debug('direct_percentage: value @value', ['@value' => $value]);
        return min(($value / 100) * 15, 15);

      case 'percentage_calculation':
        $numerator = $entity->get($component['numerator_field'] ?? '')->value ?? 0;
        $denominator = $entity->get($component['denominator_field'] ?? '')->value ?? 0;
        \Drupal::logger('score')->debug('percentage_calculation: numerator @numerator, denominator @denominator', [
          '@numerator' => $numerator,
          '@denominator' => $denominator,
        ]);
        if ($denominator == 0) return 0;
        $percentage = ($numerator / $denominator) * 100;
        return min(($percentage / 100) * 15, 15);

      case 'list_mapping':
        if (!$field_name || !$entity->hasField($field_name)) {
          \Drupal::logger('score')->warning('list_mapping: missing field @field', ['@field' => $field_name]);
          return 0;
        }
        $value = $entity->get($field_name)->value ?? null;
        $mappings = $component['mappings'] ?? [];
        \Drupal::logger('score')->debug('list_mapping: value @value, mappings @mappings', [
          '@value' => $value,
          '@mappings' => print_r($mappings, TRUE),
        ]);
        return min($mappings[$value] ?? 0, 15);

      case 'capped_linear':
        if (!$field_name || !$entity->hasField($field_name)) {
          \Drupal::logger('score')->warning('capped_linear: missing field @field', ['@field' => $field_name]);
          return 0;
        }
        $value = $entity->get($field_name)->value ?? 0;
        $max_value = $component['max_value'] ?? 25;
        \Drupal::logger('score')->debug('capped_linear: value @value, max_value @max', [
          '@value' => $value,
          '@max' => $max_value,
        ]);
        return ($value > $max_value ? $max_value : $value) / $max_value * 15;

      case 'taxonomy_field_value':
        if (!$field_name || !$entity->hasField($field_name)) {
          \Drupal::logger('score')->warning('taxonomy_field_value: missing field @field', ['@field' => $field_name]);
          return 0;
        }
        $term = $entity->get($field_name)->entity ?? null;
        if (!$term instanceof Term) {
          \Drupal::logger('score')->warning('taxonomy_field_value: term is not a Term object');
          return 0;
        }
        $taxonomy_field = $component['taxonomy_field'] ?? null;
        $taxonomy_value = $term->get($taxonomy_field)->value ?? 0;
        \Drupal::logger('score')->debug('taxonomy_field_value: taxonomy_value @value', ['@value' => $taxonomy_value]);
        return min($taxonomy_value, 15);

      case 'boolean_points':
        if (!$field_name || !$entity->hasField($field_name)) {
          \Drupal::logger('score')->warning('boolean_points: missing field @field', ['@field' => $field_name]);
          return 0;
        }
        $value = $entity->get($field_name)->value ?? 0;
        \Drupal::logger('score')->debug('boolean_points: value @value', ['@value' => $value]);
        return $value ? 15 : 0;

      default:
        \Drupal::logger('score')->warning('Unknown component type: @type', ['@type' => $type]);
        return 0;
    }
  }

  /**
   * Recalculate all entities for a bundle.
   */
  public function recalculateScoreSystem(string $bundle): int {
    $config = $this->configFactory->get('score.settings');
    $definitions = $config->get('score_definitions') ?? [];
    $count = 0;
    \Drupal::logger('score')->notice('recalculateScoreSystem() called for bundle @bundle', [
      '@bundle' => $bundle,
    ]);
    foreach ($definitions as $definition) {
      \Drupal::logger('score')->debug('Checking definition: @definition', [
        '@definition' => print_r($definition, TRUE),
      ]);
      if (!in_array($bundle, $definition['bundles'] ?? [])) {
        \Drupal::logger('score')->debug('Bundle @bundle not in this definition', [
          '@bundle' => $bundle,
        ]);
        continue;
      }
      $entity_type = $definition['entity_type'] ?? 'node';
      $storage = \Drupal::entityTypeManager()->getStorage($entity_type);
      $query = $storage->getQuery()->accessCheck(FALSE)->condition('type', $bundle);
      $ids = $query->execute();
      \Drupal::logger('score')->notice('Found @count entities for bundle @bundle', [
        '@count' => count($ids),
        '@bundle' => $bundle,
      ]);
      if ($ids) {
        $entities = $storage->loadMultiple($ids);
        foreach ($entities as $entity) {
          $this->calculateScores($entity);
          $count++;
        }
      }
    }
    \Drupal::logger('score')->notice('Recalculation finished for bundle @bundle, total updated: @count', [
      '@bundle' => $bundle,
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
