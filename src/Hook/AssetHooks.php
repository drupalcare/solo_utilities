<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\solo_utilities\Asset\AssetAttacher;
use Drupal\solo_utilities\Css\ThemeLibraryRegistrar;
use Drupal\solo_utilities\Feature\MenuDebugNotice;
use Drupal\solo_utilities\Settings\SettingsReader;
use Drupal\solo_utilities\Theme\SoloThemeDetectionInterface;

/**
 * Routes asset hooks to the services that own them.
 */
final class AssetHooks {

  public function __construct(
    private readonly ThemeLibraryRegistrar $registrar,
    private readonly AssetAttacher $attacher,
    private readonly SoloThemeDetectionInterface $themeDetection,
    private readonly MenuDebugNotice $menuDebugNotice,
    private readonly SettingsReader $settingsReader,
  ) {}

  /**
   * Implements hook_library_info_build().
   *
   * @return array<string, array<string, mixed>>
   *   Library definitions keyed by library name.
   */
  #[Hook('library_info_build')]
  public function libraryInfoBuild(): array {
    return $this->registrar->buildLibraries();
  }

  /**
   * Implements hook_css_alter().
   *
   * @param array<string, array<string, mixed>> $css
   *   The CSS assets keyed by data.
   */
  #[Hook('css_alter')]
  public function cssAlter(array &$css): void {
    $this->registrar->moveAfterThemes($css);
  }

  /**
   * Implements hook_page_attachments().
   *
   * @param array<string, mixed> $attachments
   *   The page attachments.
   */
  #[Hook('page_attachments')]
  public function pageAttachments(array &$attachments): void {
    if (!$this->themeDetection->isActive()) {
      return;
    }
    $theme = $this->themeDetection->activeThemeName();
    $this->attacher->attachSettingLibraries($attachments, $theme);
    $this->attacher->attachGeneratedAssets($attachments, $theme);
    $this->menuDebugNotice->attach($attachments, $this->settingsReader->forTheme($theme));
  }

}
