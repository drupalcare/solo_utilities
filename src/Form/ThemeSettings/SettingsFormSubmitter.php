<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Form\ThemeSettings;

use Drupal\Component\Utility\Html;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Template\TwigEnvironment;
use Drupal\Core\Theme\Registry;
use Drupal\file\FileInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\solo_utilities\Form\SettingsOptions;
use Drupal\solo_utilities\Settings\SettingsCatalog;
use Drupal\solo_utilities\Theme\SoloThemeDetection;

/**
 * Finishes saving the theme settings form, after core's own save.
 *
 * FormBuilder appends core's submit before any alter runs, and this handler is
 * added in the alter, so everything here is the last word on the stored values.
 * Generated CSS and JS are not here: ThemeSettingsSaveSubscriber regenerates
 * them on every config save.
 *
 * Referenced from the form as an object callable, so it survives a cached form:
 * the services stay protected and not readonly for
 * DependencySerializationTrait.
 *
 * @internal
 */
final class SettingsFormSubmitter {

  use DependencySerializationTrait;

  /**
   * The popup login keys that go back to their defaults when it is turned off.
   */
  private const POPUP_LOGIN_KEYS = [
    'link_text',
    'use_inline_styles',
    'animation_duration',
    'close_on_escape',
    'close_on_outside_click',
    'focus_trap',
    'announce_to_screen_readers',
    'return_focus_on_close',
    'custom_triggers',
    'z_index',
    'overlay_opacity',
  ];

  /**
   * Constructs the submit handler.
   *
   * @param \Drupal\solo_utilities\Theme\SoloThemeDetection $themeDetection
   *   Names the edited theme.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Gives the editable settings object.
   * @param \Drupal\solo_utilities\Settings\SettingsCatalog $catalog
   *   Holds the region groups and the feature defaults.
   * @param \Drupal\solo_utilities\Form\SettingsOptions $options
   *   Lists content types and menus.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entityRepository
   *   Loads files by UUID.
   * @param \Drupal\Core\Theme\Registry $themeRegistry
   *   Reset after a save.
   * @param \Drupal\Core\Template\TwigEnvironment $twig
   *   Invalidated after a save.
   * @param \Drupal\file\FileUsage\FileUsageInterface|null $fileUsage
   *   File usage, absent when the File module is not installed.
   */
  public function __construct(
    protected SoloThemeDetection $themeDetection,
    protected ConfigFactoryInterface $configFactory,
    protected SettingsCatalog $catalog,
    protected SettingsOptions $options,
    protected EntityRepositoryInterface $entityRepository,
    protected Registry $themeRegistry,
    protected TwigEnvironment $twig,
    protected ?FileUsageInterface $fileUsage = NULL,
  ) {}

  /**
   * Applies the rules core's plain save cannot.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Its state.
   */
  public function submit(array &$form, FormStateInterface $form_state): void {
    $theme = $this->themeDetection->editedThemeName($form_state);
    $name = $theme . '.settings';
    $config = $this->configFactory->getEditable($name);

    $this->cleanWidths($config, $form_state);
    $this->cleanLayouts($config, $form_state);
    $this->cleanPopupLogin($config, $form_state);
    $this->cleanMenuTemplates($config, $form_state);
    $this->saveFeature($config, 'preloader', $form_state);
    $this->saveFeature($config, 'back_to_top', $form_state);
    $this->cleanSearch($config, $form_state);
    $this->updateFileUsage($form_state, $theme);

    $config->save();
    $this->configFactory->reset($name);

    $this->themeRegistry->reset();
    $this->twig->invalidate();
  }

  /**
   * Keeps a per-type width only while the option is on and a width is chosen.
   */
  private function cleanWidths(Config $config, FormStateInterface $form_state): void {
    $enabled = (bool) $form_state->getValue(['layout', 'width', 'per_content_type_enabled'], FALSE);
    foreach (array_keys($this->options->contentTypes()) as $type) {
      $path = ['layout', 'width', 'per_content_type', $type];
      $value = $form_state->getValue($path);
      if ($enabled && !empty($value)) {
        $config->set(implode('.', $path), $value);
      }
      else {
        $config->clear(implode('.', $path));
      }
    }
  }

  /**
   * Keeps a per-type layout only where it differs from its group's own.
   */
  private function cleanLayouts(Config $config, FormStateInterface $form_state): void {
    foreach ($this->catalog->regionGroups() as $group) {
      $enabled = (bool) $form_state->getValue(['region_groups', $group, 'per_content_type_enabled'], FALSE);
      $config->set('region_groups.' . $group . '.per_content_type_enabled', $enabled);
      foreach (array_keys($this->options->contentTypes()) as $type) {
        foreach ([2, 3, 4] as $columns) {
          $path = 'region_groups.' . $group . '.per_content_type.columns_' . $columns . '.' . $type;
          $value = $enabled ? $form_state->getValue(explode('.', $path)) : NULL;
          $global = $form_state->getValue(['region_groups', $group, 'columns_' . $columns]);
          if ($value !== NULL && $value !== $global) {
            $config->set($path, $value);
          }
          else {
            $config->clear($path);
          }
        }
      }
    }
  }

  /**
   * Returns the popup login settings to their defaults when it is turned off.
   */
  private function cleanPopupLogin(Config $config, FormStateInterface $form_state): void {
    if ($form_state->getValue(['popup_login', 'enabled'])) {
      return;
    }
    foreach (self::POPUP_LOGIN_KEYS as $key) {
      $config->clear('popup_login.' . $key);
    }
  }

  /**
   * Stores one row per menu that has a template chosen.
   */
  private function cleanMenuTemplates(Config $config, FormStateInterface $form_state): void {
    $menus = $this->options->menus();
    $rows = $form_state->getValue(['menus', 'template_assignments']);
    $cleaned = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
      if (is_array($row) && !empty($row['menu_id']) && !empty($row['template'])) {
        $cleaned[$row['menu_id']] = [
          'menu_id' => $row['menu_id'],
          'menu_name' => (string) ($menus[$row['menu_id']] ?? $row['menu_id']),
          'template' => $row['template'],
        ];
      }
    }
    $config->set('menus.template_assignments', $cleaned);
  }

  /**
   * Saves a feature: on, its values cast to their schema types; off, defaults.
   */
  private function saveFeature(Config $config, string $feature, FormStateInterface $form_state): void {
    $defaults = $this->catalog->defaultsFor($feature);
    $config->set($feature, empty($form_state->getValue([$feature, 'enabled']))
      ? $defaults
      : $this->castToDefaults($defaults, (array) $form_state->getValue([$feature])));
  }

  /**
   * Gives every default key its submitted value, in the default's type.
   *
   * @param array<string, mixed> $defaults
   *   The default values.
   * @param array<string, mixed> $values
   *   The submitted values.
   *
   * @return array<string, mixed>
   *   One value for each default key.
   */
  private function castToDefaults(array $defaults, array $values): array {
    $result = [];
    foreach ($defaults as $key => $default) {
      if (is_array($default)) {
        $result[$key] = $this->castToDefaults($default, (array) ($values[$key] ?? []));
        continue;
      }
      $value = $values[$key] ?? $default;
      if (is_bool($default)) {
        $value = (bool) $value;
      }
      elseif (is_int($default)) {
        $value = (int) $value;
      }
      $result[$key] = $value;
    }
    return $result;
  }

  /**
   * Empties the searched content types when their control is off.
   */
  private function cleanSearch(Config $config, FormStateInterface $form_state): void {
    $filters = $form_state->getValue(['search', 'disabled_filters']);
    if (is_array($filters) && empty($filters['disable_types'])) {
      $config->set('search.result_content_types', []);
    }
  }

  /**
   * Tracks the files embedded in the formatted copyright text.
   *
   * Newly referenced files become permanent and gain a usage; files no longer
   * referenced lose theirs. Compared with what validate kept, as core has
   * already saved the new text by now.
   */
  private function updateFileUsage(FormStateInterface $form_state, string $theme): void {
    if ($this->fileUsage === NULL) {
      return;
    }
    $new = $this->embeddedFiles($form_state->getValue(['footer', 'copyright_formatted']));
    $old = $this->embeddedFiles($form_state->get(SettingsFormValidator::PREVIOUS_COPYRIGHT));

    foreach (array_diff($new, $old) as $uuid) {
      $file = $this->entityRepository->loadEntityByUuid('file', $uuid);
      if ($file instanceof FileInterface) {
        if ($file->isTemporary()) {
          $file->setPermanent();
          $file->save();
        }
        $this->fileUsage->add($file, $theme, 'theme', $theme);
      }
    }
    foreach (array_diff($old, $new) as $uuid) {
      $file = $this->entityRepository->loadEntityByUuid('file', $uuid);
      if ($file instanceof FileInterface) {
        $this->fileUsage->delete($file, $theme, 'theme', $theme);
      }
    }
  }

  /**
   * Lists the UUIDs of files embedded in a text_format value.
   *
   * @return list<string>
   *   File UUIDs.
   */
  private function embeddedFiles(mixed $formatted): array {
    $text = is_array($formatted) ? ($formatted['value'] ?? '') : '';
    if (!is_string($text) || $text === '') {
      return [];
    }
    $xpath = new \DOMXPath(Html::load($text));
    $uuids = [];
    foreach ($xpath->query('//*[@data-entity-type="file" and @data-entity-uuid]') ?: [] as $node) {
      if ($node instanceof \DOMElement) {
        $uuids[] = $node->getAttribute('data-entity-uuid');
      }
    }
    return $uuids;
  }

}
