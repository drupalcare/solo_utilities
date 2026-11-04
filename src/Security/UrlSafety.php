<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Security;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\File\Exception\InvalidStreamWrapperException;
use Drupal\Core\File\FileUrlGeneratorInterface;

/**
 * Decides whether a stored path or URL may reach the page. Fails closed.
 *
 * Every method returns the safe form of the value, or NULL. An empty value is
 * NULL too: whether a blank field is acceptable is the caller's decision.
 *
 * Tighter than 1.x, on purpose: a protocol-relative `//host` passed as
 * "root-relative" there, which let it point at any host.
 *
 * @internal
 */
final class UrlSafety {

  /**
   * Longest accepted value.
   */
  private const MAX_LENGTH = 2048;

  /**
   * Hosts a font stylesheet may come from unless the caller lists its own.
   */
  private const FONT_HOSTS = ['fonts.googleapis.com'];

  public function __construct(
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * A logo path, in a stored form: checked, not yet a URL.
   *
   * Accepts a root-relative path, public://, themes/..., a bare file name in
   * the public file system, and an absolute http or https URL.
   */
  public function logoPath(mixed $value): ?string {
    $path = $this->clean($value);
    if ($path === NULL) {
      return NULL;
    }
    if ($this->isRootRelative($path)
      || str_starts_with($path, 'public://')
      || str_starts_with($path, 'themes/')
      || (!str_contains($path, ':') && !str_contains($path, '/'))) {
      return $this->hasTraversal($path) ? NULL : $path;
    }
    return $this->httpUrl($path);
  }

  /**
   * A logo path as a URL for an img src.
   */
  public function logoUrl(mixed $value): ?string {
    $path = $this->logoPath($value);
    if ($path === NULL) {
      return NULL;
    }
    if ($this->isRootRelative($path) || str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
      return $path;
    }
    if (str_starts_with($path, 'themes/')) {
      // Unlike the 1.x '/themes/...', this holds on a site in a subdirectory.
      return $this->fileUrlGenerator->generateString($path);
    }
    $uri = str_starts_with($path, 'public://') ? $path : 'public://' . $path;
    try {
      return $this->fileUrlGenerator->generateAbsoluteString($uri);
    }
    catch (InvalidStreamWrapperException) {
      return NULL;
    }
  }

  /**
   * An absolute http or https URL with a host.
   */
  public function httpUrl(mixed $value): ?string {
    $url = $this->clean($value);
    if ($url === NULL || !UrlHelper::isValid($url, TRUE)) {
      return NULL;
    }
    $parts = parse_url($url);
    if ($parts === FALSE || empty($parts['host'])) {
      return NULL;
    }
    return in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], TRUE) ? $url : NULL;
  }

  /**
   * An https URL of a font stylesheet, from a listed host only.
   *
   * @param mixed $value
   *   The stored URL.
   * @param list<string> $hosts
   *   The accepted hosts. Empty means Google Fonts, the host Solo documents.
   */
  public function fontStylesheetUrl(mixed $value, array $hosts = []): ?string {
    $url = $this->httpUrl($value);
    if ($url === NULL) {
      return NULL;
    }
    $parts = parse_url($url);
    if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
      return NULL;
    }
    return in_array(strtolower($parts['host'] ?? ''), $hosts ?: self::FONT_HOSTS, TRUE) ? $url : NULL;
  }

  /**
   * A root-relative image path as a CSS url() value.
   *
   * @see \Drupal\solo_utilities\Security\CssValueValidator
   */
  public function cssImageUrl(mixed $value): ?string {
    $path = $this->clean($value);
    if ($path === NULL || !$this->isRootRelative($path) || $this->hasTraversal($path)) {
      return NULL;
    }
    // Refused, not stripped: removing a character can create a new prefix, such
    // as `/;/host` turning into the protocol-relative `//host`.
    if (preg_match('/[{};<>]/', $path) === 1) {
      return NULL;
    }
    return "url('" . str_replace("'", "\\'", $path) . "')";
  }

  /**
   * The trimmed value, or NULL when it cannot be a path or URL at all.
   *
   * A backslash is refused here for every form: browsers read it as a slash,
   * so `/\host` is a protocol-relative URL, and in CSS it starts an escape.
   */
  private function clean(mixed $value): ?string {
    if (!is_string($value)) {
      return NULL;
    }
    $value = trim($value);
    if ($value === ''
      || strlen($value) > self::MAX_LENGTH
      || !mb_check_encoding($value, 'UTF-8')
      || str_contains($value, '\\')
      || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
      return NULL;
    }
    return $value;
  }

  /**
   * Whether a path starts with one slash and is not protocol-relative.
   */
  private function isRootRelative(string $path): bool {
    return $path[0] === '/' && !str_starts_with($path, '//');
  }

  /**
   * Whether a local path steps upward, plainly or percent-encoded.
   */
  private function hasTraversal(string $path): bool {
    return in_array('..', explode('/', rawurldecode($path)), TRUE);
  }

}
