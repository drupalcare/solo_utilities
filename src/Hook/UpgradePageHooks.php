<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Installer\InstallerKernel;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Registers the Solo upgrade page's template and points new installs to it.
 *
 * @internal
 */
final class UpgradePageHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly MessengerInterface $messenger,
  ) {}

  /**
   * Implements hook_theme().
   *
   * @return array<string, array<string, mixed>>
   *   The theme definition.
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'solo_upgrade' => [
        'variables' => [
          'solo_found' => TRUE,
          'themes' => [],
          'waiting' => '',
          'run' => NULL,
          'done' => [],
          'needs' => [],
          'backup' => '',
          'restore' => NULL,
        ],
      ],
    ];
  }

  /**
   * Implements hook_modules_installed().
   *
   * Not hook_install(): core rebuilds the router only after that has run, and
   * this message links to a route of this module. No router exists to link to
   * during a site install.
   *
   * @param list<string> $modules
   *   The modules just installed.
   * @param bool $is_syncing
   *   Whether a configuration sync installed them.
   */
  #[Hook('modules_installed')]
  public function modulesInstalled(array $modules, bool $is_syncing): void {
    if ($is_syncing || InstallerKernel::installationAttempted() || !in_array('solo_utilities', $modules, TRUE)) {
      return;
    }
    $this->messenger->addStatus($this->t('Solo Utilities is installed. See your Solo themes, their occupied regions and their upgrade state on the <a href=":url">Solo upgrade page</a>.', [
      ':url' => Url::fromRoute('solo_utilities.upgrade')->toString(),
    ]));
  }

}
