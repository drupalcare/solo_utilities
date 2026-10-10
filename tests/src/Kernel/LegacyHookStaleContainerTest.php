<?php

declare(strict_types=1);

namespace Drupal\Tests\solo_utilities\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\KernelTests\KernelTestBase;
use Drupal\solo_utilities\Hook\SoloUtilitiesHooks;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder as SymfonyContainerBuilder;

/**
 * The #[LegacyHook] wrappers work when the Hook class is not a service.
 *
 * WHY THIS EXISTS. Issue #3628961 comment #7: "You have requested a
 * non-existent service Drupal\solo_utilities\Hook\SoloUtilitiesHooks". The
 * service was first declared in 1.0.7. A site updated from 1.0.6 runs the new
 * .module against the container compiled for 1.0.6 until a cache rebuild, and
 * every wrapper called \Drupal::service(SoloUtilitiesHooks::class). Because
 * hook_preprocess_page() fires on every page, every page, /user/login
 * included, answered HTTP 500 until `drush cr` or `drush updb`.
 *
 * This test builds exactly that container: the Hook class definition is
 * removed after every other compiler pass, including core's
 * HookCollectorPass, which re-registers Hook classes on Drupal 11.1+.
 *
 * @group solo_utilities
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */
#[Group('solo_utilities')]
#[RunTestsInSeparateProcesses]
class LegacyHookStaleContainerTest extends KernelTestBase implements CompilerPassInterface {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'solo_utilities',
  ];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->addCompilerPass($this, PassConfig::TYPE_AFTER_REMOVING, -1000);
  }

  /**
   * Removes the Hook class service, as a pre-1.0.7 container lacks it.
   */
  public function process(SymfonyContainerBuilder $container): void {
    $container->removeDefinition(SoloUtilitiesHooks::class);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
  }

  /**
   * The wrappers resolve the Hook class without the container service.
   */
  public function testWrappersRunWithoutTheHookService(): void {
    // Control: without this, a green result would prove nothing.
    $this->assertFalse(
      $this->container->has(SoloUtilitiesHooks::class),
      'The Hook class must be absent from the container, as on a site updated from 1.0.6 before a cache rebuild.'
    );

    $route_match = $this->container->get('current_route_match');

    // The help page answer proves the Hook class really was instantiated
    // and its method ran, not merely that nothing threw.
    $help = solo_utilities_help('help.page.solo_utilities', $route_match);
    $this->assertIsString($help);
    $this->assertStringContainsString('Solo', $help);
    $this->assertNull(solo_utilities_help('some.other.route', $route_match));

    // The wrapper that took every page down in the reported failure.
    $variables = [];
    solo_utilities_preprocess_page($variables);
    $this->assertSame([], $variables, 'No Solo theme is active, so nothing is attached.');
  }

}
