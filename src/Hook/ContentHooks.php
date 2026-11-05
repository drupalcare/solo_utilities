<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;
use Drupal\solo_utilities\Feature\CommentPreprocessor;
use Drupal\solo_utilities\Feature\ContentPreprocessor;
use Drupal\solo_utilities\Feature\MenuTemplates;
use Drupal\solo_utilities\Feature\SearchResult;
use Drupal\solo_utilities\Settings\BoundThemeSettings;
use Drupal\solo_utilities\Settings\SettingsReader;
use Drupal\solo_utilities\Theme\SoloThemeDetectionInterface;

/**
 * Routes content and menu preprocess hooks to the features that own them.
 *
 * Order::Last keeps Solo after other modules, where its theme hooks ran in 1.x.
 *
 * @internal
 */
final class ContentHooks {

  public function __construct(
    private readonly SoloThemeDetectionInterface $detection,
    private readonly SettingsReader $settingsReader,
    private readonly ContentPreprocessor $content,
    private readonly CommentPreprocessor $comments,
    private readonly MenuTemplates $menus,
    private readonly SearchResult $search,
  ) {}

  /**
   * Implements hook_preprocess_HOOK() for node.html.twig.
   *
   * @param array<string, mixed> $variables
   *   The template variables.
   */
  #[Hook('preprocess_node', order: Order::Last)]
  public function preprocessNode(array &$variables): void {
    if ($this->detection->isActive()) {
      $this->content->preprocessNode($variables, $this->activeSettings());
    }
  }

  /**
   * Implements hook_preprocess_HOOK() for field.html.twig.
   *
   * @param array<string, mixed> $variables
   *   The template variables.
   */
  #[Hook('preprocess_field', order: Order::Last)]
  public function preprocessField(array &$variables): void {
    if ($this->detection->isActive()) {
      $this->content->preprocessField($variables);
    }
  }

  /**
   * Implements hook_preprocess_HOOK() for field--node--created.html.twig.
   *
   * @param array<string, mixed> $variables
   *   The template variables.
   */
  #[Hook('preprocess_field__node__created', order: Order::Last)]
  public function preprocessFieldNodeCreated(array &$variables): void {
    if ($this->detection->isActive()) {
      $this->content->preprocessFieldNodeCreated($variables);
    }
  }

  /**
   * Implements hook_preprocess_HOOK() for filter-caption.html.twig.
   *
   * @param array<string, mixed> $variables
   *   The template variables.
   */
  #[Hook('preprocess_filter_caption', order: Order::Last)]
  public function preprocessFilterCaption(array &$variables): void {
    if ($this->detection->isActive()) {
      $this->content->preprocessFilterCaption($variables);
    }
  }

  /**
   * Implements hook_preprocess_HOOK() for search-result.html.twig.
   *
   * @param array<string, mixed> $variables
   *   The template variables.
   */
  #[Hook('preprocess_search_result', order: Order::Last)]
  public function preprocessSearchResult(array &$variables): void {
    if ($this->detection->isActive()) {
      $this->search->preprocess($variables, $this->activeSettings());
    }
  }

  /**
   * Implements hook_preprocess_HOOK() for comment.html.twig.
   *
   * @param array<string, mixed> $variables
   *   The template variables.
   */
  #[Hook('preprocess_comment', order: Order::Last)]
  public function preprocessComment(array &$variables): void {
    if ($this->detection->isActive()) {
      $this->comments->preprocessComment($variables, $this->activeSettings());
    }
  }

  /**
   * Implements hook_preprocess_HOOK() for field--comment.html.twig.
   *
   * @param array<string, mixed> $variables
   *   The template variables.
   */
  #[Hook('preprocess_field__comment', order: Order::Last)]
  public function preprocessFieldComment(array &$variables): void {
    if ($this->detection->isActive()) {
      $this->comments->preprocessCommentField($variables, $this->activeSettings());
    }
  }

  /**
   * Implements hook_preprocess_HOOK() for menu.html.twig.
   *
   * @param array<string, mixed> $variables
   *   The template variables.
   */
  #[Hook('preprocess_menu', order: Order::Last)]
  public function preprocessMenu(array &$variables): void {
    if ($this->detection->isActive()) {
      $this->menus->preprocessMenu($variables, $this->activeSettings());
    }
  }

  /**
   * Implements hook_preprocess_HOOK() for menu--primary-menu.html.twig.
   *
   * @param array<string, mixed> $variables
   *   The template variables.
   */
  #[Hook('preprocess_menu__primary_menu', order: Order::Last)]
  public function preprocessMenuPrimaryMenu(array &$variables): void {
    if ($this->detection->isActive()) {
      $this->menus->preprocessPrimaryMenu($variables, $this->activeSettings());
    }
  }

  /**
   * Implements hook_preprocess_HOOK() for menu--primary-sidebar-menu.html.twig.
   *
   * @param array<string, mixed> $variables
   *   The template variables.
   */
  #[Hook('preprocess_menu__primary_sidebar_menu', order: Order::Last)]
  public function preprocessMenuPrimarySidebarMenu(array &$variables): void {
    if ($this->detection->isActive()) {
      $this->menus->preprocessPrimarySidebarMenu($variables, $this->activeSettings());
    }
  }

  /**
   * Implements hook_preprocess_HOOK() for menu--account.html.twig.
   *
   * @param array<string, mixed> $variables
   *   The template variables.
   */
  #[Hook('preprocess_menu__account', order: Order::Last)]
  public function preprocessMenuAccount(array &$variables): void {
    if ($this->detection->isActive()) {
      $this->menus->preprocessAccountMenu($variables, $this->activeSettings());
    }
  }

  /**
   * Implements hook_preprocess_HOOK() for menu-local-task.html.twig.
   *
   * @param array<string, mixed> $variables
   *   The template variables.
   */
  #[Hook('preprocess_menu_local_task', order: Order::Last)]
  public function preprocessMenuLocalTask(array &$variables): void {
    if ($this->detection->isActive()) {
      $this->menus->preprocessLocalTask($variables);
    }
  }

  /**
   * The active theme's settings, for a hook that has passed its guard.
   */
  private function activeSettings(): BoundThemeSettings {
    return $this->settingsReader->forTheme($this->detection->activeThemeName());
  }

}
