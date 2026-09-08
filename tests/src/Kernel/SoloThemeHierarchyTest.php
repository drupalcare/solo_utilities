<?php

declare(strict_types=1);

namespace Drupal\Tests\solo_utilities\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Covers the Solo theme-hierarchy helpers against unknown theme names.
 *
 * WHY THIS EXISTS. solo_utilities__is_solo_in_theme_hierarchy() called
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
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */
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
      solo_utilities__is_solo_or_sub_in_hierarchy_active(),
      'An uninstalled default theme is simply not in the Solo hierarchy.'
    );
  }

  /**
   * The same holds when asking about an unknown theme directly.
   */
  public function testUnknownThemeNameDoesNotThrow(): void {
    $this->assertFalse(
      solo_utilities__is_solo_in_theme_hierarchy('a_theme_that_is_not_installed'),
      'An unknown theme name answers FALSE.'
    );
  }

  /**
   * Solo answers TRUE for itself without needing to be installed.
   */
  public function testSoloIsInItsOwnHierarchy(): void {
    $this->assertTrue(
      solo_utilities__is_solo_in_theme_hierarchy('solo'),
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
    $this->assertFalse(solo_utilities__is_solo_in_theme_hierarchy(''), 'Empty string.');
    $this->assertFalse(solo_utilities__is_solo_in_theme_hierarchy(NULL), 'NULL.');
    $this->assertFalse(solo_utilities__is_solo_in_theme_hierarchy(['solo']), 'Array.');
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
      solo_utilities__is_solo_in_theme_hierarchy('stark'),
      'Stark is installed and is not in the Solo hierarchy.'
    );
  }

}
