<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Form\ThemeSettings\Section;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\solo_utilities\Form\SettingsOptions;
use Drupal\solo_utilities\Form\ThemeSettings\SettingsFormContext;

/**
 * The Libraries and Fonts section.
 *
 * Labels, descriptions and order are those of Solo 1.x.
 *
 * @internal
 */
final class LibrariesFontsSection implements SettingsSectionInterface {

  use StringTranslationTrait;

  /**
   * The vertical tabs of the CSS classes section.
   */
  private const CLASSES_TABS = 'css_classes_tabs';

  /**
   * The color names of each family of classes.
   */
  private const COLORS = [
    // These are W3.CSS class suffixes, so they keep W3.CSS spelling, which the
    // spell checker flags in one word.
    // cspell:disable
    'base' => [
      'red',
      'pink',
      'purple',
      'deep-purple',
      'indigo',
      'blue',
      'light-blue',
      'cyan',
      'aqua',
      'teal',
      'green',
      'light-green',
      'lime',
      'sand',
      'khaki',
      'yellow',
      'amber',
      'orange',
      'deep-orange',
      'blue-grey',
      'brown',
      'light-grey',
      'grey',
      'dark-grey',
      'black',
      'white',
      'pale-red',
      'pale-yellow',
      'pale-green',
      'pale-blue',
    ],
    'link' => [
      'red',
      'green',
      'blue',
      'yellow',
      'white',
      'black',
      'grey',
      'amber',
      'aqua',
      'light-blue',
      'brown',
      'cyan',
      'blue-grey',
      'blue-gray',
      'light-green',
      'indigo',
      'khaki',
      'lime',
      'orange',
      'deep-orange',
      'pink',
      'purple',
      'deep-purple',
      'sand',
      'teal',
      'light-gray',
      'light-grey',
      'dark-gray',
      'dark-grey',
      'pale-red',
      'pale-yellow',
      'pale-green',
      'pale-blue',
    ],
    'button' => [
      'red',
      'green',
      'blue',
      'yellow',
      'white',
      'black',
      'gray',
      'grey',
      'amber',
      'aqua',
      'light-blue',
      'brown',
      'cyan',
      'blue-grey',
      'blue-gray',
      'light-green',
      'indigo',
      'khaki',
      'lime',
      'orange',
      'deep-orange',
      'pink',
      'purple',
      'deep-purple',
      'sand',
      'teal',
      'light-gray',
      'light-grey',
      'dark-gray',
      'dark-grey',
      'pale-red',
      'pale-yellow',
      'pale-green',
      'pale-blue',
    ],
    // cspell:enable
  ];

  /**
   * The kinds of class: prefix, color family and live demo.
   */
  private const CLASS_KINDS = [
    'background' => [
      'prefix' => 'w3-',
      'colors' => 'base',
      'demo' => 'https://w3css.flashwebcenter.com/node/169',
    ],
    'background_hover' => [
      'prefix' => 'w3-hover-',
      'colors' => 'base',
      'demo' => 'https://w3css.flashwebcenter.com/node/168',
    ],
    'text' => [
      'prefix' => 'w3-text-',
      'colors' => 'base',
      'demo' => 'https://w3css.flashwebcenter.com/node/124',
    ],
    'text_hover' => [
      'prefix' => 'w3-hover-text-',
      'colors' => 'base',
      'demo' => 'https://w3css.flashwebcenter.com/node/143',
    ],
    'link_text' => [
      'prefix' => 'w3-link-',
      'colors' => 'base',
      'demo' => 'https://w3css.flashwebcenter.com/node/135',
    ],
    'link_background' => [
      'prefix' => 'w3-link-bg-',
      'colors' => 'link',
      'demo' => 'https://w3css.flashwebcenter.com/node/137',
    ],
    'link_background_hover' => [
      'prefix' => 'w3-link-hover-',
      'colors' => 'link',
      'demo' => 'https://w3css.flashwebcenter.com/node/136',
    ],
    'button' => [
      'prefix' => 'w3-button-',
      'colors' => 'button',
      'demo' => 'https://w3css.flashwebcenter.com/node/162',
    ],
    'button_hover' => [
      'prefix' => 'w3-button-hover-',
      'colors' => 'button',
      'demo' => 'https://w3css.flashwebcenter.com/node/161',
    ],
  ];

  /**
   * One pane of the CSS class reference.
   */
  private const CLASS_PANE_TEMPLATE = '<div class="solo-classes__kind"><strong>{{ name }}</strong> <code>{{ pattern }}</code> <span class="solo-classes__kind-count">{{ count }}</span> <a class="solo-classes__kind-demo" href="{{ demo }}" target="_blank" rel="noopener">{{ demo_label }}</a></div><div class="solo-classes__chips">{% for chip in chips %}<code class="solo-classes__swatch solo-classes__swatch--{{ chip }}">{{ chip }}</code>{% endfor %}</div>';

  public function __construct(
    private readonly SettingsOptions $options,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function build(array &$form, SettingsFormContext $context): void {
    $settings = $context->settings;
    $form["solo_settings"]["settings_libraries_fonts"] = [
      "#type" => "details",
      "#title" => $this->t("Libraries & fonts"),
    ];

    // Create vertical tabs container.
    $form["solo_settings"]["settings_libraries_fonts"]["libraries_fonts_tabs"] = [
      "#type" => "vertical_tabs",
      "#default_tab" => "edit-w3css-library",
    ];

    // W3.CSS Library Tab.
    $form["solo_settings"]["settings_libraries_fonts"]["w3css_library"] = [
      "#type" => "details",
      "#title" => $this->t("W3.CSS library"),
      "#group" => "libraries_fonts_tabs",
      "#weight" => -10,
      "#id" => "edit-w3css-library",
    ];

    $form["solo_settings"]["settings_libraries_fonts"]["w3css_library"]["typography__load_w3css"] = [
      "#parents" => ['typography', 'load_w3css'],
      "#type" => "checkbox",
      "#title" => $this->t("Load the W3.CSS library locally"),
      "#default_value" => $settings->get('typography.load_w3css'),
    ];

    // Font Libraries Tab.
    $form["solo_settings"]["settings_libraries_fonts"]["font_libraries"] = [
      "#type" => "details",
      "#title" => $this->t("Font libraries"),
      "#group" => "libraries_fonts_tabs",
      "#weight" => -8,
    ];

    $form["solo_settings"]["settings_libraries_fonts"]["font_libraries"]["typography__fonts__local"] = [
      "#parents" => ['typography', 'fonts', 'local'],
      "#type" => "checkbox",
      "#title" => $this->t("Load Google fonts locally (global and heading fonts only)"),
      "#description"   => $this->t("Any font you choose for the site, heading or special fonts has a CSS class you can apply to any HTML tag. <a target='_blank' rel='noopener' href='https://www.drupal.org/node/2866181#change-font'>Documentation on drupal.org</a>."),
      "#default_value" => $settings->get('typography.fonts.local'),
    ];

    $form["solo_settings"]["settings_libraries_fonts"]["font_libraries"]["typography__fonts__icon_library"] = [
      "#parents" => ['typography', 'fonts', 'icon_library'],
      "#type" => "select",
      "#title" => $this->t("Select Font Awesome library"),
      "#default_value" => $settings->get('typography.fonts.icon_library'),
      "#options" => [
        0 => $this->t("None"),
        1 => $this->t("Load Font Awesome 4.7 locally."),
        2 => $this->t("Load Font Awesome 4.7 from the CDN."),
        3 => $this->t("Load Font Awesome 5.15.4 from the CDN."),
        4 => $this->t("Load Font Awesome 6.5.1 from the CDN."),
      ],
    ];

    // Global Site Fonts Tab.
    $form["solo_settings"]["settings_libraries_fonts"]["global_fonts"] = [
      "#type" => "details",
      "#title" => $this->t("Global site fonts"),
      "#group" => "libraries_fonts_tabs",
      "#weight" => -6,
    ];

    $form["solo_settings"]["settings_libraries_fonts"]["global_fonts"]["typography__fonts__global"] = [
      "#parents" => ['typography', 'fonts', 'global'],
      "#type" => "select",
      "#title" => $this->t("Global site fonts"),
      "#description"   => $this->t("The site font, applied to the whole website. Each of the 50 fonts has a CSS class you can apply to any HTML tag; Open Sans, for example, uses <code>open-sans</code>. <a target='_blank' rel='noopener' href='https://unitedstarsofamerica.com/node/192'>Live demo of all available fonts</a>."),
      "#options" => $this->options->globalFont(),
      "#default_value" => $settings->get('typography.fonts.global'),
    ];

    // Heading Fonts Tab.
    $form["solo_settings"]["settings_libraries_fonts"]["heading_fonts"] = [
      "#type" => "details",
      "#title" => $this->t("Heading fonts"),
      "#group" => "libraries_fonts_tabs",
      "#weight" => -4,
    ];

    $form["solo_settings"]["settings_libraries_fonts"]["heading_fonts"]["typography__fonts__heading"] = [
      "#parents" => ['typography', 'fonts', 'heading'],
      "#type" => "select",
      "#title" => $this->t("Heading fonts"),
      "#description"   => $this->t("The heading font, applied to h1, h2 and h3 only. Each of the 26 fonts has a CSS class you can apply to any h1, h2 or h3 tag; Open Sans, for example, uses <code>open-sans</code>. <a target='_blank' rel='noopener' href='https://unitedstarsofamerica.com/node/193'>Live demo of all available fonts</a>."),
      "#options" => $this->options->headingFont(),
      "#default_value" => $settings->get('typography.fonts.heading'),
    ];

    // Special Fonts Tab.
    $form["solo_settings"]["settings_libraries_fonts"]["special_fonts"] = [
      "#type" => "details",
      "#title" => $this->t("Special fonts"),
      "#group" => "libraries_fonts_tabs",
      "#weight" => -2,
    ];

    $form["solo_settings"]["settings_libraries_fonts"]["special_fonts"]["typography__fonts__special"] = [
      "#parents" => ['typography', 'fonts', 'special'],
      "#type" => "select",
      "#title" => $this->t("Special fonts"),
      "#description"   => $this->t("Imported only: not applied to any HTML tag by default. Each of the 25 fonts has a CSS class you can apply to any HTML tag; Open Sans, for example, uses <code>open-sans</code>. <a target='_blank' rel='noopener' href='https://unitedstarsofamerica.com/node/191'>Live demo of all available fonts</a>."),
      "#options" => $this->options->specialFont(),
      "#default_value" => $settings->get('typography.fonts.special'),
    ];

    $form["solo_settings"]["settings_css_classes"] = $this->classReference();
  }

  /**
   * Builds the reference of CSS classes, one tab per kind of class.
   *
   * @return array<string, mixed>
   *   The section.
   */
  private function classReference(): array {
    $section = [
      '#type' => 'details',
      '#title' => $this->t('CSS classes'),
      '#description' => $this->t('To use these classes, the w3.css library must be active. Copy any class into a text field. Separate multiple classes with a space.'),
      self::CLASSES_TABS => ['#type' => 'vertical_tabs'],
    ];
    $labels = $this->classKindLabels();
    foreach (self::CLASS_KINDS as $kind => $definition) {
      $colors = self::COLORS[$definition['colors']];
      $section['css_classes_' . $kind] = [
        '#type' => 'details',
        '#title' => $labels[$kind]['tab'],
        '#group' => self::CLASSES_TABS,
        'list' => [
          '#type' => 'inline_template',
          '#template' => self::CLASS_PANE_TEMPLATE,
          '#context' => [
            'name' => $labels[$kind]['name'],
            'pattern' => $definition['prefix'] . '<color>',
            'count' => $this->formatPlural(count($colors), '1 class', '@count classes'),
            'demo' => $definition['demo'],
            'demo_label' => $this->t('Live demo'),
            'chips' => array_map(static fn (string $color): string => $definition['prefix'] . $color, $colors),
          ],
        ],
      ];
    }
    return $section;
  }

  /**
   * Returns the labels of each kind of class.
   *
   * @return array<string, array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>>
   *   The tab label and the full name, keyed by kind.
   */
  private function classKindLabels(): array {
    return [
      'background' => [
        'tab' => $this->t('Background'),
        'name' => $this->t('Background colors'),
      ],
      'background_hover' => [
        'tab' => $this->t('Background hover'),
        'name' => $this->t('Background hover colors'),
      ],
      'text' => [
        'tab' => $this->t('Text'),
        'name' => $this->t('Text colors'),
      ],
      'text_hover' => [
        'tab' => $this->t('Text hover'),
        'name' => $this->t('Text hover colors'),
      ],
      'link_text' => [
        'tab' => $this->t('Link text'),
        'name' => $this->t('Link text colors'),
      ],
      'link_background' => [
        'tab' => $this->t('Link background'),
        'name' => $this->t('Link background colors'),
      ],
      'link_background_hover' => [
        'tab' => $this->t('Link background hover'),
        'name' => $this->t('Link background hover colors'),
      ],
      'button' => [
        'tab' => $this->t('Button'),
        'name' => $this->t('Button colors'),
      ],
      'button_hover' => [
        'tab' => $this->t('Button hover'),
        'name' => $this->t('Button hover colors'),
      ],
    ];
  }

}
