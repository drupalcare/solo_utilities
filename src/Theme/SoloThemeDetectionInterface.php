<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Theme;

use Drupal\Core\Form\FormStateInterface;

/**
 * Answers every "is this Solo or a Solo sub-theme?" question in one place.
 *
 * "Solo theme" means Solo itself or any theme with Solo among its base themes,
 * at any depth. Two scopes are explicit on purpose:
 *
 * - Installed (rendering): isInHierarchy() and installedThemes(). A theme that
 *   is not installed is not a Solo theme here.
 * - On disk (migration): chainOnDisk(), familyNames(), depth(), parentName()
 *   and hasCompleteChain(). Installed or not, the resolved base chain decides.
 *
 * Nothing here throws: an unknown, uninstalled or malformed theme name is
 * simply not a Solo theme. Implementations never call
 * ThemeExtensionList::getBaseThemes(), which Drupal 12 removed.
 *
 * @api
 */
interface SoloThemeDetectionInterface {

  /**
   * The machine name of the theme rendering this request.
   */
  public function activeThemeName(): string;

  /**
   * Whether the theme rendering this request is Solo or a Solo sub-theme.
   */
  public function isActive(): bool;

  /**
   * The default (front-end) theme's machine name, or '' when none is set.
   */
  public function defaultThemeName(): string;

  /**
   * Whether the default (front-end) theme is Solo or a Solo sub-theme.
   */
  public function isDefault(): bool;

  /**
   * The theme a theme settings form is editing, or '' for the global page.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The state of the system_theme_settings form.
   */
  public function editedThemeName(FormStateInterface $form_state): string;

  /**
   * Whether a theme settings form is editing Solo or a Solo sub-theme.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The state of the system_theme_settings form.
   */
  public function isEdited(FormStateInterface $form_state): bool;

  /**
   * Whether a theme is Solo, or has Solo among its base themes at any depth.
   *
   * Installed scope.
   *
   * @param mixed $theme_name
   *   A theme machine name. Configuration feeds this, so it is not trusted to
   *   be a string.
   */
  public function isInHierarchy(mixed $theme_name): bool;

  /**
   * Solo and every theme on disk whose real base chain reaches it.
   *
   * On-disk scope. A chain broken by a missing base theme is left out.
   *
   * @return list<string>
   *   Theme machine names, sorted.
   */
  public function chainOnDisk(): array;

  /**
   * A theme followed by its base themes, nearest first.
   *
   * On-disk scope, for template lookup. Any theme on disk is answered, Solo
   * related or not.
   *
   * @param string $theme
   *   A theme machine name.
   *
   * @return list<string>
   *   The theme first, then each base theme up to the root; empty when the
   *   theme is not on disk.
   */
  public function familyNames(string $theme): array;

  /**
   * The distance from a theme to Solo.
   *
   * On-disk scope.
   *
   * @param string $theme
   *   A theme machine name.
   *
   * @return int|null
   *   0 for Solo, 1 for a direct sub-theme, and so on; NULL when the theme is
   *   not on disk or Solo is not among its base themes.
   */
  public function depth(string $theme): ?int;

  /**
   * The direct base theme of a theme.
   *
   * On-disk scope.
   *
   * @param string $theme
   *   A theme machine name.
   *
   * @return string
   *   The base theme machine name; '' for none or an unknown theme.
   */
  public function parentName(string $theme): string;

  /**
   * Whether every base theme in a theme's chain exists on disk.
   *
   * On-disk scope. Core keeps a missing base theme as a key with a NULL value;
   * such a chain proves nothing about ownership.
   *
   * @param string $theme
   *   A theme machine name.
   *
   * @return bool
   *   FALSE for a broken chain and for a theme that is not on disk.
   */
  public function hasCompleteChain(string $theme): bool;

  /**
   * Every installed theme that is Solo or a Solo sub-theme.
   *
   * Installed scope.
   *
   * @return array<string, string>
   *   Readable names keyed by theme machine name.
   */
  public function installedThemes(): array;

  /**
   * Forgets every remembered answer.
   *
   * Call it when themes are installed or uninstalled.
   */
  public function resetCache(): void;

}
