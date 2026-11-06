<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Service;

use Drupal\Core\Command\GenerateTheme;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ProfileExtensionList;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Creates a Solo sub-theme from the starterkit that ships inside Solo.
 *
 * Used by `drush solo:subtheme`, a thin wrapper over core's generate-theme
 * command. Core does the copy and the rename; Solo's own StarterKit class
 * (solo_subtheme/src/StarterKit.php) finishes the spellings core does not
 * know. This class only validates names, finds the starterkit and runs core.
 *
 * @internal
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
   * Generates a sub-theme with core's generate-theme command.
   *
   * Call validate() first; core itself refuses an existing destination.
   *
   * @param string $machine_name
   *   The new theme's machine name.
   * @param string $label
   *   The new theme's human-readable name.
   * @param string $starter
   *   Absolute path of the starterkit folder, from starterPath().
   * @param string $parent
   *   Absolute path of the folder to create the theme in. Core's generator
   *   takes a path relative to the Drupal root, so it must be inside it.
   *
   * @return list<string>
   *   Files, relative to the new theme, that still mention the starter's
   *   name (empty when the rename is complete).
   *
   * @throws \RuntimeException
   *   When the destination exists, the folder is outside the Drupal root or
   *   core's generator fails. A partly created theme is removed first.
   */
  public function generate(string $machine_name, string $label, string $starter, string $parent): array {
    $destination = $parent . '/' . $machine_name;
    if (file_exists($destination)) {
      throw new \RuntimeException((string) $this->t('@path already exists. Choose another name or remove that folder.', ['@path' => $destination]));
    }
    $root_prefix = rtrim($this->appRoot, '/') . '/';
    if (!str_starts_with($parent . '/', $root_prefix)) {
      throw new \RuntimeException((string) $this->t('@path is outside the Drupal root; sub-themes must be created inside it.', ['@path' => $parent]));
    }
    // The starterkit is found by core through its machine name.
    if (!is_file($starter . '/' . self::STARTER_FOLDER . '.starterkit.yml')) {
      throw new \RuntimeException((string) $this->t('@path is not a starterkit: its .starterkit.yml file is missing.', ['@path' => $starter]));
    }

    $command = new GenerateTheme(NULL, $this->appRoot);
    $input = new ArrayInput([
      'machine-name' => $machine_name,
      '--name' => $label,
      '--description' => $label . ' is an empty theme for your customization.',
      '--path' => substr($parent, strlen($root_prefix)),
      '--starterkit' => self::STARTER_FOLDER,
    ]);
    $input->setInteractive(FALSE);
    $output = new BufferedOutput();

    // Core's command changes into the Drupal root; put the directory back.
    $previous = getcwd();
    try {
      $status = $command->run($input, $output);
    }
    catch (\Throwable $e) {
      (new Filesystem())->remove($destination);
      throw new \RuntimeException($e->getMessage(), 0, $e);
    }
    finally {
      if ($previous !== FALSE) {
        chdir($previous);
      }
    }
    if ($status !== 0) {
      (new Filesystem())->remove($destination);
      throw new \RuntimeException(trim($output->fetch()));
    }
    return $this->leftovers($destination);
  }

  /**
   * Lists files that still mention the starter's name.
   *
   * A spelling neither core nor the StarterKit class knows (for example one
   * added in a later Solo release) is reported, so it is never kept silently.
   *
   * @param string $directory
   *   The new theme's folder.
   *
   * @return list<string>
   *   Paths relative to the theme.
   */
  private function leftovers(string $directory): array {
    $leftovers = [];
    $files = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
    );
    foreach ($files as $file) {
      if (!$file instanceof \SplFileInfo || !$file->isFile()) {
        continue;
      }
      $path = $file->getPathname();
      $contents = file_get_contents($path);
      if ($contents === FALSE) {
        throw new \RuntimeException(sprintf('Cannot read %s.', $path));
      }
      if (preg_match(self::STARTER_NAME_PATTERN, $file->getBasename() . "\n" . $contents) === 1) {
        $leftovers[] = substr($path, strlen($directory) + 1);
      }
    }
    sort($leftovers);
    return $leftovers;
  }

}
