<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Service;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ProfileExtensionList;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Creates a Solo sub-theme from the starter that ships inside Solo.
 *
 * Used by `drush solo:subtheme`. The starter spells its own name in several
 * forms (machine name, class name, label, CSS/JS file names, JS behavior),
 * and every one of them is replaced, in file names and in file contents.
 */
final class SubthemeGenerator {

  use StringTranslationTrait;

  /**
   * Names Drupal reserves for directories inside an extension.
   *
   * Listed in the drupal.org guide "Naming and placing your Drupal module";
   * the same rule applies to theme machine names.
   */
  public const RESERVED_NAMES = [
    'src', 'lib', 'vendor', 'assets', 'css', 'files', 'images', 'js', 'misc',
    'templates', 'includes', 'fixtures', 'drupal',
  ];

  /**
   * The starter's folder name inside the Solo theme.
   */
  public const STARTER_FOLDER = 'solo_subtheme';

  /**
   * Every spelling of the starter's name, keyed by form.
   */
  private const STARTER_NAMES = [
    'machine' => 'solo_subtheme',
    'class' => 'SoloSubtheme',
    'label' => 'Solo Sub-theme',
    'label_lower' => 'Solo sub-theme',
    'kebab' => 'solo-subtheme',
    'camel' => 'soloSubTheme',
  ];

  /**
   * Matches any spelling of the starter's name, to catch one not replaced.
   */
  private const STARTER_NAME_PATTERN = '/solo[\s_-]?sub[\s_-]?theme/i';

  public function __construct(
    private readonly ThemeExtensionList $themeList,
    private readonly ModuleExtensionList $moduleList,
    private readonly ProfileExtensionList $profileList,
    private readonly string $appRoot,
  ) {}

  /**
   * Returns the default human-readable name for a machine name.
   */
  public function defaultLabel(string $machine_name): string {
    return ucwords(str_replace('_', ' ', $machine_name));
  }

  /**
   * Returns why a machine name or label cannot be used, or NULL when both can.
   */
  public function validate(string $machine_name, string $label): ?string {
    if (preg_match('/^[a-z][a-z0-9_]*$/', $machine_name) !== 1) {
      return (string) $this->t('"@name" is not a valid machine name. Use lowercase letters, numbers and underscores, starting with a letter, for example mysite_theme.', ['@name' => $machine_name]);
    }
    if (strlen($machine_name) > DRUPAL_EXTENSION_NAME_MAX_LENGTH) {
      return (string) $this->t('"@name" is @length characters; Drupal allows at most @max.', [
        '@name' => $machine_name,
        '@length' => strlen($machine_name),
        '@max' => DRUPAL_EXTENSION_NAME_MAX_LENGTH,
      ]);
    }
    if (in_array($machine_name, self::RESERVED_NAMES, TRUE)) {
      return (string) $this->t('"@name" is reserved by Drupal. Choose another name.', ['@name' => $machine_name]);
    }
    if ($this->themeList->exists($machine_name) || $this->moduleList->exists($machine_name) || $this->profileList->exists($machine_name)) {
      return (string) $this->t('A theme, module or profile named "@name" already exists on this site. Machine names must be unique.', ['@name' => $machine_name]);
    }
    // The label is written into the .info.yml file unquoted, so it is limited
    // to characters that need no YAML quoting.
    if (preg_match('/^[\p{L}\p{N}][\p{L}\p{N} _-]*$/u', $label) !== 1) {
      return (string) $this->t('"@label" is not a valid label. Use letters, numbers, spaces, hyphens and underscores.', ['@label' => $label]);
    }
    return NULL;
  }

  /**
   * Returns the absolute path of the starter inside the installed Solo theme.
   *
   * @throws \RuntimeException
   *   When Solo or its starter folder cannot be found.
   */
  public function starterPath(): string {
    if (!$this->themeList->exists('solo')) {
      throw new \RuntimeException((string) $this->t('The Solo theme was not found. Download it first: composer require drupal/solo'));
    }
    $starter = $this->appRoot . '/' . $this->themeList->getPath('solo') . '/' . self::STARTER_FOLDER;
    if (!is_dir($starter)) {
      throw new \RuntimeException((string) $this->t('The Solo starter folder was not found at @path.', ['@path' => $starter]));
    }
    return $starter;
  }

  /**
   * Returns the folder new sub-themes are created in: themes/custom.
   */
  public function customThemesPath(): string {
    return $this->appRoot . '/themes/custom';
  }

  /**
   * Copies the starter to a new folder and renames it.
   *
   * Call validate() first; this only refuses an existing destination.
   *
   * @param string $machine_name
   *   The new theme's machine name.
   * @param string $label
   *   The new theme's human-readable name.
   * @param string $starter
   *   Absolute path of the starter folder.
   * @param string $parent
   *   Absolute path of the folder to create the theme in.
   *
   * @return list<string>
   *   Files, relative to the new theme, that still mention the starter's
   *   name (empty when the rename is complete).
   *
   * @throws \RuntimeException
   *   When the destination exists or a file cannot be read. A partly created
   *   theme is removed first.
   */
  public function generate(string $machine_name, string $label, string $starter, string $parent): array {
    $destination = $parent . '/' . $machine_name;
    if (file_exists($destination)) {
      throw new \RuntimeException((string) $this->t('@path already exists. Choose another name or remove that folder.', ['@path' => $destination]));
    }

    $filesystem = new Filesystem();
    try {
      $filesystem->mirror($starter, $destination);
      return $this->rename($destination, $this->replacements($machine_name, $label), $filesystem);
    }
    catch (\Throwable $e) {
      $filesystem->remove($destination);
      throw new \RuntimeException($e->getMessage(), 0, $e);
    }
  }

  /**
   * Maps every spelling of the starter's name to the new theme's.
   *
   * @return array<string, string>
   *   Old spelling => new spelling.
   */
  private function replacements(string $machine_name, string $label): array {
    $class_name = str_replace(' ', '', ucwords(str_replace('_', ' ', $machine_name)));
    return [
      self::STARTER_NAMES['machine'] => $machine_name,
      self::STARTER_NAMES['class'] => $class_name,
      self::STARTER_NAMES['label'] => $label,
      self::STARTER_NAMES['label_lower'] => $label,
      self::STARTER_NAMES['kebab'] => str_replace('_', '-', $machine_name),
      self::STARTER_NAMES['camel'] => lcfirst($class_name),
    ];
  }

  /**
   * Replaces the starter's names in every file name and file.
   *
   * @param string $directory
   *   The new theme's folder.
   * @param array<string, string> $replacements
   *   Old spelling => new spelling.
   * @param \Symfony\Component\Filesystem\Filesystem $filesystem
   *   The filesystem helper.
   *
   * @return list<string>
   *   Files that still mention the starter's name.
   */
  private function rename(string $directory, array $replacements, Filesystem $filesystem): array {
    // The starter's README explains renaming the folder by hand, which has
    // just been done.
    $filesystem->remove($directory . '/README.md');

    $paths = [];
    $files = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
    );
    foreach ($files as $file) {
      if ($file instanceof \SplFileInfo && $file->isFile()) {
        $paths[] = $file->getPathname();
      }
    }

    $leftovers = [];
    foreach ($paths as $path) {
      $contents = file_get_contents($path);
      if ($contents === FALSE) {
        throw new \RuntimeException(sprintf('Cannot read %s.', $path));
      }
      $renamed = strtr($contents, $replacements);
      if ($renamed !== $contents) {
        $filesystem->dumpFile($path, $renamed);
      }
      $basename = strtr(basename($path), $replacements);
      if ($basename !== basename($path)) {
        $filesystem->rename($path, dirname($path) . '/' . $basename);
        $path = dirname($path) . '/' . $basename;
      }
      // A spelling the map does not know (for example one added in a later
      // Solo release) is reported, so it is never kept silently.
      if (preg_match(self::STARTER_NAME_PATTERN, $basename . "\n" . $renamed) === 1) {
        $leftovers[] = substr($path, strlen($directory) + 1);
      }
    }
    sort($leftovers);
    return $leftovers;
  }

}
