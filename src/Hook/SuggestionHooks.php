<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;
use Drupal\solo_utilities\Feature\Suggestions;
use Drupal\solo_utilities\Settings\SettingsReader;
use Drupal\solo_utilities\Theme\SoloThemeDetection;

/**
 * Routes theme suggestion hooks to the feature that owns them.
 *
 * Order::Last keeps Solo after other modules, where its theme hooks ran in 1.x.
 *
 * @internal
 */
final class SuggestionHooks {

  public function __construct(
    private readonly SoloThemeDetection $detection,
    private readonly SettingsReader $settingsReader,
    private readonly Suggestions $suggestions,
  ) {}

  /**
   * Implements hook_theme_suggestions_HOOK_alter() for form.
   *
   * @param list<string> $suggestions
   *   The suggestions.
   * @param array<string, mixed> $variables
   *   The template variables.
   */
  #[Hook('theme_suggestions_form_alter', order: Order::Last)]
  public function form(array &$suggestions, array $variables): void {
    if ($this->detection->isActive()) {
      $this->suggestions->form($suggestions, $variables);
    }
  }

  /**
   * Implements hook_theme_suggestions_HOOK_alter() for block.
   *
   * @param list<string> $suggestions
   *   The suggestions.
   * @param array<string, mixed> $variables
   *   The template variables.
   */
  #[Hook('theme_suggestions_block_alter', order: Order::Last)]
  public function block(array &$suggestions, array $variables): void {
    if ($this->detection->isActive()) {
      $this->suggestions->block($suggestions, $variables);
    }
  }

  /**
   * Implements hook_theme_suggestions_HOOK_alter() for menu.
   *
   * @param list<string> $suggestions
   *   The suggestions.
   * @param array<string, mixed> $variables
   *   The template variables.
   */
  #[Hook('theme_suggestions_menu_alter', order: Order::Last)]
  public function menu(array &$suggestions, array $variables): void {
    if ($this->detection->isActive()) {
      $this->suggestions->menu($suggestions, $variables, $this->settingsReader->forTheme($this->detection->activeThemeName()));
    }
  }

  /**
   * Implements hook_theme_suggestions_HOOK_alter() for page.
   *
   * @param list<string> $suggestions
   *   The suggestions.
   */
  #[Hook('theme_suggestions_page_alter', order: Order::Last)]
  public function page(array &$suggestions): void {
    if ($this->detection->isActive()) {
      $this->suggestions->page($suggestions);
    }
  }

  /**
   * Implements hook_theme_suggestions_HOOK_alter() for user.
   *
   * @param list<string> $suggestions
   *   The suggestions.
   * @param array<string, mixed> $variables
   *   The template variables.
   */
  #[Hook('theme_suggestions_user_alter', order: Order::Last)]
  public function user(array &$suggestions, array $variables): void {
    if ($this->detection->isActive()) {
      $this->suggestions->user($suggestions, $variables);
    }
  }

  /**
   * Implements hook_theme_suggestions_HOOK_alter() for node_edit_form.
   *
   * @param list<string> $suggestions
   *   The suggestions.
   * @param array<string, mixed> $variables
   *   The template variables.
   */
  #[Hook('theme_suggestions_node_edit_form_alter', order: Order::Last)]
  public function nodeEditForm(array &$suggestions, array $variables): void {
    if ($this->detection->isActive()) {
      $this->suggestions->nodeEditForm($suggestions, $variables);
    }
  }

}
