<?php

declare(strict_types=1);

namespace Drupal\Tests\solo_utilities\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Every hook runs through its class; no procedural implementation is left.
 *
 * Replaces LegacyHookStaleContainerTest. That test proved the #[LegacyHook]
 * functions still worked when the hook class was missing from a stale
 * container. At the ^11.3 floor core discovers class hooks itself, so those
 * functions are gone and this test proves the class path is the only path.
 *
 * @group solo_utilities
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */
#[Group('solo_utilities')]
#[RunTestsInSeparateProcesses]
class HookDispatchTest extends KernelTestBase {

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
   * The help hook answers through the module handler, from the class.
   */
  public function testHelpRunsThroughTheHookClass(): void {
    $handler = $this->container->get('module_handler');
    $route_match = $this->container->get('current_route_match');
    $help = $handler->invoke('solo_utilities', 'help', ['help.page.solo_utilities', $route_match]);
    $this->assertNotEmpty($help);
    $this->assertStringContainsString('Solo', (string) $help);
    $this->assertNull($handler->invoke('solo_utilities', 'help', ['some.other.route', $route_match]));
  }

  /**
   * The old procedural hook implementations no longer exist.
   */
  public function testNoProceduralHookImplementationsRemain(): void {
    $hooks = [
      'help',
      'preprocess_page',
      'form_node_form_alter',
      'node_presave',
      'node_insert',
      'form_block_form_alter',
      'preprocess_block',
      'modules_enabled',
      'modules_disabled',
    ];
    $defined = get_defined_functions()['user'];
    foreach ($hooks as $hook) {
      $this->assertNotContains('solo_utilities_' . $hook, $defined, $hook);
    }
    // Control: a forwarder that must stay callable is still there.
    $this->assertContains('solo_utilities__is_solo_in_theme_hierarchy', $defined);
  }

}
