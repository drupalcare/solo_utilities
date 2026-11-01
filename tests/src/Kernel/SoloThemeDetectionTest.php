<?php

declare(strict_types=1);

namespace Drupal\Tests\solo_utilities\Kernel;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\solo_utilities\Access\ColorSchemesAccessCheck;
use Drupal\solo_utilities\Hook\SoloUtilitiesHooks;
use Drupal\solo_utilities\Service\SoloThemeDetection;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Routing\Route;

/**
 * The Solo theme detection service, and the features that depend on it.
 *
 * WHY THIS EXISTS. "Is Solo in charge" was answered by two procedural helpers
 * that always read system.theme:default. Two different questions were one
 * function: "is Solo rendering this request" (active theme) and "is Solo the
 * front-end theme" (default). Guarding every hook with the first would have
 * removed the node width field, because the node form renders under the ADMIN
 * theme. This test pins both answers, under an admin theme, an unknown theme, a
 * sub-theme and Solo itself, and proves the node width feature still works with
 * an admin theme active.
 *
 * The fixture themes live in tests/themes: "solo" is a stand-in base theme.
 *
 * @group solo_utilities
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */
#[Group('solo_utilities')]
#[RunTestsInSeparateProcesses]
class SoloThemeDetectionTest extends KernelTestBase {

  use UserCreationTrait;

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
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('node_width');
    $this->installSchema('node', ['node_access']);
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $this->container->get('theme_installer')->install([
      'claro',
      'solo',
      'solo_utilities_test_sub',
      'solo_utilities_test_other',
    ]);
  }

  /**
   * Makes a theme the active one, as core does while rendering.
   */
  private function activate(string $theme): void {
    $this->container->get('theme.manager')->setActiveTheme($this->container->get('theme.initialization')->getActiveThemeByName($theme));
  }

  /**
   * Sets the front-end theme.
   */
  private function setDefaultTheme(string $theme): void {
    $this->config('system.theme')->set('default', $theme)->save();
  }

  /**
   * The detection service.
   */
  private function detection(): SoloThemeDetection {
    return $this->container->get(SoloThemeDetection::class);
  }

  /**
   * Hierarchy answers: Solo, a sub-theme, others, unknown, malformed.
   */
  public function testHierarchy(): void {
    $detection = $this->detection();
    $this->assertTrue($detection->isInHierarchy('solo'), 'Solo.');
    $this->assertTrue($detection->isInHierarchy('solo_utilities_test_sub'), 'Sub-theme of Solo.');
    $this->assertFalse($detection->isInHierarchy('claro'), 'Admin theme.');
    $this->assertFalse($detection->isInHierarchy('solo_utilities_test_other'), 'Theme on another base.');
    $this->assertFalse($detection->isInHierarchy('a_theme_that_is_not_installed'), 'Unknown theme.');
    $this->assertFalse($detection->isInHierarchy(''), 'Empty.');
    $this->assertFalse($detection->isInHierarchy(NULL), 'NULL.');
    $this->assertFalse($detection->isInHierarchy(['solo']), 'Array.');
  }

  /**
   * Active and default are separate questions with separate answers.
   *
   * @param string $active
   *   The theme rendering the request.
   * @param string $default
   *   The front-end theme.
   * @param bool $expect_active
   *   Expected isActive().
   * @param bool $expect_default
   *   Expected isDefault().
   */
  #[DataProvider('scenarioProvider')]
  public function testActiveAndDefault(string $active, string $default, bool $expect_active, bool $expect_default): void {
    $this->setDefaultTheme($default);
    $this->activate($active);
    $this->assertSame($expect_active, $this->detection()->isActive(), 'isActive()');
    $this->assertSame($expect_default, $this->detection()->isDefault(), 'isDefault()');
  }

  /**
   * Scenarios: admin theme over Solo, sub-theme, Solo, another theme.
   *
   * @return array<string, array{string, string, bool, bool}>
   *   Active theme, default theme, expected isActive, expected isDefault.
   */
  public static function scenarioProvider(): array {
    return [
      'admin theme active, Solo default' => ['claro', 'solo', FALSE, TRUE],
      'admin theme active, sub-theme default' => ['claro', 'solo_utilities_test_sub', FALSE, TRUE],
      'sub-theme active and default' => ['solo_utilities_test_sub', 'solo_utilities_test_sub', TRUE, TRUE],
      'Solo active and default' => ['solo', 'solo', TRUE, TRUE],
      'Solo active, other default' => ['solo', 'claro', TRUE, FALSE],
      'other theme everywhere' => ['solo_utilities_test_other', 'solo_utilities_test_other', FALSE, FALSE],
    ];
  }

  /**
   * An unknown default theme is "not Solo", not an HTTP 500.
   */
  public function testUnknownDefaultThemeDoesNotThrow(): void {
    $this->setDefaultTheme('a_theme_that_is_not_installed');
    $this->assertFalse($this->detection()->isDefault());
    // The page hook that took every page down in 1.0.7.
    $variables = [];
    $this->container->get(SoloUtilitiesHooks::class)->preprocessPage($variables);
    $this->assertSame([], $variables);
  }

  /**
   * Active answers are not cached across a mid-request theme switch.
   */
  public function testActiveThemeSwitchIsFollowed(): void {
    $this->activate('claro');
    $this->assertFalse($this->detection()->isActive());
    $this->activate('solo_utilities_test_sub');
    $this->assertTrue($this->detection()->isActive());
    $this->activate('claro');
    $this->assertFalse($this->detection()->isActive());
  }

  /**
   * The access check follows the default theme while an admin theme renders.
   */
  public function testAccessCheckUsesDefaultThemeUnderAdminTheme(): void {
    $check = $this->container->get('solo_utilities.access_check');
    $this->assertInstanceOf(ColorSchemesAccessCheck::class, $check);
    $route = new Route('/admin/config/system/solo_utilities');
    $user = $this->createUser(['administer Color Schemes Rules']);

    $this->activate('claro');

    $this->setDefaultTheme('solo_utilities_test_sub');
    $this->assertTrue($check->access($route, $user)->isAllowed(), 'Admin theme active, Solo-based default: allowed.');

    $this->setDefaultTheme('claro');
    $this->assertTrue($check->access($route, $user)->isForbidden(), 'No Solo theme: forbidden.');

    $this->setDefaultTheme('a_theme_that_is_not_installed');
    $this->assertTrue($check->access($route, $user)->isForbidden(), 'Unknown default theme: forbidden, no exception.');

    $this->setDefaultTheme('solo_utilities_test_sub');
    $nobody = $this->createUser([]);
    $this->assertTrue($check->access($route, $nobody)->isForbidden(), 'Solo-based default but no permission: forbidden.');
  }

  /**
   * The node width field shows on the node form while an admin theme renders.
   *
   * This is finding F1: a guard on the ACTIVE theme would remove it.
   */
  public function testNodeWidthFieldShowsUnderAdminTheme(): void {
    $this->setDefaultTheme('solo_utilities_test_sub');
    $this->config('solo_utilities_test_sub.settings')->set('enable_custom_node_width', TRUE)->save();
    $this->activate('claro');

    $form = $this->alterNodeForm();
    $this->assertArrayHasKey('custom_node_width', $form, 'The width field is on the node form under the admin theme.');
    $this->assertSame(['solo_utilities__node_form_submit'], $form['#submit']);

    // Control: the same setup with a non-Solo default has no field.
    $this->setDefaultTheme('claro');
    $this->assertArrayNotHasKey('custom_node_width', $this->alterNodeForm(), 'No Solo default, no field.');
  }

  /**
   * A saved width is stored while an admin theme renders.
   */
  public function testNodeWidthIsSavedUnderAdminTheme(): void {
    $this->setDefaultTheme('solo_utilities_test_sub');
    $this->activate('claro');
    $this->container->get('request_stack')->getCurrentRequest()->request->set('custom_node_width', 'sw-1024');

    $node = Node::create(['type' => 'page', 'title' => 'Wide']);
    $node->save();

    $stored = $this->container->get('entity_type.manager')->getStorage('node_width')->loadByProperties(['node_id' => $node->id()]);
    $this->assertCount(1, $stored, 'One width row was stored.');
    $row = reset($stored);
    $this->assertInstanceOf(FieldableEntityInterface::class, $row);
    $this->assertSame('sw-1024', $row->get('width_class')->value);

    // Control: with an unknown default theme nothing is stored.
    $this->setDefaultTheme('a_theme_that_is_not_installed');
    $other = Node::create(['type' => 'page', 'title' => 'Plain']);
    $other->save();
    $this->assertSame([], $this->container->get('entity_type.manager')->getStorage('node_width')->loadByProperties(['node_id' => $other->id()]));
  }

  /**
   * Runs the module's real node form alter and returns the form.
   *
   * @return array<string, mixed>
   *   The altered form.
   */
  private function alterNodeForm(): array {
    $node = Node::create(['type' => 'page', 'title' => 'Form']);
    $form_object = $this->container->get('entity_type.manager')->getFormObject('node', 'default');
    $form_object->setEntity($node);
    $form_state = new FormState();
    $form_state->setFormObject($form_object);
    $form = ['#submit' => []];
    $form_id = 'node_page_form';
    $this->container->get('module_handler')->alter('form_node_form', $form, $form_state, $form_id);
    return $form;
  }

}
