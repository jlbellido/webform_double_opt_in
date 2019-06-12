<?php

namespace Drupal\webform_double_opt_in\Plugin\WebformHandler;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\webform\Element\WebformMessage;
use Drupal\webform\Element\WebformSelectOther;
use Drupal\webform\Plugin\WebformHandler\EmailWebformHandler;
use Drupal\webform_double_opt_in\Event\GetSubmissionStateEvent;
use Drupal\webform_double_opt_in\Event\GetSubmissionStateOptionsEvent;
use Drupal\webform_double_opt_in\Event\WebformDoubleOptInEvents;
use Drupal\webform\Plugin\WebformElement\WebformCompositeBase;
use Drupal\webform\Plugin\WebformElementManagerInterface;
use Drupal\webform\Twig\TwigExtension;
use Drupal\webform\Utility\WebformElementHelper;
use Drupal\webform\Utility\WebformOptionsHelper;
use Drupal\webform\WebformSubmissionConditionsValidatorInterface;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\webform\WebformThemeManagerInterface;
use Drupal\webform\WebformTokenManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Extends the e-mail handler and makes it compatible with double-opt in.
 *
 * @WebformHandler(
 *   id = "webform_double_opt_in_email_compatible",
 *   label = @Translation("Double opt-in compatible email"),
 *   category = @Translation("Notification"),
 *   description = @Translation("Sends a webform submission via an email."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_UNLIMITED,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_OPTIONAL,
 *   tokens = TRUE,
 * )
 */
class DoubleOptInCompatibleEmailWebformHandler extends EmailWebformHandler {

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, LoggerChannelFactoryInterface $logger_factory, ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager, WebformSubmissionConditionsValidatorInterface $conditions_validator, AccountInterface $current_user, ModuleHandlerInterface $module_handler, LanguageManagerInterface $language_manager, MailManagerInterface $mail_manager, WebformThemeManagerInterface $theme_manager, WebformTokenManagerInterface $token_manager, WebformElementManagerInterface $element_manager, EventDispatcherInterface $event_dispatcher) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $logger_factory, $config_factory, $entity_type_manager, $conditions_validator, $current_user, $module_handler, $language_manager, $mail_manager, $theme_manager, $token_manager, $element_manager);

    $this->eventDispatcher = $event_dispatcher;
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
      $container->get('event_dispatcher')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $this->applyFormStateToConfiguration($form_state);

    // Get options, mail, and text elements as options (text/value).
    $text_element_options_value = [];
    $text_element_options_raw = [];
    $name_element_options = [];
    $mail_element_options = [];
    $options_element_options = [];

    $elements = $this->webform->getElementsInitializedAndFlattened();
    foreach ($elements as $element_key => $element) {
      $element_plugin = $this->elementManager->getElementInstance($element);
      if (!$element_plugin->isInput($element) || !isset($element['#type'])) {
        continue;
      }

      // Set title.
      $element_title = (isset($element['#title'])) ? new FormattableMarkup('@title (@key)', ['@title' => $element['#title'], '@key' => $element_key]) : $element_key;

      // Add options element token, which can include multiple values.
      if (isset($element['#options'])) {
        $options_element_options["[webform_submission:values:$element_key:raw]"] = $element_title;
      }

      // Multiple value elements can NOT be used as a tokens.
      if ($element_plugin->hasMultipleValues($element)) {
        continue;
      }

      if (!$element_plugin->isComposite()) {
        // Add text element value and raw tokens.
        $text_element_options_value["[webform_submission:values:$element_key:value]"] = $element_title;
        $text_element_options_raw["[webform_submission:values:$element_key:raw]"] = $element_title;

        // Add name element token.
        $name_element_options["[webform_submission:values:$element_key:raw]"] = $element_title;

        // Add mail element token.
        if (in_array($element['#type'], ['email', 'hidden', 'value', 'textfield', 'webform_email_multiple', 'webform_email_confirm'])) {
          $mail_element_options["[webform_submission:values:$element_key:raw]"] = $element_title;
        }
      }

      // Allow 'webform_name' composite to be used a value token.
      if ($element['#type'] === 'webform_name') {
        $name_element_options["[webform_submission:values:$element_key:value]"] = $element_title;
      }

      // Handle composite sub elements.
      if ($element_plugin instanceof WebformCompositeBase) {
        $composite_elements = $element_plugin->getCompositeElements();
        foreach ($composite_elements as $composite_key => $composite_element) {
          $composite_element_plugin = $this->elementManager->getElementInstance($element);
          if (!$composite_element_plugin->isInput($element) || !isset($composite_element['#type'])) {
            continue;
          }

          // Set composite title.
          if (isset($element['#title'])) {
            $f_args = [
              '@title' => $element['#title'],
              '@composite_title' => $composite_element['#title'],
              '@key' => $element_key,
              '@composite_key' => $composite_key,
            ];
            $composite_title = new FormattableMarkup('@title: @composite_title (@key: @composite_key)', $f_args);
          }
          else {
            $composite_title = "$element_key:$composite_key";
          }

          // Add name element token. Only applies to basic (not composite) elements.
          $name_element_options["[webform_submission:values:$element_key:$composite_key:raw]"] = $composite_title;

          // Add mail element token.
          if (in_array($composite_element['#type'], ['email', 'webform_email_multiple', 'webform_email_confirm'])) {
            $mail_element_options["[webform_submission:values:$element_key:$composite_key:raw]"] = $composite_title;
          }
        }
      }
    }

    // Get roles.
    $roles_element_options = [];
    if ($roles = $this->configFactory->get('webform.settings')->get('mail.roles')) {
      $role_names = array_map('\Drupal\Component\Utility\Html::escape', user_role_names(TRUE));
      if (!in_array('authenticated', $roles)) {
        $role_names = array_intersect_key($role_names, array_combine($roles, $roles));
      }
      foreach ($role_names as $role_name => $role_label) {
        $roles_element_options["[webform_role:$role_name]"] = new FormattableMarkup('@title (@key)', ['@title' => $role_label, '@key' => $role_name]);
      }
    }

    // Get email and name other.
    $other_element_email_options = [
      '[site:mail]' => 'Site email address',
      '[current-user:mail]' => 'Current user email address [Authenticated only]',
      '[webform:author:mail]' => 'Webform author email address',
      '[webform_submission:user:mail]' => 'Webform submission owner email address [Authenticated only]',
    ];
    $other_element_name_options = [
      '[site:name]' => 'Site name',
      '[current-user:display-name]' => 'Current user display name',
      '[current-user:account-name]' => 'Current user account name',
      '[webform:author:display-name]' => 'Webform author display name',
      '[webform:author:account-name]' => 'Webform author account name',
      '[webform_submission:author:display-name]' => 'Webform submission author display name',
      '[webform_submission:author:account-name]' => 'Webform submission author account name',
    ];

    // Disable client-side HTML5 validation which is having issues with hidden
    // element validation.
    // @see http://stackoverflow.com/questions/22148080/an-invalid-form-control-with-name-is-not-focusable
    $form['#attributes']['novalidate'] = 'novalidate';

    // To.
    $form['to'] = [
      '#type' => 'details',
      '#title' => $this->t('Send to'),
      '#open' => TRUE,
    ];
    $form['to']['to_mail'] = $this->buildElement('to_mail', $this->t('To email'), $this->t('To email address'), TRUE, $mail_element_options, $options_element_options, $roles_element_options, $other_element_email_options);
    $form['to']['cc_mail'] = $this->buildElement('cc_mail', $this->t('CC email'), $this->t('CC email address'), FALSE, $mail_element_options, $options_element_options, $roles_element_options, $other_element_email_options);
    $form['to']['bcc_mail'] = $this->buildElement('bcc_mail', $this->t('BCC email'), $this->t('BCC email address'), FALSE, $mail_element_options, $options_element_options, $roles_element_options, $other_element_email_options);

    $token_types = ['webform', 'webform_submission'];
    // Show webform role tokens if they have been specified.
    if (!empty($roles_element_options)) {
      $token_types[] = 'webform_role';
    }
    if ($this->moduleHandler->moduleExists('webform_access')) {
      $token_types[] = 'webform_access';
    }
    $form['to']['token_tree_link'] = $this->buildTokenTreeElement($token_types);

    if (empty($roles_element_options) && $this->currentUser->hasPermission('administer webform')) {
      $form['to']['roles_message'] = [
        '#type' => 'webform_message',
        '#message_type' => 'warning',
        '#message_message' => $this->t('Please note: You can select which user roles can be available to receive webform emails by going to the Webform module\'s <a href=":href">admin settings</a> form.', [':href' => Url::fromRoute('webform.config.handlers')->toString()]),
        '#message_close' => TRUE,
        '#message_id' => 'webform_email_roles_message',
        '#message_storage' => WebformMessage::STORAGE_USER,
      ];
    }

    // From.
    $form['from'] = [
      '#type' => 'details',
      '#title' => $this->t('Send from'),
      '#open' => TRUE,
    ];
    $form['from']['from_mail'] = $this->buildElement('from_mail', $this->t('From email'), $this->t('From email address'), TRUE, $mail_element_options, $options_element_options, NULL, $other_element_email_options);
    $form['from']['from_name'] = $this->buildElement('from_name', $this->t('From name'), $this->t('From name'), FALSE, $name_element_options, NULL, NULL, $other_element_name_options);
    $form['from']['token_tree_link'] = $this->buildTokenTreeElement();

    // Message.
    $form['message'] = [
      '#type' => 'details',
      '#title' => $this->t('Message'),
      '#open' => TRUE,
    ];
    $form['message'] += $this->buildElement('subject', $this->t('Subject'), $this->t('subject'), FALSE, $text_element_options_raw);

    $has_edit_twig_access = (TwigExtension::hasEditTwigAccess() || $this->configuration['twig']);

    // Message: Body.
    // Building a custom select other element that toggles between
    // HTML (CKEditor), Plain text (CodeMirror), and Twig (CodeMirror)
    // custom body elements.
    $body_options = [];
    $body_options[WebformSelectOther::OTHER_OPTION] = $this->t('Custom body…');
    if ($has_edit_twig_access) {
      $body_options['twig'] = $this->t('Twig template…');
    }
    $body_options[static::DEFAULT_VALUE] = $this->t('Default');
    $body_options[(string) $this->t('Elements')] = $text_element_options_value;

    // Get default format.
    $body_default_format = ($this->configuration['html']) ? 'html' : 'text';

    // Get default values.
    $body_default_values = $this->getBodyDefaultValues();

    // Get custom default values which are the same as default values.
    $body_custom_default_values = $this->getBodyDefaultValues();

    // Set up default Twig body and convert tokens to use the
    // webform_token() Twig function.
    // @see \Drupal\webform\Twig\TwigExtension
    $twig_default_body = $body_custom_default_values[$body_default_format];
    $twig_default_body = preg_replace('/(\[[^]]+\])/', '{{ webform_token(\'\1\', webform_submission) }}', $twig_default_body);
    $body_custom_default_values['twig'] = $twig_default_body;

    // Look at the 'body' and determine the body select and custom
    // default values.
    if (WebformOptionsHelper::hasOption($this->configuration['body'], $body_options)) {
      $body_select_default_value = $this->configuration['body'];
    }
    elseif ($this->configuration['twig']) {
      $body_select_default_value = 'twig';
      $body_custom_default_values['twig'] = $this->configuration['body'];
    }
    else {
      $body_select_default_value = WebformSelectOther::OTHER_OPTION;
      $body_custom_default_values[$body_default_format] = $this->configuration['body'];
    }

    // Build body select menu.
    $form['message']['body'] = [
      '#type' => 'select',
      '#title' => $this->t('Body'),
      '#options' => $body_options,
      '#required' => TRUE,
      '#default_value' => $body_select_default_value,
    ];
    foreach ($body_default_values as $format => $default_value) {
      if ($format == 'html') {
        $form['message']['body_custom_' . $format] = [
          '#type' => 'webform_html_editor',
          '#format' => $this->configFactory->get('webform.settings')->get('html_editor.mail_format'),
        ];
      }
      else {
        $form['message']['body_custom_' . $format] = [
          '#type' => 'webform_codemirror',
          '#mode' => $format,
        ];
      }
      $form['message']['body_custom_' . $format] += [
        '#title' => $this->t('Body custom value (@format)', ['@format' => $format]),
        '#title_display' => 'hidden',
        '#default_value' => $body_custom_default_values[$format],
        '#states' => [
          'visible' => [
            ':input[name="settings[body]"]' => ['value' => WebformSelectOther::OTHER_OPTION],
            ':input[name="settings[html]"]' => ['checked' => ($format == 'html') ? TRUE : FALSE],
          ],
          'required' => [
            ':input[name="settings[body]"]' => ['value' => WebformSelectOther::OTHER_OPTION],
            ':input[name="settings[html]"]' => ['checked' => ($format == 'html') ? TRUE : FALSE],
          ],
        ],
      ];
      // Must set #parents because body_custom_* is not a configuration value.
      // @see \Drupal\webform\Plugin\WebformHandler\EmailWebformHandler::validateConfigurationForm
      $form['message']['body_custom_' . $format]['#parents'] = ['settings', 'body_custom_' . $format];

      // Default body.
      $form['message']['body_default_' . $format] = [
        '#type' => 'webform_codemirror',
        '#mode' => $format,
        '#title' => $this->t('Body default value (@format)', ['@format' => $format]),
        '#title_display' => 'hidden',
        '#default_value' => $body_default_values[$format],
        '#attributes' => ['readonly' => 'readonly', 'disabled' => 'disabled'],
        '#states' => [
          'visible' => [
            ':input[name="settings[body]"]' => ['value' => static::DEFAULT_VALUE],
            ':input[name="settings[html]"]' => ['checked' => ($format == 'html') ? TRUE : FALSE],
          ],
        ],
      ];
    }
    // Twig body with help.
    $form['message']['body_custom_twig'] = [
      '#type' => 'webform_codemirror',
      '#mode' => 'twig',
      '#title' => $this->t('Body custom value (Twig)'),
      '#title_display' => 'hidden',
      '#default_value' => $body_custom_default_values['twig'],
      '#access' => $has_edit_twig_access,
      '#states' => [
        'visible' => [
          ':input[name="settings[body]"]' => ['value' => 'twig'],
        ],
        'required' => [
          ':input[name="settings[body]"]' => ['value' => 'twig'],
        ],
      ],
      // Must set #parents because body_custom_twig is not a configuration value.
      // @see \Drupal\webform\Plugin\WebformHandler\EmailWebformHandler::validateConfigurationForm
      '#parents' => ['settings', 'body_custom_twig'],
    ];
    $form['message']['body_custom_twig_help'] = TwigExtension::buildTwigHelp() + [
      '#access' => $has_edit_twig_access,
      '#states' => [
        'visible' => [
          ':input[name="settings[body]"]' => ['value' => 'twig'],
        ],
      ],
    ];
    // Tokens.
    $form['message']['token_tree_link'] = $this->buildTokenTreeElement();

    // Elements.
    $form['elements'] = [
      '#type' => 'details',
      '#title' => $this->t('Included email values'),
      '#description' => $this->t('The selected elements will be included in the [webform_submission:values] token. Individual values may still be printed if explicitly specified as a [webform_submission:values:?] in the email body template.'),
      '#open' => $this->configuration['excluded_elements'] ? TRUE : FALSE,
    ];
    $form['elements']['excluded_elements'] = [
      '#type' => 'webform_excluded_elements',
      '#webform_id' => $this->webform->id(),
      '#default_value' => $this->configuration['excluded_elements'],
    ];
    $form['elements']['ignore_access'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Always include private and restricted access elements.'),
      '#description' => $this->t('If checked, access controls for included element will be ignored.'),
      '#return_value' => TRUE,
      '#default_value' => $this->configuration['ignore_access'],
    ];
    $form['elements']['exclude_empty'] = [
      '#type' => 'checkbox',
      '#title' => t('Exclude empty elements'),
      '#return_value' => TRUE,
      '#default_value' => $this->configuration['exclude_empty'],
    ];
    $form['elements']['exclude_empty_checkbox'] = [
      '#type' => 'checkbox',
      '#title' => t('Exclude unselected checkboxes'),
      '#return_value' => TRUE,
      '#default_value' => $this->configuration['exclude_empty_checkbox'],
    ];
    $elements = $this->webform->getElementsInitializedFlattenedAndHasValue();
    foreach ($elements as $element) {
      if (!empty($element['#access_view_roles']) || !empty($element['#private'])) {
        $form['elements']['ignore_access_message'] = [
          '#type' => 'webform_message',
          '#message_message' => $this->t('This webform contains private and/or restricted access elements, which will only be included if the user submitting the form has access to these elements.'),
          '#message_type' => 'warning',
          '#states' => [
            'visible' => [':input[name="settings[ignore_access]"]' => ['checked' => FALSE]],
          ],
        ];
        break;
      }
    }

    // Attachments.
    $form['attachments'] = [
      '#type' => 'details',
      '#title' => $this->t('Attachments'),
      '#access' => $this->getWebform()->hasAttachments(),
    ];
    if (!$this->supportsAttachments()) {
      $t_args = [
        ':href_smtp' => 'https://www.drupal.org/project/smtp',
        ':href_mailsystem' => 'https://www.drupal.org/project/mailsystem',
        ':href_swiftmailer' => 'https://www.drupal.org/project/swiftmailer',
      ];
      $form['attachments']['attachments_message'] = [
        '#type' => 'webform_message',
        '#message_message' => $this->t('To send email attachments, please install and configure the <a href=":href_smtp">SMTP Authentication Support</a> module or the <a href=":href_mailsystem">Mail System</a> and <a href=":href_swiftmailer">SwiftMailer</a> module.', $t_args),
        '#message_type' => 'warning',
        '#message_close' => TRUE,
        '#message_storage' => WebformMessage::STORAGE_SESSION,
      ];
    }
    $form['attachments']['attachments'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Include files as attachments'),
      '#description' => $this->t('If checked, only elements selected in the above email values will be attached the email.'),
      '#return_value' => TRUE,
      '#disabled' => !$this->supportsAttachments(),
      '#default_value' => $this->configuration['attachments'],
    ];

    // Additional.
    $results_disabled = $this->getWebform()->getSetting('results_disabled');
    $form['additional'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Additional settings'),
    ];
    // Settings: States.
    $form['additional']['states'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Send email'),
      '#options' => $this->getSubmissionStateOptions(),
      '#access' => $results_disabled ? FALSE : TRUE,
      '#default_value' => $results_disabled ? [WebformSubmissionInterface::STATE_COMPLETED] : $this->configuration['states'],
    ];
    $form['additional']['states_message'] = [
      '#type' => 'webform_message',
      '#message_message' => $this->t("Because no submission state is checked, this email can only be sent using the 'Resend' form and/or custom code."),
      '#message_type' => 'warning',
      '#states' => [
        'visible' => [
          ':input[name^="settings[states]"]' => ['checked' => FALSE],
        ],
      ],
    ];
    // Settings: Reply-to.
    $form['additional']['reply_to'] = $this->buildElement('reply_to', $this->t('Reply-to email'), $this->t('Reply-to email address'), FALSE, $mail_element_options, NULL, NULL, $other_element_email_options);
    // Settings: Return path.
    $form['additional']['return_path'] = $this->buildElement('return_path', $this->t('Return path'), $this->t('Return path email address'), FALSE, $mail_element_options, NULL, NULL, $other_element_email_options);
    // Settings: Sender mail.
    $form['additional']['sender_mail'] = $this->buildElement('sender_mail', $this->t('Sender email'), $this->t('Sender email address'), FALSE, $mail_element_options, $options_element_options, NULL, $other_element_email_options);
    // Settings: Sender name.
    $form['additional']['sender_name'] = $this->buildElement('sender_name', $this->t('Sender name'), $this->t('Sender name'), FALSE, $name_element_options, NULL, NULL, $other_element_name_options);

    // Settings: HTML.
    $form['additional']['html'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send email as HTML'),
      '#return_value' => TRUE,
      '#access' => $this->supportsHtml(),
      '#default_value' => $this->configuration['html'],
    ];

    // Setting: Themes.
    $form['additional']['theme_name'] = [
      '#type' => 'select',
      '#title' => $this->t('Theme to render this email'),
      '#description' => $this->t('Select the theme that will be used to render this email.'),
      '#options' => $this->themeManager->getThemeNames(),
      '#default_value' => $this->configuration['theme_name'],
    ];

    // Development.
    $form['development'] = [
      '#type' => 'details',
      '#title' => $this->t('Development settings'),
    ];
    $form['development']['debug'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable debugging'),
      '#description' => $this->t('If checked, sent emails will be displayed onscreen to all users.'),
      '#return_value' => TRUE,
      '#default_value' => $this->configuration['debug'],
    ];

    // ISSUE: TranslatableMarkup is breaking the #ajax.
    // WORKAROUND: Convert all Render/Markup to strings.
    WebformElementHelper::convertRenderMarkupToStrings($form);

    $this->elementTokenValidate($form, $token_types);

    return $this->setSettingsParents($form);
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE) {
    $state = $webform_submission->getWebform()->getSetting('results_disabled') ? WebformSubmissionInterface::STATE_COMPLETED : $this->getSubmissionState($webform_submission);
    if ($this->configuration['states'] && in_array($state, $this->configuration['states'])) {
      $message = $this->getMessage($webform_submission);
      $this->sendMessage($webform_submission, $message);
    }
  }

  /**
   * Returns the available submission state options.
   */
  protected function getSubmissionStateOptions() {
    $defaultStateOptions = [
      WebformSubmissionInterface::STATE_DRAFT => $this->t('…when <b>draft</b> is saved.'),
      WebformSubmissionInterface::STATE_CONVERTED => $this->t('…when anonymous submission is <b>converted</b> to authenticated.'),
      WebformSubmissionInterface::STATE_COMPLETED => $this->t('…when submission is <b>completed</b>.'),
      WebformSubmissionInterface::STATE_UPDATED => $this->t('…when submission is <b>updated</b>.'),
      WebformSubmissionInterface::STATE_DELETED => $this->t('…when submission is <b>deleted</b>.'),
      WebformSubmissionInterface::STATE_LOCKED => $this->t('…when submission is <b>locked</b>.'),
    ];

    $getSubmissionStateOptionsEvent = new GetSubmissionStateOptionsEvent($defaultStateOptions);
    $this->eventDispatcher->dispatch(WebformDoubleOptInEvents::GET_SUBMISSION_STATE_OPTIONS, $getSubmissionStateOptionsEvent);

    $submissionStateOptions = $getSubmissionStateOptionsEvent->getSubmissionStateOptions();

    return $submissionStateOptions;
  }

  /**
   * Returns the webform submission state.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webformSubmission
   *   The webform submission to get the state for.
   */
  protected function getSubmissionState(WebformSubmissionInterface $webformSubmission) {
    $state = $webformSubmission->getState();

    $getSubmissionStateEvent = new GetSubmissionStateEvent($webformSubmission, $state);
    $this->eventDispatcher->dispatch(WebformDoubleOptInEvents::GET_SUBMISSION_STATE, $getSubmissionStateEvent);

    $state = $getSubmissionStateEvent->getState();

    return $state;
  }

}
