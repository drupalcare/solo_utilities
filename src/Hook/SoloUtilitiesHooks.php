<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Hook;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ThemeSettingsProvider;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Template\Attribute;
use Drupal\block\BlockInterface;
use Drupal\node\NodeInterface;
use Drupal\solo_utilities\ColorSchemesNegotiator;
use Drupal\solo_utilities\Service\BlockTitleSettings;
use Drupal\solo_utilities\Service\ReadmeRenderer;
use Drupal\solo_utilities\Service\SoloThemeDetection;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Hook implementations for solo_utilities.
 *
 * @internal
 */
final class SoloUtilitiesHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly SoloThemeDetection $detection,
    private readonly ThemeSettingsProvider $themeSettings,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RequestStack $requestStack,
    private readonly ColorSchemesNegotiator $negotiator,
    private readonly BlockTitleSettings $blockTitleSettings,
    private readonly ReadmeRenderer $readmeRenderer,
  ) {}

  /**
   * Implements hook_help().
   *
   * Theme scope: none. Reads no theme; the help page is theme independent.
   */
  #[Hook('help')]
  public function help(string $route_name, RouteMatchInterface $route_match): string|array|NULL {
    if ($route_name === 'help.page.solo_utilities') {
      return $this->readmeRenderer->render();
    }
    return NULL;
  }

  /**
   * Implements hook_preprocess_HOOK() for page templates.
   *
   * Theme scope: default. Kept as before: the rules attach for the front-end
   * theme even when an admin theme renders. Candidate for active; owner
   * decision.
   */
  #[Hook('preprocess_page')]
  public function preprocessPage(array &$variables): void {
    // Check if Solo theme or a sub-theme is active before attaching libraries.
    if ($this->detection->isDefault()) {
      $this->negotiator->evaluateRules($variables);
    }
  }

  /**
   * Implements hook_form_FORM_ID_alter().
   *
   * Theme scope: default. Node forms render under the admin theme; the width
   * field must still show. isActive() would remove the feature.
   */
  #[Hook('form_node_form_alter')]
  public function formNodeFormAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    if ($this->detection->isDefault()) {
      $default_theme = $this->detection->defaultThemeName();
      if ($this->themeSettings->getSetting('enable_custom_node_width', $default_theme)) {
        $node = $form_state->getFormObject()->getEntity();
        if ($node instanceof NodeInterface) {
          $current_width_class = 'none';
          $node_id = $node->id();
          if ($node_id) {
            $current_width = $this->entityTypeManager->getStorage('node_width')->loadByProperties([
              'node_id' => $node_id,
            ]);
            $current_width_class = !empty($current_width) ? reset($current_width)->get('width_class')->value : 'none';
          }
          $width_options = [
            'none' => $this->t('None'),
            'sw-800' => $this->t('800px / 50rem'),
            'sw-1024' => $this->t('1024px / 64rem'),
            'sw-1280' => $this->t('1280px / 80rem'),
            'sw-1440' => $this->t('1440px / 90rem'),
            'sw-1600' => $this->t('1600px / 100rem'),
            'sw-1920' => $this->t('1920px / 120rem'),
            'sw-2560' => $this->t('2560px / 160rem'),
            'sw-100' => $this->t('100%'),
          ];
          $form['custom_node_width'] = [
            '#type' => 'select',
            '#title' => $this->t('Custom Width'),
            '#options' => $width_options,
            '#default_value' => $current_width_class,
            '#attributes' => [
              'name' => 'custom_node_width',
            ],
            '#weight' => 999,
          ];
          // Add a custom submit handler to ensure the width gets correctly.
          $form['#submit'][] = 'solo_utilities__node_form_submit';
        }
      }
    }
  }

  /**
   * Implements hook_node_presave().
   *
   * Saves the custom width value when the node is saved.
   *
   * Theme scope: default. Saves run under the admin theme; same reason as the
   * node form.
   */
  #[Hook('node_presave')]
  public function nodePresave(NodeInterface $node): void {
    // Check if the Solo theme or its sub-themes are active.
    if ($this->detection->isDefault()) {
      // Use the request stack to get the POST data during node save.
      $custom_width = $this->requestStack->getCurrentRequest()->request->get('custom_node_width');
      if ($custom_width === NULL) {
        // Exit if no width is provided.
        return;
      }
      $node_width_storage = $this->entityTypeManager->getStorage('node_width');
      $node_id = $node->id();
      if ($node_id) {
        if ($custom_width && $custom_width !== 'none') {
          $existing = $node_width_storage->loadByProperties([
            'node_id' => $node_id,
          ]);
          if (!empty($existing)) {
            $node_width = reset($existing);
            $node_width->set('width_class', $custom_width);
          }
          else {
            $node_width = $node_width_storage->create([
              'node_id' => $node_id,
              'width_class' => $custom_width,
            ]);
          }
          $node_width->save();
        }
        elseif ($custom_width === 'none') {
          // Remove custom width if 'None' is selected.
          $existing = $node_width_storage->loadByProperties([
            'node_id' => $node_id,
          ]);
          if (!empty($existing)) {
            $node_width = reset($existing);
            $node_width->delete();
          }
        }
      }
    }
  }

  /**
   * Implements hook_node_insert().
   *
   * Saves the custom width value when a new node is created.
   *
   * Theme scope: default. Saves run under the admin theme; same reason as the
   * node form.
   */
  #[Hook('node_insert')]
  public function nodeInsert(NodeInterface $node): void {
    // Check if the Solo theme or its sub-themes are active.
    if ($this->detection->isDefault()) {
      // Use the request object to get the POST data during node save.
      $custom_width = $this->requestStack->getCurrentRequest()->request->get('custom_node_width');
      // Check if custom width is not NULL and not 'none'.
      if ($custom_width !== NULL && $custom_width !== 'none') {
        // Get the storage for the node_width entity.
        $node_width_storage = $this->entityTypeManager->getStorage('node_width');
        // Create and save a new node_width entity for the node.
        $node_width = $node_width_storage->create([
          'node_id' => $node->id(),
          'width_class' => $custom_width,
        ]);
        // Save the node_width entity.
        $node_width->save();
      }
    }
  }

  /**
   * Implements hook_form_FORM_ID_alter().
   *
   * Theme scope: default. Block forms render under the admin theme; the
   * setting is read from the default theme.
   */
  #[Hook('form_block_form_alter')]
  public function formBlockFormAlter(array &$form, FormStateInterface $form_state): void {
    if (!$this->blockTitleSettings->isEnabled()) {
      return;
    }
    $block = $form_state->getFormObject()?->getEntity();
    if (!$block instanceof BlockInterface) {
      return;
    }
    // If the block does NOT support 'label_display', do not alter the form.
    if (!isset($form['settings']['label_display'])) {
      return;
    }
    // Capture original label_display value and stored custom setting.
    $original = $form['settings']['label_display']['#default_value'] ?? NULL;
    $stored = $this->blockTitleSettings->get($block, 'solo_block_title_visibility');
    // Determine the default for dropdown.
    $default = $stored ?? match (TRUE) {
      $original === 'visible' || $original === TRUE => 'visible',
            $original === '0' || $original === FALSE => 'visually_hidden',
            $original === NULL => 'none',
            default => 'visible',
    };
    // Unset the core checkbox.
    unset($form['settings']['label_display']);
    $dropdown_visibility = [
      '#type' => 'select',
      '#title' => $this->t('Block title visibility mode'),
      '#options' => [
        'visible' => $this->t('Visible (always render)'),
        'visually_hidden' => $this->t('Visually hidden (screen readers only)'),
        'none' => $this->t('Do not render at all'),
      ],
      '#default_value' => $default,
      '#description' => $this->t('Controls how this block title is rendered in the HTML.'),
      '#parents' => [
        'settings',
        'solo_block_title_visibility',
      ],
    ];
    $heading_level = $this->blockTitleSettings->get($block, 'solo_block_title_tag') ?? 'h2';
    $dropdown_tag = [
      '#type' => 'select',
      '#title' => $this->t('Block title tag'),
      '#options' => [
        'h1' => $this->t('H1 (⚠ Avoid unless this is the main page heading)'),
        'h2' => $this->t('H2'),
        'h3' => $this->t('H3'),
        'h4' => $this->t('H4'),
        'h5' => $this->t('H5'),
        'h6' => $this->t('H6'),
        'div' => $this->t('Div (non-semantic wrapper)'),
      ],
      '#default_value' => $heading_level,
      '#description' => $this->t('Choose an HTML wrapper for the block title. Use H2–H6 for headings. Use "Div" if you do not want to affect heading structure. Avoid H1 unless this is the primary page title.'),
      '#parents' => [
        'settings',
        'solo_block_title_tag',
      ],
      '#states' => [
        'visible' => [
          ':input[name="settings[solo_block_title_visibility]"]' => [
            '!value' => 'none',
          ],
        ],
      ],
    ];
    // Insert both fields after 'label'.
    $new_settings = [];
    foreach ($form['settings'] as $key => $field) {
      $new_settings[$key] = $field;
      if ($key === 'label') {
        $new_settings['solo_block_title_visibility'] = $dropdown_visibility;
        $new_settings['solo_block_title_tag'] = $dropdown_tag;
      }
    }
    $form['settings'] = $new_settings;
    // Attach entity builder.
    $form['#entity_builders'][] = '_solo_utilities_block_form_entity_builder';
  }

  /**
   * Implements hook_preprocess_block().
   *
   * Theme scope: default. Kept as before: the flag is read from the default
   * theme. Candidate for active; owner decision.
   */
  #[Hook('preprocess_block')]
  public function preprocessBlock(array &$variables): void {
    $variables['solo_block_title_visibility_enabled'] = $this->blockTitleSettings->isEnabled();
    if (!$variables['solo_block_title_visibility_enabled']) {
      return;
    }
    $id = $variables['elements']['#id'] ?? NULL;
    if (!$id) {
      return;
    }
    /** @var \Drupal\block\BlockInterface|null $block */
    $block = $this->entityTypeManager->getStorage('block')->load($id);
    if (!$block instanceof BlockInterface) {
      return;
    }
    $settings = $block->get('settings') ?? [];
    // Resolve final visibility mode.
    $custom_mode = $this->blockTitleSettings->get($block, 'solo_block_title_visibility');
    $legacy = $settings['label_display'] ?? NULL;
    $mode = $custom_mode ?? match (TRUE) {
      $legacy === 'visually_hidden', $legacy === '0', $legacy === FALSE, $legacy === NULL => 'visually_hidden',
            $legacy === '1', $legacy === TRUE => 'visible',
            default => 'visible',
    };
    // Store for use in Twig.
    $variables['configuration']['solo_block_title_visibility'] = $mode;
    // Ensure title_attributes is always safe.
    if (!isset($variables['title_attributes']) || !$variables['title_attributes'] instanceof Attribute) {
      $variables['title_attributes'] = new Attribute($variables['title_attributes'] ?? []);
    }
    // Ensure label is available if missing.
    if (empty($variables['label']) && empty($variables['configuration']['label'])) {
      $label = $variables['elements']['#configuration']['label'] ?? NULL;
      if (!$label && !empty($settings['label'])) {
        $label = $settings['label'];
      }
      // Clean fallback for plugin ID.
      if (!$label && !empty($variables['plugin_id'])) {
        if (str_starts_with($variables['plugin_id'], 'views_block:')) {
          // Extract view and display ID: views_block:view_id-display_id.
          $plugin_parts = explode(':', $variables['plugin_id']);
          $view_parts = explode('-', $plugin_parts[1] ?? '');
          if (count($view_parts) >= 2) {
            [$view_id, $display_id] = $view_parts;
            $label = ucwords(str_replace('_', ' ', $view_id)) . ' - ' . ucwords(str_replace('_', ' ', $display_id));
          }
        }
        else {
          // Fallback for other plugin types.
          $label = ucwords(str_replace([
            '_',
            ':',
          ], [
            ' ',
            ' ',
          ], $variables['plugin_id']));
        }
      }
      $variables['label'] = $label;
      $variables['configuration']['label'] = $label;
    }
    // Apply final visibility behavior.
    switch ($mode) {
      case 'none':
        unset($variables['label']);
        break;

      case 'visually_hidden':
        $variables['title_attributes']->addClass('visually-hidden');
        break;

      case 'visible':
      default:
        // No change.
        break;
    }
    $allowed_tags = [
      'div',
      'h1',
      'h2',
      'h3',
      'h4',
      'h5',
      'h6',
    ];
    $tag = $this->blockTitleSettings->get($block, 'solo_block_title_tag') ?? 'h2';
    $tag = in_array($tag, $allowed_tags, TRUE) ? $tag : 'h2';
    $variables['configuration']['solo_block_title_tag'] = $tag;
  }

}
