<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Form\ThemeSettings;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\solo_utilities\Security\CssValueValidator;
use Drupal\solo_utilities\Security\UrlSafety;
use Drupal\solo_utilities\Settings\SettingsReader;
use Drupal\solo_utilities\Theme\SoloThemeDetection;

/**
 * Validates the theme settings form.
 *
 * Referenced from the form as an object callable, so it survives a cached form:
 * the services stay protected and not readonly for
 * DependencySerializationTrait.
 *
 * @internal
 */
final class SettingsFormValidator {

  use DependencySerializationTrait;
  use StringTranslationTrait;

  /**
   * The form state storage key of the copyright text before this save.
   */
  public const PREVIOUS_COPYRIGHT = 'solo_utilities_previous_copyright';

  public function __construct(
    protected SoloThemeDetection $themeDetection,
    protected SettingsReader $settingsReader,
    protected UrlSafety $urlSafety,
    protected CssValueValidator $cssValues,
  ) {}

  /**
   * Validates the submitted values.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Its state.
   */
  public function validate(array &$form, FormStateInterface $form_state): void {
    $theme = $this->themeDetection->editedThemeName($form_state);
    // Core's save runs before the submit handler, so what was stored has to be
    // kept now for the submit handler to compare file usage against.
    $form_state->set(self::PREVIOUS_COPYRIGHT, $this->settingsReader->forTheme($theme)->get('footer.copyright_formatted'));

    $this->storeSchemeName($form_state);
    $this->validatePreloaderLogo($form_state);
    $this->validateSocialIconColor($form_state);
    $this->validateFooterLink($form_state);
  }

  /**
   * Stores the chosen scheme's "category|name" option as its two keys.
   *
   * None stores the category and keeps the stored name. A stored scheme with no
   * option posts nothing, and then both keys stay as they are.
   */
  private function storeSchemeName(FormStateInterface $form_state): void {
    $value = $form_state->getValue(['color_scheme', 'name']);
    if (is_string($value) && str_contains($value, '|')) {
      [$category, $name] = explode('|', $value, 2);
      $form_state->setValue(['color_scheme', 'category'], $category);
      $form_state->setValue(['color_scheme', 'name'], $name);
      return;
    }
    $stored = $this->settingsReader->forTheme($this->themeDetection->editedThemeName($form_state))->get('color_scheme.name');
    $form_state->setValue(['color_scheme', 'name'], $stored);
    if ($value === 'none') {
      $form_state->setValue(['color_scheme', 'category'], 'none');
    }
  }

  /**
   * Checks a custom preloader logo path, when one is in use.
   */
  private function validatePreloaderLogo(FormStateInterface $form_state): void {
    $enabled = $form_state->getValue(['preloader', 'enabled']);
    $use_theme_logo = $form_state->getValue(['preloader', 'logo', 'use_theme']);
    if (empty($enabled) || !empty($use_theme_logo)) {
      return;
    }
    $path = trim((string) $form_state->getValue(['preloader', 'logo', 'url']));
    if ($path !== '' && $this->urlSafety->logoPath($path) === NULL) {
      $form_state->setErrorByName('preloader][logo][url', $this->t('The preloader logo path is not valid. Use a path like logo.svg (public filesystem), public://logo.svg, or themes/contrib/solo/logo.svg.'));
    }
  }

  /**
   * Checks the social icon color, which is printed into a style attribute.
   *
   * A bare custom property name is the trap: CSS discards the declaration and
   * the icons render grey with nothing logged.
   */
  private function validateSocialIconColor(FormStateInterface $form_state): void {
    $color = trim((string) $form_state->getValue(['social_icons', 'color']));
    if ($color === '') {
      return;
    }
    if ($this->cssValues->customPropertyName($color) !== NULL) {
      $form_state->setErrorByName('social_icons][color', $this->t('%value is a CSS custom property name, not a color, so browsers ignore it and the icons stay gray. Enter %suggestion instead.', [
        '%value' => $color,
        '%suggestion' => 'var(' . $color . ')',
      ]));
    }
    elseif ($this->cssValues->color($color) === NULL) {
      $form_state->setErrorByName('social_icons][color', $this->t('%value is not a color this setting accepts. Enter a hex color such as #1877f2, an rgb() value, transparent, currentcolor, or a custom property reference such as var(--solo-fb).', [
        '%value' => $color,
      ]));
    }
  }

  /**
   * Checks the footer link fields, unless formatted text replaces them.
   */
  private function validateFooterLink(FormStateInterface $form_state): void {
    if ($form_state->getValue(['footer', 'use_formatted_copyright'])) {
      return;
    }
    $url = (string) $form_state->getValue(['footer', 'credit_link', 'url']);
    if ($url !== '' && !UrlHelper::isValid($url, TRUE)) {
      $form_state->setErrorByName('footer][credit_link][url', $this->t('The URL %url is not valid.', ['%url' => $url]));
    }
    if ($url !== '' && empty($form_state->getValue(['footer', 'credit_link', 'text']))) {
      $form_state->setErrorByName('footer][credit_link][text', $this->t('You must enter link text if you provide a URL.'));
    }
  }

}
