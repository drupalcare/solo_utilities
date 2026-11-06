<?php

declare(strict_types=1);

namespace Drupal\Tests\solo_utilities\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\solo_utilities\Theme\SoloThemeDetectionInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Covers the Solo theme-hierarchy helpers against unknown theme names.
 *
 * WHY THIS EXISTS. The 1.x hierarchy helper (now SoloThemeDetection) called
 * ThemeHandler::getTheme() on whatever name it was handed. That method THROWS
 * UnknownExtensionException for a theme it does not know, so an unknown name
 * did not produce FALSE — it produced an uncaught exception, and because the
 * helper is reached from hook_preprocess_page() the result was HTTP 500 on
 * every page, raised from inside this module.
 *
 * The name comes from system.theme default, which can legitimately point at a
 * theme that has been uninstalled or removed from disk. This module had no
 * tests at all; these are the first.
 *
 * @group solo_utilities
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */
#[Group('solo_utilities')]
#[RunTestsInSeparateProcesses]
class SoloThemeHierarchyTest extends KernelTestBase {

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
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
  }

  /**
   * The detection service that owns the hierarchy questions.
   */
  private function detection(): SoloThemeDetectionInterface {
    return $this->container->get(SoloThemeDetectionInterface::class);
  }

  /**
   * An unknown default theme yields FALSE rather than a 500.
   *
   * This is the regression: before the guard, this call raised
   * UnknownExtensionException and took the page with it.
   */
  public function testUnknownDefaultThemeDoesNotThrow(): void {
    $this->config('system.theme')
      ->set('default', 'a_theme_that_is_not_installed')
      ->save();

    $this->assertFalse(
      $this->detection()->isDefault(),
      'An uninstalled default theme is simply not in the Solo hierarchy.'
    );
  }

  /**
   * The same holds when asking about an unknown theme directly.
   */
  public function testUnknownThemeNameDoesNotThrow(): void {
    $this->assertFalse(
      $this->detection()->isInHierarchy('a_theme_that_is_not_installed'),
      'An unknown theme name answers FALSE.'
    );
  }

  /**
   * Solo answers TRUE for itself without needing to be installed.
   */
  public function testSoloIsInItsOwnHierarchy(): void {
    $this->assertTrue(
      $this->detection()->isInHierarchy('solo'),
      'Solo is trivially in the Solo hierarchy.'
    );
  }

  /**
   * A missing or non-string name is refused at the trust boundary.
   *
   * The system.theme default value is configuration, so it can be absent or
   * malformed.
   */
  public function testEmptyAndNonStringNamesReturnFalse(): void {
    $this->assertFalse($this->detection()->isInHierarchy(''), 'Empty string.');
    $this->assertFalse($this->detection()->isInHierarchy(NULL), 'NULL.');
    $this->assertFalse($this->detection()->isInHierarchy(['solo']), 'Array.');
  }

  /**
   * A genuinely installed, non-Solo theme still answers FALSE.
   *
   * Without this the guard could pass by short-circuiting everything, and the
   * function would look correct while having stopped doing its job.
   */
  public function testInstalledNonSoloThemeReturnsFalse(): void {
    $this->container->get('theme_installer')->install(['stark']);

    $this->assertTrue(
      $this->container->get('theme_handler')->themeExists('stark'),
      'Stark really is installed, so the next assertion tests the walk, not the guard.'
    );
    $this->assertFalse(
      $this->detection()->isInHierarchy('stark'),
      'Stark is installed and is not in the Solo hierarchy.'
    );
  }

}
