<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Form\ThemeSettings\Section;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element\Radios;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\solo_utilities\Form\SettingsOptions;
use Drupal\solo_utilities\Form\ThemeSettings\SettingsFormContext;
use Drupal\solo_utilities\Theme\SoloThemeDetection;

/**
 * The Predefined Color Schemes section.
 *
 * Labels, descriptions and order are those of Solo 1.x.
 *
 * @internal
 */
final class ColorSchemesSection implements SettingsSectionInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly SettingsOptions $options,
    private readonly SoloThemeDetection $themeDetection,
    private readonly ExtensionPathResolver $pathResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function build(array &$form, SettingsFormContext $context): void {
    $settings = $context->settings;
    $form["solo_settings"]["settings_predefined_theme"] = [
      "#type" => "details",
      "#title" => $this->t("Predefined Color Schemes"),
      "#description" => $this->themeDetection->isDefault()
        ? $this->t("<p>Apply color schemes based on site elements such as content types, taxonomies, user roles and specific URLs.</p><p class='solo-links'><a href='/admin/config/system/solo_utilities'>Manage Color Schemes Rules</a> <a target='_blank' rel='noopener' href='https://unitedstarsofamerica.com/color-schemes'>Demo site: examples of all color schemes</a></p>")
        : $this->t("<p>Solo comes with 50 color schemes. Apply one to parts of your website such as content types, taxonomies, user roles and specific URLs. Requires the <a target='_blank' rel='noopener' href='https://www.drupal.org/project/solo_utilities'>Solo Utilities</a> module.</p><div class='solo-note solo-note--warning'><strong>Requires an active theme.</strong> Solo or one of its sub-themes must be part of the active theme hierarchy; installing them is not enough. Solo must be the default theme, or a parent, grandparent or any other ancestor of the current default theme.</div><p class='solo-links'><a target='_blank' rel='noopener' href='https://unitedstarsofamerica.com/color-schemes'>Demo site: examples of all color schemes</a></p>"),
    ];

    // Create vertical tabs container.
    $form["solo_settings"]["settings_predefined_theme"]["predefined_theme_tabs"] = [
      "#type" => "vertical_tabs",
      "#default_tab" => "edit-color-scheme-selection",
    ];

    // Color Scheme Selection Tab.
    $form["solo_settings"]["settings_predefined_theme"]["color_scheme_selection"] = [
      "#type" => "details",
      "#title" => $this->t("Color Scheme Selection"),
      "#group" => "predefined_theme_tabs",
      "#weight" => -10,
      "#id" => "edit-color-scheme-selection",
    ];

    // The chosen scheme decides the category; the validator stores it from there.
    $form["solo_settings"]["settings_predefined_theme"]["color_scheme_selection"]["color_scheme__category"] = [
      "#parents" => ['color_scheme', 'category'],
      "#type" => "value",
      "#value" => $settings->get('color_scheme.category'),
    ];

    // Server-rendered radios: selection needs no script, and arrow keys, screen
    // readers and forced colors come with the native input. The script only adds
    // the tabs and the selected name.
    $categories = $this->options->themeCategory();
    $schemes = ['none' => $categories['none']] + $this->options->predefinedTheme();
    $category = $settings->string('color_scheme.category');
    $name = $settings->string('color_scheme.name');
    $selected = $category === 'none' || $name === '' ? 'none' : $this->options->schemeOptionKey($category, $name);
    $picker = [
      "#parents" => ['color_scheme', 'name'],
      "#id" => "edit-predefined-current-theme",
      "#type" => "radios",
      "#title" => $this->t("Choose the desired color scheme"),
      "#options" => $schemes,
      "#default_value" => $selected,
      "#process" => [[Radios::class, 'processRadios'], [self::class, 'arrangeThumbnails']],
      "#solo_scheme_categories" => $categories,
      "#prefix" => '<div class="solo-schemes">',
      "#suffix" => '</div>',
    ];
    foreach ($schemes as $value => $label) {
      $picker[$value]['#title'] = $this->thumbnail((string) $value, (string) $label);
    }
    $form["solo_settings"]["settings_predefined_theme"]["color_scheme_selection"]["color_scheme__name"] = $picker;
  }

  /**
   * The label of one scheme: its image, and its name for the tag and for a11y.
   *
   * @param string $value
   *   The option value, "category|name" or "none".
   * @param string $label
   *   The translated name.
   *
   * @return \Drupal\Component\Render\MarkupInterface
   *   The markup of the label. The image is decorative: the name labels the input.
   */
  private function thumbnail(string $value, string $label): MarkupInterface {
    $file = $value === 'none' ? 'none' : (explode('|', $value, 2)[1] ?? $value);
    $src = base_path() . $this->pathResolver->getPath('theme', 'solo') . '/images/schemes/' . $file . '.webp';
    return Markup::create('<img class="solo-schemes__image" src="' . Html::escape($src) . '" alt="" width="120" height="160" loading="lazy" decoding="async"><span class="solo-schemes__name">' . Html::escape($label) . '</span>');
  }

  /**
   * Places each radio in a titled grid of its category.
   *
   * This is the shape without script as well: one heading and grid per category.
   * The script turns each group into a tab panel.
   *
   * @param array<string, mixed> $element
   *   The radios element, after core made one radio per option.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array<string, mixed> $complete_form
   *   The form.
   *
   * @return array<string, mixed>
   *   The element.
   */
  public static function arrangeThumbnails(array $element, FormStateInterface $form_state, array &$complete_form): array {
    foreach ($element['#solo_scheme_categories'] as $category => $title) {
      $group = [
        '#type' => 'container',
        '#attributes' => ['class' => ['solo-schemes__group']],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'h4',
          '#value' => $title,
          '#attributes' => ['class' => ['solo-schemes__group-title']],
        ],
        'grid' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['solo-schemes__grid']],
        ],
      ];
      foreach (array_keys($element['#options']) as $value) {
        if ($value === $category || str_starts_with((string) $value, $category . '|')) {
          $group['grid'][$value] = $element[$value];
          unset($element[$value]);
        }
      }
      $element['group_' . $category] = $group;
    }
    return $element;
  }

}
