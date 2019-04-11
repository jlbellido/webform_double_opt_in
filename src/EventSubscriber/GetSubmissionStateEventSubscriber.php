<?php

namespace Drupal\webform_double_opt_in\EventSubscriber;

use Drupal\webform\Event\GetSubmissionStateEvent;
use Drupal\webform\Event\WebformEvents;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\webform_double_opt_in\Plugin\WebformHandler\DoubleOptInEmailWebformHandler;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adds the double opt-in confirmed state option.
 *
 * @package Drupal\webform_double_opt_in\EventSubscriber
 */
class GetSubmissionStateEventSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[WebformEvents::GET_SUBMISSION_STATE][] = ['getState', 0];

    return $events;
  }

  /**
   * Checks if the state is double opt-in confirmed, sets it if the case.
   *
   * @param \Drupal\webform\Event\GetSubmissionStateEvent $getSubmissionStateEvent
   *   The dispatched event object.
   */
  public function getState(GetSubmissionStateEvent $getSubmissionStateEvent) {
    $submissionData = $getSubmissionStateEvent->getSubmission()->getData();

    // Set opt-in status if opt-in is confirmed and submission status completed.
    if (isset($submissionData['opt_in_status']) && $submissionData['opt_in_status'] === DoubleOptInEmailWebformHandler::STATUS_CONFIRMED && $getSubmissionStateEvent->getState() === WebformSubmissionInterface::STATE_COMPLETED) {
      $getSubmissionStateEvent->setState($submissionData['opt_in_status']);
    }
  }

}
