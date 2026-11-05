<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Css;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\solo_utilities\Theme\SoloThemeDetection;
use Psr\Log\LoggerInterface;

/**
 * Names, writes and removes the generated files of one Solo theme.
 *
 * Files live in `public://solo/<theme>/` and carry a digest of their bytes, so
 * new settings give a new URL and no browser sees old CSS. Themes never share a
 * file. Only names this class generates are ever removed.
 *
 * @internal
 */
final class ThemeAssetFiles {

  /**
   * Hex length of the hash in a file name.
   */
  private const HASH_LENGTH = 10;

  public function __construct(
    private readonly FileSystemInterface $fileSystem,
    private readonly ThemeCssBuilder $builder,
    private readonly SoloThemeDetection $themeDetection,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * The URI of the generated settings stylesheet.
   *
   * @param string $theme
   *   The theme machine name.
   * @param string $css
   *   The file's content.
   */
  public function settingsCssUri(string $theme, string $css): string {
    return $this->uri($theme, 'settings', 'css', $css);
  }

  /**
   * The URI of the typed stylesheet.
   *
   * @param string $theme
   *   The theme machine name.
   * @param string $css
   *   The file's content.
   */
  public function customCssUri(string $theme, string $css): string {
    return $this->uri($theme, 'custom', 'css', $css);
  }

  /**
   * The URI of the typed inline CSS box.
   *
   * @param string $theme
   *   The theme machine name.
   * @param string $css
   *   The file's content.
   */
  public function inlineCssUri(string $theme, string $css): string {
    return $this->uri($theme, 'inline', 'css', $css);
  }

  /**
   * The URI of the typed script.
   *
   * @param string $theme
   *   The theme machine name.
   * @param string $js
   *   The file's content.
   */
  public function customJsUri(string $theme, string $js): string {
    return $this->uri($theme, 'custom', 'js', $js);
  }

  /**
   * Whether a generated file is on disk.
   *
   * @param string $uri
   *   The file URI.
   */
  public function exists(string $uri): bool {
    return file_exists($uri);
  }

  /**
   * Whether a URI names a stylesheet this class generates, for any theme.
   *
   * Matches the shape uri() gives a settings, custom or inline stylesheet, so a
   * file anything else puts in the same directory is never claimed.
   *
   * @param string $uri
   *   A stylesheet URI or path.
   */
  public function isGeneratedStylesheet(string $uri): bool {
    $pattern = '#^' . preg_quote($this->directory(''), '#') . '[a-z0-9_]+/(settings|custom|inline)\.[0-9a-f]{' . self::HASH_LENGTH . '}\.css$#D';
    return preg_match($pattern, $uri) === 1;
  }

  /**
   * Brings a Solo theme's files in line with its settings.
   *
   * Logs refused field names, so this is the one place they are reported: on a
   * save and on a rebuild, never on every page view. Any other theme is refused.
   *
   * @param string $theme
   *   The theme machine name.
   *
   * @return bool
   *   TRUE when every file that should exist does.
   */
  public function rebuild(string $theme): bool {
    if (!$this->themeDetection->isInHierarchy($theme)) {
      return FALSE;
    }
    $assets = $this->builder->build($theme);
    foreach ($assets->refusedFields as $field) {
      $this->logger->warning('Solo theme @theme: setting @field was refused and emits nothing.', [
        '@theme' => $theme,
        '@field' => $field,
      ]);
    }

    $changed = FALSE;
    $settings = $this->sync($theme, 'settings', 'css', $assets->settingsCss, $changed);
    $custom_css = $this->sync($theme, 'custom', 'css', $assets->customCss, $changed);
    $inline_css = $this->sync($theme, 'inline', 'css', $assets->inlineCss, $changed);
    $custom_js = $this->sync($theme, 'custom', 'js', $assets->customJs, $changed);
    if ($changed) {
      $this->invalidateLibraries();
    }
    return $settings && $custom_css && $inline_css && $custom_js;
  }

  /**
   * Removes every generated file of a theme.
   *
   * @param string $theme
   *   The theme machine name; a name that is not a machine name does nothing.
   */
  public function deleteAll(string $theme): void {
    if (preg_match('/^[a-z0-9_]+$/D', $theme) !== 1) {
      return;
    }
    $changed = FALSE;
    $this->removeOthers($theme, 'settings', 'css', NULL, $changed);
    $this->removeOthers($theme, 'custom', 'css', NULL, $changed);
    $this->removeOthers($theme, 'inline', 'css', NULL, $changed);
    $this->removeOthers($theme, 'custom', 'js', NULL, $changed);
    if ($changed) {
      $this->invalidateLibraries();
    }
  }

  /**
   * Makes the next library lookup see the current file names.
   */
  public function invalidateLibraries(): void {
    $this->cacheTagsInvalidator->invalidateTags(['library_info']);
  }

  /**
   * Gives the content-addressed URI of a file.
   *
   * @param string $theme
   *   The theme machine name.
   * @param string $name
   *   The name part: settings or custom.
   * @param string $extension
   *   The extension: css or js.
   * @param string $content
   *   The file's content.
   */
  private function uri(string $theme, string $name, string $extension, string $content): string {
    return $this->directory($theme) . '/' . $name . '.' . substr(hash('xxh128', $content), 0, self::HASH_LENGTH) . '.' . $extension;
  }

  /**
   * The directory of one theme.
   *
   * @param string $theme
   *   The theme machine name.
   */
  private function directory(string $theme): string {
    return 'public://solo/' . $theme;
  }

  /**
   * Writes one file when absent and removes its older versions.
   *
   * @param string $theme
   *   The theme machine name.
   * @param string $name
   *   The name part: settings or custom.
   * @param string $extension
   *   The extension: css or js.
   * @param string $content
   *   The content; empty means the file should not exist.
   * @param bool $changed
   *   Set to TRUE when a file was added or removed.
   *
   * @return bool
   *   TRUE when the disk now matches.
   */
  private function sync(string $theme, string $name, string $extension, string $content, bool &$changed): bool {
    if ($content === '') {
      $this->removeOthers($theme, $name, $extension, NULL, $changed);
      return TRUE;
    }
    $uri = $this->uri($theme, $name, $extension, $content);
    if (!file_exists($uri)) {
      $directory = $this->directory($theme);
      if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
        $this->logger->error('Could not prepare the directory @dir.', ['@dir' => $directory]);
        return FALSE;
      }
      try {
        $this->fileSystem->saveData($content, $uri, FileExists::Replace);
      }
      catch (FileException $e) {
        $this->logger->error('Could not write @uri: @message', ['@uri' => $uri, '@message' => $e->getMessage()]);
        return FALSE;
      }
      $changed = TRUE;
    }
    // After the write: a failure above keeps the old file for pages still
    // pointing at it.
    $this->removeOthers($theme, $name, $extension, $uri, $changed);
    return TRUE;
  }

  /**
   * Removes the versions of one file, except the one to keep.
   *
   * @param string $theme
   *   The theme machine name.
   * @param string $name
   *   The name part: settings or custom.
   * @param string $extension
   *   The extension: css or js.
   * @param string|null $keep
   *   The URI to keep, or NULL to remove them all.
   * @param bool $changed
   *   Set to TRUE when a file was removed.
   */
  private function removeOthers(string $theme, string $name, string $extension, ?string $keep, bool &$changed): void {
    $directory = $this->directory($theme);
    if (!is_dir($directory)) {
      return;
    }
    $mask = '/^' . $name . '\.[0-9a-f]{' . self::HASH_LENGTH . '}\.' . $extension . '$/D';
    foreach (array_keys($this->fileSystem->scanDirectory($directory, $mask, ['recurse' => FALSE])) as $uri) {
      if ($uri === $keep) {
        continue;
      }
      try {
        $this->fileSystem->delete($uri);
        $changed = TRUE;
      }
      catch (FileException $e) {
        $this->logger->warning('Could not delete @uri: @message', ['@uri' => $uri, '@message' => $e->getMessage()]);
      }
    }
  }

}
