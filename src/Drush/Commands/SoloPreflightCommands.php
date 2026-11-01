<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Drush\Commands;

use Drupal\solo_utilities\Service\SoloReadinessScanner;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush command that checks the installed Solo sub-themes for Solo 2.
 *
 * @internal
 */
final class SoloPreflightCommands extends DrushCommands {

  public function __construct(
    private readonly SoloReadinessScanner $scanner,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('solo_utilities.readiness_scanner'));
  }

  /**
   * Lists what each Solo sub-theme must change before Solo 2.
   *
   * Read only: it writes no file and changes no setting.
   *
   * @return int
   *   0 when nothing needs a change, 1 when at least one finding was listed
   *   and 2 when Solo is not on the site.
   */
  #[CLI\Command(name: 'solo:preflight', aliases: ['solo-preflight'])]
  #[CLI\Usage(name: 'drush solo:preflight', description: 'Scan the installed Solo sub-themes and list what Solo 2 will change for them.')]
  public function preflight(): int {
    $result = $this->scanner->scan();
    if (!$result['solo_found']) {
      $this->logger()->error(dt('The Solo theme was not found on this site.'));
      return 2;
    }
    $this->io()->writeln(dt('Solo Utilities is enabled: !state', ['!state' => $result['solo_utilities_enabled'] ? 'yes' : 'no']));
    if ($result['subthemes'] === []) {
      $this->io()->success(dt('No installed Solo sub-theme was found. Nothing to change.'));
      return self::EXIT_SUCCESS;
    }

    $total = 0;
    foreach ($result['subthemes'] as $name => $subtheme) {
      $count = count($subtheme['findings']);
      $total += $count;
      $this->io()->section(dt('!name (!path): !count to change', [
        '!name' => $name,
        '!path' => $subtheme['path'],
        '!count' => $count,
      ]));
      foreach ($subtheme['findings'] as $finding) {
        $location = $finding['file'] . ($finding['line'] > 0 ? ':' . $finding['line'] : '');
        $this->io()->writeln(sprintf('  [%s] %s  %s', $finding['type'], $location, $finding['detail']));
      }
    }
    if ($total === 0) {
      $this->io()->success(dt('All installed Solo sub-themes are ready for Solo 2.'));
      return self::EXIT_SUCCESS;
    }
    $this->io()->warning(dt('!count item(s) to change. Nothing was written.', ['!count' => $total]));
    return self::EXIT_FAILURE;
  }

}
