<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Drush\Commands;

use Drupal\solo_utilities\Service\SubthemeGenerator;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush command that creates a Solo sub-theme in themes/custom.
 *
 * @internal
 */
final class SoloSubthemeCommands extends DrushCommands {

  public function __construct(
    private readonly SubthemeGenerator $generator,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('solo_utilities.subtheme_generator'));
  }

  /**
   * Creates a Solo sub-theme in themes/custom.
   *
   * @param string $machine_name
   *   The new theme's machine name.
   * @param array<string, mixed> $options
   *   The command options.
   *
   * @return int
   *   The exit code.
   */
  #[CLI\Command(name: 'solo:subtheme', aliases: ['solo-subtheme'])]
  #[CLI\Argument(name: 'machine_name', description: 'Machine name of the new theme: lowercase letters, numbers and underscores, starting with a letter, at most 50 characters. Use a short name unique to your site, for example mysite_theme.')]
  #[CLI\Option(name: 'label', description: 'Human-readable name. Defaults to the machine name in title case.')]
  #[CLI\Usage(name: 'drush solo:subtheme mysite_theme', description: 'Create themes/custom/mysite_theme from the Solo starter.')]
  #[CLI\Usage(name: 'drush solo:subtheme mysite_theme --label="My Site"', description: 'The same, with a custom human-readable name.')]
  public function subtheme(string $machine_name, array $options = ['label' => self::REQ]): int {
    $label = is_string($options['label']) && trim($options['label']) !== ''
      ? trim($options['label'])
      : $this->generator->defaultLabel($machine_name);

    $error = $this->generator->validate($machine_name, $label);
    if ($error !== NULL) {
      $this->logger()->error($error);
      return self::EXIT_FAILURE;
    }

    try {
      $leftovers = $this->generator->generate(
        $machine_name,
        $label,
        $this->generator->starterPath(),
        $this->generator->customThemesPath(),
      );
    }
    catch (\RuntimeException $e) {
      $this->logger()->error(dt('Could not create the sub-theme: !message', ['!message' => $e->getMessage()]));
      return self::EXIT_FAILURE;
    }

    foreach ($leftovers as $file) {
      $this->logger()->warning(dt('!file still mentions the starter name; rename it by hand.', ['!file' => $file]));
    }
    $this->io()->success(dt('Created !label (!name) in themes/custom/!name.', [
      '!label' => $label,
      '!name' => $machine_name,
    ]));
    $this->io()->writeln(dt('Next: drush theme:install !name && drush config:set system.theme default !name -y', ['!name' => $machine_name]));
    return self::EXIT_SUCCESS;
  }

}
