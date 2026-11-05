<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Css;

/**
 * The CSS and JavaScript one theme's settings produce.
 *
 * An empty string means there is nothing of that kind to output.
 *
 * @internal
 */
final class GeneratedAssets {

  /**
   * Constructs the result.
   *
   * @param string $settingsCss
   *   Rules built from validated values; goes in settings.<hash>.css.
   * @param string $customCss
   *   The stylesheet the site builder typed; goes in custom.<hash>.css.
   * @param string $inlineCss
   *   The typed CSS box, checked and minified; goes in inline.<hash>.css.
   * @param string $customJs
   *   The script the site builder typed; goes in custom.<hash>.js.
   * @param string $preloaderCss
   *   Custom properties of the preloader. Painted before any stylesheet loads,
   *   so it is emitted in the document head and never written to a file.
   * @param list<string> $refusedFields
   *   Dotted names of settings refused at output. Names only, never values.
   */
  public function __construct(
    public readonly string $settingsCss,
    public readonly string $customCss,
    public readonly string $inlineCss,
    public readonly string $customJs,
    public readonly string $preloaderCss,
    public readonly array $refusedFields,
  ) {}

}
