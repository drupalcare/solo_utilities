<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Migration;

use Drupal\block_content\BlockContentInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Entity\SynchronizableInterface;
use Drupal\layout_builder\InlineBlockUsageInterface;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionListInterface;
use Drupal\solo_utilities\Theme\SoloThemeDetectionInterface;

/**
 * Renames Solo 1 classes and ids held in configuration and content.
 *
 * Every method is a dry run unless $apply is TRUE, and returns one row per
 * value it would change or wants checked. Configuration is changed through
 * the config API; content through the entity API, as a new revision.
 *
 * Content is limited to ids (owner decision D3): a class typed into a body
 * field is the editor's, and Solo 2 froze the font and animation value
 * classes (D1), so there is nothing else in content Solo renames.
 *
 * Content is handled per item, where an item is the entity a visitor sees: a
 * node, a term, a reusable block, a menu link, or a Layout Builder default
 * layout. Paragraphs and Layout Builder inline blocks are never saved alone;
 * the item that holds them is saved so it points at their new revisions.
 * For each item the default revision and the latest pending revision (a
 * draft) are rewritten; older revisions are counted and left as they are.
 * Each item is written in one database transaction, so a failure leaves that
 * item exactly as it was and the run goes on with the next one.
 *
 * @internal
 */
final class StoredClassMigrator {

  /**
   * Views option keys that hold a class list.
   *
   * @var list<string>
   */
  private const array VIEWS_CLASS_KEYS = [
    'css_class',
    'row_class',
    'class',
    'wrapper_class',
    'element_class',
    'element_wrapper_class',
    'element_label_class',
  ];

  /**
   * Formatted text field types scanned with --content.
   *
   * @var list<string>
   */
  private const array TEXT_TYPES = ['text', 'text_long', 'text_with_summary'];

  /**
   * Solo's code settings beside the inline CSS, with the file type they hold.
   *
   * @var array<string, array{string, string}>
   */
  private const array CUSTOM_CODE = [
    'custom_code.css_file' => ['Dynamic CSS', 'stored.css'],
    'custom_code.js_file' => ['Dynamic JS', 'stored.js'],
  ];

  /**
   * Content entities loaded per batch.
   */
  private const int BATCH = 50;

  /**
   * How deep paragraphs and inline blocks may nest before an item is skipped.
   */
  private const int MAX_DEPTH = 10;

  /**
   * The Layout Builder override field on a content entity.
   */
  private const string LAYOUT_FIELD = 'layout_builder__layout';

  /**
   * The revision log message of a rename.
   */
  public const string LOG_MESSAGE = 'Solo 2: renamed Solo 1 ids (drush solo:migrate-classes --content).';

  /**
   * The revision log message of a rollback.
   */
  public const string ROLLBACK_MESSAGE = 'Solo 2: rollback of the Solo 1 id rename (drush solo:migrate-classes-rollback).';

  /**
   * Entities loaded while handling the current item, by type, for the reset.
   *
   * @var array<string, array<string, string>>
   */
  private array $touched = [];

  /**
   * The rollback log content() writes to while it applies, or NULL.
   */
  private ?RollbackLog $log = NULL;

  public function __construct(
    private readonly ClassMigrator $engine,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly SoloThemeDetectionInterface $detection,
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly ?InlineBlockUsageInterface $inlineBlockUsage = NULL,
  ) {}

  /**
   * Builds the migrator from the container and Solo's class map.
   *
   * @throws \RuntimeException
   *   When the class map cannot be read.
   */
  public static function fromContainer(): self {
    return new self(
      SoloClassMap::migrator(),
      \Drupal::configFactory(),
      \Drupal::entityTypeManager(),
      \Drupal::service('entity_field.manager'),
      \Drupal::service(SoloThemeDetectionInterface::class),
      \Drupal::database(),
      \Drupal::time(),
      \Drupal::hasService('inline_block.usage') ? \Drupal::service('inline_block.usage') : NULL,
    );
  }

  /**
   * Renames classes in the inline CSS setting of Solo and its sub-themes.
   *
   * @param bool $apply
   *   Whether to save the changes.
   * @param \Drupal\solo_utilities\Migration\RollbackLog|null $log
   *   Receives the configuration before and after each save, when given.
   *
   * @return list<array{store: string, name: string, path: string, before: string, after: string, note: string}>
   *   One row per changed line or review note.
   */
  public function inlineCss(bool $apply, ?RollbackLog $log = NULL): array {
    $rows = [];
    foreach ($this->detection->chainOnDisk() as $theme) {
      $name = $theme . '.settings';
      $config = $this->configFactory->getEditable($name);
      $css = $config->get('custom_code.inline_css');
      if (!is_string($css) || trim($css) === '') {
        continue;
      }
      $result = $this->engine->migrateCss($css);
      foreach ($result['changes'] as $change) {
        $rows[] = self::row('inline CSS', $name, 'custom_code.inline_css L' . $change['line'], $change['before'], $change['after']);
      }
      foreach ($result['notes'] as $note) {
        $rows[] = self::row('inline CSS', $name, 'custom_code.inline_css L' . $note['line'], $note['token'], $note['new'], $note['kind']);
      }
      if ($apply && $result['changes'] !== []) {
        $before = $config->getRawData();
        $config->set('custom_code.inline_css', $result['content'])->save();
        self::logConfig($log, $name, $before, $config->getRawData());
      }
    }
    return $rows;
  }

  /**
   * Renames classes in Solo's Dynamic CSS and Dynamic JS settings.
   *
   * Only the settings are written. The files Solo generates from them under
   * sites/<site>/files are never written here; Solo builds new ones from the
   * changed settings.
   *
   * @param bool $apply
   *   Whether to save the changes.
   * @param \Drupal\solo_utilities\Migration\RollbackLog|null $log
   *   Receives the configuration before and after each save, when given.
   *
   * @return list<array{store: string, name: string, path: string, before: string, after: string, note: string}>
   *   One row per changed line or review note.
   */
  public function customCode(bool $apply, ?RollbackLog $log = NULL): array {
    $rows = [];
    foreach ($this->detection->chainOnDisk() as $theme) {
      $name = $theme . '.settings';
      $config = $this->configFactory->getEditable($name);
      $changed = [];
      foreach (self::CUSTOM_CODE as $key => [$store, $filename]) {
        $code = $config->get($key);
        if (!is_string($code) || trim($code) === '') {
          continue;
        }
        $result = $this->engine->migrate($code, $filename);
        foreach ($result['changes'] as $change) {
          $rows[] = self::row($store, $name, $key . ' L' . $change['line'], $change['before'], $change['after']);
        }
        foreach ($result['notes'] as $note) {
          $rows[] = self::row($store, $name, $key . ' L' . $note['line'], $note['token'], $note['new'], $note['kind']);
        }
        if ($result['changes'] !== []) {
          $changed[$key] = $result['content'];
        }
      }
      if ($apply && $changed !== []) {
        $before = $config->getRawData();
        foreach ($changed as $key => $content) {
          $config->set($key, $content);
        }
        $config->save();
        self::logConfig($log, $name, $before, $config->getRawData());
      }
    }
    return $rows;
  }

  /**
   * Renames classes in Block Class settings, Views and text formats.
   *
   * @param bool $apply
   *   Whether to save the changes.
   * @param \Drupal\solo_utilities\Migration\RollbackLog|null $log
   *   Receives the configuration before and after each save, when given.
   *
   * @return list<array{store: string, name: string, path: string, before: string, after: string, note: string}>
   *   One row per changed value or review note.
   */
  public function configuration(bool $apply, ?RollbackLog $log = NULL): array {
    $rows = [];
    foreach ($this->configFactory->listAll('block.block.') as $name) {
      $this->rewrite($name, 'block_class', $apply, $log, $rows, function (array $data, array &$found): array {
        $classes = $data['third_party_settings']['block_class']['classes'] ?? NULL;
        if (is_string($classes)) {
          $data['third_party_settings']['block_class']['classes'] = $this->classList($classes, 'third_party_settings.block_class.classes', $found);
        }
        return $data;
      });
    }
    foreach ($this->configFactory->listAll('views.view.') as $name) {
      $this->rewrite($name, 'Views', $apply, $log, $rows, function (array $data, array &$found): array {
        foreach (array_keys($data['display'] ?? []) as $id) {
          if (isset($data['display'][$id]['display_options']) && is_array($data['display'][$id]['display_options'])) {
            $data['display'][$id]['display_options'] = $this->walkViews($data['display'][$id]['display_options'], 'display.' . $id . '.display_options', $found);
          }
        }
        return $data;
      });
    }
    foreach ($this->configFactory->listAll('filter.format.') as $name) {
      $this->rewrite($name, 'text format', $apply, $log, $rows, function (array $data, array &$found): array {
        $allowed = $data['filters']['filter_html']['settings']['allowed_html'] ?? NULL;
        if (is_string($allowed)) {
          $data['filters']['filter_html']['settings']['allowed_html'] = $this->classAttributes($allowed, 'filters.filter_html.settings.allowed_html', $found);
        }
        return $data;
      });
    }
    // CKEditor 5 keeps its own copy of the allowed tags and its styles; the
    // two must change with the text format or the editor refuses the format.
    foreach ($this->configFactory->listAll('editor.editor.') as $name) {
      $this->rewrite($name, 'text editor', $apply, $log, $rows, function (array $data, array &$found): array {
        if (!isset($data['settings']['plugins']) || !is_array($data['settings']['plugins'])) {
          return $data;
        }
        $plugins = &$data['settings']['plugins'];
        foreach ($plugins['ckeditor5_sourceEditing']['allowed_tags'] ?? [] as $i => $tag) {
          if (is_string($tag)) {
            $plugins['ckeditor5_sourceEditing']['allowed_tags'][$i] = $this->classAttributes($tag, 'ckeditor5_sourceEditing.allowed_tags.' . $i, $found);
          }
        }
        foreach ($plugins['ckeditor5_style']['styles'] ?? [] as $i => $style) {
          if (is_string($style['element'] ?? NULL)) {
            $plugins['ckeditor5_style']['styles'][$i]['element'] = $this->classAttributes($style['element'], 'ckeditor5_style.styles.' . $i, $found);
          }
        }
        unset($plugins);
        return $data;
      });
    }
    return $rows;
  }

  /**
   * Renames Solo ids in formatted text, inline blocks and menu link attributes.
   *
   * @param bool $apply
   *   Whether to save the changes. Requires $log.
   * @param \Drupal\solo_utilities\Migration\RollbackLog|null $log
   *   Receives every changed value, written before its item is committed.
   * @param int $revisionUid
   *   The user id recorded as the author of the new revisions.
   * @param int $limit
   *   The most items to handle in this run; 0 for all.
   * @param string|null $from
   *   The first item to handle, as entity_type:id; NULL to start at the top.
   * @param int $maxFailures
   *   The run stops once this many items have failed.
   *
   * @return array{rows: list<array{store: string, name: string, path: string, before: string, after: string, note: string}>, summary: array<string, array<string, int>>, failures: list<array{item: string, error: string}>, resume: ?string, stopped: bool}
   *   The report rows, counts per entity type, the failed items (exception
   *   class only, never field values), the item to give --from to carry on,
   *   and whether the run stopped at the failure limit.
   *
   * @throws \LogicException
   *   When asked to apply without a rollback log.
   */
  public function content(bool $apply, ?RollbackLog $log = NULL, int $revisionUid = 1, int $limit = 0, ?string $from = NULL, int $maxFailures = 10): array {
    if ($apply && $log === NULL) {
      throw new \LogicException('Content is only written with a rollback log.');
    }
    $result = ['rows' => [], 'summary' => [], 'failures' => [], 'resume' => NULL, 'stopped' => FALSE];
    $this->log = $apply ? $log : NULL;
    $ids = array_keys($this->engine->idMap());
    if ($ids === []) {
      $this->log = NULL;
      return $result;
    }
    $units = $this->candidateUnits($ids, $result['rows'], $result['summary']);
    [$units, $result['resume']] = self::slice($units, $from, $limit);

    $transform = function (array $context, string $text) use (&$result): ?string {
      if ($context['property'] === 'options.attributes.id') {
        return $this->engine->newId($text);
      }
      $migrated = $this->engine->migrateHtmlIds($text);
      // A link to an old id on another page or site is never rewritten; it is
      // listed with its whole href so it can be checked by hand.
      foreach ($migrated['notes'] as $note) {
        $where = $context['field'] . '.' . $context['delta'] . '.' . $context['property'] . ($context['role'] === 'pending' ? ' (draft)' : '');
        $result['rows'][] = self::row('content', self::changeName($context), $where, $note['href'], $note['new'], 'other-page-link');
      }
      return $migrated['renamed'] === [] ? NULL : $migrated['value'];
    };
    $remaining = array_values($units);
    foreach ($remaining as $index => $unit) {
      $outcome = $this->handleUnit($unit, $apply, $transform, $revisionUid, self::LOG_MESSAGE, $result['rows'], $result['summary']);
      if ($outcome['error'] !== NULL) {
        $result['failures'][] = ['item' => $unit, 'error' => $outcome['error']];
        self::count($result['summary'], self::unitType($unit), 'failed');
        if (count($result['failures']) >= $maxFailures) {
          $result['stopped'] = TRUE;
          $result['resume'] = $remaining[$index + 1] ?? NULL;
          break;
        }
        continue;
      }
      foreach ($outcome['changes'] as $change) {
        $where = $change['field'] . '.' . $change['delta'] . '.' . $change['property'] . ($change['role'] === 'pending' ? ' (draft)' : '');
        $pairs = $change['property'] === 'options.attributes.id'
          ? [$change['before'] => $change['after']]
          : $this->engine->migrateHtmlIds($change['before'])['renamed'];
        foreach ($pairs as $old => $new) {
          $result['rows'][] = self::row('content', self::changeName($change), $where, (string) $old, $new);
        }
      }
    }
    $this->log = NULL;
    return $result;
  }

  /**
   * Restores the values a content and configuration run recorded in its log.
   *
   * Compare and swap: a value is restored only while it still holds what the
   * run wrote. A value changed since then is reported as a conflict and left.
   *
   * @param list<array<string, mixed>> $records
   *   The records from RollbackLog::read().
   * @param bool $apply
   *   Whether to save the restored values.
   * @param int $revisionUid
   *   The user id recorded as the author of the new revisions.
   * @param int $maxFailures
   *   The run stops once this many items have failed.
   *
   * @return array{rows: list<array{store: string, name: string, path: string, before: string, after: string, note: string}>, restored: int, conflicts: int, failures: list<array{item: string, error: string}>, stopped: bool}
   *   One row per value, and the counts.
   */
  public function rollback(array $records, bool $apply, int $revisionUid = 1, int $maxFailures = 10): array {
    $result = ['rows' => [], 'restored' => 0, 'conflicts' => 0, 'failures' => [], 'stopped' => FALSE];
    // A rollback writes no log of its own; its input log is the record.
    $this->log = NULL;
    $map = [];
    foreach ($records as $record) {
      if (($record['kind'] ?? NULL) === 'config' && is_string($record['name'] ?? NULL) && is_array($record['before'] ?? NULL) && is_array($record['after'] ?? NULL)) {
        $config = $this->configFactory->getEditable($record['name']);
        if ($config->getRawData() !== $record['after']) {
          $result['conflicts']++;
          $result['rows'][] = self::row('config', $record['name'], '', '', '', 'conflict: changed since the run, left as it is');
          continue;
        }
        $result['restored']++;
        $result['rows'][] = self::row('config', $record['name'], '', '', '', $apply ? 'restored' : 'would be restored');
        if ($apply) {
          $config->setData($record['before'])->save();
        }
      }
      elseif (($record['kind'] ?? NULL) === 'content' && is_string($record['item'] ?? NULL) && is_string($record['before'] ?? NULL) && is_string($record['after'] ?? NULL)) {
        $map[$record['item']][self::key($record)] = $record;
      }
    }

    foreach ($map as $unit => $entries) {
      $visited = [];
      $conflicts = [];
      $transform = static function (array $context, string $text) use ($entries, &$visited, &$conflicts): ?string {
        $key = self::key($context);
        if (!isset($entries[$key])) {
          return NULL;
        }
        $visited[$key] = TRUE;
        if ($text === $entries[$key]['after']) {
          return $entries[$key]['before'];
        }
        if ($text !== $entries[$key]['before']) {
          $conflicts[$key] = TRUE;
        }
        return NULL;
      };
      $ignored = [];
      $summary = [];
      $outcome = $this->handleUnit($unit, $apply, $transform, $revisionUid, self::ROLLBACK_MESSAGE, $ignored, $summary);
      if ($outcome['error'] !== NULL) {
        $result['failures'][] = ['item' => $unit, 'error' => $outcome['error']];
        if (count($result['failures']) >= $maxFailures) {
          $result['stopped'] = TRUE;
          break;
        }
        continue;
      }
      foreach ($entries as $key => $entry) {
        $where = $entry['field'] . '.' . $entry['delta'] . '.' . $entry['property'];
        $name = self::changeName($entry);
        if (isset($conflicts[$key])) {
          $result['conflicts']++;
          $result['rows'][] = self::row('content', $name, $where, (string) $entry['after'], (string) $entry['before'], 'conflict: edited since the run, left as it is');
        }
        elseif (!isset($visited[$key])) {
          $result['conflicts']++;
          $result['rows'][] = self::row('content', $name, $where, (string) $entry['after'], (string) $entry['before'], 'not found: the item or its ' . ($entry['role'] === 'pending' ? 'draft' : 'default revision') . ' changed since the run');
        }
      }
      foreach ($outcome['changes'] as $change) {
        $result['restored']++;
        $result['rows'][] = self::row('content', self::changeName($change), $change['field'] . '.' . $change['delta'] . '.' . $change['property'], $change['before'], $change['after'], $apply ? 'restored' : 'would be restored');
      }
    }
    return $result;
  }

  /**
   * Finds every item holding an old id, and counts what is left alone.
   *
   * @param list<string> $ids
   *   Old ids.
   * @param list<array<string, string>> $rows
   *   Report rows, appended to (items with no host).
   * @param array<string, array<string, int>> $summary
   *   Counts, updated.
   *
   * @return list<string>
   *   Items as entity_type:id, sorted by type, then by id.
   */
  private function candidateUnits(array $ids, array &$rows, array &$summary): array {
    $units = [];
    $children = [];
    foreach (self::TEXT_TYPES as $type) {
      foreach ($this->entityFieldManager->getFieldMapByFieldType($type) as $entityType => $fields) {
        $storage = $this->entityTypeManager->getStorage($entityType);
        $revisionable = $this->entityTypeManager->getDefinition($entityType)->isRevisionable();
        foreach (array_keys($fields) as $field) {
          $summaryToo = $type === 'text_with_summary';
          $current = $this->matchQuery($storage, $field, $summaryToo, $ids)->execute();
          $matched = $current;
          if ($revisionable) {
            $latest = $this->matchQuery($storage, $field, $summaryToo, $ids)->latestRevision()->execute();
            $all = $this->matchQuery($storage, $field, $summaryToo, $ids)->allRevisions()->execute();
            $matched = $current + $latest;
            // Owner decision OD1 (b): older revisions are history and stay.
            $old = count(array_diff_key($all, $matched));
            if ($old > 0) {
              self::count($summary, $entityType, 'old_revisions', $old);
            }
          }
          foreach ($matched as $entityId) {
            $children[$entityType][(string) $entityId] = TRUE;
          }
        }
      }
    }
    foreach ($children as $entityType => $entityIds) {
      $storage = $this->entityTypeManager->getStorage($entityType);
      foreach (array_chunk(array_keys($entityIds), self::BATCH) as $chunk) {
        foreach ($storage->loadMultiple($chunk) as $entity) {
          if (!$entity instanceof ContentEntityInterface) {
            continue;
          }
          $unit = $this->resolveUnit($entity, 0);
          if ($unit === NULL) {
            self::count($summary, $entityType, 'no_host');
            $rows[] = self::row('content', $entityType . ':' . $entity->id(), '', '', '', 'not rewritten: no host');
            continue;
          }
          $units[$unit] = TRUE;
        }
        $storage->resetCache($chunk);
      }
    }
    if ($this->entityTypeManager->hasDefinition('menu_link_content')) {
      $storage = $this->entityTypeManager->getStorage('menu_link_content');
      // The link options are a serialized map an entity query cannot search,
      // so every link is loaded, a chunk at a time, and checked here.
      $all = array_values($storage->getQuery()->accessCheck(FALSE)->sort('id')->execute());
      foreach (array_chunk($all, self::BATCH) as $chunk) {
        foreach ($storage->loadMultiple($chunk) as $link) {
          if ($link instanceof ContentEntityInterface && $link->hasField('link') && !$link->get('link')->isEmpty()) {
            $options = $link->get('link')->first()?->get('options')->getValue();
            $id = is_array($options) ? ($options['attributes']['id'] ?? NULL) : NULL;
            if (is_string($id) && $this->engine->newId($id) !== NULL) {
              $units['menu_link_content:' . $link->id()] = TRUE;
            }
          }
        }
        $storage->resetCache($chunk);
      }
    }
    $units = array_keys($units);
    usort($units, [self::class, 'compareUnits']);
    return $units;
  }

  /**
   * Builds the candidate query for one text field.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $storage
   *   The storage.
   * @param string $field
   *   The field name.
   * @param bool $summary
   *   Whether the field has a summary to search as well.
   * @param list<string> $ids
   *   Old ids.
   */
  private function matchQuery(EntityStorageInterface $storage, string $field, bool $summary, array $ids): QueryInterface {
    $query = $storage->getQuery()->accessCheck(FALSE);
    $or = $query->orConditionGroup();
    foreach ($ids as $id) {
      $or->condition($field . '.value', $id, 'CONTAINS');
      if ($summary) {
        $or->condition($field . '.summary', $id, 'CONTAINS');
      }
    }
    return $query->condition($or);
  }

  /**
   * Returns the item a visitor sees for an entity, or NULL when it has none.
   *
   * A paragraph belongs to its parent; an inline (non-reusable) content block
   * belongs to the entity or default layout whose Layout Builder uses it.
   */
  private function resolveUnit(ContentEntityInterface $entity, int $depth): ?string {
    if ($depth > self::MAX_DEPTH) {
      return NULL;
    }
    if ($entity->getEntityType()->get('entity_revision_parent_id_field') && method_exists($entity, 'getParentEntity')) {
      $parent = $entity->getParentEntity();
      return $parent instanceof ContentEntityInterface ? $this->resolveUnit($parent, $depth + 1) : NULL;
    }
    if ($entity instanceof BlockContentInterface && !$entity->isReusable()) {
      $usage = $this->inlineBlockUsage?->getUsage($entity->id());
      if (!is_object($usage) || !isset($usage->layout_entity_type, $usage->layout_entity_id)) {
        return NULL;
      }
      return $usage->layout_entity_type . ':' . $usage->layout_entity_id;
    }
    return $entity->getEntityTypeId() . ':' . $entity->id();
  }

  /**
   * Rewrites one item, its default revision and its pending draft.
   *
   * @param string $unit
   *   The item, as entity_type:id.
   * @param bool $apply
   *   Whether to save.
   * @param callable $transform
   *   Receives a value context and the text; returns the new text or NULL.
   * @param int $revisionUid
   *   The author of the new revisions.
   * @param string $message
   *   The revision log message.
   * @param list<array<string, string>> $rows
   *   Report rows, appended to (report-only findings).
   * @param array<string, array<string, int>> $summary
   *   Counts, updated.
   *
   * @return array{changes: list<array<string, mixed>>, error: ?string}
   *   Every value changed, and the exception class when the item failed.
   */
  private function handleUnit(string $unit, bool $apply, callable $transform, int $revisionUid, string $message, array &$rows, array &$summary): array {
    [$entityType, $id] = explode(':', $unit, 2);
    $this->touched = [];
    $all = [];
    $transaction = NULL;
    try {
      if (!$this->entityTypeManager->hasDefinition($entityType)) {
        $rows[] = self::row('content', $unit, '', '', '', 'not rewritten: no host');
        return ['changes' => [], 'error' => NULL];
      }
      $storage = $this->entityTypeManager->getStorage($entityType);
      $entity = $storage->load($id);
      if ($apply) {
        $transaction = $this->database->startTransaction();
      }

      if ($entityType === 'entity_view_display') {
        // A Layout Builder default layout: only its inline blocks are content.
        if ($entity instanceof SectionListInterface) {
          $changes = [];
          $blocks = [];
          $sections = $entity->getSections();
          if ($this->walkSections($sections, $unit, 'default', $transform, $changes, $blocks, $rows, 0)) {
            self::count($summary, $entityType, 'items');
            self::count($summary, $entityType, 'default');
            if ($apply) {
              $this->saveBlocks($blocks, $revisionUid, $message);
              self::replaceSections($entity, $sections);
              $entity->save();
            }
            $all = $changes;
          }
        }
        else {
          $rows[] = self::row('content', $unit, '', '', '', 'not rewritten: no host');
        }
      }
      elseif ($entity instanceof ContentEntityInterface) {
        $revisions = ['default' => $entity];
        // Read before anything is saved: saving the default revision makes it
        // the latest, and the draft would then be lost from sight.
        if ($entity instanceof RevisionableInterface && $storage instanceof RevisionableStorageInterface && $entity->getEntityType()->isRevisionable()) {
          $latestId = $storage->getLatestRevisionId($id);
          if ($latestId !== NULL && (string) $latestId !== (string) $entity->getRevisionId()) {
            $pending = $storage->loadRevision($latestId);
            if ($pending instanceof ContentEntityInterface) {
              $revisions['pending'] = $pending;
            }
          }
        }
        $counted = FALSE;
        // The default revision is saved first, then the draft, so the draft
        // ends up the latest revision again, still a draft.
        foreach ($revisions as $role => $revision) {
          $changes = [];
          $blocks = [];
          if (!$this->walk($revision, $unit, $role, $transform, $changes, $blocks, $rows, 0)) {
            continue;
          }
          if (!$counted) {
            self::count($summary, $entityType, 'items');
            $counted = TRUE;
          }
          self::count($summary, $entityType, $role);
          if ($apply) {
            $this->saveBlocks($blocks, $revisionUid, $message);
            self::prepareRevision($revision, $revisionUid, $message, $this->time->getRequestTime());
            $revision->save();
          }
          array_push($all, ...$changes);
        }
      }
      else {
        $rows[] = self::row('content', $unit, '', '', '', 'not rewritten: no host');
      }

      self::countChanges($summary, $entityType, $all);
      $changes = [];
      foreach ($all as $change) {
        $entityObject = $change['entity'];
        unset($change['entity']);
        $change['revision_after'] = $entityObject instanceof RevisionableInterface ? $entityObject->getRevisionId() : NULL;
        $changes[] = $change;
      }
      if ($transaction !== NULL) {
        // Written before the commit: a log line for an item that then failed
        // to commit is harmless, since rollback only swaps matching values.
        foreach ($changes as $change) {
          $this->logContent($change);
        }
        $transaction->commitOrRelease();
      }
      return ['changes' => $changes, 'error' => NULL];
    }
    catch (\Throwable $e) {
      $transaction?->rollBack();
      return ['changes' => [], 'error' => get_class($e)];
    }
    finally {
      $this->resetTouched();
    }
  }

  /**
   * Writes one changed value to the rollback log.
   *
   * @param array<string, mixed> $change
   *   The change.
   */
  private function logContent(array $change): void {
    $this->log?->write(['kind' => 'content'] + $change);
  }

  /**
   * Rewrites the values of one entity revision and what it holds.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The revision.
   * @param string $unit
   *   The item it belongs to.
   * @param string $role
   *   Either 'default' or 'pending'.
   * @param callable $transform
   *   Receives a value context and the text; returns the new text or NULL.
   * @param list<array<string, mixed>> $changes
   *   Changed values, appended to.
   * @param list<array{0: \Drupal\layout_builder\SectionComponent, 1: \Drupal\Core\Entity\ContentEntityInterface}> $blocks
   *   Inline blocks to save before the item, appended to.
   * @param list<array<string, string>> $rows
   *   Report rows, appended to (report-only findings).
   * @param int $depth
   *   How far below the item this entity is.
   *
   * @return bool
   *   Whether anything changed.
   */
  private function walk(ContentEntityInterface $entity, string $unit, string $role, callable $transform, array &$changes, array &$blocks, array &$rows, int $depth): bool {
    if ($depth > self::MAX_DEPTH) {
      throw new \RuntimeException('Paragraphs or inline blocks nest too deep.');
    }
    $this->touch($entity);
    $changed = FALSE;
    foreach (array_keys($entity->getTranslationLanguages()) as $langcode) {
      $translation = $entity->getTranslation($langcode);
      $isDefault = $translation->isDefaultTranslation();
      foreach ($translation->getFieldDefinitions() as $name => $definition) {
        // Untranslatable values are shared by every translation: handle once.
        if (!$isDefault && !$definition->isTranslatable()) {
          continue;
        }
        $type = $definition->getType();
        if (in_array($type, self::TEXT_TYPES, TRUE)) {
          foreach ($translation->get($name) as $delta => $item) {
            foreach ($type === 'text_with_summary' ? ['value', 'summary'] : ['value'] as $property) {
              $text = $item->get($property)->getValue();
              if (!is_string($text) || $text === '') {
                continue;
              }
              $context = self::context($entity, $unit, $role, $langcode, $name, (int) $delta, $property);
              $new = $transform($context, $text);
              if ($new !== NULL && $new !== $text) {
                $item->set($property, $new);
                $changes[] = $context + ['before' => $text, 'after' => $new, 'entity' => $entity];
                $changed = TRUE;
              }
            }
          }
        }
        elseif ($type === 'entity_reference_revisions') {
          foreach ($translation->get($name) as $item) {
            $child = $item->entity ?? NULL;
            if (!$child instanceof ContentEntityInterface || !$child->getEntityType()->get('entity_revision_parent_id_field')) {
              continue;
            }
            if ($this->walk($child, $unit, $role, $transform, $changes, $blocks, $rows, $depth + 1)) {
              // entity_reference_revisions saves a child marked as needing it
              // when the parent is saved, and points the parent at the new
              // child revision.
              if (method_exists($child, 'setNeedsSave')) {
                $child->setNeedsSave(TRUE);
              }
              $changed = TRUE;
            }
          }
        }
        elseif ($type === 'link' && $entity->getEntityTypeId() === 'menu_link_content') {
          $item = $translation->get($name)->first();
          $options = $item?->get('options')->getValue();
          $id = is_array($options) ? ($options['attributes']['id'] ?? NULL) : NULL;
          if ($item !== NULL && is_array($options) && is_string($id) && $id !== '') {
            $context = self::context($entity, $unit, $role, $langcode, $name, 0, 'options.attributes.id');
            $new = $transform($context, $id);
            if ($new !== NULL && $new !== $id) {
              $options['attributes']['id'] = $new;
              $item->set('options', $options);
              $changes[] = $context + ['before' => $id, 'after' => $new, 'entity' => $entity];
              $changed = TRUE;
            }
          }
        }
        elseif ($name === self::LAYOUT_FIELD && $isDefault) {
          $list = $translation->get($name);
          if ($list instanceof SectionListInterface) {
            $sections = $list->getSections();
            if ($this->walkSections($sections, $unit, $role, $transform, $changes, $blocks, $rows, $depth)) {
              self::replaceSections($list, $sections);
              $changed = TRUE;
            }
          }
        }
      }
    }
    return $changed;
  }

  /**
   * Rewrites the inline blocks of a Layout Builder layout.
   *
   * Section and component settings are only reported (owner decision OD2):
   * their shape belongs to contrib modules.
   *
   * @param array<int, \Drupal\layout_builder\Section> $sections
   *   The sections. Components are changed in place.
   * @param string $unit
   *   The item.
   * @param string $role
   *   Either 'default' or 'pending'.
   * @param callable $transform
   *   Receives a value context and the text; returns the new text or NULL.
   * @param list<array<string, mixed>> $changes
   *   Changed values, appended to.
   * @param list<array{0: \Drupal\layout_builder\SectionComponent, 1: \Drupal\Core\Entity\ContentEntityInterface}> $blocks
   *   Inline blocks to save before the item, appended to.
   * @param list<array<string, string>> $rows
   *   Report rows, appended to.
   * @param int $depth
   *   The depth of the layout's owner.
   *
   * @return bool
   *   Whether any inline block changed.
   */
  private function walkSections(array $sections, string $unit, string $role, callable $transform, array &$changes, array &$blocks, array &$rows, int $depth): bool {
    $changed = FALSE;
    $blockStorage = $this->entityTypeManager->hasDefinition('block_content') ? $this->entityTypeManager->getStorage('block_content') : NULL;
    foreach ($sections as $delta => $section) {
      $this->reportSettings($section->getLayoutSettings(), $unit, 'section ' . $delta . ' layout settings', $rows);
      foreach ($section->getComponents() as $uuid => $component) {
        $additional = $component->get('additional');
        if (is_array($additional)) {
          $this->reportSettings($additional, $unit, 'section ' . $delta . ' component ' . $uuid, $rows);
        }
        if (!str_starts_with($component->getPluginId(), 'inline_block:') || !$blockStorage instanceof RevisionableStorageInterface) {
          continue;
        }
        $configuration = $component->get('configuration');
        if (!is_array($configuration)) {
          continue;
        }
        if (!empty($configuration['block_serialized'])) {
          // Never unserialize stored data; an unsaved Layout Builder edit is
          // the editor's to finish.
          $rows[] = self::row('content', $unit, 'section ' . $delta . ' component ' . $uuid, '', '', 'not rewritten: inline block has unsaved Layout Builder changes');
          continue;
        }
        $revisionId = $configuration['block_revision_id'] ?? NULL;
        if (!is_scalar($revisionId) || (string) $revisionId === '') {
          continue;
        }
        $block = $blockStorage->loadRevision($revisionId);
        if ($block instanceof ContentEntityInterface && $this->walk($block, $unit, $role, $transform, $changes, $blocks, $rows, $depth + 1)) {
          $blocks[] = [$component, $block];
          $changed = TRUE;
        }
      }
    }
    return $changed;
  }

  /**
   * Reports class and id values in Layout Builder settings; never writes.
   *
   * @param array<mixed> $settings
   *   The settings.
   * @param string $unit
   *   The item.
   * @param string $path
   *   Where the settings are, for the report.
   * @param list<array<string, string>> $rows
   *   Report rows, appended to.
   */
  private function reportSettings(array $settings, string $unit, string $path, array &$rows): void {
    foreach ($settings as $key => $value) {
      if (is_array($value)) {
        $this->reportSettings($value, $unit, $path . '.' . $key, $rows);
        continue;
      }
      if (!is_string($value) || trim($value) === '') {
        continue;
      }
      $after = $this->engine->newId($value);
      if ($after === NULL && str_contains(strtolower((string) $key), 'class')) {
        $list = $this->engine->migrateClassList($value);
        $after = $list['renamed'] === [] ? NULL : $list['value'];
      }
      if ($after !== NULL) {
        $rows[] = self::row('Layout Builder', $unit, $path . '.' . $key, $value, $after, 'Layout Builder setting: report only');
      }
    }
  }

  /**
   * Saves changed inline blocks and points their components at them.
   *
   * Core saves inline blocks in its own presave hook, which skips syncing
   * saves; the item is saved syncing to keep its "changed" time, so the
   * blocks are saved here, the way core does it.
   *
   * @param list<array{0: \Drupal\layout_builder\SectionComponent, 1: \Drupal\Core\Entity\ContentEntityInterface}> $blocks
   *   The blocks.
   * @param int $revisionUid
   *   The author of the new revisions.
   * @param string $message
   *   The revision log message.
   */
  private function saveBlocks(array $blocks, int $revisionUid, string $message): void {
    foreach ($blocks as [$component, $block]) {
      self::prepareRevision($block, $revisionUid, $message, $this->time->getRequestTime());
      $block->save();
      $configuration = $component->get('configuration');
      $configuration = is_array($configuration) ? $configuration : [];
      $configuration['block_revision_id'] = $block->getRevisionId();
      $component->setConfiguration($configuration);
    }
  }

  /**
   * Writes changed sections back to a section list.
   *
   * @param \Drupal\layout_builder\SectionListInterface $list
   *   The list.
   * @param array<int, \Drupal\layout_builder\Section> $sections
   *   The sections, in order.
   */
  private static function replaceSections(SectionListInterface $list, array $sections): void {
    $list->removeAllSections();
    foreach ($sections as $section) {
      if ($section instanceof Section) {
        $list->appendSection($section);
      }
    }
  }

  /**
   * Prepares a save: new revision, its metadata, and no "changed" update.
   *
   * A syncing save keeps the "changed" time (owner decision OD5) and keeps
   * content moderation from changing which revision is the default one.
   */
  private static function prepareRevision(ContentEntityInterface $entity, int $revisionUid, string $message, int $time): void {
    if ($entity instanceof RevisionableInterface && $entity->getEntityType()->isRevisionable()) {
      $entity->setNewRevision(TRUE);
      if ($entity instanceof RevisionLogInterface) {
        $entity->setRevisionLogMessage($message);
        $entity->setRevisionUserId($revisionUid);
        $entity->setRevisionCreationTime($time);
      }
    }
    if ($entity instanceof SynchronizableInterface) {
      $entity->setSyncing(TRUE);
    }
  }

  /**
   * Records an entity loaded for the current item.
   */
  private function touch(ContentEntityInterface $entity): void {
    $this->touched[$entity->getEntityTypeId()][(string) $entity->id()] = (string) $entity->id();
  }

  /**
   * Drops the entities of the current item from the static caches.
   *
   * A dry run changes them in memory only; a failed item must not be reused.
   */
  private function resetTouched(): void {
    foreach ($this->touched as $entityType => $ids) {
      $this->entityTypeManager->getStorage($entityType)->resetCache(array_values($ids));
    }
    $this->touched = [];
  }

  /**
   * Builds the context of one value.
   *
   * @return array{item: string, entity_type: string, id: string, langcode: string, role: string, field: string, delta: int, property: string, revision_before: int|string|null}
   *   The context.
   */
  private static function context(ContentEntityInterface $entity, string $unit, string $role, string $langcode, string $field, int $delta, string $property): array {
    return [
      'item' => $unit,
      'entity_type' => $entity->getEntityTypeId(),
      'id' => (string) $entity->id(),
      'langcode' => $langcode,
      'role' => $role,
      'field' => $field,
      'delta' => $delta,
      'property' => $property,
      'revision_before' => $entity->getRevisionId(),
    ];
  }

  /**
   * Returns the lookup key of a value context or log record.
   *
   * @param array<string, mixed> $context
   *   The context or record.
   */
  private static function key(array $context): string {
    $parts = [];
    foreach (['entity_type', 'id', 'langcode', 'role', 'field', 'delta', 'property'] as $name) {
      $value = $context[$name] ?? '';
      $parts[] = is_scalar($value) ? (string) $value : '';
    }
    return implode('|', $parts);
  }

  /**
   * Names a changed value for the report: entity_type:id[:langcode].
   *
   * @param array<string, mixed> $change
   *   The change or record.
   */
  private static function changeName(array $change): string {
    $name = $change['entity_type'] . ':' . $change['id'];
    if (($change['langcode'] ?? '') !== '') {
      $name .= ':' . $change['langcode'];
    }
    if ($change['item'] !== $change['entity_type'] . ':' . $change['id']) {
      $name .= ' (in ' . $change['item'] . ')';
    }
    return $name;
  }

  /**
   * Counts translations and child entities of one item's changes.
   *
   * @param array<string, array<string, int>> $summary
   *   Counts, updated.
   * @param string $entityType
   *   The item's entity type.
   * @param list<array<string, mixed>> $changes
   *   The changes.
   */
  private static function countChanges(array &$summary, string $entityType, array $changes): void {
    $translations = [];
    $children = [];
    foreach ($changes as $change) {
      $entity = $change['entity_type'] . ':' . $change['id'];
      $translations[$entity . ':' . $change['langcode'] . ':' . $change['role']] = TRUE;
      if ($change['item'] !== $entity) {
        $children[$entity . ':' . $change['role']] = TRUE;
      }
    }
    if ($translations !== []) {
      self::count($summary, $entityType, 'translations', count($translations));
    }
    if ($children !== []) {
      self::count($summary, $entityType, 'children', count($children));
    }
  }

  /**
   * Adds to one count.
   *
   * @param array<string, array<string, int>> $summary
   *   Counts, updated.
   * @param string $entityType
   *   The entity type the count belongs to.
   * @param string $key
   *   The count.
   * @param int $by
   *   How much to add.
   */
  private static function count(array &$summary, string $entityType, string $key, int $by = 1): void {
    $summary[$entityType][$key] = ($summary[$entityType][$key] ?? 0) + $by;
  }

  /**
   * Returns the entity type of an item.
   */
  private static function unitType(string $unit): string {
    return explode(':', $unit, 2)[0];
  }

  /**
   * Orders items by entity type, then by numeric or string id.
   */
  public static function compareUnits(string $a, string $b): int {
    [$typeA, $idA] = explode(':', $a, 2) + [1 => ''];
    [$typeB, $idB] = explode(':', $b, 2) + [1 => ''];
    if ($typeA !== $typeB) {
      return strcmp($typeA, $typeB);
    }
    if (ctype_digit($idA) && ctype_digit($idB)) {
      return (int) $idA <=> (int) $idB;
    }
    return strcmp($idA, $idB);
  }

  /**
   * Applies --from and --limit to the sorted items.
   *
   * @param list<string> $units
   *   The sorted items.
   * @param string|null $from
   *   The first item to keep.
   * @param int $limit
   *   The most items to keep; 0 for all.
   *
   * @return array{0: list<string>, 1: ?string}
   *   The items to handle, and the first item left for a later run.
   */
  private static function slice(array $units, ?string $from, int $limit): array {
    if ($from !== NULL) {
      $units = array_values(array_filter($units, static fn (string $unit): bool => self::compareUnits($unit, $from) >= 0));
    }
    if ($limit > 0 && count($units) > $limit) {
      return [array_slice($units, 0, $limit), $units[$limit]];
    }
    return [$units, NULL];
  }

  /**
   * Writes one configuration save to the rollback log.
   *
   * @param \Drupal\solo_utilities\Migration\RollbackLog|null $log
   *   The log, or NULL.
   * @param string $name
   *   The configuration name.
   * @param array<mixed> $before
   *   The raw data before the save.
   * @param array<mixed> $after
   *   The raw data after the save.
   */
  private static function logConfig(?RollbackLog $log, string $name, array $before, array $after): void {
    $log?->write(['kind' => 'config', 'name' => $name, 'before' => $before, 'after' => $after]);
  }

  /**
   * Runs a rewrite callback on one configuration object.
   *
   * @param string $name
   *   The configuration name.
   * @param string $store
   *   The store label for the report.
   * @param bool $apply
   *   Whether to save the changes.
   * @param \Drupal\solo_utilities\Migration\RollbackLog|null $log
   *   Receives the data before and after the save, when given.
   * @param list<array<string, string>> $rows
   *   Report rows, appended to.
   * @param callable $callback
   *   Receives the raw data and a findings list by reference; returns data.
   */
  private function rewrite(string $name, string $store, bool $apply, ?RollbackLog $log, array &$rows, callable $callback): void {
    $config = $this->configFactory->getEditable($name);
    $data = $config->getRawData();
    /** @var list<array<string, string>> $found */
    $found = [];
    $new = $callback($data, $found);
    foreach ($found as $item) {
      $rows[] = self::row($store, $name, $item['path'], $item['before'], $item['after'], $item['note']);
    }
    if ($apply && $new !== $data) {
      $config->setData($new)->save();
      self::logConfig($log, $name, $data, $config->getRawData());
    }
  }

  /**
   * Walks Views display options and renames every class-list value.
   *
   * @param array<mixed> $options
   *   Display options.
   * @param string $path
   *   The config path of the options, for the report.
   * @param list<array<string, string>> $found
   *   Findings, appended to.
   *
   * @return array<mixed>
   *   The options.
   */
  private function walkViews(array $options, string $path, array &$found): array {
    foreach ($options as $key => $value) {
      if (is_array($value)) {
        $options[$key] = $this->walkViews($value, $path . '.' . $key, $found);
      }
      elseif (is_string($value) && in_array($key, self::VIEWS_CLASS_KEYS, TRUE) && $value !== '') {
        $options[$key] = $this->classList($value, $path . '.' . $key, $found);
      }
    }
    return $options;
  }

  /**
   * Renames the values of every class attribute in an HTML tag list.
   *
   * @param string $html
   *   The tags.
   * @param string $path
   *   The config path, for the report.
   * @param list<array<string, string>> $found
   *   Findings, appended to.
   */
  private function classAttributes(string $html, string $path, array &$found): string {
    return preg_replace_callback(
      '/(\bclass\s*=\s*")([^"]*)(")/i',
      fn (array $m): string => $m[1] . $this->classList($m[2], $path, $found) . $m[3],
      $html,
    ) ?? $html;
  }

  /**
   * Renames one class list and records what changed or needs checking.
   *
   * @param string $classes
   *   Space-separated classes.
   * @param string $path
   *   The config path, for the report.
   * @param list<array<string, string>> $found
   *   Findings, appended to.
   */
  private function classList(string $classes, string $path, array &$found): string {
    $result = $this->engine->migrateClassList($classes);
    if ($result['renamed'] !== []) {
      $found[] = ['path' => $path, 'before' => $classes, 'after' => $result['value'], 'note' => ''];
    }
    foreach ($result['notes'] as $note) {
      $found[] = ['path' => $path, 'before' => $note['token'], 'after' => $note['new'], 'note' => $note['kind']];
    }
    return $result['value'];
  }

  /**
   * Builds a report row.
   *
   * @return array{store: string, name: string, path: string, before: string, after: string, note: string}
   *   The row. An empty note means a rename; otherwise the review kind.
   */
  private static function row(string $store, string $name, string $path, string $before, string $after, string $note = ''): array {
    return [
      'store' => $store,
      'name' => $name,
      'path' => $path,
      'before' => $before,
      'after' => $after,
      'note' => $note,
    ];
  }

}
