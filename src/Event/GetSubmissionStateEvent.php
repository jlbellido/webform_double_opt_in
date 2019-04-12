<?php

namespace Drupal\webform_double_opt_in\Event;

use Drupal\webform\WebformSubmissionInterface;
use Symfony\Component\EventDispatcher\Event;

// TODO: Add interface.
/**
 * Class GetSubmissionStateEvent.
 *
 * @package Drupal\webform_double_opt_in\Event
 */
class GetSubmissionStateEvent extends Event {

  /**
   * The Webform submission.
   *
   * @var \Drupal\webform\WebformSubmissionInterface
   */
  protected $webformSubmission;

  /**
   * The Webform submission state.
   *
   * @var string
   */
  protected $state;

  /**
   * GetSubmissionStateEvent constructor.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The Webform submission.
   * @param string $state
   *   The Webform submission state.
   */
  public function __construct(WebformSubmissionInterface $webform_submission, $state) {
    $this->webformSubmission = $webform_submission;
    $this->state = $state;
  }

  /**
   * Gets the Webform submission.
   *
   * @return \Drupal\webform\WebformSubmissionInterface
   *   The Webform submission.
   */
  public function getSubmission() {
    return $this->webformSubmission;
  }

  /**
   * Sets the Webform submission.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webformSubmission
   *   The Webform submission.
   */
  public function setSubmission(WebformSubmissionInterface $webformSubmission) {
    $this->webformSubmission = $webformSubmission;
  }

  /**
   * Gets the Webform submission state.
   *
   * @return string
   *   The Webform submission state.
   */
  public function getState() {
    return $this->state;
  }

  /**
   * Sets the Webform submission state.
   *
   * @param string $state
   *   The Webform submission state.
   */
  public function setState($state) {
    $this->state = $state;
  }

}
