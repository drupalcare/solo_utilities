<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Form\ThemeSettings;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\solo_utilities\Security\CssValueValidator;
use Drupal\solo_utilities\Security\UrlSafety;
use Drupal\solo_utilities\Settings\CompatibilityRules;
use Drupal\solo_utilities\Settings\SettingsReader;
use Drupal\solo_utilities\Theme\SoloThemeDetectionInterface;

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
    protected SoloThemeDetectionInterface $themeDetection,
    protected SettingsReader $settingsReader,
    protected UrlSafety $urlSafety,
    protected CssValueValidator $cssValues,
    protected MessengerInterface $messenger,
    protected ExtensionPathResolver $extensionPathResolver,
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
    $this->validateCompatibility($form_state);
    // Color pairs below WCAG contrast: errors when both colors are set here,
    // warnings naming the schemes that fail when one side comes from a scheme.
    (new ContrastValidator())->validateForm($form_state, $this->messenger, $this->extensionPathResolver->getPath('theme', 'solo') . '/schemes');
  }

  /**
   * Checks settings that cannot work together; the rules live in compat.yml.
   *
   * The rule ids (C3b, C5, C11) are the ones in solo-2.0-compat.yml, and the
   * generator check fails when one of them is not enforced here.
   */
  public function validateCompatibility(FormStateInterface $form_state): void {
    // C3b: force_show overrides every other preloader rule, even a disabled
    // preloader, so the preloader still shows.
    $rule = CompatibilityRules::rule('C3b');
    if ($this->conditionsHold($rule['when'], $form_state)) {
      $this->messenger->addWarning($this->t('"Force show for testing" is checked while the preloader is disabled, so the preloader still shows on every page, including admin pages and for logged-in users. Uncheck "Force show for testing" when you are done testing.'));
    }
    // C5: the login popup must sit above the sidebar menu and the fixed search.
    $rule = CompatibilityRules::rule('C5');
    $z_index = $form_state->getValue(explode('.', $rule['path']));
    if (is_numeric($z_index) && (int) $z_index < $rule['min']) {
      $form_state->setErrorByName(str_replace('.', '][', $rule['path']), $this->t('The popup login z-index must be at least @min, or the popup opens underneath the sidebar menu and the fixed search.', ['@min' => $rule['min']]));
    }
    // C11: a full menu that appears at a width where the page is still
    // stacked can overflow sideways.
    $rule = CompatibilityRules::rule('C11');
    $menu = $this->breakpointWidth($form_state->getValue(explode('.', $rule['smaller'])));
    $page = $this->breakpointWidth($form_state->getValue(explode('.', $rule['larger'])));
    if ($menu !== NULL && $page !== NULL && $menu < $page) {
      $this->messenger->addWarning($this->t('The menu breakpoint (@menu px) is smaller than the page layout breakpoint (@page px). Between those widths the full menu shows while the page columns are still stacked, and the menu can overflow the screen. Check the site at those widths.', [
        '@menu' => $menu,
        '@page' => $page,
      ]));
    }
  }

  /**
   * Tells whether every condition of a compat rule holds for the submission.
   *
   * @param list<array{path: string, value: string}> $conditions
   *   The conditions.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  private function conditionsHold(array $conditions, FormStateInterface $form_state): bool {
    foreach ($conditions as $condition) {
      $value = $form_state->getValue(explode('.', $condition['path']));
      $holds = match ($condition['value']) {
        'checked' => !empty($value),
        'unchecked' => empty($value),
        default => $value === $condition['value'],
      };
      if (!$holds) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Returns the pixel width in a breakpoint choice such as mn-576, or NULL.
   */
  private function breakpointWidth(mixed $choice): ?int {
    return is_string($choice) && preg_match('/^(?:mn|sb)-(\d+)$/', $choice, $match) ? (int) $match[1] : NULL;
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
   * the icons render gray with nothing logged.
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
