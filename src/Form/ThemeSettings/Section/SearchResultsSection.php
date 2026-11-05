<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Form\ThemeSettings\Section;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\solo_utilities\Form\SettingsOptions;
use Drupal\solo_utilities\Form\ThemeSettings\SettingsFormContext;

/**
 * The Search Results section; a short note when the Search module is off.
 *
 * Labels, descriptions and order are those of Solo 1.x.
 *
 * @internal
 */
final class SearchResultsSection implements SettingsSectionInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly SettingsOptions $options,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function build(array &$form, SettingsFormContext $context): void {
    $settings = $context->settings;
    // Check if the Search module is enabled.
    if ($this->moduleHandler->moduleExists("search")) {

      $form["solo_settings"]["settings_search"] = [
        "#type" => "details",
        "#title" => $this->t("<strong class='purple'>Search Results</strong> Settings - Solo Theme"),
      ];

      // Create vertical tabs container.
      $form["solo_settings"]["settings_search"]["search_tabs"] = [
        "#type" => "vertical_tabs",
        "#default_tab" => "edit-search-filters",
      ];

      // Search Filters Tab.
      $form["solo_settings"]["settings_search"]["search_filters"] = [
        "#type" => "details",
        "#title" => $this->t("Search Filters"),
        "#group" => "search_tabs",
        "#weight" => -10,
        "#id" => "edit-search-filters",
      ];

      // Add checkboxes to enable/disable search filters.
      $form["solo_settings"]["settings_search"]["search_filters"]["search__disabled_filters"] = [
        "#parents" => ['search', 'disabled_filters'],
        "#type" => "checkboxes",
        "#title" => $this->t("Disable advanced search filters"),
        "#description" => $this->t("Choose the filters to disable in the advanced search form. If all filters are disabled, the 'Advanced' section will be hidden. By default, all filters are enabled."),
        "#options" => [
          "disable_language" => $this->t("Disable Language filter"),
          "disable_keywords" => $this->t("Disable Keywords filter"),
          "disable_types" => $this->t('Enable “Limit by content type” control'),
        ],
        "#default_value" => $settings->get('search.disabled_filters'),
      ];

      // Display Options Tab.
      $form["solo_settings"]["settings_search"]["display_options"] = [
        "#type" => "details",
        "#title" => $this->t("Display Options"),
        "#group" => "search_tabs",
        "#weight" => -8,
      ];

      // Checkbox for enabling teaser mode.
      $form["solo_settings"]["settings_search"]["display_options"]["search__teaser_mode"] = [
        "#parents" => ['search', 'teaser_mode'],
        "#type" => "checkbox",
        "#title" => $this->t("Use Teaser Mode for Search Results"),
        "#default_value" => $settings->get('search.teaser_mode'),
        "#description" => $this->t("Enable this option to display search results using the teaser view mode."),
      ];

      // Content Types Tab.
      $form["solo_settings"]["settings_search"]["content_types"] = [
        "#type" => "details",
        "#title" => $this->t("Content Types"),
        "#group" => "search_tabs",
        "#weight" => -6,
      ];

      // List of content types for filtering search results conditional visibility.
      $form["solo_settings"]["settings_search"]["content_types"]["search__result_content_types"] = [
        "#parents" => ['search', 'result_content_types'],
        "#type" => "checkboxes",
        "#title" => $this->t("Content Types to Display in Search Results"),
        "#options" => $this->options->contentTypes(),
        "#default_value" => $settings->get('search.result_content_types'),
        "#description" => $this->t("Allow site builder to select which content types can be searched across the site. The chosen content types will apply to both the search form on the page and the Drupal Core Search Blocks."),
        "#states" => [
          // Show this field only if "Disable Types" is selected.
          "visible" => [
            ":input[name='search[disabled_filters][disable_types]']" => ["checked" => TRUE],
          ],
        ],
      ];
    }
    else {
      // Drupal 12 core has no Search module; it is a contributed project
      // there. Nothing is posted, so the stored search.* values are kept. The
      // note is the description, so the builder adds no section hint.
      $form["solo_settings"]["settings_search"] = [
        "#type" => "details",
        "#title" => $this->t("<strong class='purple'>Search Results</strong> Settings - Solo Theme"),
        "#description" => $this->t("Install the Search module to use these settings."),
      ];
    }
  }

}
