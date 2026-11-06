<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Hook;

use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;
use Drupal\solo_utilities\Asset\SoloLibraryDefinitions;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Adds the Solo theme's generated libraries during library discovery.
 *
 * Kept apart from AssetHooks on purpose: this runs inside core's library
 * parse, so it depends on nothing that itself needs library discovery.
 *
 * @internal
 */
final class LibraryHooks {

  public function __construct(
    private readonly ExtensionPathResolver $extensionPathResolver,
    #[Autowire(service: 'logger.channel.solo_utilities')]
    private readonly LoggerInterface $logger,
    #[Autowire('%app.root%')]
    private readonly string $root,
  ) {}

  /**
   * Implements hook_library_info_alter().
   *
   * Adds the Solo theme's generated libraries (fonts, color schemes,
   * breakpoints) under their public solo/* names. Runs inside core's library
   * parse, before paths resolve, so the result is the same as a yml entry. A
   * key the theme's own libraries.yml defines is never overwritten.
   *
   * @param array<string, array<string, mixed>> $libraries
   *   The libraries of the extension, keyed by library name.
   * @param string $extension
   *   The extension that defines them.
   */
  #[Hook('library_info_alter', order: Order::First)]
  public function libraryInfoAlter(array &$libraries, string $extension): void {
    if ($extension !== 'solo') {
      return;
    }
    $problems = [];
    $path = $this->root . '/' . $this->extensionPathResolver->getPath('theme', 'solo');
    foreach ((new SoloLibraryDefinitions())->build($path, $problems) as $name => $definition) {
      $libraries[$name] = ($libraries[$name] ?? []) + $definition;
    }
    foreach ($problems as $problem) {
      $this->logger->warning('Solo generated libraries: @problem', ['@problem' => $problem]);
    }
  }

}
