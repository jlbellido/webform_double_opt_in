<?php

namespace Drupal\webform_double_opt_in\Event;

/**
 * Provides event names for events triggered by Webform double opt-in.
 *
 * @package Drupal\webform_double_opt_in\Event
 */
final class WebformDoubleOptInEvents {

  /**
   * Name of the event fired when getting the submission state of a submission.
   *
   * @see Drupal\webform_double_opt_in\Event\GetSubmissionStateEvent.php.
   *
   * This event allows modules to alter the submission state.
   *
   * @Event
   *
   * @var string
   */
  const GET_SUBMISSION_STATE = 'webform_double_opt_in.get_submission_state';

  /**
   * Name of the event fired when getting the available submission states.
   *
   * @see Drupal\webform_double_opt_in\Event\GetSubmissionStateOptionsEvent.
   *
   * This event allows modules to alter the available submission states.
   *
   * @Event
   *
   * @var string
   */
  const GET_SUBMISSION_STATE_OPTIONS = 'webform_double_opt_in.get_submission_state_options';

}
