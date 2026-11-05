<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;
use Drupal\solo_utilities\Feature\BackToTop;
use Drupal\solo_utilities\Feature\HtmlPreprocessor;
use Drupal\solo_utilities\Feature\PagePreprocessor;
use Drupal\solo_utilities\Feature\Preloader;
use Drupal\solo_utilities\Settings\SettingsReader;
use Drupal\solo_utilities\Theme\SoloThemeDetectionInterface;

/**
 * Routes preprocess hooks to the features that own them.
 *
 * Core keeps one preprocess implementation per module for a theme hook, so
 * each hook has one method in the whole module. Order::Last keeps Solo after
 * other modules, where its theme hooks ran in 1.x.
 *
 * @internal
 */
final class PreprocessHooks {

  public function __construct(
    private readonly SoloThemeDetectionInterface $detection,
    private readonly SettingsReader $settingsReader,
    private readonly HtmlPreprocessor $htmlPreprocessor,
    private readonly PagePreprocessor $pagePreprocessor,
    private readonly Preloader $preloader,
    private readonly BackToTop $backToTop,
  ) {}

  /**
   * Implements hook_preprocess_HOOK() for html.html.twig.
   *
   * @param array<string, mixed> $variables
   *   The template variables.
   */
  #[Hook('preprocess_html', order: Order::Last)]
  public function preprocessHtml(array &$variables): void {
    if ($this->detection->isActive()) {
      $settings = $this->settingsReader->forTheme($this->detection->activeThemeName());
      $this->htmlPreprocessor->preprocess($variables, $settings);
      $this->preloader->preprocess($variables, $settings);
      $this->backToTop->preprocess($variables, $settings);
    }
  }

  /**
   * Implements hook_preprocess_HOOK() for page.html.twig.
   *
   * @param array<string, mixed> $variables
   *   The template variables.
   */
  #[Hook('preprocess_page', order: Order::Last)]
  public function preprocessPage(array &$variables): void {
    if ($this->detection->isActive()) {
      $this->pagePreprocessor->preprocess($variables, $this->settingsReader->forTheme($this->detection->activeThemeName()));
    }
  }

}
