<?php

declare(strict_types=1);

namespace Drupal\Tests\solo_utilities\Kernel;

use Drupal\block\Entity\Block;
use Drupal\Core\Config\Schema\SchemaCheckTrait;
use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Covers the move of block title settings into third-party settings.
 *
 * WHY THIS EXISTS. The block title visibility mode and tag were stored in each
 * block's PLUGIN settings. No block plugin schema declares them, so every
 * block saved through this module's form failed config validation: 136
 * block configs on a real site. They now live in
 * third_party_settings.solo_utilities, which this module's schema declares,
 * and solo_utilities_update_8002() moves the values on existing blocks.
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */
#[RunTestsInSeparateProcesses]
class BlockTitleThirdPartySettingsTest extends KernelTestBase {

  use SchemaCheckTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'block',
    'field',
    'filter',
    'text',
    'node',
    'solo_utilities',
  ];

  /**
   * Writes a block in the legacy shape, bypassing config schema casting.
   *
   * @param string $id
   *   The block ID.
   * @param array<string, mixed> $extra
   *   Extra top-level keys for the block config, such as third-party settings.
   */
  protected function writeLegacyBlock(string $id, array $extra = []): void {
    $this->container->get('config.storage')->write("block.block.$id", $extra + [
      'uuid' => $this->container->get('uuid')->generate(),
      'langcode' => 'en',
      'status' => TRUE,
      'dependencies' => ['theme' => ['stark']],
      'id' => $id,
      'theme' => 'stark',
      'region' => 'content',
      'weight' => 0,
      'provider' => NULL,
      'plugin' => 'system_powered_by_block',
      'settings' => [
        'id' => 'system_powered_by_block',
        'label' => 'Powered by',
        'label_display' => 'visible',
        'provider' => 'system',
        'solo_block_title_visibility' => 'visually_hidden',
        'solo_block_title_tag' => 'h3',
      ],
      'visibility' => [],
    ]);
  }

  /**
   * Asserts a block's stored config passes schema validation.
   */
  protected function assertBlockSchemaValid(string $id): void {
    $name = "block.block.$id";
    $data = $this->container->get('config.storage')->read($name);
    $result = $this->checkConfigSchema($this->container->get('config.typed'), $name, $data);
    $this->assertTrue($result, 'Schema errors: ' . print_r($result, TRUE));
  }

  /**
   * The update moves both values and leaves valid config behind.
   */
  public function testMoveToThirdPartySettings(): void {
    $this->writeLegacyBlock('legacy');
    $migrator = $this->container->get('solo_utilities.block_title_visibility_migrator');

    $this->assertSame(1, $migrator->moveToThirdPartySettings());

    $block = Block::load('legacy');
    $this->assertSame('visually_hidden', $block->getThirdPartySetting('solo_utilities', 'solo_block_title_visibility'));
    $this->assertSame('h3', $block->getThirdPartySetting('solo_utilities', 'solo_block_title_tag'));
    $this->assertArrayNotHasKey('solo_block_title_visibility', $block->get('settings'));
    $this->assertArrayNotHasKey('solo_block_title_tag', $block->get('settings'));
    $this->assertBlockSchemaValid('legacy');
    $this->assertContains('solo_utilities', $block->getDependencies()['module']);

    // A second run finds nothing to do.
    $this->assertSame(0, $migrator->moveToThirdPartySettings());
  }

  /**
   * A value already in third-party settings is not overwritten by legacy.
   */
  public function testExistingThirdPartyValueWins(): void {
    $this->writeLegacyBlock('both', [
      'third_party_settings' => [
        'solo_utilities' => ['solo_block_title_visibility' => 'none'],
      ],
    ]);

    $this->container->get('solo_utilities.block_title_visibility_migrator')->moveToThirdPartySettings();

    $block = Block::load('both');
    $this->assertSame('none', $block->getThirdPartySetting('solo_utilities', 'solo_block_title_visibility'));
    $this->assertSame('h3', $block->getThirdPartySetting('solo_utilities', 'solo_block_title_tag'));
    $this->assertArrayNotHasKey('solo_block_title_visibility', $block->get('settings'));
  }

  /**
   * The block form's entity builder writes third-party settings only.
   */
  public function testEntityBuilderWritesThirdPartySettings(): void {
    $this->writeLegacyBlock('form');
    $block = Block::load('form');
    $form = [];
    $form_state = (new FormState())->setValues([
      'settings' => [
        'solo_block_title_visibility' => 'none',
        'solo_block_title_tag' => 'div',
      ],
    ]);

    _solo_utilities_block_form_entity_builder('block', $block, $form, $form_state);
    $block->save();

    $block = Block::load('form');
    $this->assertSame('none', $block->getThirdPartySetting('solo_utilities', 'solo_block_title_visibility'));
    $this->assertSame('div', $block->getThirdPartySetting('solo_utilities', 'solo_block_title_tag'));
    $this->assertArrayNotHasKey('solo_block_title_visibility', $block->get('settings'));
    $this->assertArrayNotHasKey('solo_block_title_tag', $block->get('settings'));
    $this->assertBlockSchemaValid('form');
  }

  /**
   * The read helper prefers third-party and falls back to the legacy place.
   */
  public function testReadHelperFallsBackToLegacy(): void {
    $this->writeLegacyBlock('read');
    $block = Block::load('read');
    $this->assertSame('h3', solo_utilities__block_title_setting($block, 'solo_block_title_tag'));

    $block->setThirdPartySetting('solo_utilities', 'solo_block_title_tag', 'h5');
    $this->assertSame('h5', solo_utilities__block_title_setting($block, 'solo_block_title_tag'));

    $this->assertNull(solo_utilities__block_title_setting($block, 'not_a_setting'));
  }

}
