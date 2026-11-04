<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Drush\Commands;

use Drupal\solo_utilities\Upgrade\SettingsMigrator;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush command that writes the pre-migration Solo settings back.
 *
 * @internal
 */
final class SoloSettingsRestoreCommands extends DrushCommands {

  public function __construct(
    private readonly SettingsMigrator $migrator,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get(SettingsMigrator::class));
  }

  /**
   * Writes the backup taken before the settings migration to each theme.
   *
   * For rolling back to Solo 1 without a database snapshot. Run it BEFORE the
   * code is rolled back.
   *
   * @return int
   *   0 when every backup was written, 1 when any failed or the run was
   *   cancelled.
   */
  #[CLI\Command(name: 'solo:settings-restore', aliases: ['solo-settings-restore'])]
  #[CLI\Usage(name: 'drush solo:settings-restore', description: 'Restore Solo theme settings to their state before the migration.')]
  public function restore(): int {
    $this->io()->warning(dt('Settings saved after the migration are lost by this. A config/sync exported with the new names has no reverse transform: restore it from version control by hand.'));
    if (!$this->io()->confirm(dt('Replace the settings of every migrated Solo theme with the backup?'), FALSE)) {
      return self::EXIT_FAILURE;
    }
    $result = $this->migrator->restore();
    foreach ($result['restored'] as $theme) {
      $this->io()->writeln(dt('Restored: !theme', ['!theme' => $theme]));
    }
    foreach ($result['skipped'] as $theme) {
      $this->io()->writeln(dt('Skipped, no provable Solo theme on disk: !theme', ['!theme' => $theme]));
    }
    if ($result['failed'] !== []) {
      $this->logger()->error(dt('Not restored: !themes. See the log.', ['!themes' => implode(', ', $result['failed'])]));
      return self::EXIT_FAILURE;
    }
    if ($result['restored'] === []) {
      $this->io()->writeln(dt('No backup to restore.'));
    }
    return self::EXIT_SUCCESS;
  }

}
