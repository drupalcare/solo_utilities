<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Service;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Carries the node form's custom width value to the node save hooks.
 *
 * @internal
 */
final class NodeWidthForm implements ContainerInjectionInterface {

  use AutowireTrait;

  public function __construct(
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * Submit handler: puts the chosen width into the request for the save hooks.
   *
   * @param array<string, mixed> $form
   *   The node form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submit(array $form, FormStateInterface $form_state): void {
    // Ensure custom width is saved to the node.
    $form_object = $form_state->getFormObject();
    $node = $form_object instanceof EntityFormInterface ? $form_object->getEntity() : NULL;
    if ($node instanceof NodeInterface) {
      $custom_width = $form_state->getValue('custom_node_width');
      $this->requestStack->getCurrentRequest()->request->set('custom_node_width', $custom_width);
    }
  }

}
