<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Form\ThemeSettings\Section;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\solo_utilities\Form\ThemeSettings\SettingsFormContext;

/**
 * The settings navigation: every section, grouped, in the left column.
 *
 * It holds no setting. SettingsFormBuilder fills its groups once every section
 * exists, so a region without blocks is listed as text instead of a link. It
 * fills the icons too; the two templates hand them to the scripts, which build
 * the Other group and the color fields' clear buttons.
 *
 * @internal
 */
final class BlueprintSection implements SettingsSectionInterface {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function build(array &$form, SettingsFormContext $context): void {
    $form["solo_settings"]["settings_blueprint"] = [
      "#type" => "inline_template",
      "#template" => <<<'TWIG'
<nav class="solo-nav" aria-label="{{ nav_label }}">
  <details class="solo-nav__menu" open>
    <summary class="solo-nav__toggle">{{ icons.sections }}{{ nav_label }}<span class="solo-nav__current"></span></summary>
    {% for group in groups %}
      <h2 class="solo-nav__group">{{ group.icon }}{{ group.title }}</h2>
      <ul class="solo-nav__list">
        {% for row in group.rows %}
          <li class="solo-nav__item">
            {% if row.target %}
              <a class="solo-nav__link" href="#{{ row.target }}">
                {{ row.icon }}
                <span class="solo-nav__name">{{ row.label }}</span>
                {% if row.detail %}<small class="solo-nav__detail">{{ row.detail }}</small>{% endif %}
              </a>
            {% else %}
              <span class="solo-nav__text">
                {{ row.icon }}
                <span class="solo-nav__name">{{ row.label }}</span>
                <small class="solo-nav__detail">{{ no_blocks }}</small>
              </span>
            {% endif %}
            {% if row.regions %}
              <ul class="solo-nav__regions">
                {% for region in row.regions %}
                  <li>{{ region.label }}{% if region.empty %} <small class="solo-nav__detail">{{ no_blocks }}</small>{% endif %}</li>
                {% endfor %}
              </ul>
            {% endif %}
          </li>
        {% endfor %}
      </ul>
    {% endfor %}
    <p class="solo-nav__note"><a href="{{ block_layout_url }}">{{ icons.block_layout }}{{ block_layout }}</a></p>
    <template data-solo-icon="other">{{ icons.other }}</template>
    <template data-solo-icon="close">{{ icons.close }}</template>
  </details>
</nav>
TWIG,
      "#context" => [
        "nav_label" => $this->t("Sections"),
        "no_blocks" => $this->t("No blocks"),
        "block_layout" => $this->t("Block layout"),
        "block_layout_url" => Url::fromRoute("block.admin_display_theme", ["theme" => $context->theme])->toString(),
        "groups" => [],
        "icons" => [],
      ],
    ];
  }

}
