<?php

namespace Drupal\webform_double_opt_in\EventSubscriber;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\webform_double_opt_in\Event\GetSubmissionStateOptionsEvent;
use Drupal\webform_double_opt_in\Event\WebformDoubleOptInEvents;
use Drupal\webform_double_opt_in\Plugin\WebformHandler\DoubleOptInEmailWebformHandler;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adds the double opt-in confirmed state option.
 *
 * @package Drupal\webform_double_opt_in\EventSubscriber
 */
class GetSubmissionStateOptionsEventSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[WebformDoubleOptInEvents::GET_SUBMISSION_STATE_OPTIONS][] = ['getStateOptions', 0];

    return $events;
  }

  /**
   * Adds the double opt-in confirmed state option.
   *
   * @param \Drupal\webform\Event\GetSubmissionStateOptionsEvent $getSubmissionStateOptionsEvent
   *   The dispatched event object.
   */
  public function getStateOptions(GetSubmissionStateOptionsEvent $getSubmissionStateOptionsEvent) {
    $submissionStateOptions = [
      DoubleOptInEmailWebformHandler::STATUS_CONFIRMED => new TranslatableMarkup('…when <b>double opt-in</b> is confirmed'),
    ];

    $getSubmissionStateOptionsEvent->addSubmissionStateOptions($submissionStateOptions);
  }

}
