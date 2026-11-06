<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\solo_utilities\Layout\LayoutResolver;
use Drupal\solo_utilities\Theme\SoloThemeDetectionInterface;

/**
 * The one home of the option lists on the theme settings form.
 *
 * @internal
 */
final class SettingsOptions {

  use StringTranslationTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ThemeExtensionList $themeList,
    private readonly string $appRoot,
    private readonly SoloThemeDetectionInterface $detection,
  ) {}

  /**
   * Options copied from Solo 1.x.
   *
   * @return array<int|string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by stored value.
   */
  public function mobileLayout(): array {
    return [
      'left_main_right' => $this->t('Default Layout (Left, Main, Right)'),
      'main_left_right' => $this->t('Main Content First, then Left and Right Sidebars'),
      'main_right_left' => $this->t('Main Content First, then Right and Left Sidebars'),
      'left_right_main' => $this->t('Left Sidebar First, then Right Sidebar and Main Content'),
      'right_left_main' => $this->t('Right Sidebar First, then Left Sidebar and Main Content'),
    ];
  }

  /**
   * Options copied from Solo 1.x.
   *
   * @return array<int|string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by stored value.
   */
  public function contentTypeWidth(): array {
    return [
      '' => $this->t('None'),
      'sw-800' => $this->t('800px / 50rem'),
      'sw-1024' => $this->t('1024px / 64rem'),
      'sw-1280' => $this->t('1280px / 80rem'),
      'sw-1440' => $this->t('1440px / 90rem'),
      'sw-1600' => $this->t('1600px / 100rem'),
      'sw-1920' => $this->t('1920px / 120rem'),
      'sw-2560' => $this->t('2560px / 160rem'),
      'sw-100' => $this->t('100%'),
    ];
  }

  /**
   * Options copied from Solo 1.x.
   *
   * @return array<int|string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by stored value.
   */
  public function siteWidth(): array {
    return [
      'sw-800' => $this->t('800px / 50rem'),
      'sw-1024' => $this->t('1024px / 64rem'),
      'sw-1280' => $this->t('1280px / 80rem'),
      'sw-1440' => $this->t('1440px / 90rem'),
      'sw-1600' => $this->t('1600px / 100rem'),
      'sw-1920' => $this->t('1920px / 120rem'),
      'sw-2560' => $this->t('2560px / 160rem'),
      'sw-100' => $this->t('100%'),
    ];
  }

  /**
   * Options copied from Solo 1.x.
   *
   * @return array<int|string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by stored value.
   */
  public function siteBreakpoints(): array {
    return [
      'sb-576' => $this->t('576px / 36rem'),
      'sb-768' => $this->t('768px / 48rem'),
      'sb-992' => $this->t('992px / 62rem'),
      'sb-1200' => $this->t('1200px / 75rem'),
      'sb-1400' => $this->t('1400px / 87.5rem'),
    ];
  }

  /**
   * Options copied from Solo 1.x.
   *
   * @return array<int|string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by stored value.
   */
  public function menuBreakpoints(): array {
    return [
      'mn-576' => $this->t('576px / 36rem'),
      'mn-768' => $this->t('768px / 48rem'),
      'mn-992' => $this->t('992px / 62rem'),
      'mn-1200' => $this->t('1200px / 75rem'),
      'mn-1400' => $this->t('1400px / 87.5rem'),
    ];
  }

  /**
   * Options copied from Solo 1.x.
   *
   * @return array<int|string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by stored value.
   */
  public function siteGlobalRegionsGap(): array {
    return [
      '12' => $this->t('12px / 0.75rem'),
      '14' => $this->t('14px / 0.875rem'),
      '16' => $this->t('Default 16px / 1rem'),
      '20' => $this->t('20px / 1.25rem'),
      '24' => $this->t('24px / 1.5rem'),
      '36' => $this->t('36px / 2.25rem'),
      '48' => $this->t('48px / 3rem'),
      '64' => $this->t('64px / 4rem'),
      'no' => $this->t('None'),
    ];
  }

  /**
   * Options copied from Solo 1.x.
   *
   * @return array<int|string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by stored value.
   */
  public function siteGlobalFontSize(): array {
    return [
      '12' => $this->t('12px / 0.75rem'),
      '14' => $this->t('14px / 0.875rem'),
      '16' => $this->t('Default 16px / 1rem'),
      '18' => $this->t('18px / 1.125rem'),
      '20' => $this->t('20px / 1.25rem'),
    ];
  }

  /**
   * Options copied from Solo 1.x.
   *
   * @return array<int|string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by stored value.
   */
  public function siteNameFontSize(): array {
    return [
      '' => $this->t('The Default Settings'),
      '18' => $this->t('Large 18px / 1.125rem'),
      '24' => $this->t('X Large 24px / 1.5rem'),
      '36' => $this->t('XX Large 36px / 2.25rem'),
      '48' => $this->t('XXX Large 48px / 3rem'),
      '64' => $this->t('Jumbo 64px / 4rem'),
    ];
  }

  /**
   * Options copied from Solo 1.x.
   *
   * @return array<int|string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by stored value.
   */
  public function pageTitleFontSize(): array {
    return [
      '' => $this->t('The Default Settings'),
      '18' => $this->t('Large 18px / 1.125rem'),
      '24' => $this->t('X Large 24px / 1.5rem'),
      '36' => $this->t('XX Large 36px / 2.25rem'),
      '48' => $this->t('XXX Large 48px / 3rem'),
      '64' => $this->t('Jumbo 64px / 4rem'),
    ];
  }

  /**
   * Options copied from Solo 1.x.
   *
   * @return array<int|string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by stored value.
   */
  public function animateSiteName(): array {
    return [
      '' => $this->t('Animation Effects - None'),
      'animate-site-aurora-wave' => $this->t('Aurora Wave'),
      'animate-site-cosmic-drift' => $this->t('Cosmic Drift'),
      'animate-site-dreamy-bloom' => $this->t('Dreamy Bloom'),
      'animate-site-echo-pulse' => $this->t('Echo Pulse'),
      'animate-site-electric-surge' => $this->t('Electric Surge'),
      'animate-site-gliding-letters' => $this->t('Gliding Letters'),
      'animate-site-gravity-defy' => $this->t('Gravity Defy'),
      'animate-site-holographic-spin' => $this->t('Holographic Spin'),
      'animate-site-moonlight-dance' => $this->t('Moonlight Dance'),
      'animate-site-mystic-fade' => $this->t('Mystic Fade'),
      'animate-site-phoenix-rise' => $this->t('Phoenix Rise'),
      'animate-site-quantum-leap' => $this->t('Quantum Leap'),
      'animate-site-rhythmic-slide' => $this->t('Rhythmic Slide'),
      'animate-site-sapphire-zoom' => $this->t('Sapphire Zoom'),
      'animate-site-serene-float' => $this->t('Serene Float'),
      'animate-site-shimmering-wave' => $this->t('Shimmering Wave'),
      'animate-site-solar-flare' => $this->t('Solar Flare'),
      'animate-site-starry-glide' => $this->t('Starry Glide'),
      'animate-site-swirling-entrance' => $this->t('Swirling Entrance'),
      'animate-site-timeless-flip' => $this->t('Timeless Flip'),
      'animate-site-velvet-slide' => $this->t('Velvet Slide'),
      'animate-site-whisper-fade' => $this->t('Whisper Fade'),
      'animate-site-whispering-shadows' => $this->t('Whispering Shadows'),
      'animate-site-zen-rotation' => $this->t('Zen Rotation'),
    ];
  }

  /**
   * Options copied from Solo 1.x.
   *
   * @return array<int|string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by stored value.
   */
  public function animatePageTitle(): array {
    return [
      '' => $this->t('Animation Effects - None'),
      'animate-title-aurora-wave' => $this->t('Aurora Wave'),
      'animate-title-cosmic-drift' => $this->t('Cosmic Drift'),
      'animate-title-dreamy-bloom' => $this->t('Dreamy Bloom'),
      'animate-title-echo-pulse' => $this->t('Echo Pulse'),
      'animate-title-electric-surge' => $this->t('Electric Surge'),
      'animate-title-gliding-letters' => $this->t('Gliding Letters'),
      'animate-title-gravity-defy' => $this->t('Gravity Defy'),
      'animate-title-holographic-spin' => $this->t('Holographic Spin'),
      'animate-title-moonlight-dance' => $this->t('Moonlight Dance'),
      'animate-title-mystic-fade' => $this->t('Mystic Fade'),
      'animate-title-phoenix-rise' => $this->t('Phoenix Rise'),
      'animate-title-quantum-leap' => $this->t('Quantum Leap'),
      'animate-title-rhythmic-slide' => $this->t('Rhythmic Slide'),
      'animate-title-sapphire-zoom' => $this->t('Sapphire Zoom'),
      'animate-title-serene-float' => $this->t('Serene Float'),
      'animate-title-shimmering-wave' => $this->t('Shimmering Wave'),
      'animate-title-solar-flare' => $this->t('Solar Flare'),
      'animate-title-starry-glide' => $this->t('Starry Glide'),
      'animate-title-swirling-entrance' => $this->t('Swirling Entrance'),
      'animate-title-timeless-flip' => $this->t('Timeless Flip'),
      'animate-title-velvet-slide' => $this->t('Velvet Slide'),
      'animate-title-whisper-fade' => $this->t('Whisper Fade'),
      'animate-title-whispering-shadows' => $this->t('Whispering Shadows'),
      'animate-title-zen-rotation' => $this->t('Zen Rotation'),
    ];
  }

  /**
   * Options copied from Solo 1.x.
   *
   * @return array<int|string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by stored value.
   */
  public function themeCategory(): array {
    return [
      'none' => $this->t('None'),
      'elegant_whites_and_neutrals' => $this->t('Elegant Whites and Neutrals'),
      'bold_and_dramatic_colors' => $this->t('Bold and Dramatic Colors'),
      'denim_influences' => $this->t('Denim Influences'),
      'earthy_and_smokey_tones' => $this->t('Earthy and Smokey Tones'),
      'midnight_and_twilight' => $this->t('Midnight and Twilight Themes'),
      'berry_and_fruit_tones' => $this->t('Berry and Fruit Tones'),
      'soft_and_subtle_hues' => $this->t('Soft and Subtle Hues'),
      'galactic_and_celestial' => $this->t('Galactic and Celestial Themes'),
      'urban_and_modern_hues' => $this->t('Urban and Modern Hues'),
      'ocean_and_deep_sea' => $this->t('Ocean and Deep Sea Themes'),
      'forest_and_nature_tones' => $this->t('Forest and Nature Tones'),
      'miscellaneous' => $this->t('Miscellaneous'),
    ];
  }

  /**
   * Options copied from Solo 1.x.
   *
   * @return array<int|string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by stored value.
   */
  public function predefinedTheme(): array {
    return [
      'midnight_and_twilight|solo-midnight-whispers' => $this->t('Midnight Whispers'),
      'midnight_and_twilight|solo-midnight-garden-trio' => $this->t('Midnight Garden Trio'),
      'midnight_and_twilight|solo-midnight-denim-swirl' => $this->t('Midnight Denim Swirl'),
      'midnight_and_twilight|solo-mystic-ash-twilight' => $this->t('Mystic Ash Twilight'),
      'midnight_and_twilight|solo-twilight-ash-mystique' => $this->t('Twilight Ash Mystique'),

      'ocean_and_deep_sea|solo-ocean-depths-mystery' => $this->t('Ocean Depths Mystery'),
      'ocean_and_deep_sea|solo-deep-sea-echo' => $this->t('Deep Sea Echo'),
      'ocean_and_deep_sea|solo-deep-ocean-shadows' => $this->t('Deep Ocean Shadows'),

      'berry_and_fruit_tones|solo-berry-blue-dreams' => $this->t('Berry Blue Dreams'),
      'berry_and_fruit_tones|solo-raspberry-nightfall' => $this->t('Raspberry Nightfall'),
      'berry_and_fruit_tones|solo-lemon-lime-forest' => $this->t('Lemon Lime Forest'),
      'berry_and_fruit_tones|solo-blueberry-skies-quartet' => $this->t('Blueberry Skies Quartet'),

      'urban_and_modern_hues|solo-urban-stone-shadows' => $this->t('Urban Stone Shadows'),
      'urban_and_modern_hues|solo-parisian-skyline' => $this->t('Parisian Skyline'),
      'urban_and_modern_hues|solo-nightfall-paris' => $this->t('Nightfall in Paris'),

      'forest_and_nature_tones|solo-enchanted-forest-zest' => $this->t('Enchanted Forest Zest'),
      'forest_and_nature_tones|solo-jungle-denim-fusion' => $this->t('Jungle Denim Fusion'),

      'galactic_and_celestial|solo-galactic-stone-hues' => $this->t('Galactic Stone Hues'),
      'galactic_and_celestial|solo-metallic-nebula' => $this->t('Metallic Nebula'),
      'galactic_and_celestial|solo-celestial-blue-depths' => $this->t('Celestial Blue Depths'),

      'elegant_whites_and_neutrals|solo-pristine-porcelain-glow' => $this->t('Pristine Porcelain Glow'),
      'elegant_whites_and_neutrals|solo-porcelain-brilliance' => $this->t('Porcelain Brilliance'),
      'elegant_whites_and_neutrals|solo-luminous-alabaster' => $this->t('Luminous Alabaster'),
      'elegant_whites_and_neutrals|solo-ivory-whisper' => $this->t('Ivory Whisper'),
      'elegant_whites_and_neutrals|solo-soft-silk-elegance' => $this->t('Soft Silk Elegance'),
      'elegant_whites_and_neutrals|solo-gentle-ivory-breeze' => $this->t('Gentle Ivory Breeze'),
      'elegant_whites_and_neutrals|solo-ethereal-white-harmony' => $this->t('Ethereal White Harmony'),
      'elegant_whites_and_neutrals|solo-classic-ivory-lace' => $this->t('Classic Ivory Lace'),
      'elegant_whites_and_neutrals|solo-silken-shadow-whisper' => $this->t('Silken Shadow Whisper'),

      'bold_and_dramatic_colors|solo-eclipse-noir' => $this->t('Eclipse Noir'),
      'bold_and_dramatic_colors|solo-nocturnal-velvet' => $this->t('Nocturnal Velvet'),
      'bold_and_dramatic_colors|solo-royal-azure-flame' => $this->t('Royal Azure Flame'),
      'bold_and_dramatic_colors|solo-flaming-sapphire' => $this->t('Flaming Sapphire'),
      'bold_and_dramatic_colors|solo-rustic-redwood-charm' => $this->t('Rustic Redwood Charm'),
      'bold_and_dramatic_colors|solo-crimson-denim-night' => $this->t('Crimson Denim Night'),
      'bold_and_dramatic_colors|solo-razzle-onyx-elegance' => $this->t('Razzle Onyx Elegance'),
      'bold_and_dramatic_colors|solo-midnight-navy-duo' => $this->t('Midnight Navy Duo'),

      'soft_and_subtle_hues|solo-blushing-magnolia' => $this->t('Blushing Magnolia'),
      'soft_and_subtle_hues|solo-magnolia-afternoon' => $this->t('Magnolia Afternoon'),
      'soft_and_subtle_hues|solo-cotton-candy-whispers' => $this->t('Cotton Candy Whispers'),

      'denim_influences|solo-denim-twilight' => $this->t('Denim Twilight'),
      'denim_influences|solo-indigo-pulse' => $this->t('Indigo Pulse'),
      'denim_influences|solo-crimson-denim-night' => $this->t('Crimson Denim Night'),
      'denim_influences|solo-jungle-denim-fusion' => $this->t('Jungle Denim Fusion'),
      'denim_influences|solo-midnight-denim-swirl' => $this->t('Midnight Denim Swirl'),
      'denim_influences|solo-salmon-denim-fade' => $this->t('Salmon Denim Fade'),

      'earthy_and_smokey_tones|solo-smokey-quartz-symphony' => $this->t('Smokey Quartz Symphony'),
      'earthy_and_smokey_tones|solo-gunmetal-echo' => $this->t('Gunmetal Echo'),
      'earthy_and_smokey_tones|solo-dusk-shadow-fusion' => $this->t('Dusk Shadow Fusion'),
      'earthy_and_smokey_tones|solo-porpoise-dream' => $this->t('Porpoise Dream'),
      'earthy_and_smokey_tones|solo-obsidian-cascade' => $this->t('Obsidian Cascade'),
      'earthy_and_smokey_tones|solo-charcoal-gray' => $this->t('Charcoal Gray'),

      'miscellaneous|solo-monochrome-splash' => $this->t('Monochrome Splash'),
    ];
  }

  /**
   * Options copied from Solo 1.x.
   *
   * @return array<int|string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by stored value.
   */
  public function globalFont(): array {
    return [
      '' => $this->t('None'),
      'solo-abel' => $this->t('Abel - sans-serif'),
      'solo-alegreya-sans' => $this->t('Alegreya Sans - sans-serif'),
      'solo-alegreya-sans-sc' => $this->t('Alegreya Sans SC - sans-serif'),
      'solo-amita' => $this->t('Amita - cursive'),
      'solo-archivo-narrow' => $this->t('Archivo Narrow - sans-serif'),
      'solo-arimo' => $this->t('Arimo - sans-serif'),
      'solo-arvo' => $this->t('Arvo - serif'),
      'solo-barlow' => $this->t('Barlow - sans-serif'),
      'solo-barlow-semi-condensed' => $this->t('Barlow Semi Condensed - sans-serif'),
      'solo-be-vietnam-pro' => $this->t('Be Vietnam Pro - sans-serif'),
      'solo-bitter' => $this->t('Bitter - serif'),
      'solo-cairo' => $this->t('Cairo - sans-serif'),
      'solo-comfortaa' => $this->t('Comfortaa - cursive'),
      'solo-courgette' => $this->t('Courgette - cursive'),
      'solo-domine' => $this->t('Domine - serif'),
      'solo-dosis' => $this->t('Dosis - sans-serif'),
      'solo-exo-2' => $this->t('EXO 2 - sans-serif'),
      'solo-fira-sans' => $this->t('Fira Sans - sans-serif'),
      'solo-glegoo' => $this->t('Glegoo - serif'),
      'solo-hind-siliguri' => $this->t('Hind Siliguri - sans-serif'),
      'solo-ibm-plex-sans' => $this->t('IBM Plex Sans - sans-serif'),
      'solo-inconsolata' => $this->t('Inconsolata - monospace'),
      'solo-josefin-sans' => $this->t('Josefin Sans - sans-serif'),
      'solo-kanit' => $this->t('Kanit - sans-serif'),
      'solo-kavivanar' => $this->t('Kavivanar - cursive'),
      'solo-lato' => $this->t('Lato - sans-serif'),
      'solo-libre-baskerville' => $this->t('Libre Baskerville - serif'),
      'solo-lora' => $this->t('Lora - serif'),
      'solo-merienda' => $this->t('Merienda - cursive'),
      'solo-merriweather' => $this->t('Merriweather - serif'),
      'solo-montserrat' => $this->t('Montserrat - sans-serif'),
      'solo-mukta' => $this->t('Mukta - sans-serif'),
      'solo-nunito' => $this->t('Nunito - sans-serif'),
      'solo-open-sans' => $this->t('Open Sans - sans-serif'),
      'solo-oranienbaum' => $this->t('Oranienbaum - serif'),
      'solo-oswald' => $this->t('Oswald - sans-serif'),
      'solo-oxygen' => $this->t('Oxygen - sans-serif'),
      'solo-playfair-display' => $this->t('Playfair Display - serif'),
      'solo-poppins' => $this->t('Poppins - sans-serif'),
      'solo-prompt' => $this->t('Prompt - sans-serif'),
      'solo-pt-serif' => $this->t('PT Serif - serif'),
      'solo-quicksand' => $this->t('Quicksand - sans-serif'),
      'solo-raleway' => $this->t('Raleway - sans-serif'),
      'solo-roboto' => $this->t('Roboto - sans-serif'),
      'solo-roboto-condensed' => $this->t('Roboto Condensed - sans-serif'),
      'solo-roboto-mono' => $this->t('Roboto Mono - monospace'),
      'solo-source-sans-3' => $this->t('Source Sans 3 - sans-serif'),
      'solo-spline-sans' => $this->t('Spline Sans - sans-serif'),
      'solo-titillium-web' => $this->t('Titillium Web - sans-serif'),
      'solo-ubuntu' => $this->t('Ubuntu - sans-serif'),
      'solo-work-sans' => $this->t('Work Sans - sans-serif'),
    ];
  }

  /**
   * Options copied from Solo 1.x.
   *
   * @return array<int|string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by stored value.
   */
  public function headingFont(): array {
    return [
      '' => $this->t('None'),
      'solo-abril-fatface' => $this->t('Abril Fatface - cursive'),
      'solo-aladin' => $this->t('Aladin - cursive'),
      'solo-anton' => $this->t('Anton - sans-serif'),
      'solo-archivo-black' => $this->t('Archivo Black - sans-serif'),
      'solo-bebas-neue' => $this->t('Bebas Neue - cursive'),
      'solo-big-shoulders-stencil-display' => $this->t('Big Shoulders Stencil Display - cursive'),
      'solo-big-shoulders-stencil-text' => $this->t('Big Shoulders Stencil Text - cursive'),
      'solo-black-ops-one' => $this->t('Black Ops One - cursive'),
      'solo-bree-serif' => $this->t('Bree Serif - serif'),
      'solo-cinzel' => $this->t('Cinzel - serif'),
      'solo-dancing-script' => $this->t('Dancing Script - cursive'),
      'solo-dm-serif-display' => $this->t('DM Serif Display - serif'),
      'solo-doppio-one' => $this->t('Doppio - sans-serif'),
      'solo-fjalla-one' => $this->t('Fjalla One - sans-serif'),
      'solo-francois-one' => $this->t('Francois One - sans-serif'),
      'solo-fredericka-the-great' => $this->t('Fredericka The Great - cursive'),
      'solo-fredoka' => $this->t('Fredoka - cursive'),
      'solo-hammersmith-one' => $this->t('Hammersmith One - sans-serif'),
      'solo-indie-flower' => $this->t('Indie Flower - cursive'),
      'solo-kalam' => $this->t('Kalam - cursive'),
      'solo-lobster-two' => $this->t('Lobster Two - cursive'),
      'solo-patrick-hand' => $this->t('Patrick Hand - cursive'),
      'solo-prosto-one' => $this->t('Prosto One - cursive'),
      'solo-righteous' => $this->t('Righteous - cursive'),
      'solo-staatliches' => $this->t('Staatliches - cursive'),
      'solo-yanone-kaffeesatz' => $this->t('Yanone Kaffeesatz - sans-serif'),
    ];
  }

  /**
   * Options copied from Solo 1.x.
   *
   * @return array<int|string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by stored value.
   */
  public function specialFont(): array {
    return [
      '' => $this->t('None'),
      'solo-akronim' => $this->t('akronim - cursive'),
      'solo-barriecito' => $this->t('barriecito - cursive'),
      'solo-bungee-shade' => $this->t('bungee-shade - cursive'),
      'solo-ceviche-one' => $this->t('ceviche-one - cursive'),
      'solo-creepster' => $this->t('creepster - cursive'),
      'solo-faster-one' => $this->t('faster-one - cursive'),
      'solo-finger-paint' => $this->t('finger-paint - cursive'),
      'solo-frijole' => $this->t('frijole - cursive'),
      'solo-irish-grover' => $this->t('irish-grover - cursive'),
      'solo-kumar-one' => $this->t('kumar-one - cursive'),
      'solo-londrina-outline' => $this->t('londrina-outline - cursive'),
      'solo-megrim' => $this->t('megrim - cursive'),
      'solo-metal-mania' => $this->t('metal-mania - cursive'),
      'solo-monoton' => $this->t('monoton - cursive'),
      'solo-moo-lah-lah' => $this->t('moo-lah-lah - cursive'),
      'solo-new-rocker' => $this->t('new-rocker - cursive'),
      'solo-nosifer' => $this->t('nosifer - cursive'),
      'solo-pirata-one' => $this->t('pirata-one - system-ui'),
      'solo-ruslan-display' => $this->t('ruslan-display - cursive'),
      'solo-rye' => $this->t('rye - cursive'),
      'solo-sancreek' => $this->t('sancreek - cursive'),
      'solo-slackey' => $this->t('slackey - cursive'),
      'solo-trade-winds' => $this->t('trade-winds - cursive'),
      'solo-train-one' => $this->t('train-one - cursive'),
      'solo-unifrakturmaguntia' => $this->t('unifrakturmaguntia - cursive'),
    ];
  }

  /**
   * Options copied from Solo 1.x.
   *
   * @return array<int|string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by stored value.
   */
  public function nameTitleGoogleFont(): array {
    return [
      '' => $this->t('None'),
      'solo-acme' => $this->t('Acme - sans-serif'),
      'solo-audiowide' => $this->t('Audiowide - sans-serif'),
      'solo-berkshire-swash' => $this->t('Berkshire Swash - serif'),
      'solo-contrail-one' => $this->t('Contrail One - sans-serif'),
      'solo-cookie' => $this->t('Cookie - cursive'),
      'solo-croissant-one' => $this->t('Croissant One - serif'),
      'solo-crushed' => $this->t('Crushed - sans-serif'),
      'solo-eagle-lake' => $this->t('Eagle Lake - serif'),
      'solo-fugaz-one' => $this->t('Fugaz One - sans-serif'),
      'solo-gilda-display' => $this->t('Gilda Display - serif'),
      'solo-girassol' => $this->t('Girassol - serif'),
      'solo-italiana' => $this->t('Italiana - sans-serif'),
      'solo-joan' => $this->t('Joan - sans-serif'),
      'solo-julius-sans-one' => $this->t('Julius Sans One - sans-serif'),
      'solo-katibeh' => $this->t('Katibeh - serif'),
      'solo-kaushan-script' => $this->t('Kaushan Script - cursive'),
      'solo-overlock-sc' => $this->t('Overlock SC - sans-serif'),
      'solo-poiret-one' => $this->t('Poiret One - sans-serif'),
      'solo-prata' => $this->t('Prata - serif'),
      'solo-redressed' => $this->t('Redressed - cursive'),
      'solo-tapestry' => $this->t('Tapestry - serif'),
      'solo-uncial-antiqua' => $this->t('Uncial Antiqua - system-ui'),
      'solo-unica-one' => $this->t('Unica One - sans-serif'),
      'solo-yatra-one' => $this->t('Yatra One - system-ui'),
      'solo-yeseva-one' => $this->t('Yeseva One - serif'),
    ];
  }

  /**
   * The node date formats, each showing today's date as an example.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by stored value.
   */
  public function siteNodeDateFormat(): array {
    return [
      'none' => $this->t('- None -'),
      'solo_date_s' => $this->t("Short: (@d_s)", ['@d_s' => date('j  F,  Y')]),
      'solo_date_m' => $this->t("Medium: (@d_m)", ['@d_m' => date('D F d, o')]),
      'solo_date_l' => $this->t("Long: (@d_l)", ['@d_l' => date('g:i A T, D F d, o')]),
    ];
  }

  /**
   * The color fields of a region, in 1.x's order.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by the key below `regions.<region>.colors`.
   */
  public function regionColors(): array {
    return [
      'background' => $this->t('Region background'),
      'text' => $this->t('Region text'),
      'heading' => $this->t('Headings (H1-H3)'),
      'link' => $this->t('Link text'),
      'link_hover' => $this->t('Link text (hover)'),
      'border' => $this->t('Borders'),
      'form_field_background' => $this->t('Form field background'),
      'menu_link_text' => $this->t('Menu link text'),
      'menu_link_text_hover' => $this->t('Menu link text (hover)'),
      'menu_link_background' => $this->t('Menu link background'),
      'menu_link_background_hover' => $this->t('Menu link background (hover)'),
      'button_text' => $this->t('Button text'),
      'button_text_hover' => $this->t('Button text (hover)'),
      'button_background' => $this->t('Button background'),
      'button_background_hover' => $this->t('Button background (hover)'),
    ];
  }

  /**
   * The labels of each region group and its regions.
   *
   * The regions of a group come from LayoutResolver::GROUP_REGIONS and their
   * labels from Solo's own info file, so a region is named in one place. Only
   * the group container labels live here.
   *
   * @return array<string, array{container: \Drupal\Core\StringTranslation\TranslatableMarkup, regions: array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>}>
   *   Labels keyed by group, then by region machine name.
   */
  public function groupLabels(): array {
    $containers = [
      'top' => $this->t('Top Container Wrapper'),
      'main' => $this->t('Main Container Wrapper'),
      'bottom' => $this->t('Bottom Container Wrapper'),
      'footer' => $this->t('Footer Container Wrapper'),
    ];
    $info = $this->soloRegions();
    $labels = [];
    foreach (LayoutResolver::GROUP_REGIONS as $group => $regions) {
      $labels[$group] = ['container' => $containers[$group], 'regions' => []];
      foreach ($regions as $region) {
        // Region labels are info-file strings, translated at display time as
        // core does for any theme region.
        // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
        $labels[$group]['regions'][$region] = $this->t($info[$region] ?? ucwords(str_replace('_', ' ', $region)));
      }
    }
    return $labels;
  }

  /**
   * The region labels Solo's info file declares.
   *
   * @return array<string, string>
   *   Untranslated labels keyed by region machine name; empty when the theme
   *   is not in the codebase.
   */
  private function soloRegions(): array {
    try {
      $regions = $this->themeList->getExtensionInfo('solo')['regions'] ?? [];
    }
    catch (\InvalidArgumentException) {
      return [];
    }
    return is_array($regions) ? array_map('strval', $regions) : [];
  }

  /**
   * The column layouts of a region group.
   *
   * @param int $columns
   *   Two, three or four columns.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by stored value.
   */
  public function columnLayouts(int $columns): array {
    return match ($columns) {
      2 => [
        'solo-col-1-1' => $this->t('50%, 50%'),
        'solo-col-2-1' => $this->t('67%, 33%'),
        'solo-col-3-1' => $this->t('75%, 25%'),
        'solo-col-4-1' => $this->t('80%, 20%'),
        'solo-col-1-2' => $this->t('33%, 67%'),
        'solo-col-1-3' => $this->t('25%, 75%'),
        'solo-col-1-4' => $this->t('20%, 80%'),
      ],
      3 => [
        'solo-col-1-1-1' => $this->t('33.3%, 33.3%, 33.3%'),
        'solo-col-1-2-1' => $this->t('25%, 50%, 25%'),
        'solo-col-1-3-1' => $this->t('20%, 60%, 20%'),
      ],
      4 => [
        'solo-col-1-1-1-1' => $this->t('25%, 25%, 25%, 25%'),
        'solo-col-1-2-2-1' => $this->t('16.6%, 33.3%, 33.3%, 16.6%'),
        'solo-col-2-1-1-2' => $this->t('33.3%, 16.6%, 16.6%, 33.3%'),
        'solo-col-1-2-1-2' => $this->t('16.6%, 33.3%, 16.6%, 33.3%'),
      ],
      default => throw new \InvalidArgumentException('A region group has two, three or four columns.'),
    };
  }

  /**
   * Region corner rounding.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by stored value.
   */
  public function rounded(): array {
    return [
      '' => $this->t('None'),
      'radius-4' => $this->t('4px / 0.25rem'),
      'radius-8' => $this->t('8px / 0.5rem'),
      'radius-16' => $this->t('16px / 1rem'),
      'radius-24' => $this->t('24px / 1.5rem'),
    ];
  }

  /**
   * Which pages show a region.
   *
   * @return array<int, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by stored value.
   */
  public function visibility(): array {
    return [
      0 => $this->t('Visible on all pages'),
      1 => $this->t('Visible on Home Page Only.'),
      2 => $this->t('Visible on All Pages Except Home Page.'),
    ];
  }

  /**
   * Region alignment.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by stored value.
   */
  public function alignment(): array {
    return [
      'region-left' => $this->t('Align Left'),
      'region-center' => $this->t('Align Center'),
      'region-right' => $this->t('Align Right'),
    ];
  }

  /**
   * The primary menu's mega menu layouts.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by stored value.
   */
  public function megamenuLayouts(): array {
    return [
      'm-1' => $this->t('1 Column - 100%'),
      'm-1-1' => $this->t('2 Columns - 50%, 50%'),
      'm-2-1' => $this->t('2 Columns - 67%, 33%'),
      'm-3-1' => $this->t('2 Columns - 75%, 25%'),
      'm-4-1' => $this->t('2 Columns - 80%, 20%'),
      'm-1-2' => $this->t('2 Columns - 33%, 67%'),
      'm-1-3' => $this->t('2 Columns - 25%, 75%'),
      'm-1-4' => $this->t('2 Columns - 20%, 80%'),
      'm-1-1-1' => $this->t('3 Columns - 33.3%, 33.3%, 33.3%'),
      'm-1-2-1' => $this->t('3 Columns - 25%, 50%, 25%'),
      'm-1-3-1' => $this->t('3 Columns - 20%, 60%, 20%'),
      'm-1-1-1-1' => $this->t('4 Columns - 25%, 25%, 25%, 25%'),
      'm-1-2-2-1' => $this->t('4 Columns - 16.6%, 33.3%, 33.3%, 16.6%'),
      'm-2-1-1-2' => $this->t('4 Columns - 33.3%, 16.6%, 16.6%, 33.3%'),
      'm-1-2-1-2' => $this->t('4 Columns - 16.6%, 33.3%, 16.6%, 33.3%'),
    ];
  }

  /**
   * The content types of the site.
   *
   * @return array<string, string>
   *   Labels keyed by machine name.
   */
  public function contentTypes(): array {
    return $this->labels('node_type');
  }

  /**
   * The menus of the site.
   *
   * @return array<string, string>
   *   Labels keyed by machine name.
   */
  public function menus(): array {
    return $this->labels('menu');
  }

  /**
   * The text formats a formatted text element may offer.
   *
   * @return list<string>
   *   Format machine names.
   */
  public function textFormats(): array {
    return array_keys($this->labels('filter_format'));
  }

  /**
   * The site's date formats, plus Solo's relative format.
   *
   * @return array<string, string|\Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by stored value.
   */
  public function commentDateFormats(): array {
    $options = $this->labels('date_format');
    $options['solo_relative'] = $this->t('Relative (e.g., "2 hours ago")');
    return $options;
  }

  /**
   * The select's option key for a stored color scheme.
   *
   * @param string $category
   *   The stored category.
   * @param string $name
   *   The stored scheme name.
   *
   * @return string
   *   The "category|name" key. A scheme listed under several categories uses
   *   the stored one, otherwise the first that lists it. An unknown name is
   *   returned as stored, so the select shows it as unset.
   */
  public function schemeOptionKey(string $category, string $name): string {
    $options = $this->predefinedTheme();
    if (isset($options[$category . '|' . $name])) {
      return $category . '|' . $name;
    }
    foreach (array_keys($options) as $key) {
      if (str_ends_with($key, '|' . $name)) {
        return $key;
      }
    }
    return $name;
  }

  /**
   * The menu templates a menu can be assigned, for one theme and its bases.
   *
   * @param string $theme
   *   The edited theme.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by template file name, with the default first.
   */
  public function menuTemplates(string $theme): array {
    $allowed = [
      'menu--responsive-menu-template-hover.html.twig' => $this->t('Responsive menu (hover)'),
      'menu--responsive-menu-template-click.html.twig' => $this->t('Responsive menu (click)'),
      'menu--primary-sidebar-menu-template-hover.html.twig' => $this->t('Sidebar menu (hover)'),
      'menu--primary-sidebar-menu-template-click.html.twig' => $this->t('Sidebar menu (click)'),
    ];
    // familyNames() is nearest-first; this lookup was always theme, then its
    // bases root-first, so the bases are reversed to keep that order.
    $family = $this->detection->familyNames($theme);
    $names = [$theme, ...array_reverse(array_slice($family, 1))];
    $found = [];
    foreach ($names as $name) {
      foreach (glob($this->appRoot . '/' . $this->themeList->getPath($name) . '/templates/navigation/menu--*.html.twig') ?: [] as $file) {
        $found[basename($file)] = TRUE;
      }
    }
    return ['' => $this->t('- Default -')] + array_intersect_key($allowed, $found);
  }

  /**
   * The labels of every entity of a type.
   *
   * @return array<string, string>
   *   Labels keyed by entity ID.
   */
  private function labels(string $entity_type): array {
    $labels = [];
    foreach ($this->entityTypeManager->getStorage($entity_type)->loadMultiple() as $id => $entity) {
      $labels[(string) $id] = (string) $entity->label();
    }
    return $labels;
  }

}
