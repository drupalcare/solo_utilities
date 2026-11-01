<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Theme\ThemeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Answers one question in one place: is this a Solo theme?
 *
 * "Solo theme" means Solo itself or any theme with Solo among its base themes.
 * The walk lives only here (drift test: ThemeDetectionDriftTest). The question
 * is asked about two different themes, and they are two methods on purpose:
 *
 * - isActive(): the theme rendering THIS request. Core switches the active
 *   theme mid-request, so the answer is never cached across calls. Use it
 *   in a hook that must do nothing outside a Solo theme.
 * - isDefault(): the front-end theme named by system.theme:default. Use it in
 *   a feature that must work while an admin theme is active (the node form
 *   and the block form are rendered by the admin theme, yet their Solo
 *   settings live on the default theme).
 *
 * Neither method throws: an unknown, uninstalled or malformed theme name is
 * simply not a Solo theme. That was the 1.0.7 HTTP 500.
 *
 * This class takes no module class in its constructor and builds itself in
 * create(), so \Drupal::classResolver() can still build it from a container
 * compiled before this service existed.
 *
 * @api
 */
final class SoloThemeDetection implements ContainerInjectionInterface {

  /**
   * Constructs the service.
   *
   * @param \Drupal\Core\Theme\ThemeManagerInterface $themeManager
   *   The theme manager, source of the active theme.
   * @param \Drupal\Core\Extension\ThemeHandlerInterface $themeHandler
   *   The theme handler, source of theme info.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory, source of the default theme name.
   */
  public function __construct(
    private readonly ThemeManagerInterface $themeManager,
    private readonly ThemeHandlerInterface $themeHandler,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('theme.manager'),
      $container->get('theme_handler'),
      $container->get('config.factory'),
    );
  }

  /**
   * Whether the theme rendering this request is Solo or a Solo sub-theme.
   */
  public function isActive(): bool {
    return $this->isInHierarchy($this->themeManager->getActiveTheme()->getName());
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
   * Whether a theme is Solo, or has Solo among its base themes.
   *
   * @param mixed $theme_name
   *   A theme machine name. Configuration feeds this, so it is not trusted to
   *   be a string.
   */
  public function isInHierarchy(mixed $theme_name): bool {
    if (!is_string($theme_name) || $theme_name === '') {
      return FALSE;
    }

    // Answer for Solo itself before touching the theme handler, so the result
    // does not depend on Solo being installed on this site.
    if ($theme_name === 'solo') {
      return TRUE;
    }

    // ThemeHandler::getTheme() THROWS UnknownExtensionException for a theme it
    // does not know. system.theme:default can name an uninstalled theme and a
    // base theme in an .info.yml need not be installed, so check first.
    if (!$this->themeHandler->themeExists($theme_name)) {
      return FALSE;
    }

    $base_themes = $this->themeHandler->getTheme($theme_name)->info['base theme'] ?? [];
    foreach ((array) $base_themes as $base_theme_name) {
      if ($this->isInHierarchy($base_theme_name)) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
