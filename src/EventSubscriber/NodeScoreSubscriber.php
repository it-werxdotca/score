<?php

namespace Drupal\score\EventSubscriber;

use Drupal\score\ScoreCalculatorService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityEvent;

class NodeScoreSubscriber implements EventSubscriberInterface {

  protected $scoreCalculator;

  public function __construct(ScoreCalculatorService $score_calculator) {
    $this->scoreCalculator = $score_calculator;
  }

  public static function getSubscribedEvents() {
    return [
      'entity.presave' => 'onEntityPresave',
    ];
  }

  public function onEntityPresave(EntityEvent $event) {
    $entity = $event->getEntity();
    if ($entity instanceof EntityInterface && $entity->getEntityTypeId() === 'node') {
      \Drupal::logger('score')->notice('Node presave event hit for node @nid', ['@nid' => $entity->id()]);
      $this->scoreCalculator->calculateScores($entity);
    }
  }
}
