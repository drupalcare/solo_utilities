<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Feature;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\solo_utilities\Context\RequestContext;
use Drupal\solo_utilities\Settings\BoundThemeSettings;
use Drupal\user\UserInterface;

/**
 * The template variables of comments and the comment field.
 *
 * @internal
 */
final class CommentPreprocessor {

  use StringTranslationTrait;

  public function __construct(
    private readonly DateFormatterInterface $dateFormatter,
    private readonly TimeInterface $time,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RequestContext $requestContext,
    TranslationInterface $stringTranslation,
  ) {
    $this->setStringTranslation($stringTranslation);
  }

  /**
   * Adds the display flags, dates, title id and reply count of a comment.
   *
   * @param array<string, mixed> $variables
   *   The comment template variables.
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The active theme's settings.
   */
  public function preprocessComment(array &$variables, BoundThemeSettings $settings): void {
    /** @var \Drupal\comment\CommentInterface $comment */
    $comment = $variables['elements']['#comment'];
    $show_date = $settings->bool('comments.show_date', TRUE);
    $date_format = $settings->string('comments.date_format', 'medium');

    $variables['heading_first'] = $settings->bool('comments.heading_first', TRUE);
    $variables['show_permalink'] = $settings->bool('comments.show_permalink', TRUE);
    $variables['show_author'] = $settings->bool('comments.show_author', TRUE);
    $variables['show_date'] = $show_date;
    $variables['show_picture'] = $settings->bool('comments.show_picture', TRUE);
    $variables['show_new_indicator'] = $settings->bool('comments.show_new_indicator', TRUE);
    $variables['title_tag'] = $settings->string('comments.title_tag', 'h3');

    $created = $comment->getCreatedTime();
    $time_ago = $this->t('@time ago', [
      '@time' => $this->dateFormatter->formatInterval($this->time->getRequestTime() - $created),
    ]);
    if ($show_date) {
      $is_relative = $date_format === 'solo_relative' || $date_format === 'relative';
      $variables['formatted_date'] = $is_relative ? $time_ago : $this->dateFormatter->format($created, $date_format);
      // For the datetime attribute of the time element.
      $variables['datetime'] = $this->dateFormatter->format($created, 'custom', 'c');
    }
    $variables['created'] = $time_ago;
    // For aria-labelledby.
    $variables['title_id'] = 'comment-' . $comment->id() . '-title';

    // Direct replies only.
    $replies = (int) $this->entityTypeManager->getStorage('comment')->getQuery()
      ->accessCheck(TRUE)
      ->condition('entity_id', $comment->getCommentedEntityId())
      ->condition('entity_type', $comment->getCommentedEntityTypeId())
      ->condition('field_name', $comment->getFieldName())
      ->condition('pid', $comment->id())
      ->condition('status', 1)
      ->count()
      ->execute();
    $variables['has_replies'] = $replies > 0;
    $variables['replies_count'] = $replies;

    CacheableMetadata::createFromRenderArray($variables)
      ->addCacheableDependency($settings)
      ->addCacheTags(['comment_list'])
      ->applyTo($variables);
  }

  /**
   * Adds the comment count, current user picture and form position.
   *
   * Works for every comment field, as it goes by field type and not field name.
   *
   * @param array<string, mixed> $variables
   *   The field template variables.
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The active theme's settings.
   */
  public function preprocessCommentField(array &$variables, BoundThemeSettings $settings): void {
    $cache = CacheableMetadata::createFromRenderArray($variables)->addCacheableDependency($settings);
    $variables['comment_count'] = count(array_filter($variables['comments'] ?? [], 'is_numeric', ARRAY_FILTER_USE_KEY));
    if (!$this->requestContext->isAnonymous()) {
      $user = $this->entityTypeManager->getStorage('user')->load($this->requestContext->userId());
      if ($user instanceof UserInterface && $user->hasField('user_picture') && !$user->get('user_picture')->isEmpty()) {
        $variables['user_picture'] = $this->entityTypeManager->getViewBuilder('user')->view($user, 'compact');
      }
      $cache->addCacheContexts(['user']);
    }
    $variables['comment_form_position'] = $settings->string('comments.form_position', 'before');
    $cache->applyTo($variables);
  }

}
