<?php

declare(strict_types=1);

namespace Drupal\solo_utilities;

use Drupal\Component\Plugin\Exception\ContextException;
use Drupal\Component\Plugin\Exception\MissingValueContextException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Context\ContextHandlerInterface;
use Drupal\Core\Plugin\Context\ContextRepositoryInterface;
use Drupal\Core\Plugin\ContextAwarePluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Class ColorSchemesNegotiator.
 *
 * Determines which libraries to load based on the defined rules.
 */
class ColorSchemesNegotiator {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The request stack service.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The current route match service.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $currentRouteMatch;

  /**
   * The context handler service.
   *
   * @var \Drupal\Core\Plugin\Context\ContextHandlerInterface
   */
  protected $contextHandler;

  /**
   * The context repository service.
   *
   * @var \Drupal\Core\Plugin\Context\ContextRepositoryInterface
   */
  protected $contextRepository;

  /**
   * Constructs a ColorSchemesNegotiator object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack service.
   * @param \Drupal\Core\Routing\RouteMatchInterface $current_route_match
   *   The current route match service.
   * @param \Drupal\Core\Plugin\Context\ContextHandlerInterface $context_handler
   *   The context handler service.
   * @param \Drupal\Core\Plugin\Context\ContextRepositoryInterface $context_repository
   *   The context repository service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, RequestStack $request_stack, RouteMatchInterface $current_route_match, ContextHandlerInterface $context_handler, ContextRepositoryInterface $context_repository) {
    $this->entityTypeManager = $entity_type_manager;
    $this->requestStack = $request_stack;
    $this->currentRouteMatch = $current_route_match;
    $this->contextHandler = $context_handler;
    $this->contextRepository = $context_repository;
  }

  /**
   * Evaluates the rules and loads the appropriate libraries.
   *
   * @return string|null
   *   The library to be loaded based on the evaluated rules, or NULL if none
   *   are met.
   */
  public function evaluateRules() {
    $rules = $this->entityTypeManager->getStorage('color_schemes_rule')->loadMultiple();
    // \Drupal::logger('solo_utilities')
    // ->info('Evaluating library load rules.');
    foreach ($rules as $rule) {
      // Check if the rule is enabled.
      if (!$rule->getStatus()) {
        // \Drupal::logger('solo_utilities')->info('Rule {label} is disabled
        // and will not be processed.', ['label' => $rule->label()]);
        continue;
      }

      // Get visibility conditions, conjunction, and predefined theme.
      $conditions = $rule->getVisibilityConditions();
      $conjunction = $rule->getConjunction();
      $library = $rule->get('predefined_theme');

      // Prepare to resolve conditions.
      $resolved_conditions = [];
      foreach ($conditions as $condition_id => $condition) {
        if ($condition instanceof ContextAwarePluginInterface) {
          try {
            $contexts = $this->contextRepository->getRuntimeContexts(
              array_values($condition->getContextMapping())
            );
            $this->contextHandler->applyContextMapping($condition, $contexts);
          }
          catch (MissingValueContextException | ContextException $e) {
            // \Drupal::logger('solo_utilities')->warning('Condition {plugin_id}
            // skipped due to missing or invalid context: {context_name}', [
            // 'plugin_id' => $condition->getPluginId(),
            // 'context_name' => $condition_id,
            // ]);
            continue 2;
          }
        }
        $resolved_conditions[$condition_id] = $condition;
      }

      // Resolve conditions according to conjunction.
      if ($this->resolveConditions($resolved_conditions, $conjunction) !== FALSE) {
        // \Drupal::logger('solo_utilities')->info('All conditions met for
        // rule {label}.', ['label' => $rule->label()]);
        return $library;
      }
      else {
        // \Drupal::logger('solo_utilities')->info('Conditions not met for
        // rule {label}.', ['label' => $rule->label()]);
      }
    }

    return NULL;
  }

  /**
   * Resolves conditions according to the specified conjunction.
   *
   * @param array $conditions
   *   The array of conditions to evaluate.
   * @param string $conjunction
   *   The conjunction ('and' or 'or') to use when evaluating conditions.
   *
   * @return bool
   *   TRUE if conditions are met, FALSE otherwise.
   */
  protected function resolveConditions(array $conditions, $conjunction) {
    $conditions_met = ($conjunction === 'and') ? TRUE : FALSE;

    foreach ($conditions as $condition) {
      // Check if the condition is negated.
      $negate = $condition->getConfiguration()['negate'] ?? FALSE;
      $result = $condition->evaluate();

      if ($negate) {
        $result = !$result;
      }

      // Log the result of each condition evaluation.
      // \Drupal::logger('solo_utilities')->info('Condition {plugin_id}
      // evaluated to: {result}', [
      // 'plugin_id' => $condition->getPluginId(),
      // 'result' => $result ? 'TRUE' : 'FALSE',
      // ]);.
      if ($conjunction === 'or' && $result) {
        return TRUE;
      }
      if ($conjunction === 'and' && !$result) {
        return FALSE;
      }
    }

    return $conditions_met;
  }

}
