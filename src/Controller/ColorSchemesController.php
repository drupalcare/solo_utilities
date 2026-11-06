<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\solo_utilities\ColorSchemesRuleInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Controller for handling Ajax operations and overview for Color Schemes Rules.
 *
 * @internal
 *
 * @phpstan-consistent-constructor
 */
class ColorSchemesController extends ControllerBase {

  /**
   * The Messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The Logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructs a ColorSchemesController object.
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger service.
   */
  public function __construct(MessengerInterface $messenger, LoggerChannelInterface $logger) {
    $this->messenger = $messenger;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('messenger'),
      $container->get('logger.factory')->get('solo_utilities')
    );
  }

  /**
   * Provides an overview of all theme configurations.
   *
   * @return array<string, mixed>
   *   A renderable array containing the overview page.
   */
  public function overview() {
    // Build a render array for the overview page with links to all
    // configurations.
    $build = [
      '#type' => 'markup',
      '#markup' => $this->t('Welcome to the Solo Utilities overview page. Here you can manage all theme configurations.'),
      'links' => [
        '#theme' => 'item_list',
        '#items' => [],
        '#title' => $this->t('Available configurations'),
      ],
    ];

    // Add a link to the Color Schemes Rules configuration.
    $build['links']['#items'][] = Link::fromTextAndUrl($this->t('Color Schemes Rules'), Url::fromRoute('entity.color_schemes_rule.collection'))->toRenderable();

    // Here, you can add more items as you add more features to Solo Utilities.
    // $build['links']['#items'][] =
    // Link::fromTextAndUrl($this->t('Another Configuration'),
    // Url::fromRoute('some.other.route'))->toRenderable();
    return $build;
  }

  /**
   * Handles Ajax operations for enabling or disabling Color Schemes Rules.
   *
   * @param \Drupal\solo_utilities\ColorSchemesRuleInterface $color_schemes_rule
   *   The library load rule entity, upcast by the route.
   * @param string $op
   *   The operation being performed, either 'enable' or 'disable'.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response indicating success or failure.
   */
  public function ajaxOperation(ColorSchemesRuleInterface $color_schemes_rule, string $op): JsonResponse {
    $account = $this->currentUser();

    // Check if the user has the necessary permission to manage rules.
    // The deprecated 1.x name still counts until drush updatedb moves it.
    if (!$account->hasPermission('administer color schemes rules') && !$account->hasPermission('administer Color Schemes Rules')) {
      return new JsonResponse(['status' => 'error', 'message' => 'You are not authorized to perform this operation.'], 403);
    }

    // The route upcasts the rule (parameters option), so it arrives loaded.
    $rule = $color_schemes_rule;

    switch ($op) {
      case 'enable':
        $rule->set('status', TRUE);
        break;

      case 'disable':
        $rule->set('status', FALSE);
        break;

      default:
        return new JsonResponse(['status' => 'error', 'message' => 'Invalid operation.']);
    }

    $rule->save();
    return new JsonResponse(['status' => 'success']);
  }

}
