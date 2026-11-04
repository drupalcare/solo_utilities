<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Context;

use Drupal\Core\Controller\TitleResolverInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\Core\Routing\AdminContext;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The one home of facts about the current request.
 *
 * Features ask here instead of reading the route, the request or the user. A
 * decision built from an answer must carry the matching cache context: each
 * method names its own in its description.
 *
 * @internal
 */
final class RequestContext {

  /**
   * Cache context of an answer that depends on the route.
   */
  public const CACHE_CONTEXT_ROUTE = 'route';

  /**
   * Cache context of an answer that depends on the request path.
   */
  public const CACHE_CONTEXT_PATH = 'url.path';

  /**
   * Cache context of an answer that depends on the user's roles.
   */
  public const CACHE_CONTEXT_ROLES = 'user.roles';

  /**
   * The request attribute holding the body classes, named as in 1.x.
   */
  private const BODY_CLASSES_ATTRIBUTE = 'solo_body_classes';

  /**
   * Routes that count as admin or editor work though core may not mark them.
   */
  private const EDITOR_ROUTES = [
    'system.admin',
    'system.admin_content',
    'system.admin_structure',
    'system.theme_settings',
  ];

  public function __construct(
    private readonly AdminContext $adminContext,
    private readonly RouteMatchInterface $routeMatch,
    private readonly RequestStack $requestStack,
    private readonly CurrentPathStack $currentPath,
    private readonly AccountInterface $currentUser,
    private readonly TitleResolverInterface $titleResolver,
  ) {}

  /**
   * The route's resolved title, or NULL with no route. Context: route.
   *
   * @return mixed
   *   Whatever the route's title resolves to: often a string, but it can be
   *   markup or a render array.
   */
  public function pageTitle(): mixed {
    $request = $this->requestStack->getCurrentRequest();
    $route = $this->routeMatch->getRouteObject();
    return $request !== NULL && $route !== NULL ? $this->titleResolver->getTitle($request, $route) : NULL;
  }

  /**
   * Whether core marks this route as an admin route. Context: route.
   */
  public function isAdminRoute(): bool {
    return $this->adminContext->isAdminRoute();
  }

  /**
   * Whether this request is admin or editor work. Contexts: route, url.path.
   *
   * Wider than core's admin flag: entity edit and delete forms and Layout
   * Builder count, whichever way the site marks them. A path under /admin also
   * counts when the route does not settle it.
   */
  public function isAdminOrEditorRoute(): bool {
    $route = $this->routeName();
    if (in_array($route, self::EDITOR_ROUTES, TRUE) || str_starts_with($route, 'layout_builder.')) {
      return TRUE;
    }
    if (str_starts_with($route, 'entity.') && (str_contains($route, '.edit_form') || str_contains($route, '.delete_form'))) {
      return TRUE;
    }
    return preg_match('#^/admin(?:/|$)#D', $this->requestPath()) === 1;
  }

  /**
   * The current route name, or '' when there is none. Context: route.
   */
  public function routeName(): string {
    return (string) $this->routeMatch->getRouteName();
  }

  /**
   * The path as requested, alias included. Context: url.path.
   */
  public function requestPath(): string {
    return $this->requestStack->getCurrentRequest()?->getPathInfo() ?? '';
  }

  /**
   * The internal path, such as /node/1. Context: url.path.
   */
  public function internalPath(): string {
    return $this->currentPath->getPath();
  }

  /**
   * Applies include and exclude path rules. Context: url.path.
   *
   * One path per line. A line starting with a minus excludes; any other line
   * includes. A rule matches its own path and everything below it. Excludes
   * are checked first.
   *
   * @param string $rules
   *   The rules text.
   * @param string|null $path
   *   The path to test, or NULL for the requested path.
   *
   * @return bool|null
   *   FALSE when excluded or outside every include, TRUE when included, NULL
   *   when the rules say nothing about it (none exist, or only other excludes).
   */
  public function matchesPathRules(string $rules, ?string $path = NULL): ?bool {
    $path = $this->normalizePath($path ?? $this->requestPath());
    $includes = [];
    foreach (preg_split('/\R/', $rules) ?: [] as $line) {
      $line = trim($line);
      if ($line === '') {
        continue;
      }
      if ($line[0] !== '-') {
        $includes[] = $this->normalizePath($line);
      }
      elseif ($this->pathIsAtOrBelow($path, $this->normalizePath(substr($line, 1)))) {
        return FALSE;
      }
    }
    if ($includes === []) {
      return NULL;
    }
    foreach ($includes as $include) {
      if ($this->pathIsAtOrBelow($path, $include)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether the current user is anonymous. Context: user.roles.
   */
  public function isAnonymous(): bool {
    return $this->currentUser->isAnonymous();
  }

  /**
   * The current user's id, 0 when anonymous. Context: user.
   */
  public function userId(): int {
    return (int) $this->currentUser->id();
  }

  /**
   * Whether the current user holds any listed role. Context: user.roles.
   *
   * @param list<string> $roles
   *   Role machine names.
   */
  public function hasAnyRole(array $roles): bool {
    return array_intersect($roles, $this->currentUser->getRoles()) !== [];
  }

  /**
   * The route's entity under a parameter name, or NULL. Context: route.
   *
   * NULL also for an unconverted parameter: on some routes `node` is an id
   * string, not a node, and calling a method on it was a fatal error in 1.x.
   */
  public function routeEntity(string $parameter): ?EntityInterface {
    $entity = $this->routeMatch->getParameter($parameter);
    return $entity instanceof EntityInterface ? $entity : NULL;
  }

  /**
   * A route parameter as a non-empty string, or NULL. Context: route.
   *
   * The converted value comes first: a route default such as `view_id` is a
   * parameter with no raw value, as raw values only cover path variables.
   */
  public function routeString(string $parameter): ?string {
    $value = $this->routeMatch->getParameter($parameter);
    if (!is_string($value)) {
      $value = $this->routeMatch->getRawParameter($parameter);
    }
    return is_string($value) && $value !== '' ? $value : NULL;
  }

  /**
   * The base path of the site, empty at the docroot. Context: url.site.
   */
  public function basePath(): string {
    return $this->requestStack->getCurrentRequest()?->getBasePath() ?? '';
  }

  /**
   * Remembers the body classes for features that render after the html one.
   *
   * @param list<string> $classes
   *   The body classes of this request.
   */
  public function rememberBodyClasses(array $classes): void {
    $this->requestStack->getCurrentRequest()?->attributes->set(self::BODY_CLASSES_ATTRIBUTE, $classes);
  }

  /**
   * The body classes remembered for this request.
   *
   * @return list<string>
   *   The classes; empty before the html template was preprocessed.
   */
  public function bodyClasses(): array {
    $classes = $this->requestStack->getCurrentRequest()?->attributes->get(self::BODY_CLASSES_ATTRIBUTE, []);
    return is_array($classes) ? $classes : [];
  }

  /**
   * Whether a normalized path is a rule's path or below it.
   */
  private function pathIsAtOrBelow(string $path, string $rule): bool {
    return $path === $rule || ($rule !== '' && str_starts_with($path . '/', $rule . '/'));
  }

  /**
   * Gives a path one leading slash and no trailing one; the root becomes ''.
   */
  private function normalizePath(string $path): string {
    $path = trim($path, "/ \t");
    return $path === '' ? '' : '/' . $path;
  }

}
