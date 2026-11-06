<?php

declare(strict_types=1);

namespace Drupal\solo_utilities;

use Drupal\Component\Plugin\Exception\ContextException;
use Drupal\Component\Plugin\Exception\MissingValueContextException;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Condition\ConditionInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Context\ContextHandlerInterface;
use Drupal\Core\Plugin\Context\ContextRepositoryInterface;
use Drupal\Core\Plugin\ContextAwarePluginInterface;
use Drupal\solo_utilities\Entity\ColorSchemesRule;

/**
 * Picks the color scheme from the Color Schemes Rules.
 *
 * Rules run in weight order (then label, as on the admin list); the first
 * enabled rule whose conditions pass wins.
 *
 * @internal
 */
final class ColorSchemesNegotiator {

  /**
   * Constructs a ColorSchemesNegotiator object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager service.
   * @param \Drupal\Core\Plugin\Context\ContextHandlerInterface $contextHandler
   *   The context handler service.
   * @param \Drupal\Core\Plugin\Context\ContextRepositoryInterface $contextRepository
   *   The context repository service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ContextHandlerInterface $contextHandler,
    private readonly ContextRepositoryInterface $contextRepository,
  ) {}

  /**
   * Evaluates the rules and returns the scheme with its cacheability.
   *
   * The cacheability covers every rule that was evaluated, so a role- or
   * path-based rule varies the page by role or path whether it matched or
   * not. Apply it to the render array that uses the result.
   *
   * @return \Drupal\solo_utilities\NegotiatedColorScheme
   *   The scheme of the first matching rule (NULL when none matched) and the
   *   cache metadata the answer depends on.
   */
  public function negotiate(): NegotiatedColorScheme {
    $cacheability = new CacheableMetadata();
    $cacheability->addCacheTags(['config:color_schemes_rule_list']);

    /** @var array<string, \Drupal\Core\Config\Entity\ConfigEntityInterface> $rules */
    $rules = $this->entityTypeManager->getStorage('color_schemes_rule')->loadMultiple();
    uasort($rules, static fn (ConfigEntityInterface $a, ConfigEntityInterface $b): int => (int) ColorSchemesRule::sort($a, $b));

    foreach ($rules as $rule) {
      if (!$rule instanceof ColorSchemesRule || !$rule->getStatus()) {
        continue;
      }
      $cacheability->addCacheableDependency($rule);

      $resolved_conditions = [];
      foreach ($rule->getVisibilityConditions() as $condition_id => $condition) {
        if ($condition instanceof ContextAwarePluginInterface) {
          try {
            $contexts = $this->contextRepository->getRuntimeContexts(
              array_values($condition->getContextMapping())
            );
            foreach ($contexts as $context) {
              $cacheability->addCacheableDependency($context);
            }
            $this->contextHandler->applyContextMapping($condition, $contexts);
          }
          catch (MissingValueContextException | ContextException) {
            // A condition without its context cannot pass, so the rule is
            // skipped. The skip still depends on what was gathered above.
            $cacheability->addCacheableDependency($condition);
            continue 2;
          }
        }
        // A condition that is not a cacheable dependency makes the result
        // uncacheable (max-age 0), which is the safe answer.
        $cacheability->addCacheableDependency($condition);
        $resolved_conditions[$condition_id] = $condition;
      }

      if ($this->resolveConditions($resolved_conditions, (string) $rule->getConjunction())) {
        $scheme = $rule->get('predefined_theme');
        return new NegotiatedColorScheme(is_string($scheme) ? $scheme : NULL, $cacheability);
      }
    }

    return new NegotiatedColorScheme(NULL, $cacheability);
  }

  /**
   * Evaluates the rules and returns the scheme to load.
   *
   * @return string|null
   *   The scheme of the first matching rule, or NULL if none matched.
   *
   * @deprecated in solo_utilities:2.0.0 and is removed from
   *   solo_utilities:3.0.0. Use ::negotiate() instead and apply its
   *   cacheability to the render array.
   * @see https://www.drupal.org/node/0000000
   */
  public function evaluateRules(): ?string {
    @trigger_error(__METHOD__ . '() is deprecated in solo_utilities:2.0.0 and is removed from solo_utilities:3.0.0. Use ::negotiate() instead and apply its cacheability to the render array. See https://www.drupal.org/node/0000000', E_USER_DEPRECATED);
    return $this->negotiate()->scheme;
  }

  /**
   * Resolves conditions according to the specified conjunction.
   *
   * An empty list passes for 'and' and fails for 'or', as before.
   *
   * @param array<string|int, \Drupal\Core\Condition\ConditionInterface> $conditions
   *   The conditions to evaluate.
   * @param string $conjunction
   *   The conjunction ('and' or 'or') to use when evaluating conditions.
   *
   * @return bool
   *   TRUE if conditions are met, FALSE otherwise.
   */
  private function resolveConditions(array $conditions, string $conjunction): bool {
    foreach ($conditions as $condition) {
      if (!$condition instanceof ConditionInterface) {
        continue;
      }
      // evaluate() ignores the negate flag; ConditionManager::execute() is
      // what normally applies it, so it is applied here.
      $result = $condition->evaluate();
      if ($condition->getConfiguration()['negate'] ?? FALSE) {
        $result = !$result;
      }
      if ($conjunction === 'or' && $result) {
        return TRUE;
      }
      if ($conjunction === 'and' && !$result) {
        return FALSE;
      }
    }

    return $conjunction === 'and';
  }

}
