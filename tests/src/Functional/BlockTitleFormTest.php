<?php

declare(strict_types=1);

namespace Drupal\Tests\solo_utilities\Functional;

use Drupal\block\Entity\Block;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Covers the block title settings end to end: form, storage and output.
 *
 * The block form stores the title visibility mode and tag as the block's
 * third-party settings, and Solo renders the title from them. The kernel
 * test covers the entity builder on its own; this one proves the form really
 * submits through it and that the page shows the result.
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */
#[RunTestsInSeparateProcesses]
class BlockTitleFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['block', 'node', 'solo_utilities'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'solo';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->config('solo.settings')
      ->set('enable_block_title_visibility', TRUE)
      ->save();
    $this->drupalPlaceBlock('system_powered_by_block', [
      'id' => 'powered',
      'label' => 'Powered title',
      'label_display' => 'visible',
      'region' => 'content',
    ]);
  }

  /**
   * Saving the form writes third-party settings and the page follows them.
   */
  public function testFormSavesThirdPartySettings(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer blocks']));

    $this->drupalGet('admin/structure/block/manage/powered');
    $this->assertSession()->fieldValueEquals('settings[solo_block_title_visibility]', 'visible');
    $this->assertSession()->fieldValueEquals('settings[solo_block_title_tag]', 'h2');

    $this->submitForm([
      'settings[solo_block_title_visibility]' => 'visually_hidden',
      'settings[solo_block_title_tag]' => 'h4',
    ], 'Save block');

    $block = Block::load('powered');
    $this->assertSame('visually_hidden', $block->getThirdPartySetting('solo_utilities', 'solo_block_title_visibility'));
    $this->assertSame('h4', $block->getThirdPartySetting('solo_utilities', 'solo_block_title_tag'));
    $this->assertArrayNotHasKey('solo_block_title_visibility', $block->get('settings'));
    $this->assertArrayNotHasKey('solo_block_title_tag', $block->get('settings'));

    // The form shows the stored values again.
    $this->drupalGet('admin/structure/block/manage/powered');
    $this->assertSession()->fieldValueEquals('settings[solo_block_title_visibility]', 'visually_hidden');
    $this->assertSession()->fieldValueEquals('settings[solo_block_title_tag]', 'h4');

    // And the page renders the title with them.
    $this->drupalGet('<front>');
    $this->assertSession()->elementTextEquals('css', 'h4.block__title.visually-hidden', 'Powered title');
  }

}
