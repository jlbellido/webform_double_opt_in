<?php

namespace Drupal\webform_double_opt_in\Event;

use Symfony\Component\EventDispatcher\Event;

// TODO: Add interface.
/**
 * Class GetSubmissionStateOptionsEvent.
 *
 * @package Drupal\webform_double_opt_in\Event
 */
class GetSubmissionStateOptionsEvent extends Event {

  /**
   * The available submission state options.
   *
   * @var array
   */
  protected $submissionStateOptions;

  /**
   * GetSubmissionStateOptionsEvent constructor.
   *
   * @param array $submission_state_options
   *   The available submission state options.
   */
  public function __construct(array $submission_state_options) {
    $this->submissionStateOptions = $submission_state_options;
  }

  /**
   * Gets the available submission state options.
   *
   * @return array
   *   The available submission state options.
   */
  public function getSubmissionStateOptions() {
    return $this->submissionStateOptions;
  }

  /**
   * Sets the available submission state options.
   *
   * @param array $submissionStateOptions
   *   The available submission state options.
   */
  public function setSubmissionStateOptions(array $submissionStateOptions) {
    $this->submissionStateOptions = $submissionStateOptions;
  }

  /**
   * Adds submission state options.
   *
   * @param array $submissionStateOptions
   *   The submission state options to add.
   */
  public function addSubmissionStateOptions(array $submissionStateOptions) {
    $this->submissionStateOptions = array_merge($this->submissionStateOptions, $submissionStateOptions);
  }

}
