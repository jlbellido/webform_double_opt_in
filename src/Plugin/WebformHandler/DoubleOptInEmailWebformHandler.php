<?php

namespace Drupal\webform_double_opt_in\Plugin\WebformHandler;

use Drupal\Core\Form\FormStateInterface;
use Drupal\email_confirmer\EmailConfirmationInterface;
use Drupal\email_confirmer\EmailConfirmerManagerInterface;
use Drupal\webform\Plugin\WebformHandler\EmailWebformHandler;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\webform\Plugin\WebformElementManagerInterface;
use Drupal\webform\WebformSubmissionConditionsValidatorInterface;
use Drupal\webform\WebformThemeManagerInterface;
use Drupal\webform\WebformTokenManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

// TODO: Do not extend EmailWebformHandler?
/**
 * Sends a double opt-in e-mail.
 *
 * @WebformHandler(
 *   id = "webform_double_opt_in_email",
 *   label = @Translation("Double opt-in e-mail"),
 *   category = @Translation("Notification"),
 *   description = @Translation("Sends a double opt-in e-mail."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_UNLIMITED,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_OPTIONAL,
 *   tokens = TRUE,
 * )
 */
class DoubleOptInEmailWebformHandler extends EmailWebformHandler {

  /**
   * Opt-in states.
   */
  const STATUS_PENDING_MAIL = 'Double opt-in confirmation mail pending';
  const STATUS_PENDING = 'Double opt-in confirmation pending';
  const STATUS_CONFIRMED = 'Double opt-in confirmed';

  /**
   * Default setting for the global opt-in flag.
   */
  const OPT_IN_GLOBALLY_DEFAULT = FALSE;

  /**
   * Key of the global opt-in setting.
   */
  const OPT_IN_GLOBALLY = 'opt_in_globally';

  /**
   * The email confirmer manager.
   *
   * @var \Drupal\email_confirmer\EmailConfirmerManagerInterface
   */
  protected $eMailConfirmer;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, LoggerChannelFactoryInterface $logger_factory, ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager, WebformSubmissionConditionsValidatorInterface $conditions_validator, AccountInterface $current_user, ModuleHandlerInterface $module_handler, LanguageManagerInterface $language_manager, MailManagerInterface $mail_manager, WebformThemeManagerInterface $theme_manager, WebformTokenManagerInterface $token_manager, WebformElementManagerInterface $element_manager, EmailConfirmerManagerInterface $email_confirmer) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $logger_factory, $config_factory, $entity_type_manager, $conditions_validator, $current_user, $module_handler, $language_manager, $mail_manager, $theme_manager, $token_manager, $element_manager);
    $this->eMailConfirmer = $email_confirmer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.factory'),
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('webform_submission.conditions_validator'),
      $container->get('current_user'),
      $container->get('module_handler'),
      $container->get('language_manager'),
      $container->get('plugin.manager.mail'),
      $container->get('webform.theme_manager'),
      $container->get('webform.token_manager'),
      $container->get('plugin.manager.webform.element'),
      $container->get('email_confirmer')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getSummary() {
    $summary = parent::getSummary();

    $summary['#settings'][self::OPT_IN_GLOBALLY] = self::OPT_IN_GLOBALLY_DEFAULT;

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    $defaultConfiguration = parent::defaultConfiguration();

    $defaultConfiguration[self::OPT_IN_GLOBALLY] = self::OPT_IN_GLOBALLY_DEFAULT;

    return $defaultConfiguration;
  }

  /**
   * Get configuration default values.
   *
   * @return array
   *   Configuration default values.
   */
  protected function getDefaultConfigurationValues() {
    $defaultValues = parent::getDefaultConfigurationValues();

    $defaultValues[self::OPT_IN_GLOBALLY] = self::OPT_IN_GLOBALLY_DEFAULT;
    $this->defaultValues = $defaultValues;

    return $this->defaultValues;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = [];

    $form['opt_in_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Opt-in settings'),
      '#open' => TRUE,
    ];

    // Opt-in globally setting.
    $form['opt_in_settings'][self::OPT_IN_GLOBALLY] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use global opt-in per e-mail address.'),
      '#description' => $this->t('If checked, the user will not receive an opt-in e-mail if the e-mail has already been confirmed globally previously. If not, the user will receive an opt-in e-mail for every form submission.'),
      '#return_value' => TRUE,
      '#default_value' => $this->configuration[self::OPT_IN_GLOBALLY],
    ];

    $parentForm = parent::buildConfigurationForm($form, $form_state);

    /* Use form of this handler as first argument,
    in order for it to be prepended to the parent form.
    And show up first on the configuration form. */
    $form = array_merge($form, $parentForm);

    // Override available tokens. Add email-confirmer tokens.
    $form['message']['token_tree_link'] = $this->tokenManager->buildTreeElement(
      ['webform', 'webform_submission', 'email-confirmer'],
      $this->t('Use [webform_submission:values:ELEMENT_KEY:raw] to get plain text values and use [webform_submission:values:ELEMENT_KEY:value] to get HTML values.')
    );

    return $form;
  }

  /**
   * Sets the double opt-in status to FALSE.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   Values of the form submission.
   */
  public function preSave(WebformSubmissionInterface $webform_submission) {
    parent::preSave($webform_submission);

    $submissionData = $webform_submission->getData();
    // Only set the opt-in flag if it does not exist yet.
    if (!isset($submissionData['opt_in_status'])) {
      /* TODO: Automatically create the opt_in_status field or save it somewhere else. */
      $submissionData['opt_in_status'] = self::STATUS_PENDING_MAIL;
      $webform_submission->setData($submissionData);
    }
  }

  /**
   * Sends opt-in e-mail if the opt_in_status is pending mail.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The Webform submission.
   * @param bool $update
   *   Update flag.
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE) {
    // Check if the handler should trigger.
    $state = $webform_submission->getWebform()->getSetting('results_disabled') ? WebformSubmissionInterface::STATE_COMPLETED : $webform_submission->getState();
    if ($this->configuration['states'] && in_array($state, $this->configuration['states'])) {

      // Check if the opt-in e-mail needs to be sent.
      $submissionData = $webform_submission->getData();
      if (isset($submissionData['opt_in_status']) && $submissionData['opt_in_status'] === self::STATUS_PENDING_MAIL) {
        $submissionData['opt_in_status'] = self::STATUS_PENDING;
        $webform_submission->setData($submissionData);
        $webform_submission->resave();

        if ($this->configuration[self::OPT_IN_GLOBALLY]) {
          $optInRealm = 'webform_double_opt_in';
        }
        else {
          $optInRealm = 'webform_double_opt_in_' . $webform_submission->id();
        }

        $message = $this->getMessage($webform_submission);

        /* Set the opt-in status to confirmed if the user already confirmed his e-mail address at some point. */
        $exitingEmailConfirmation = $this->eMailConfirmer->getConfirmation($message['to_mail'], FALSE, $optInRealm);
        if ($exitingEmailConfirmation instanceof EmailConfirmationInterface && $exitingEmailConfirmation->isConfirmed()) {
          $submissionData['opt_in_status'] = self::STATUS_CONFIRMED;
          $webform_submission->setData($submissionData);
          $webform_submission->resave();
        }
        else {
          $message['is_html'] = $message['html'];
          // TODO: Replace tokens in mail subject.
          $confirmation = $this->eMailConfirmer->confirm($message['to_mail'], ['webform_submission_id' => $webform_submission->id()], $optInRealm, $message);
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function postDelete(WebformSubmissionInterface $webform_submission) {
    // Override parent method to prevent e-mail sending.
  }

  /**
   * Returns the allowed token types for the handler.
   *
   * @param bool $showRoleTokens
   *   Flag if "webform_role" tokens should be allowed.
   *
   * @return array
   *   Array of token types.
   */
  protected function getPossibleTokenTypes($showRoleTokens) {
    $tokenTypes = parent::getPossibleTokenTypes($showRoleTokens);

    // Allow email confirmer tokens.
    $tokenTypes[] = 'email-confirmer';

    return $tokenTypes;
  }

}
