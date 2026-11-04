<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Feature;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\solo_utilities\Settings\BoundThemeSettings;

/**
 * Turns on the menu debug tools and warns that they are on.
 *
 * @internal
 */
final class MenuDebugNotice {

  use StringTranslationTrait;

  public function __construct(
    private readonly MessengerInterface $messenger,
    TranslationInterface $stringTranslation,
  ) {
    $this->setStringTranslation($stringTranslation);
  }

  /**
   * Attaches the debug tools when the setting is on.
   *
   * @param array<string, mixed> $attachments
   *   The page attachments.
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The active theme's settings.
   */
  public function attach(array &$attachments, BoundThemeSettings $settings): void {
    if (!$settings->bool('menus.debug.enabled')) {
      return;
    }
    $verbosity = $settings->string('menus.debug.verbosity') ?: 'normal';
    $attachments['#attached']['drupalSettings']['solo']['debug'] = TRUE;
    $attachments['#attached']['drupalSettings']['solo']['debug_verbosity'] = $verbosity;
    $attachments['#attached']['library'][] = 'solo/solo-debug';
    $this->messenger->addWarning($this->t('<strong>SOLO DEBUG MODE ACTIVE</strong> (Verbosity: @verbosity) — Development tools are enabled. Open browser console and run showReport() for menu diagnostics. <a class="warning" href="@settings_url">Disable debug mode</a> before going to production.', [
      '@verbosity' => ucfirst($verbosity),
      '@settings_url' => Url::fromRoute('system.theme_settings_theme', ['theme' => $settings->themeName()])->toString(),
    ]));
  }

}
