<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Theme;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Theme\ThemeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Answers one question in one place: which theme, and is it a Solo theme?
 *
 * "Solo theme" means Solo itself or any theme with Solo among its base themes,
 * at any depth. The question is asked about three different themes, and each
 * has its own methods on purpose:
 *
 * - Active: the theme rendering THIS request. Core switches the active theme
 *   mid-request, so the answer is never cached across calls. Use it in a hook
 *   that must do nothing outside a Solo theme.
 * - Default: the front-end theme named by system.theme:default. Use it in a
 *   feature that must work while an admin theme is active (the node form and
 *   the block form are rendered by the admin theme, yet their Solo settings
 *   live on the default theme).
 * - Edited: the theme whose settings form is being built or saved, which core
 *   passes as the form's first build argument. A module's alter of that form
 *   runs with the admin theme active, so neither of the others is right there.
 *
 * Nothing here throws: an unknown, uninstalled or malformed theme name is
 * simply not a Solo theme. That was the 1.0.7 HTTP 500.
 *
 * This class takes no module class in its constructor and builds itself in
 * create(), so \Drupal::classResolver() can still build it from a container
 * compiled before this service existed.
 *
 * @api
 */
final class SoloThemeDetection implements SoloThemeDetectionInterface, ContainerInjectionInterface {

  /**
   * Remembered isInHierarchy() answers, by theme name.
   *
   * Keyed by name, never by "active" or "default": core switches the active
   * theme mid-request, and a name always answers the same until a theme is
   * installed or uninstalled (see resetCache()).
   *
   * @var array<string, bool>
   */
  private array $hierarchyCache = [];

  /**
   * Remembered familyNames() answers, by theme name.
   *
   * @var array<string, list<string>>
   */
  private array $familyCache = [];

  /**
   * Constructs the service.
   *
   * @param \Drupal\Core\Theme\ThemeManagerInterface $themeManager
   *   The theme manager, source of the active theme.
   * @param \Drupal\Core\Extension\ThemeHandlerInterface $themeHandler
   *   The theme handler, source of theme info.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory, source of the default theme name.
   * @param \Drupal\Core\Extension\ThemeExtensionList $themeList
   *   The theme extension list, source of every theme on disk.
   */
  public function __construct(
    private readonly ThemeManagerInterface $themeManager,
    private readonly ThemeHandlerInterface $themeHandler,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ThemeExtensionList $themeList,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('theme.manager'),
      $container->get('theme_handler'),
      $container->get('config.factory'),
      $container->get('extension.list.theme'),
    );
  }

  /**
   * The machine name of the theme rendering this request.
   */
  public function activeThemeName(): string {
    return $this->themeManager->getActiveTheme()->getName();
  }

  /**
   * Whether the theme rendering this request is Solo or a Solo sub-theme.
   */
  public function isActive(): bool {
    return $this->isInHierarchy($this->activeThemeName());
  }

  /**
   * The default (front-end) theme's machine name, or '' when none is set.
   *
   * The one reader of system.theme:default, for code that must read a Solo
   * setting from the front-end theme while an admin theme is active.
   */
  public function defaultThemeName(): string {
    $name = $this->configFactory->get('system.theme')->get('default');
    return is_string($name) ? $name : '';
  }

  /**
   * Whether the default (front-end) theme is Solo or a Solo sub-theme.
   *
   * Reads system.theme:default. Not the active theme: under an admin theme
   * this still answers for the front end.
   */
  public function isDefault(): bool {
    return $this->isInHierarchy($this->defaultThemeName());
  }

  /**
   * The theme a theme settings form is editing, or '' for the global page.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The state of the system_theme_settings form.
   */
  public function editedThemeName(FormStateInterface $form_state): string {
    $name = $form_state->getBuildInfo()['args'][0] ?? '';
    return is_string($name) ? $name : '';
  }

  /**
   * Whether a theme settings form is editing Solo or a Solo sub-theme.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The state of the system_theme_settings form.
   */
  public function isEdited(FormStateInterface $form_state): bool {
    return $this->isInHierarchy($this->editedThemeName($form_state));
  }

  /**
   * Whether a theme is Solo, or has Solo among its base themes at any depth.
   *
   * @param mixed $theme_name
   *   A theme machine name. Configuration feeds this, so it is not trusted to
   *   be a string.
   */
  public function isInHierarchy(mixed $theme_name): bool {
    if (!is_string($theme_name) || $theme_name === '') {
      return FALSE;
    }

    if (isset($this->hierarchyCache[$theme_name])) {
      return $this->hierarchyCache[$theme_name];
    }

    // A visited list, not recursion: an .info.yml pair naming each other as
    // base theme must end in "not Solo", never in a stack overflow.
    $visited = [];
    $pending = [$theme_name];
    while ($pending !== []) {
      $name = array_pop($pending);
      if (!is_string($name) || isset($visited[$name])) {
        continue;
      }
      $visited[$name] = TRUE;

      // Answer for Solo itself before touching the theme handler, so the result
      // does not depend on Solo being installed on this site.
      if ($name === 'solo') {
        return $this->hierarchyCache[$theme_name] = TRUE;
      }

      // ThemeHandler::getTheme() THROWS UnknownExtensionException for a theme
      // it does not know. system.theme:default can name an uninstalled theme
      // and a base theme in an .info.yml need not be installed, so check first.
      if ($this->themeHandler->themeExists($name)) {
        array_push($pending, ...array_values((array) ($this->themeHandler->getTheme($name)->info['base theme'] ?? [])));
      }
    }

    return $this->hierarchyCache[$theme_name] = FALSE;
  }

  /**
   * Solo and every theme on disk whose real base chain reaches it.
   *
   * For migration code, which must also cover themes that are not installed.
   * isInHierarchy() answers FALSE for those and keeps that meaning for
   * rendering. Only the resolved chain decides, never a name. A chain broken by
   * a missing base theme, Solo included, proves nothing and is left out: core
   * keeps a missing base theme as a key with a NULL value.
   *
   * @return list<string>
   *   Theme machine names, sorted.
   */
  public function chainOnDisk(): array {
    $themes = $this->themeList->getList();
    if (!isset($themes['solo'])) {
      return [];
    }
    $chain = ['solo'];
    foreach ($themes as $name => $theme) {
      $base_themes = $theme->base_themes ?? [];
      if (isset($base_themes['solo']) && !in_array(NULL, $base_themes, TRUE)) {
        $chain[] = (string) $name;
      }
    }
    sort($chain);
    return $chain;
  }


  /**
   * {@inheritdoc}
   */
  public function familyNames(string $theme): array {
    if (isset($this->familyCache[$theme])) {
      return $this->familyCache[$theme];
    }
    $found = $this->onDisk($theme);
    if ($found === NULL) {
      return [];
    }
    // Core fills base_themes root first; nearest first is its reverse.
    return $this->familyCache[$theme] = [$theme, ...array_map('strval', array_reverse(array_keys($found->base_themes ?? [])))];
  }

  /**
   * {@inheritdoc}
   */
  public function depth(string $theme): ?int {
    $found = $this->onDisk($theme);
    if ($found === NULL) {
      return NULL;
    }
    if ($theme === 'solo') {
      return 0;
    }
    $position = array_search('solo', array_map('strval', array_keys($found->base_themes ?? [])), TRUE);
    return $position === FALSE ? NULL : count($found->base_themes) - $position;
  }

  /**
   * {@inheritdoc}
   */
  public function parentName(string $theme): string {
    $base = $this->onDisk($theme)?->info['base theme'] ?? '';
    return is_string($base) ? $base : '';
  }

  /**
   * {@inheritdoc}
   */
  public function hasCompleteChain(string $theme): bool {
    $found = $this->onDisk($theme);
    return $found !== NULL && !in_array(NULL, $found->base_themes ?? [], TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function installedThemes(): array {
    $themes = [];
    foreach ($this->themeHandler->listInfo() as $name => $theme) {
      if ($this->isInHierarchy((string) $name)) {
        $label = $theme->info['name'] ?? $name;
        $themes[(string) $name] = is_string($label) ? $label : (string) $name;
      }
    }
    return $themes;
  }

  /**
   * {@inheritdoc}
   */
  public function resetCache(): void {
    $this->hierarchyCache = [];
    $this->familyCache = [];
  }

  /**
   * The extension object of a theme on disk, or NULL when there is none.
   */
  private function onDisk(string $theme): ?Extension {
    return $theme === '' ? NULL : ($this->themeList->getList()[$theme] ?? NULL);
  }

}
