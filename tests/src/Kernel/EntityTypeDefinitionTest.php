<?php

declare(strict_types=1);

namespace Drupal\Tests\solo_utilities\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Covers the two entity types on every core this module claims.
 *
 * WHY THIS EXISTS. 1.0.7 declared color_schemes_rule and node_width with PHP
 * attributes only. Drupal 10 discovers entity types from annotations only, so
 * on Drupal 10 neither entity type existed and every page that loaded one
 * threw PluginNotFoundException. Nothing went red because the suite had only
 * ever run on Drupal 11 and 12, where the attribute is read.
 *
 * The module now requires Drupal ^11.3 || ^12, so the attribute is the one
 * definition; PluginAnnotationParityTest (Unit) keeps a stray annotation out.
 * This test proves the entity types exist on whichever core runs it, and that
 * the update installs them on a site that missed them.
 *
 * @group solo_utilities
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */
#[Group('solo_utilities')]
#[RunTestsInSeparateProcesses]
class EntityTypeDefinitionTest extends KernelTestBase {

  /**
   * The entity types this module defines, keyed by ID, valued by class.
   */
  private const ENTITY_TYPES = [
    'color_schemes_rule' => 'Drupal\solo_utilities\Entity\ColorSchemesRule',
    'node_width' => 'Drupal\solo_utilities\Entity\NodeWidth',
  ];

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
   * Both entity types are discovered by the running core.
   *
   * On Drupal 10 this reads the annotation; on newer core, the attribute.
   */
  public function testEntityTypesAreDiscovered(): void {
    $manager = $this->container->get('entity_type.manager');
    foreach (self::ENTITY_TYPES as $id => $class) {
      $this->assertTrue($manager->hasDefinition($id), "Entity type $id is discovered.");
      $definition = $manager->getDefinition($id);
      $this->assertSame($class, $definition->getClass());
      $this->assertSame('solo_utilities', $definition->getProvider());
    }
    // The storage loads, which is what threw on Drupal 10 with 1.0.7.
    $this->assertSame([], $manager->getStorage('color_schemes_rule')->loadMultiple());
  }

  /**
   * The update installs the entity types a 1.0.7 install on Drupal 10 missed.
   *
   * Such a site has the hook_schema() table but neither entity type installed,
   * which is exactly the state a kernel test starts in once that table exists.
   */
  public function testUpdateInstallsMissingEntityTypes(): void {
    $this->installSchema('solo_utilities', ['solo_theme_node_width']);
    $update_manager = $this->container->get('entity.definition_update_manager');
    foreach (array_keys(self::ENTITY_TYPES) as $id) {
      $this->assertNull($update_manager->getEntityType($id), "$id starts uninstalled, as on a 1.0.7 Drupal 10 site.");
    }

    $this->container->get('module_handler')->loadInclude('solo_utilities', 'install');
    $message = solo_utilities_update_8003();
    $this->assertStringContainsString('color_schemes_rule, node_width', $message);

    $summary = $update_manager->getChangeSummary();
    foreach (array_keys(self::ENTITY_TYPES) as $id) {
      $this->assertNotNull($update_manager->getEntityType($id), "$id is installed by the update.");
      $this->assertArrayNotHasKey($id, $summary, "$id has no pending definition change.");
    }
    // The hook_schema() table survives, and the storage works on it.
    $this->assertTrue($this->container->get('database')->schema()->tableExists('solo_theme_node_width'));
    $storage = $this->container->get('entity_type.manager')->getStorage('node_width');
    $storage->create(['node_id' => 1, 'width_class' => 'w-wide'])->save();
    $this->assertCount(1, $storage->loadByProperties(['node_id' => 1]));

    // Running it again changes nothing.
    $this->assertSame('Both Solo Utilities entity types were already installed.', solo_utilities_update_8003());
  }

}
