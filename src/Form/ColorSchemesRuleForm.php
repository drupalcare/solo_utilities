<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Form;

use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Executable\ExecutableManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\Core\Plugin\Context\ContextRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form controller for Color Schemes Rule edit forms.
 */
class ColorSchemesRuleForm extends EntityForm {

  /**
   * The ConditionManager for building the visibility UI.
   *
   * @var \Drupal\Core\Executable\ExecutableManagerInterface
   */
  protected $conditionPluginManager;

  /**
   * The context repository service.
   *
   * @var \Drupal\Core\Plugin\Context\ContextRepositoryInterface
   */
  protected $contextRepository;

  /**
   * Constructs a ColorSchemesRuleForm object.
   *
   * @param \Drupal\Core\Executable\ExecutableManagerInterface $condition_plugin_manager
   *   The ConditionManager for building the visibility UI.
   * @param \Drupal\Core\Plugin\Context\ContextRepositoryInterface $context_repository
   *   The context repository service.
   */
  public function __construct(ExecutableManagerInterface $condition_plugin_manager, ContextRepositoryInterface $context_repository) {
    $this->conditionPluginManager = $condition_plugin_manager;
    $this->contextRepository = $context_repository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.condition'),
      $container->get('context.repository')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    // Get the path to the 'solo' theme using Drupal's service.
    $theme_path = \Drupal::service('extension.list.theme')->getPath('solo');
    if ($theme_path) {
      include_once DRUPAL_ROOT . '/' . $theme_path . '/includes/_theme_settings_helper_functions.inc';
    }
    else {
      // If the theme path is not found, disable the form and show an error.
      $form['error'] = [
        '#markup' => '<div class="messages messages--error">' . $this->t('The required Solo theme settings helper functions could not be loaded. Please ensure the Solo theme is installed and enabled.') . '</div>',
        '#weight' => -1000,
      ];
      // Disable all other form elements.
      $form['#attributes']['class'][] = 'form-disabled';
      return $form;
    }

    $available_contexts = $this->contextRepository->getAvailableContexts();
    $form_state->setTemporaryValue('gathered_contexts', $available_contexts);

    /** @var \Drupal\solo_utilities\Entity\ColorSchemesRule $entity */
    $entity = $this->entity;
    $form['#tree'] = TRUE;

    $form['#attached']['library'][] = 'solo_utilities/pre-defined';

    // Label field.
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#maxlength' => 255,
      '#default_value' => $entity->label(),
      '#description' => $this->t('Label for the Color Schemes Rule.'),
      '#required' => TRUE,
    ];

    // Machine name field.
    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $entity->id(),
      '#machine_name' => [
        'exists' => '\Drupal\solo_utilities\Entity\ColorSchemesRule::load',
      ],
      '#disabled' => !$entity->isNew(),
    ];

    // Status field.
    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#default_value' => $entity->status(),
    ];

    // Theme category field.
    $theme_categories = _get_theme_category();
    $form['theme_category'] = [
      '#type' => 'select',
      '#title' => $this->t('Select a category'),
      '#options' => $theme_categories,
      '#default_value' => $entity->get('theme_category') ?: 'none',
      '#required' => TRUE,
    ];

    // Predefined theme field.
    $predefined_themes = _get_predefined_theme();
    $form['predefined_theme'] = [
      '#type' => 'select',
      '#title' => $this->t('Choose the desired color scheme'),
      '#options' => $predefined_themes,
      '#default_value' => $entity->get('predefined_theme') ?: '',
      '#required' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="theme_category"]' => ['!value' => 'none'],
        ],
      ],
    ];

    $form['predefined_theme']['#attributes']['data-default-value'] = $entity->get('predefined_theme');

    // Conjunction radio buttons.
    $form['conjunction'] = [
      '#type' => 'radios',
      '#title' => $this->t('Set conjunction operator for the visibility conditions below'),
      '#options' => [
        'and' => $this->t('AND: all conditions should pass.'),
        'or' => $this->t('OR: at least one of the conditions should pass.'),
      ],
      '#default_value' => $entity->getConjunction(),
    ];

    // Conditions as vertical tabs.
    $form['conditions'] = [
      'visibility_tabs' => [
        '#type' => 'vertical_tabs',
        '#title' => $this->t('Conditions'),
        '#parents' => ['visibility_tabs'],
      ],
    ];

    $conditions = $entity->getConditions();
    $definitions = $this->conditionPluginManager->getFilteredDefinitions(
      'solo_utilities_ui',
      $form_state->getTemporaryValue('gathered_contexts'),
      ['color_schemes_rule' => $entity]
    );

    foreach ($definitions as $condition_id => $definition) {
      try {
        $condition_plugin = $this->conditionPluginManager->createInstance(
          $condition_id, $conditions[$condition_id] ?? []
        );
        $form_state->set(['conditions', $condition_id], $condition_plugin);
        $condition_form = $condition_plugin->buildConfigurationForm([], $form_state);

        // Check if the current condition is for the "current_theme" plugin.
        if ($condition_id == 'current_theme') {
          $form['conditions'][$condition_id] = [
            '#type' => 'details',
            '#title' => $condition_plugin->getPluginDefinition()['label'],
            '#group' => 'visibility_tabs',
          ];
          // Add custom select list for themes with only "Solo" and its
          // sub-themes.
          $form['conditions'][$condition_id]['theme'] = [
            '#type' => 'select',
            '#title' => $this->t('Theme'),
            '#options' => $this->getSoloAndSubThemes(),
            '#default_value' => $condition_plugin->getConfiguration()['theme'] ?? '',
            '#description' => $this->t('Select a theme that is "Solo" or its sub-theme.'),
          ];
        }
        else {
          $form['conditions'][$condition_id] = [
            '#type' => 'details',
            '#title' => $condition_plugin->getPluginDefinition()['label'],
            '#group' => 'visibility_tabs',
          ] + $condition_form;
        }
      }
      catch (PluginException $e) {
        // \Drupal::logger('solo_utilities')
        // ->warning('Condition plugin @id could not be loaded: @message',
        // ['@id' => $condition_id, '@message' => $e->getMessage()]);
      }
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    // Validate the Theme Category field.
    $theme_category = $form_state->getValue('theme_category');
    if (empty($theme_category) || $theme_category === 'none') {
      $form_state->setErrorByName('theme_category', $this->t('The Theme Category field is required.'));
    }

    // Validate visibility condition settings.
    foreach ($form_state->getValue('conditions') as $condition_id => $values) {
      if (array_key_exists('negate', $values)) {
        $form_state->setValue(['conditions', $condition_id, 'negate'], (bool) $values['negate']);
      }

      // Allow the condition to validate the form.
      $condition = $form_state->get(['conditions', $condition_id]);
      $subform = SubformState::createForSubform($form['conditions'][$condition_id], $form, $form_state);
      $condition->validateConfigurationForm($form['conditions'][$condition_id], $subform);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    /** @var \Drupal\solo_utilities\Entity\ColorSchemesRule $entity */
    $entity = $this->entity;

    // Save entity fields.
    $entity->set('label', $form_state->getValue('label'));
    $entity->set('status', $form_state->getValue('status'));
    $entity->set('theme_category', $form_state->getValue('theme_category'));

    // Extract and save the predefined theme.
    $predefined_theme = $form_state->getValue('predefined_theme');
    if (strpos($predefined_theme, '|') !== FALSE) {
      [, $predefined_theme] = explode('|', $predefined_theme, 2);
    }
    $entity->set('predefined_theme', $predefined_theme);

    // Save conjunction operator.
    $entity->set('conjunction', $form_state->getValue('conjunction'));

    // Save conditions.
    foreach ($form_state->getValue('conditions') as $condition_id => $values) {
      $condition = $form_state->get(['conditions', $condition_id]);
      $subform = SubformState::createForSubform($form['conditions'][$condition_id], $form, $form_state);
      $condition->submitConfigurationForm($form['conditions'][$condition_id], $subform);

      // Update the visibility conditions on the entity.
      $entity->getVisibilityConditions()->addInstanceId($condition_id, $condition->getConfiguration());
    }

    // Save the entity.
    $status = $entity->save();

    // Display a status message.
    $message = $this->t("The Color Schemes Rule '%label' has been %op.", [
      '%label' => $entity->label(),
      '%op' => ($status == SAVED_NEW) ? 'created' : 'updated',
    ]);
    $this->messenger()->addStatus($message);

    // Ensure this is the updated route.
    $form_state->setRedirect('entity.color_schemes_rule.collection');
  }

  /**
   * Helper function to get Solo or its sub-themes.
   *
   * @return array
   *   An associative array of theme machine names and their readable names.
   */
  protected function getSoloAndSubThemes() {
    $theme_handler = \Drupal::service('theme_handler');
    $all_themes = $theme_handler->listInfo();
    $solo_themes = [];

    foreach ($all_themes as $theme_key => $theme_info) {
      if (solo_utilities__is_solo_in_theme_hierarchy($theme_key)) {
        $solo_themes[$theme_key] = $theme_info->info['name'];
      }
    }

    return $solo_themes;
  }

  /**
   * Helper function to check if Solo or its sub-themes are enabled.
   *
   * @return bool
   *   TRUE if Solo or its sub-themes are enabled, FALSE otherwise.
   */
  protected function isSoloOrSubThemeEnabled() {
    return !empty($this->getSoloAndSubThemes());
  }

}
