<?php

declare(strict_types=1);

namespace Drupal\Tests\solo_utilities\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\solo_utilities\Service\SubthemeGenerator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Covers the generator behind `drush solo:subtheme`.
 *
 * WHY THIS EXISTS. The first version replaced only three of the six ways the
 * starter spells its own name, so a generated theme kept `solo-subtheme` in
 * its library and CSS/JS file names, `soloSubTheme` as its JS behavior and
 * "Solo sub-theme" in its schema label. The fixture below carries every
 * spelling; testEveryStarterSpellingIsReplaced() goes red when one is missed.
 *
 * @group solo_utilities
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */
#[Group('solo_utilities')]
#[RunTestsInSeparateProcesses]
class SubthemeGeneratorTest extends KernelTestBase {

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
   * A scratch folder holding the fixture starter and the generated themes.
   */
  private string $workDir;

  /**
   * The generator under test.
   */
  private SubthemeGenerator $generator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->workDir = sys_get_temp_dir() . '/solo-subtheme-test-' . bin2hex(random_bytes(6));
    $generator = $this->container->get('solo_utilities.subtheme_generator');
    $this->assertInstanceOf(SubthemeGenerator::class, $generator);
    $this->generator = $generator;
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    (new Filesystem())->remove($this->workDir);
    parent::tearDown();
  }

  /**
   * Names that break Drupal's naming rules are refused with a reason.
   */
  public function testInvalidNamesAreRefused(): void {
    $label = 'Valid Label';
    $this->assertNotNull($this->generator->validate('Bad-Name', $label), 'Uppercase and hyphen.');
    $this->assertNotNull($this->generator->validate('9theme', $label), 'Starts with a number.');
    $this->assertNotNull($this->generator->validate('css', $label), 'Reserved name.');
    $this->assertNotNull($this->generator->validate('system', $label), 'An existing module.');
    $this->assertNotNull($this->generator->validate(str_repeat('a', DRUPAL_EXTENSION_NAME_MAX_LENGTH + 1), $label), 'Over the length limit.');
    // A name no codebase ships, so the extension check cannot hit a theme
    // that happens to exist where the test runs.
    $free = 'zz_solo_subtheme_probe';
    $this->assertNotNull($this->generator->validate($free, 'Bad: label'), 'A label that needs YAML quoting.');

    $this->assertNull($this->generator->validate(str_repeat('z', DRUPAL_EXTENSION_NAME_MAX_LENGTH), $label), 'Exactly at the length limit.');
    $this->assertNull($this->generator->validate($free, 'My Site 2'));
  }

  /**
   * Every spelling of the starter's name is replaced, in names and contents.
   */
  public function testEveryStarterSpellingIsReplaced(): void {
    $starter = $this->createFixtureStarter();

    $leftovers = $this->generator->generate('my_site', 'My Site', $starter, $this->workDir);

    $this->assertSame([], $leftovers);
    $theme = $this->workDir . '/my_site';
    $this->assertFileExists($theme . '/my_site.info.yml');
    $this->assertFileExists($theme . '/my_site.libraries.yml');
    $this->assertFileExists($theme . '/css/my-site-style.css');
    $this->assertFileExists($theme . '/js/my-site-script.js');
    $this->assertFileExists($theme . '/src/Hook/MySiteHooks.php');
    $this->assertFileDoesNotExist($theme . '/README.md', 'The hand-rename README is removed.');

    $this->assertStringContainsString("name: My Site\n", (string) file_get_contents($theme . '/my_site.info.yml'));
    $this->assertStringContainsString('my_site/my-site-global', (string) file_get_contents($theme . '/my_site.info.yml'));
    $this->assertStringContainsString("my-site-global:\n", (string) file_get_contents($theme . '/my_site.libraries.yml'));
    $this->assertStringContainsString('Drupal.behaviors.mySite', (string) file_get_contents($theme . '/js/my-site-script.js'));
    $this->assertStringContainsString("label: 'My Site settings'", (string) file_get_contents($theme . '/config/schema/my_site.schema.yml'));
    $this->assertStringContainsString('namespace Drupal\my_site\Hook;', (string) file_get_contents($theme . '/src/Hook/MySiteHooks.php'));
    $this->assertStringContainsString('class MySiteHooks', (string) file_get_contents($theme . '/src/Hook/MySiteHooks.php'));
  }

  /**
   * A spelling the generator does not know is reported, not kept silently.
   */
  public function testUnknownSpellingIsReported(): void {
    $starter = $this->createFixtureStarter();
    file_put_contents($starter . '/templates/notes.txt', 'Built on the SOLO SUBTHEME starter.');

    $leftovers = $this->generator->generate('my_site', 'My Site', $starter, $this->workDir);

    $this->assertSame(['templates/notes.txt'], $leftovers);
  }

  /**
   * An existing folder is never overwritten.
   */
  public function testExistingDestinationIsRefused(): void {
    $starter = $this->createFixtureStarter();
    mkdir($this->workDir . '/my_site');
    file_put_contents($this->workDir . '/my_site/keep.txt', 'mine');

    try {
      $this->generator->generate('my_site', 'My Site', $starter, $this->workDir);
      $this->fail('An existing destination must be refused.');
    }
    catch (\RuntimeException) {
      // Expected.
    }
    $this->assertSame('mine', file_get_contents($this->workDir . '/my_site/keep.txt'));
  }

  /**
   * The starter shipped in Solo renames completely, when Solo is present.
   */
  public function testShippedStarterRenamesCompletely(): void {
    try {
      $starter = $this->generator->starterPath();
    }
    catch (\RuntimeException $e) {
      $this->markTestSkipped('The Solo theme is not available: ' . $e->getMessage());
    }

    $leftovers = $this->generator->generate('my_site', 'My Site', $starter, $this->workDir);

    $this->assertSame([], $leftovers, 'Every file of the shipped starter is renamed.');
    $this->assertFileExists($this->workDir . '/my_site/my_site.info.yml');
  }

  /**
   * Builds a starter folder carrying every spelling of the starter's name.
   *
   * @return string
   *   The fixture starter's absolute path.
   */
  private function createFixtureStarter(): string {
    $starter = $this->workDir . '/starter';
    $files = [
      'README.md' => "Copy this folder and rename every solo_subtheme.\n",
      'solo_subtheme.info.yml' => "name: Solo Sub-theme\ntype: theme\nbase theme: solo\ndescription: 'Solo Sub-theme is an empty theme.'\nlibraries:\n  - solo_subtheme/solo-subtheme-global\n",
      'solo_subtheme.libraries.yml' => "solo-subtheme-global:\n  css:\n    theme:\n      css/solo-subtheme-style.css: {}\n  js:\n    js/solo-subtheme-script.js: {}\n",
      'css/solo-subtheme-style.css' => "/* Styles for the Solo Sub-theme. */\n",
      'js/solo-subtheme-script.js' => "Drupal.behaviors.soloSubTheme = {};\n",
      'config/schema/solo_subtheme.schema.yml' => "solo_subtheme.settings:\n  type: mapping\n  label: 'Solo sub-theme settings'\n",
      'src/Hook/SoloSubthemeHooks.php' => "<?php\n\nnamespace Drupal\\solo_subtheme\\Hook;\n\nclass SoloSubthemeHooks {}\n",
      'templates/README.txt' => "Templates go here.\n",
    ];
    $filesystem = new Filesystem();
    foreach ($files as $path => $contents) {
      $filesystem->dumpFile($starter . '/' . $path, $contents);
    }
    return $starter;
  }

}
