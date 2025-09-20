<?php

namespace Drupal\score;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\taxonomy\Entity\Term;

/**
 * Service for calculating entity scores based on configuration.
 */
class ScoreCalculatorService {

  protected $entityFieldManager;
  protected $configLoader;

  /**
   * Constructor.
   *
   * @param \Drupal\score\ComponentConfigLoader $config_loader
   *   The service that loads score definitions from files.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   Entity field manager.
   */
  public function __construct(ComponentConfigLoader $config_loader, EntityFieldManagerInterface $entity_field_manager) {
    $this->configLoader = $config_loader;
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * Calculate scores for an entity based on configuration.
   */
  public function calculateScores(EntityInterface $entity): void {
    // 🔹 Use file-based config loader
    $score_definitions = $this->configLoader->getScoreDefinitions() ?? [];

    foreach ($score_definitions as $definition) {
      if ($this->entityMatches($entity, $definition)) {
        $score = $this->calculateScore($entity, $definition);
        $this->setScore($entity, $definition['score_field'], $score);
      }
    }
  }

  protected function entityMatches(EntityInterface $entity, array $definition): bool {
    $bundles = (array) ($definition['bundles'] ?? []);
    return $entity->getEntityTypeId() === ($definition['entity_type'] ?? '') &&
           in_array($entity->bundle(), $bundles);
  }

  protected function calculateScore(EntityInterface $entity, array $definition): float {
    $total_score = 0;
    $components = $definition['components'] ?? [];

    foreach ($components as $component) {
      if (!empty($component['field']) && !$entity->hasField($component['field'])) {
        continue;
      }

      $component_score = $this->calculateComponent($entity, $component);

      $weight = $component['weight'] ?? 1;
      $total_score += ($component['is_bonus'] ?? false) ? $component_score : $component_score * $weight;
    }

    $max_score = $definition['max_score'] ?? 100;
    $decimal_places = $definition['decimal_places'] ?? 1;

    return min(round($total_score, $decimal_places), $max_score);
  }

  protected function calculateComponent(EntityInterface $entity, array $component): float {
    $type = $component['type'] ?? '';

    switch ($type) {
      case 'percentage_calculation':
        return $this->calculatePercentage($entity, $component);

      case 'direct_percentage':
        return $this->getDirectPercentage($entity, $component);

      case 'list_mapping':
        return $this->getListMapping($entity, $component);

      case 'capped_linear':
        return $this->getCappedLinear($entity, $component);

      case 'taxonomy_field_value':
        return $this->getTaxonomyFieldValue($entity, $component);

      case 'boolean_points':
        return $this->getBooleanPoints($entity, $component);

      default:
        return 0;
    }
  }

  protected function calculatePercentage(EntityInterface $entity, array $component): float {
    $numerator = $entity->get($component['numerator_field'])->value ?? 0;
    $denominator = $entity->get($component['denominator_field'])->value ?: 1;

    $percentage = ($numerator / $denominator) * 100;

    // Boost small numbers (nonlinear scaling)
    if ($numerator > 0 && $numerator < 5) {
      $percentage *= (1 + sqrt($numerator)/2);
    }

    return min($percentage, $component['max_points'] ?? 100);
  }

  protected function getDirectPercentage(EntityInterface $entity, array $component): float {
    $value = $entity->get($component['field'])->value ?? 0;

    if ($value > 0 && $value < 5) {
      $value *= (1 + sqrt($value)/2);
    }

    return min($value, $component['max_points'] ?? 100);
  }

  protected function getListMapping(EntityInterface $entity, array $component): float {
    $value = $entity->get($component['field'])->value ?? '';
    $mappings = $component['mappings'] ?? [];
    return (float) ($mappings[$value] ?? 0);
  }

  protected function getCappedLinear(EntityInterface $entity, array $component): float {
    $value = $entity->get($component['field'])->value ?? 0;
    $max_value = $component['max_value'] ?? 100;
    $max_points = $component['max_points'] ?? 100;

    $capped_value = min($value, $max_value);
    return ($capped_value / $max_value) * $max_points;
  }

  protected function getTaxonomyFieldValue(EntityInterface $entity, array $component): float {
    if ($entity->get($component['field'])->isEmpty()) {
      return 0;
    }

    $term = $entity->get($component['field'])->entity;
    if (!$term instanceof Term) return 0;

    $taxonomy_field = $component['taxonomy_field'];
    return $term->get($taxonomy_field)->value ?? 0;
  }

  protected function getBooleanPoints(EntityInterface $entity, array $component): float {
    $value = $entity->get($component['field'])->value ?? 0;
    $points = $component['points'] ?? 5;
    return $value ? $points : 0;
  }

  protected function setScore(EntityInterface $entity, string $score_field, float $score): void {
    if ($entity->hasField($score_field)) {
      $entity->set($score_field, $score);
    }
  }

  /**
   * Recalculate all entities for a single score system.
   */
  public function recalculateScoreSystem(string $score_name): int {
    $score_definitions = $this->configLoader->getScoreDefinitions() ?? [];

    if (!isset($score_definitions[$score_name])) return 0;

    $definition = $score_definitions[$score_name];
    $entity_type = $definition['entity_type'] ?? 'node';
    $bundles = $definition['bundles'] ?? [];

    $storage = \Drupal::entityTypeManager()->getStorage($entity_type);
    $count = 0;

    foreach ($bundles as $bundle) {
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', $bundle)
        ->execute();

      if ($ids) {
        $entities = $storage->loadMultiple($ids);
        foreach ($entities as $entity) {
          $this->calculateScores($entity);
          $entity->save();
          $count++;
        }
      }
    }

    return $count;
  }

  /**
   * Format score for display.
   */
  public function formatScoreForDisplay(float $score, int $decimals = 0): string {
    return round($score, $decimals) . '%';
  }

}
