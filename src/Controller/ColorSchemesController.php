<?php

namespace Drupal\solo_utilities\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Link;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Controller for handling AJAX operations and overview for Color Schemes Rules.
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
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('messenger'),
      $container->get('logger.factory')->get('solo_utilities')
    );
  }

  /**
   * Provides an overview of all theme configurations.
   *
   * @return array
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
        '#title' => $this->t('Available Configurations'),
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
   * Handles AJAX operations for enabling or disabling Color Schemes Rules.
   *
   * @param string $color_schemes_rule
   *   The ID of the library load rule entity.
   * @param string $op
   *   The operation being performed, either 'enable' or 'disable'.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response indicating success or failure.
   */
  public function ajaxOperation($color_schemes_rule, $op) {
    $account = $this->currentUser();

    // Check if the user has the necessary permission to manage rules.
    if (!$account->hasPermission('administer Color Schemes Rules')) {
      return new JsonResponse(['status' => 'error', 'message' => 'You are not authorized to perform this operation.'], 403);
    }

    $storage = $this->entityTypeManager()->getStorage('color_schemes_rule');
    $rule = $storage->load($color_schemes_rule);

    if ($rule) {
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

    return new JsonResponse(['status' => 'error', 'message' => 'Rule not found.']);
  }

  /**
   * Provides the operations available for a color schemes rule.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return array
   *   An array of operation links.
   */
  public function getOperations(EntityInterface $entity) {
    $operations = parent::getOperations($entity);

    // Add enable/disable operation.
    if ($entity->getStatus()) {
      $operations['disable'] = [
        'title' => $this->t('Disable'),
        'url' => Url::fromRoute('solo_utilities.ajax_operation', [
          'op' => 'disable',
          'color_schemes_rule' => $entity->id(),
        ]),
      ];
    }
    else {
      $operations['enable'] = [
        'title' => $this->t('Enable'),
        'url' => Url::fromRoute('solo_utilities.ajax_operation', [
          'op' => 'enable',
          'color_schemes_rule' => $entity->id(),
        ]),
      ];
    }

    return $operations;
  }

}
