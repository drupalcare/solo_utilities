<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\filter\FilterPluginManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Renders the module README.md for the help page.
 *
 * @internal
 */
final class ReadmeRenderer implements ContainerInjectionInterface {

  use AutowireTrait;

  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
    #[Autowire(service: 'plugin.manager.filter')]
    private readonly FilterPluginManager $filterManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Renders the README, through the Markdown filter when that module is on.
   */
  public function render(): string {
    $text = (string) file_get_contents(dirname(__DIR__, 2) . '/README.md');

    if (!$this->moduleHandler->moduleExists('markdown')) {
      return '<pre>' . $text . '</pre>';
    }

    // Use the Markdown filter to render the README.
    $settings = $this->configFactory
      ->get('markdown.settings')
      ->getRawData();
    $config = ['settings' => $settings];
    $filter = $this->filterManager->createInstance('markdown', $config);
    return (string) $filter->process($text, 'en');
  }

}
