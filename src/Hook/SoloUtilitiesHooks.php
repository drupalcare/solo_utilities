<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Hook;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\node\NodeInterface;

/**
 * Tombstone: a compiled container from Solo Utilities 1.x names this class.
 *
 * Between a code update and the next rebuild that container still calls these
 * methods, passing its old arguments, which PHP ignores here. They do nothing
 * and carry no hook attribute. The live hooks are in SiteFeatureHooks.
 *
 * @internal
 */
final class SoloUtilitiesHooks {

  /**
   * Does nothing; see the class comment.
   */
  public function help($route_name, RouteMatchInterface $route_match): void {
  }

  /**
   * Does nothing; see the class comment.
   */
  public function preprocessPage(&$variables): void {
  }

  /**
   * Does nothing; see the class comment.
   */
  public function formNodeFormAlter(&$form, FormStateInterface $form_state, $form_id): void {
  }

  /**
   * Does nothing; see the class comment.
   */
  public function nodePresave(NodeInterface $node): void {
  }

  /**
   * Does nothing; see the class comment.
   */
  public function nodeInsert(NodeInterface $node): void {
  }

  /**
   * Does nothing; see the class comment.
   */
  public function formBlockFormAlter(array &$form, FormStateInterface $form_state): void {
  }

  /**
   * Does nothing; see the class comment.
   */
  public function preprocessBlock(array &$variables): void {
  }

}
