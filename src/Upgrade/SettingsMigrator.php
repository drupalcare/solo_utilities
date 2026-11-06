<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Upgrade;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Extension\ExtensionList;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\solo_utilities\Settings\ChoiceNormalizer;
use Drupal\solo_utilities\Theme\SoloThemeDetectionInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Moves stored Solo settings from their 1.x names to the new names.
 *
 * Rules, from the map document (planning/solo-config-map.md, sections 5 to 7):
 * an absent key stays absent and an empty one stays empty; a value that cannot
 * be cast stays under its old key; unknown keys stay where they are; the data
 * decides what is left to do, so no flag is stored and a second run changes
 * nothing. Logs carry theme and key names, never a value.
 *
 * @internal
 */
final class SettingsMigrator {

  /**
   * The key-value collection holding raw settings from before the migration.
   *
   * It holds real settings data: never log or export it.
   */
  public const BACKUP_COLLECTION = 'solo_utilities.settings_backup';

  /**
   * The name of the default config collection.
   */
  private const DEFAULT_COLLECTION = StorageInterface::DEFAULT_COLLECTION;

  public function __construct(
    private readonly SoloThemeDetectionInterface $detection,
    private readonly SettingsMap $map,
    private readonly StorageInterface $storage,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ConfigManagerInterface $configManager,
    private readonly TypedConfigManagerInterface $typedConfig,
    private readonly EventDispatcherInterface $eventDispatcher,
    private readonly KeyValueFactoryInterface $keyValue,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly ExtensionList $themeList,
    private readonly ExtensionList $moduleList,
    private readonly ChoiceNormalizer $choiceNormalizer,
  ) {}

  /**
   * The themes to migrate: Solo and every theme on disk whose chain reaches it.
   *
   * @return list<string>
   *   Theme machine names.
   */
  public function themes(): array {
    return $this->detection->chainOnDisk();
  }

  /**
   * Applies the map to one settings array, in memory only.
   *
   * @param array<string|int, mixed> $settings
   *   Raw stored settings.
   *
   * @throws \RuntimeException
   *   When the map cannot be read.
   */
  public function transform(array $settings): TransformResult {
    $data = $settings;
    $log = ['unknown' => [], 'unmovable' => [], 'differences' => []];
    $folds = [];

    foreach ($this->map->rows() as $old => $row) {
      if (!array_key_exists($old, $data)) {
        continue;
      }
      switch ($row['action']) {
        case 'keep_core':
          break;

        case 'drop':
          unset($data[$old]);
          break;

        case 'fan_out_layouts':
          $this->fanOutLayouts($data, $old, $log);
          break;

        case 'fold_align_center':
          $folds[] = $old;
          break;

        default:
          $this->place($data, $old, (string) $row['new'], $this->castFor($row, $data[$old]), $log);
      }
    }

    // Folds run last, so they see the alignment key the rows above moved.
    foreach ($folds as $old) {
      $this->foldAlignCenter($data, $old, (string) $this->map->rows()[$old]['new'], $log);
    }

    // Everything still at top level is a legacy shape, new or unknown. A key
    // already reported above has a row, so lookup() marks it as not legacy.
    foreach (array_keys($data) as $key) {
      $key = (string) $key;
      if ($this->map->isNewTopLevel($key)) {
        continue;
      }
      $row = $this->map->lookup($key);
      if ($row === NULL) {
        $log['unknown'][] = $key;
      }
      elseif (!empty($row['legacy'])) {
        if ($row['action'] === 'drop') {
          unset($data[$key]);
        }
        else {
          // A legacy shape lands inside an "ignore" subtree: verbatim.
          $this->place($data, $key, (string) $row['new'], [TRUE, $data[$key]], $log);
        }
      }
    }

    // A value the schema's Choice list rejects would fail a later config
    // import, so it returns to its default here (the one shared rule).
    $normalised = $this->choiceNormalizer->normalize($data, 'theme settings');
    $data = $normalised['data'];
    foreach (array_keys($normalised['changes']) as $path) {
      $log['differences'][] = $path . ' (reset to default)';
    }

    return new TransformResult($data, $log['unknown'], $log['unmovable'], $log['differences']);
  }

  /**
   * Migrates one theme, every collection, validating before any write.
   *
   * Order: read and transform all, validate all, back up, then save. The
   * default collection is saved last, so a storage failure part way leaves the
   * page on old keys and a re-run finishes the job.
   *
   * @param string $theme
   *   The theme machine name. The caller proves it is in the chain.
   * @param bool $refresh_backup
   *   TRUE for a newly installed theme: any older backup is from a previous
   *   install. Otherwise the first backup wins, so a re-run cannot replace
   *   the pre-migration data with half-migrated data.
   *
   * @return array{changed: bool, unknown: list<string>, unmovable: list<string>, differences: list<string>}
   *   Key names only.
   *
   * @throws \Exception
   *   When the result fails validation or storage fails.
   */
  public function migrateTheme(string $theme, bool $refresh_backup = FALSE): array {
    $name = $theme . '.settings';
    [$pending, $report] = $this->inspect($theme);
    unset($report['stored']);
    $report = ['changed' => FALSE] + $report;

    if ($pending !== []) {
      $backup = $refresh_backup ? [] : (array) $this->backupStore()->get($theme, []);
      foreach ($pending as $collection => $change) {
        $backup[$collection] ??= $change['raw'];
      }
      $this->backupStore()->set($theme, $backup);

      uksort($pending, static fn(string|int $a, string|int $b): int => ((string) $a === self::DEFAULT_COLLECTION) <=> ((string) $b === self::DEFAULT_COLLECTION));
      foreach ($pending as $collection => $change) {
        $this->write($name, (string) $collection, $change['data']);
      }
      $report['changed'] = TRUE;
    }

    $this->logReport($theme, $report);
    return $report;
  }

  /**
   * Reports what a migration of one theme would do. It writes nothing.
   *
   * @param string $theme
   *   The theme machine name.
   *
   * @return array{stored: bool, waiting: bool, unknown: list<string>, unmovable: list<string>, differences: list<string>}
   *   Whether settings are stored, whether a migration would change them, and
   *   key names only.
   *
   * @throws \Exception
   *   When the map cannot be read or the result would fail validation.
   */
  public function status(string $theme): array {
    [$pending, $report] = $this->inspect($theme);
    return ['waiting' => $pending !== []] + $report;
  }

  /**
   * Whether settings from before the migration are held for a theme.
   */
  public function hasBackup(string $theme): bool {
    return $this->backupStore()->has($theme);
  }

  /**
   * Migrates every theme in the chain; one failure does not stop the others.
   *
   * @return list<string>
   *   The themes that failed.
   */
  public function migrateAll(): array {
    $failed = [];
    foreach ($this->themes() as $theme) {
      if (!$this->tryMigrate($theme)) {
        $failed[] = $theme;
      }
    }
    return $failed;
  }

  /**
   * Migrates one theme, and logs a failure by theme and exception class.
   *
   * @param string $theme
   *   The theme machine name.
   * @param bool $refresh_backup
   *   See migrateTheme().
   * @param array<string, mixed> $report
   *   Filled with the report on success.
   *
   * @return bool
   *   FALSE when the theme failed. An exception message may quote data, so it
   *   is not logged.
   */
  public function tryMigrate(string $theme, bool $refresh_backup = FALSE, array &$report = []): bool {
    try {
      $report = $this->migrateTheme($theme, $refresh_backup);
      return TRUE;
    }
    catch (\Throwable $e) {
      $this->logger()->error('Migrating the settings of theme @theme failed (@class).', [
        '@theme' => $theme,
        '@class' => $e::class,
      ]);
      return FALSE;
    }
  }

  /**
   * Applies the map to config objects in a storage. Used on import.
   *
   * Touches only themes proven to reach Solo from disk. No backup: this data
   * is a transient copy of what is in config/sync.
   */
  public function transformStorage(StorageInterface $storage): void {
    $themes = $this->themes();
    if ($themes === []) {
      return;
    }
    $collections = array_merge([self::DEFAULT_COLLECTION], $storage->getAllCollectionNames());
    foreach ($collections as $collection) {
      $target = $this->collectionStorage($storage, $collection);
      foreach ($themes as $theme) {
        $name = $theme . '.settings';
        $raw = $target->read($name);
        if (!is_array($raw)) {
          continue;
        }
        $result = $this->transform($raw);
        $this->validate($result);
        if ($result->data !== $raw) {
          $target->write($name, $result->data);
          if ($result->unmovable !== []) {
            $this->logger()->warning('On import, these keys of @name could not be moved: @keys.', [
              '@name' => $name,
              '@keys' => implode(', ', $result->unmovable),
            ]);
          }
        }
      }
    }
  }

  /**
   * Logs active config that holds map keys but has no provable theme on disk.
   *
   * Such an object is never touched, because its chain cannot be proven.
   *
   * @return list<string>
   *   The config object names, sorted.
   */
  public function reportUnprovable(): array {
    $found = $this->findUnprovable();
    if ($found !== []) {
      $this->logger()->warning('Left unmigrated, no provable Solo theme on disk: @names. Restore the code and run updates again, or delete the config.', ['@names' => implode(', ', $found)]);
    }
    return $found;
  }

  /**
   * Finds active config that holds map keys but has no provable theme on disk.
   *
   * @return list<string>
   *   The config object names, sorted.
   */
  public function findUnprovable(): array {
    $rows = $this->map->rows();
    $found = [];
    foreach ($this->storage->listAll() as $name) {
      if (!preg_match('/^([a-z0-9_]+)\.settings$/', $name, $matches) || $this->isProvable($matches[1])) {
        continue;
      }
      foreach (array_keys((array) $this->storage->read($name)) as $key) {
        if (isset($rows[$key]) && $rows[$key]['action'] !== 'keep_core') {
          $found[] = $name;
          break;
        }
      }
    }
    sort($found);
    return $found;
  }

  /**
   * Writes every backup to its theme. Newer settings are replaced.
   *
   * A theme no longer provable from disk is skipped. A restored backup is
   * deleted, as the theme now holds the same data: a later migration then backs
   * up afresh.
   *
   * @return array{restored: list<string>, skipped: list<string>, failed: list<string>}
   *   Theme machine names.
   */
  public function restore(): array {
    $chain = $this->themes();
    $result = ['restored' => [], 'skipped' => [], 'failed' => []];
    foreach ($this->backupStore()->getAll() as $theme => $collections) {
      $theme = (string) $theme;
      if (!in_array($theme, $chain, TRUE)) {
        $result['skipped'][] = $theme;
        continue;
      }
      try {
        foreach ((array) $collections as $collection => $raw) {
          $this->write($theme . '.settings', (string) $collection, (array) $raw);
        }
        $this->backupStore()->delete($theme);
        $result['restored'][] = $theme;
      }
      catch (\Throwable $e) {
        $this->logger()->error('Restoring the settings of theme @theme failed (@class).', [
          '@theme' => $theme,
          '@class' => $e::class,
        ]);
        $result['failed'][] = $theme;
      }
    }
    return $result;
  }

  /**
   * Transforms and validates one theme in every collection, in memory only.
   *
   * @param string $theme
   *   The theme machine name.
   *
   * @return array{0: array<string|int, array{raw: array<string|int, mixed>, data: array<string|int, mixed>}>, 1: array{stored: bool, unknown: list<string>, unmovable: list<string>, differences: list<string>}}
   *   The collections that would change, and the report of key names.
   *
   * @throws \Exception
   *   When the map cannot be read or a result fails validation.
   */
  private function inspect(string $theme): array {
    $name = $theme . '.settings';
    $pending = [];
    $report = ['stored' => FALSE, 'unknown' => [], 'unmovable' => [], 'differences' => []];

    foreach ($this->collections() as $collection) {
      $raw = $this->collectionStorage($this->storage, $collection)->read($name);
      if (!is_array($raw)) {
        continue;
      }
      $report['stored'] = TRUE;
      $result = $this->transform($raw);
      $this->validate($result);
      $report['unknown'] = array_merge($report['unknown'], $result->unknown);
      $report['unmovable'] = array_merge($report['unmovable'], $result->unmovable);
      $report['differences'] = array_merge($report['differences'], $result->differences);
      if ($result->data !== $raw) {
        $pending[$collection] = ['raw' => $raw, 'data' => $result->data];
      }
    }

    foreach (['unknown', 'unmovable', 'differences'] as $list) {
      $report[$list] = array_values(array_unique($report[$list]));
    }
    return [$pending, $report];
  }

  /**
   * Casts or converts a value for its row.
   *
   * @param array<string, mixed> $row
   *   The map row.
   * @param mixed $value
   *   The stored value.
   *
   * @return array{0: bool, 1: mixed}
   *   Whether the value can move, and the value to store.
   */
  private function castFor(array $row, mixed $value): array {
    return match ($row['action']) {
      'scheme_name' => $this->schemeName($value),
      'map_template_assignments' => $this->templateAssignments($value),
      default => $this->cast((string) ($row['type'] ?? ''), $value),
    };
  }

  /**
   * Casts to a schema type, per the table in section 5 of the map document.
   *
   * Explicit, because core passes undefined keys through uncast and a sub-theme
   * with no schema gets no cast at all.
   *
   * @return array{0: bool, 1: mixed}
   *   Whether the pairing is allowed, and the cast value.
   */
  public function cast(string $type, mixed $value): array {
    if ($value === NULL) {
      return [TRUE, NULL];
    }
    return match ($type) {
      'boolean' => in_array($value, [TRUE, FALSE, 0, 1, '0', '1', ''], TRUE) ? [TRUE, (bool) $value] : [FALSE, NULL],
      'integer' => match (TRUE) {
        $value === '' => [TRUE, NULL],
        is_numeric($value) && (float) $value === (float) (int) $value => [TRUE, (int) $value],
        default => [FALSE, NULL],
      },
      'string' => is_scalar($value) ? [TRUE, (string) $value] : [FALSE, NULL],
      'sequence', 'ignore' => is_array($value) ? [TRUE, $value] : [FALSE, NULL],
      default => [FALSE, NULL],
    };
  }

  /**
   * The scheme name: the part after "|" if there is one, else the whole value.
   *
   * @return array{0: bool, 1: mixed}
   *   Whether the value can move, and the name.
   */
  private function schemeName(mixed $value): array {
    if ($value === NULL) {
      return [TRUE, NULL];
    }
    if (!is_scalar($value)) {
      return [FALSE, NULL];
    }
    $parts = explode('|', (string) $value);
    return [TRUE, $parts[1] ?? $parts[0]];
  }

  /**
   * Keys a list-shaped template assignment value by menu_id.
   *
   * @return array{0: bool, 1: mixed}
   *   Whether the value can move, and the keyed value.
   */
  private function templateAssignments(mixed $value): array {
    if ($value === NULL) {
      return [TRUE, NULL];
    }
    if (!is_array($value)) {
      return [FALSE, NULL];
    }
    if ($value === [] || !array_is_list($value)) {
      return [TRUE, $value];
    }
    $keyed = [];
    foreach ($value as $item) {
      $id = is_array($item) ? ($item['menu_id'] ?? NULL) : NULL;
      if (!is_string($id) || $id === '') {
        return [FALSE, NULL];
      }
      $keyed[$id] = $item;
    }
    return [TRUE, $keyed];
  }

  /**
   * Moves one old key to a new path.
   *
   * @param array<string|int, mixed> $data
   *   The settings being transformed.
   * @param string $old
   *   The old top-level key.
   * @param string $path
   *   The new dotted path.
   * @param array{0: bool, 1: mixed} $cast
   *   Whether the value can move, and the value.
   * @param array<string, list<string>> $log
   *   The running log of key names.
   */
  private function place(array &$data, string $old, string $path, array $cast, array &$log): void {
    [$can_move, $value] = $cast;
    $parts = explode('.', $path);
    if (NestedArray::keyExists($data, $parts)) {
      // Both present: an import ran first, or this is a re-run. New wins.
      if (!$can_move || NestedArray::getValue($data, $parts) !== $value) {
        $log['differences'][] = $old;
      }
      unset($data[$old]);
      return;
    }
    if (!$can_move || $this->blocked($data, $parts)) {
      $log['unmovable'][] = $old;
      return;
    }
    NestedArray::setValue($data, $parts, $value);
    unset($data[$old]);
  }

  /**
   * Whether a non-array holds a place on the path, such as an unknown key.
   *
   * @param array<string|int, mixed> $data
   *   The settings being transformed.
   * @param list<string> $parts
   *   The path segments.
   */
  private function blocked(array $data, array $parts): bool {
    array_pop($parts);
    $cursor = $data;
    foreach ($parts as $part) {
      if (!array_key_exists($part, $cursor)) {
        return FALSE;
      }
      if (!is_array($cursor[$part])) {
        return TRUE;
      }
      $cursor = $cursor[$part];
    }
    return FALSE;
  }

  /**
   * Moves solo_layouts.<g>.<n>col.<type> to its region group, leaf by leaf.
   *
   * Anything not of that shape stays under the old key.
   *
   * @param array<string|int, mixed> $data
   *   The settings being transformed.
   * @param string $old
   *   The old key.
   * @param array<string, list<string>> $log
   *   The running log of key names.
   */
  private function fanOutLayouts(array &$data, string $old, array &$log): void {
    if (!is_array($data[$old])) {
      if ($data[$old] === NULL) {
        unset($data[$old]);
        return;
      }
      $log['unmovable'][] = $old;
      return;
    }
    $left = [];
    foreach ($data[$old] as $group => $columns) {
      if (!in_array($group, ['top', 'main', 'bottom', 'footer'], TRUE) || !is_array($columns)) {
        $left[$group] = $columns;
        continue;
      }
      foreach ($columns as $count => $types) {
        if (!is_array($types) || !preg_match('/^(\d+)col$/', (string) $count, $matches)) {
          $left[$group][$count] = $types;
          continue;
        }
        $base = 'region_groups.' . $group . '.per_content_type.columns_' . $matches[1];
        if ($types === []) {
          // Empty stays empty.
          $this->setIfAbsent($data, $base, [], $old, $log, $left, $group, $count);
        }
        foreach ($types as $type => $value) {
          $this->setIfAbsent($data, $base . '.' . $type, $value, $old, $log, $left, $group, $count, (string) $type);
        }
      }
    }
    if ($left === []) {
      unset($data[$old]);
    }
    else {
      $data[$old] = $left;
      $log['unmovable'][] = $old;
    }
  }

  /**
   * Places one fan-out leaf; a blocked leaf stays behind under the old key.
   *
   * @param array<string|int, mixed> $data
   *   The settings being transformed.
   * @param string $path
   *   The new dotted path.
   * @param mixed $value
   *   The leaf value, verbatim.
   * @param string $old
   *   The old key.
   * @param array<string, list<string>> $log
   *   The running log of key names.
   * @param array<string|int, mixed> $left
   *   What stays under the old key.
   * @param string|int $group
   *   The old group key.
   * @param string|int $count
   *   The old column key.
   * @param string|null $type
   *   The old content type key, or NULL for an empty column set.
   */
  private function setIfAbsent(array &$data, string $path, mixed $value, string $old, array &$log, array &$left, string|int $group, string|int $count, ?string $type = NULL): void {
    $parts = explode('.', $path);
    if (NestedArray::keyExists($data, $parts)) {
      if (NestedArray::getValue($data, $parts) !== $value) {
        $log['differences'][] = $old;
      }
      return;
    }
    if ($this->blocked($data, $parts)) {
      if ($type === NULL) {
        $left[$group][$count] = [];
      }
      else {
        $left[$group][$count][$type] = $value;
      }
      return;
    }
    NestedArray::setValue($data, $parts, $value);
  }

  /**
   * Folds primary_menu_align_center into the alignment key, then removes it.
   *
   * @param array<string|int, mixed> $data
   *   The settings being transformed.
   * @param string $old
   *   The old key.
   * @param string $path
   *   The alignment path.
   * @param array<string, list<string>> $log
   *   The running log of key names.
   */
  private function foldAlignCenter(array &$data, string $old, string $path, array &$log): void {
    [$can_cast, $center] = $this->cast('boolean', $data[$old]);
    if (!$can_cast) {
      $log['unmovable'][] = $old;
      return;
    }
    $parts = explode('.', $path);
    $alignment = NestedArray::getValue($data, $parts);
    if ($center === TRUE && ($alignment === NULL || $alignment === '')) {
      if ($this->blocked($data, $parts)) {
        $log['unmovable'][] = $old;
        return;
      }
      NestedArray::setValue($data, $parts, 'center');
    }
    unset($data[$old]);
  }

  /**
   * Checks a result before anything is written.
   *
   * @throws \UnexpectedValueException
   *   Naming the key, never its value.
   */
  private function validate(TransformResult $result): void {
    $walk = static function (array $level, string $prefix) use (&$walk): void {
      foreach ($level as $key => $value) {
        if (str_contains((string) $key, '.')) {
          throw new \UnexpectedValueException('A settings key holds a dot: ' . $prefix . $key);
        }
        if (is_array($value)) {
          $walk($value, $prefix . $key . '.');
        }
        elseif ($value !== NULL && !is_scalar($value)) {
          throw new \UnexpectedValueException('A settings value cannot be stored: ' . $prefix . $key);
        }
      }
    };
    $walk($result->data, '');
  }

  /**
   * Writes one config object in one collection, as one save.
   *
   * Values were cast above for the sub-themes core will not cast. The trusted
   * save argument is deprecated from 11.4, so core's own cast runs as well; it
   * passes undefined keys through, so a sub-theme's own keys are not rewritten.
   *
   * @param string $name
   *   The config object name.
   * @param string $collection
   *   The collection name.
   * @param array<string|int, mixed> $data
   *   The data to store.
   */
  private function write(string $name, string $collection, array $data): void {
    if ($collection === self::DEFAULT_COLLECTION) {
      $this->configFactory->getEditable($name)->setData($data)->save();
      return;
    }
    // The same pattern as core's uninstall, ConfigManager::uninstall().
    $storage = $this->storage->createCollection($collection);
    $overrider = $this->configManager->getConfigCollectionInfo()->getOverrideService($collection);
    $config = $overrider
      ? $overrider->createConfigObject($name, $collection)
      : new Config($name, $storage, $this->eventDispatcher, $this->typedConfig);
    if ($data === []) {
      $config->delete();
      return;
    }
    $config->setData($data)->save();
  }

  /**
   * The default collection followed by every other one in active storage.
   *
   * @return list<string>
   *   Collection names.
   */
  private function collections(): array {
    return array_merge([self::DEFAULT_COLLECTION], $this->storage->getAllCollectionNames());
  }

  /**
   * Returns a storage for a collection.
   */
  private function collectionStorage(StorageInterface $storage, string $collection): StorageInterface {
    return $collection === self::DEFAULT_COLLECTION ? $storage : $storage->createCollection($collection);
  }

  /**
   * Whether an object's prefix is an extension whose owner can be proven.
   *
   * A theme with a missing base theme proves nothing, so it is not provable.
   */
  private function isProvable(string $prefix): bool {
    if ($this->moduleList->exists($prefix)) {
      return TRUE;
    }
    if (!$this->themeList->exists($prefix)) {
      return FALSE;
    }
    return $this->detection->hasCompleteChain($prefix);
  }

  /**
   * Logs one theme's report, key names only.
   *
   * @param string $theme
   *   The theme machine name.
   * @param array{changed: bool, unknown: list<string>, unmovable: list<string>, differences: list<string>} $report
   *   The report.
   */
  private function logReport(string $theme, array $report): void {
    $messages = [
      'unknown' => 'Theme @theme: keys left in place, not in the settings map: @keys.',
      'unmovable' => 'Theme @theme: value cannot be moved, left under the old key: @keys.',
      'differences' => 'Theme @theme: old and new keys differed, the new value was kept: @keys.',
    ];
    foreach ($messages as $list => $message) {
      if ($report[$list] !== []) {
        $this->logger()->warning($message, [
          '@theme' => $theme,
          '@keys' => implode(', ', $report[$list]),
        ]);
      }
    }
  }

  /**
   * Returns the backup store.
   */
  private function backupStore(): KeyValueStoreInterface {
    return $this->keyValue->get(self::BACKUP_COLLECTION);
  }

  /**
   * Returns the logger channel.
   */
  private function logger(): LoggerChannelInterface {
    return $this->loggerFactory->get('solo_utilities');
  }

}
