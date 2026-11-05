<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Drush\Commands;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\File\FileSystemInterface;
use Drupal\layout_builder\InlineBlockUsageInterface;
use Drupal\solo_utilities\Migration\ClassMigrationPaths;
use Drupal\solo_utilities\Migration\ClassMigrator;
use Drupal\solo_utilities\Migration\RollbackLog;
use Drupal\solo_utilities\Migration\SoloClassMap;
use Drupal\solo_utilities\Migration\StoredClassMigrator;
use Drupal\solo_utilities\Theme\SoloThemeDetectionInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Drush command that renames Solo 1 classes and ids to the Solo 2 names.
 *
 * @internal
 */
final class SoloClassMigrateCommands extends DrushCommands {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly SoloThemeDetectionInterface $detection,
    private readonly ThemeExtensionList $themeList,
    private readonly FileSystemInterface $fileSystem,
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly RequestStack $requestStack,
    private readonly ?InlineBlockUsageInterface $inlineBlockUsage = NULL,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get(SoloThemeDetectionInterface::class),
      $container->get('extension.list.theme'),
      $container->get('file_system'),
      $container->get('database'),
      $container->get('datetime.time'),
      $container->get('request_stack'),
      $container->get('inline_block.usage', ContainerInterface::NULL_ON_INVALID_REFERENCE),
    );
  }

  /**
   * Rewrite Solo 1 class names and ids in a sub-theme or module to Solo 2.
   *
   * Dry run by default. Reads .css .scss .sass .less .twig .js files and
   * rewrites them; reads .theme .php .module .inc files and only reports
   * them. Never reads .yml, *.min.*, a symbolic link or a hard-linked file.
   * Writes only inside the resolved target, which must be inside the Drupal
   * root and may not be core, Solo or the public files folder. Files are
   * replaced atomically. The --report file is written even in a dry run, and
   * never inside the Drupal root. There is deliberately no web UI for this.
   *
   * @param string $target
   *   A theme machine name, or a directory path inside the Drupal root.
   * @param array<string, mixed> $options
   *   Drush options.
   *
   * @return int
   *   0 on success, 1 when anything failed or was refused.
   */
  #[CLI\Command(name: 'solo:migrate-classes', aliases: ['solo-migrate-classes'])]
  #[CLI\Argument(name: 'target', description: 'Theme machine name, or a directory inside the Drupal root, such as web/themes/custom/mytheme or web/modules/custom/my_module. A relative path is looked up from the current folder, then the Composer project folder, then the Drupal root.')]
  #[CLI\Option(name: 'apply', description: 'Write the changes. Without it nothing is written, in files or in the database.')]
  #[CLI\Option(name: 'report', description: 'Write a Markdown report of every change and every item to check by hand to this new file (mode 0600), even in a dry run. A relative name that would land inside the web root goes to the Composer project folder; an absolute path inside the web root is refused.')]
  #[CLI\Option(name: 'ext', description: 'Comma-separated extensions to process, narrowing the default set. Allowed: css, scss, sass, less, twig, js, theme, php, module, inc.')]
  #[CLI\Option(name: 'stored', description: 'Also rename classes stored in configuration: the Solo inline CSS, Dynamic CSS and Dynamic JS settings, Block Class classes, Views classes and text format allowed tags (with CKEditor 5). Files under sites/*/files are reported, never written. Reported first.')]
  #[CLI\Option(name: 'content', description: 'Also rename Solo ids (only ids) in formatted text fields, paragraphs, Layout Builder inline blocks and menu link attributes. The default revision and the latest draft are rewritten; older revisions are counted and left. With --apply each changed item is saved as a new revision, and --backup-confirmed and --report are required.')]
  #[CLI\Option(name: 'backup-confirmed', description: 'With --apply --content: you have taken a database backup and checked that it restores. Without it --apply --content refuses and writes nothing.')]
  #[CLI\Option(name: 'revision-user', description: 'User id or account name recorded as the author of the new revisions. Default: user 1.')]
  #[CLI\Option(name: 'limit', description: 'Handle at most this many content items, then stop and print the --from value to carry on.')]
  #[CLI\Option(name: 'from', description: 'Start at this content item, given as entity_type:id (as printed by --limit).')]
  #[CLI\Option(name: 'max-failures', description: 'Stop after this many content items failed. Default: 10.')]
  #[CLI\Usage(name: 'drush solo:migrate-classes mytheme', description: 'Dry run: count what would change in theme "mytheme".')]
  #[CLI\Usage(name: 'drush solo:migrate-classes mytheme --stored --content --report=solo-report.md', description: 'Dry run of files, configuration and content, with a full report (run from the project folder).')]
  #[CLI\Usage(name: 'drush solo:migrate-classes mytheme --apply', description: 'Write the file changes.')]
  #[CLI\Usage(name: 'drush solo:migrate-classes mytheme --stored --content --apply --backup-confirmed --report=solo-report.md', description: 'After a database backup: write files, configuration and content, with a report and a rollback log beside it (solo-report.md.rollback.jsonl).')]
  #[CLI\Usage(name: 'drush solo:migrate-classes modules/custom/my_module --ext=css,js', description: 'Only stylesheets and JavaScript, in a custom module.')]
  public function migrateClasses(
    string $target,
    array $options = [
      'apply' => FALSE,
      'report' => NULL,
      'ext' => NULL,
      'stored' => FALSE,
      'content' => FALSE,
      'backup-confirmed' => FALSE,
      'revision-user' => NULL,
      'limit' => NULL,
      'from' => NULL,
      'max-failures' => 10,
    ],
  ): int {
    $ext = $options['ext'] ?? NULL;
    $argv = $_SERVER['argv'] ?? [];
    $extCount = count(array_filter(is_array($argv) ? $argv : [], static fn ($arg): bool => is_string($arg) && ($arg === '--ext' || str_starts_with($arg, '--ext='))));
    if ($extCount > 1) {
      $this->logger()->error(dt('Give --ext once, with a comma-separated list, for example --ext=css,js.'));
      return 1;
    }
    $reportOption = $options['report'] ?? NULL;
    if ($this->input()->hasParameterOption('--report') && (!is_string($reportOption) || trim($reportOption) === '')) {
      $this->logger()->error(dt('--report needs a file name, for example --report=solo-report.md.'));
      return 1;
    }
    if ($this->input()->hasParameterOption('--ext') && (!is_string($ext) || trim($ext) === '')) {
      $this->logger()->error(dt('--ext needs a value, for example --ext=css,scss. Leave it out to process all of: @list.', [
        '@list' => implode(', ', ClassMigrator::EXTENSIONS),
      ]));
      return 1;
    }
    $apply = !empty($options['apply']);
    $content = !empty($options['content']);
    $stored = !empty($options['stored']);
    // Owner decision OD4: no content is written without the operator's word
    // that a backup exists, and without a rollback log beside the report.
    if ($apply && $content) {
      if (empty($options['backup-confirmed'])) {
        $this->logger()->error(dt('--apply --content rewrites stored content. Take a database backup first (for example drush sql:dump --result-file=../solo-backup.sql), check that it restores, then add --backup-confirmed. Nothing was changed.'));
        return 1;
      }
      if (!is_string($reportOption) || trim($reportOption) === '') {
        $this->logger()->error(dt('--apply --content needs --report=<file>: the rollback log is written beside the report. Nothing was changed.'));
        return 1;
      }
    }
    $limit = $this->intOption($options['limit'] ?? NULL, 'limit', 0, 1);
    $maxFailures = $this->intOption($options['max-failures'] ?? NULL, 'max-failures', 10, 1);
    $from = $options['from'] ?? NULL;
    if ($from !== NULL && (!is_string($from) || preg_match('/^[a-z0-9_]+:[A-Za-z0-9_.-]+$/', $from) !== 1)) {
      $this->logger()->error(dt('--from needs an item as entity_type:id, for example --from=node:120.'));
      return 1;
    }
    $revisionUid = $this->revisionUser($options['revision-user'] ?? NULL);
    if ($limit === NULL || $maxFailures === NULL || $revisionUid === NULL) {
      return 1;
    }
    try {
      $migrator = SoloClassMap::migrator();
      $extensions = $migrator->normalizeExtensions(is_string($ext) ? $ext : NULL);
      $paths = $this->migrationPaths();
      $cwd = $this->getConfig()->cwd();
      $root = $paths->resolveTarget($target, $cwd);
      $reportFile = NULL;
      $reportMovedFrom = NULL;
      if (is_string($reportOption) && $reportOption !== '') {
        $resolved = $paths->resolveReport($reportOption, $cwd);
        $reportFile = $resolved['path'];
        $reportMovedFrom = $resolved['moved_from'];
      }
      $logFile = NULL;
      if ($apply && ($stored || $content) && is_string($reportOption) && $reportOption !== '') {
        $logFile = $paths->resolveReport($reportOption . '.rollback.jsonl', $cwd)['path'];
      }
    }
    catch (\InvalidArgumentException | \RuntimeException $e) {
      $this->logger()->error($e->getMessage());
      return 1;
    }

    // Create the report before touching the theme, so a bad report path
    // stops the run before any theme file is written. 'x' never overwrites,
    // and 0600 keeps theme source lines private on a shared host.
    $reportHandle = NULL;
    if ($reportFile !== NULL) {
      $reportHandle = @fopen($reportFile, 'x');
      if ($reportHandle === FALSE) {
        $error = error_get_last();
        $this->logger()->error(dt('Could not create the report @file: @reason. Nothing was changed.', [
          '@file' => $reportFile,
          '@reason' => $error['message'] ?? 'unknown error',
        ]));
        return 1;
      }
      @chmod($reportFile, 0600);
    }

    $site = $this->siteUri();
    $time = gmdate('Y-m-d\\TH:i:s\\Z', $this->time->getRequestTime());
    $checksum = hash_file('sha256', dirname(__DIR__, 3) . '/' . SoloClassMap::FILE) ?: 'unknown';
    $log = NULL;
    if ($logFile !== NULL) {
      try {
        $log = RollbackLog::create($logFile, ['site' => $site, 'time' => $time, 'map_sha256' => $checksum]);
      }
      catch (\RuntimeException $e) {
        $this->logger()->error($e->getMessage() . ' ' . dt('Nothing was changed.'));
        if ($reportHandle !== NULL) {
          fclose($reportHandle);
        }
        return 1;
      }
    }

    $relativeRoot = $paths->relative($root);
    $report = [
      '# Solo 2 class migration report',
      '',
      'Full-site rollback is your database backup: take one before --apply and check that it restores. The rollback log undoes this run item by item (drush solo:migrate-classes-rollback).',
      '',
      'Target: ' . $relativeRoot . ' (relative to the Drupal root)',
      'Mode: ' . ($apply ? 'APPLIED' : 'dry run'),
      'Site: ' . $site,
      'Time: ' . $time,
      'Class map sha256: ' . $checksum,
      'Backup: ' . (!$apply ? 'dry run, nothing is written' : ($content ? 'confirmed by the operator (--backup-confirmed)' : 'not asked for (no content is written)')),
      'Rollback log: ' . ($log !== NULL ? $log->path() : 'none'),
      'Extensions: ' . implode(', ', $extensions),
      '',
    ];
    $review = [];
    $changedLines = 0;
    $changedFiles = 0;
    $failed = 0;

    // Stored values are reported first: they change the live site at once,
    // while theme files change only after a cache rebuild.
    if ($stored || $content) {
      $contentResult = NULL;
      try {
        $storedMigrator = new StoredClassMigrator($migrator, $this->configFactory, $this->entityTypeManager, $this->entityFieldManager, $this->detection, $this->database, $this->time, $this->inlineBlockUsage);
        $rows = [];
        if ($stored) {
          $rows = array_merge(
            $storedMigrator->inlineCss($apply, $log),
            $storedMigrator->customCode($apply, $log),
            $storedMigrator->configuration($apply, $log),
            $this->publicFileRows($migrator),
          );
        }
        if ($content) {
          $contentResult = $storedMigrator->content($apply, $log, $revisionUid, $limit, $from, $maxFailures);
          $rows = array_merge($rows, $contentResult['rows']);
        }
      }
      catch (\Throwable $e) {
        $this->logger()->error(dt('Stored values: @message. No theme file was changed.', ['@message' => $e->getMessage()]));
        $log?->finish();
        if ($reportHandle !== NULL) {
          fclose($reportHandle);
        }
        return 1;
      }
      $log?->finish();
      $report[] = '# Stored values (configuration' . ($content ? ' and content' : '') . ')';
      $report[] = '';
      $report[] = '| Store | Name | Where | Before | After | Check |';
      $report[] = '|---|---|---|---|---|---|';
      foreach ($rows as $row) {
        $cells = [$row['store']];
        foreach (['name', 'path', 'before', 'after'] as $key) {
          $cells[] = self::markdownCode(mb_substr(str_replace('|', '\\|', $row[$key]), 0, 120));
        }
        $cells[] = $row['note'];
        $report[] = '| ' . implode(' | ', $cells) . ' |';
      }
      $report[] = '';
      $renames = count(array_filter($rows, static fn (array $row): bool => $row['note'] === ''));
      $this->logger()->success(dt('Stored values: @n rename(s) @verb, @r item(s) to check by hand.', [
        '@n' => $renames,
        '@verb' => $apply ? 'saved' : 'would be saved',
        '@r' => count($rows) - $renames,
      ]));
      if ($contentResult !== NULL) {
        $failed += $this->contentSummary($contentResult, $report);
      }
    }

    $scan = $migrator->scan($root, $extensions);
    $scan['readonly'] = [];
    $scan['owner'] = [];
    $euid = function_exists('posix_geteuid') ? posix_geteuid() : NULL;
    foreach ($scan['files'] as $path) {
      $real = realpath($path);
      if ($real === FALSE || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
        continue;
      }
      $relative = substr($real, strlen($root) + 1);
      // Never replace a read-only file, or one owned by another user (the
      // replacement would belong to whoever runs Drush, e.g. root via sudo).
      if (!is_writable($real)) {
        $scan['readonly'][] = $real;
        continue;
      }
      if ($euid !== NULL && fileowner($real) !== $euid) {
        $scan['owner'][] = $real;
        continue;
      }
      $contents = file_get_contents($real);
      if ($contents === FALSE) {
        $this->logger()->warning(dt('Could not read @file.', ['@file' => $relative]));
        $failed++;
        continue;
      }
      $result = $migrator->migrate($contents, $real);
      foreach ($result['notes'] as $note) {
        $review[$note['kind']][] = [$relative] + $note;
      }
      if ($result['changes'] === []) {
        continue;
      }
      if ($apply && !ClassMigrationPaths::writeAtomic($real, $result['content'])) {
        $this->logger()->error(dt('Could not write @file; it was left unchanged.', ['@file' => $relative]));
        $failed++;
        continue;
      }
      $changedFiles++;
      $changedLines += count($result['changes']);
      $report[] = '## ' . $relative;
      $report[] = '';
      foreach ($result['changes'] as $change) {
        $report[] = '- L' . $change['line'];
        $report[] = '  - ' . self::markdownCode($change['before']);
        $report[] = '  + ' . self::markdownCode($change['after']);
      }
      $report[] = '';
      $this->output()->writeln(dt('@file: @n line(s)', ['@file' => $relative, '@n' => count($result['changes'])]));
    }

    $skipped = [
      'symlinks' => dt('Symbolic links skipped (not followed; migrate their targets separately)'),
      'hardlinks' => dt('Hard-linked files skipped (the same file has another name elsewhere)'),
      'readonly' => dt('Read-only files skipped (make them writable to migrate them)'),
      'owner' => dt('Files owned by another user skipped (run Drush as the user who owns the theme files)'),
    ];
    foreach ($skipped as $key => $label) {
      if ($scan[$key] === []) {
        continue;
      }
      $report[] = sprintf('## %s (%d)', $label, count($scan[$key]));
      $report[] = '';
      foreach ($scan[$key] as $path) {
        $report[] = '- ' . self::markdownCode(substr($path, strlen($root) + 1));
      }
      $report[] = '';
      $this->logger()->warning(dt('@label: @n', ['@label' => $label, '@n' => count($scan[$key])]));
    }

    $reviewCount = 0;
    if ($review !== []) {
      $report[] = '# Needs manual review';
      $report[] = '';
    }
    foreach (ClassMigrator::NOTE_LABELS as $kind => $label) {
      if (empty($review[$kind])) {
        continue;
      }
      $reviewCount += count($review[$kind]);
      $report[] = sprintf('## %s (%d)', $label, count($review[$kind]));
      $report[] = '';
      $report[] = '| File | Line | Token | New | Source |';
      $report[] = '|---|---|---|---|---|';
      foreach ($review[$kind] as $note) {
        $source = mb_substr(str_replace('|', '\\|', $note['source']), 0, 120);
        $report[] = sprintf('| %s | %d | %s | %s | %s |', $note[0], $note['line'], self::markdownCode($note['token']), $note['new'], self::markdownCode($source));
      }
      $report[] = '';
      $this->output()->writeln(sprintf('  - %-18s %4d  %s', $kind, count($review[$kind]), $label));
    }

    $this->logger()->success(dt('@mode @lines line(s) in @files file(s) @verb; @review item(s) need manual review; @skipped file(s) skipped.', [
      '@mode' => $apply ? 'Applied:' : 'Dry run:',
      '@lines' => $changedLines,
      '@files' => $changedFiles,
      '@verb' => $apply ? 'changed' : 'would change',
      '@review' => $reviewCount,
      '@skipped' => count($scan['symlinks']) + count($scan['hardlinks']) + count($scan['readonly']) + count($scan['owner']),
    ]));

    if ($reportHandle !== NULL && $reportFile !== NULL) {
      $body = implode("\n", $report) . "\n";
      $written = fwrite($reportHandle, $body);
      fclose($reportHandle);
      if ($written !== strlen($body)) {
        $this->logger()->error(dt('Could not write the whole report to @file.', ['@file' => $reportFile]));
        return 1;
      }
      if ($reportMovedFrom !== NULL) {
        $this->logger()->success(dt('Report written to @file (not @asked: that is inside the web root, so it went to the project folder)', [
          '@file' => $reportFile,
          '@asked' => $reportMovedFrom,
        ]));
      }
      else {
        $this->logger()->success(dt('Report written to @file', ['@file' => $reportFile]));
      }
    }
    elseif (!$apply) {
      $this->logger()->success(dt('Nothing was written. Add --report=solo-report.md to list every change, then --apply to write them.'));
    }

    return $failed > 0 ? 1 : 0;
  }

  /**
   * Undo a solo:migrate-classes run from its rollback log.
   *
   * Dry run by default. A value is restored only while it still holds what the
   * run wrote; a value edited since then is listed as a conflict and left as
   * it is. Content is saved as a new revision, the way the run saved it.
   * Configuration is restored only when it is unchanged since the run. This is
   * not a database restore: for that, use the backup taken before the run.
   *
   * @param string $log
   *   The rollback log written beside the run's report.
   * @param array<string, mixed> $options
   *   Drush options.
   *
   * @return int
   *   0 on success, 1 when anything failed or was refused.
   */
  #[CLI\Command(name: 'solo:migrate-classes-rollback', aliases: ['solo-migrate-classes-rollback'])]
  #[CLI\Argument(name: 'log', description: 'The rollback log, for example solo-report.md.rollback.jsonl.')]
  #[CLI\Option(name: 'apply', description: 'Restore the values. Without it nothing is written.')]
  #[CLI\Option(name: 'revision-user', description: 'User id or account name recorded as the author of the new revisions. Default: user 1.')]
  #[CLI\Option(name: 'max-failures', description: 'Stop after this many items failed. Default: 10.')]
  #[CLI\Usage(name: 'drush solo:migrate-classes-rollback ../solo-report.md.rollback.jsonl', description: 'Dry run: list what would be restored and every conflict.')]
  public function migrateClassesRollback(
    string $log,
    array $options = [
      'apply' => FALSE,
      'revision-user' => NULL,
      'max-failures' => 10,
    ],
  ): int {
    $apply = !empty($options['apply']);
    $maxFailures = $this->intOption($options['max-failures'] ?? NULL, 'max-failures', 10, 1);
    $revisionUid = $this->revisionUser($options['revision-user'] ?? NULL);
    if ($maxFailures === NULL || $revisionUid === NULL) {
      return 1;
    }
    $path = str_starts_with($log, DIRECTORY_SEPARATOR) ? $log : rtrim($this->getConfig()->cwd(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $log;
    try {
      $records = RollbackLog::read($path);
      $stored = new StoredClassMigrator(SoloClassMap::migrator(), $this->configFactory, $this->entityTypeManager, $this->entityFieldManager, $this->detection, $this->database, $this->time, $this->inlineBlockUsage);
      $result = $stored->rollback($records, $apply, $revisionUid, $maxFailures);
    }
    catch (\RuntimeException $e) {
      $this->logger()->error($e->getMessage() . ' ' . dt('Nothing was changed.'));
      return 1;
    }
    // Names and places only: the values are site content.
    foreach ($result['rows'] as $row) {
      $this->output()->writeln(sprintf('%s  %s  %s  %s', $row['store'], $row['name'], $row['path'], $row['note']));
    }
    foreach ($result['failures'] as $failure) {
      $this->logger()->error(dt('@item failed (@error) and was left as it was.', [
        '@item' => $failure['item'],
        '@error' => $failure['error'],
      ]));
    }
    if ($result['stopped']) {
      $this->logger()->error(dt('Stopped after @n failed item(s) (--max-failures).', ['@n' => count($result['failures'])]));
    }
    if ($result['conflicts'] > 0) {
      $this->logger()->warning(dt('@n value(s) changed since the run and were left as they are.', ['@n' => $result['conflicts']]));
    }
    $this->logger()->success(dt('@n value(s) @verb.', [
      '@n' => $result['restored'],
      '@verb' => $apply ? 'restored' : 'would be restored (dry run; add --apply)',
    ]));
    return $result['failures'] === [] ? 0 : 1;
  }

  /**
   * Reads a whole-number option; logs an error and returns NULL when invalid.
   */
  private function intOption(mixed $value, string $name, int $default, int $min): ?int {
    if ($value === NULL || $value === '') {
      return $default;
    }
    if ((is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value >= $min) {
      return (int) $value;
    }
    $this->logger()->error(dt('--@name needs a whole number of at least @min.', ['@name' => $name, '@min' => $min]));
    return NULL;
  }

  /**
   * Resolves --revision-user to a user id; logs an error and returns NULL.
   *
   * Owner decision OD5: the author of the new revisions defaults to user 1.
   */
  private function revisionUser(mixed $value): ?int {
    $value = ($value === NULL || $value === '') ? '1' : $value;
    if (!is_string($value) && !is_int($value)) {
      $this->logger()->error(dt('--revision-user needs a user id or an account name.'));
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage('user');
    $value = (string) $value;
    $account = ctype_digit($value) ? $storage->load($value) : (current($storage->loadByProperties(['name' => $value])) ?: NULL);
    if (!$account instanceof UserInterface || $account->isAnonymous()) {
      $this->logger()->error(dt('--revision-user: no user @user on this site.', ['@user' => $value]));
      return NULL;
    }
    return (int) $account->id();
  }

  /**
   * Returns the site address this run works on, for the report header.
   */
  private function siteUri(): string {
    $request = $this->requestStack->getCurrentRequest();
    return $request !== NULL ? $request->getSchemeAndHttpHost() . $request->getBasePath() : 'unknown';
  }

  /**
   * Lists Solo files under sites/<site>/files that hold old names; never writes.
   *
   * Owner decision OD3: files there are reported only. Solo generates its own
   * from the Dynamic CSS and Dynamic JS settings, which --stored rewrites.
   *
   * @return list<array{store: string, name: string, path: string, before: string, after: string, note: string}>
   *   One row per file that would change.
   */
  private function publicFileRows(ClassMigrator $migrator): array {
    $public = $this->fileSystem->realpath('public://');
    if (!is_string($public) || $public === '') {
      return [];
    }
    $dir = $public . DIRECTORY_SEPARATOR . 'solo';
    if (!is_dir($dir) || is_link($dir)) {
      return [];
    }
    $rows = [];
    // Symbolic links to folders are not followed (no FOLLOW_SYMLINKS).
    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
      $extension = $file instanceof \SplFileInfo ? strtolower($file->getExtension()) : '';
      if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->isLink() || ($extension !== 'css' && $extension !== 'js') || $file->getSize() > 2097152) {
        continue;
      }
      $contents = @file_get_contents($file->getPathname());
      if (!is_string($contents)) {
        continue;
      }
      $changes = $migrator->migrate($contents, $file->getFilename())['changes'];
      if ($changes !== []) {
        $rows[] = [
          'store' => 'file',
          'name' => substr($file->getPathname(), strlen($public) + 1),
          'path' => count($changes) . ' line(s)',
          'before' => '',
          'after' => '',
          'note' => 'file in sites/*/files: report only',
        ];
      }
    }
    return $rows;
  }

  /**
   * Adds the content counts to the report and the console.
   *
   * @param array{rows: list<array<string, string>>, summary: array<string, array<string, int>>, failures: list<array{item: string, error: string}>, resume: ?string, stopped: bool} $result
   *   What StoredClassMigrator::content() returned.
   * @param list<string> $report
   *   Report lines, appended to.
   *
   * @return int
   *   The number of failed items.
   */
  private function contentSummary(array $result, array &$report): int {
    $columns = [
      'items' => 'Items',
      'default' => 'Default revisions',
      'pending' => 'Pending drafts',
      'children' => 'Child items',
      'translations' => 'Translations',
      'old_revisions' => 'Older revisions left (OD1)',
      'no_host' => 'No host',
      'failed' => 'Failed',
    ];
    $report[] = '# Content items';
    $report[] = '';
    $report[] = 'An item is what a visitor sees: a node, a term, a reusable block, a menu link, or a Layout Builder default layout. Paragraphs and inline blocks are counted as child items of the item that holds them. Older revisions are left as they are: reverting to one brings its old ids back.';
    $report[] = '';
    $report[] = '| Entity type | ' . implode(' | ', $columns) . ' |';
    $report[] = '|---' . str_repeat('|---', count($columns)) . '|';
    ksort($result['summary']);
    foreach ($result['summary'] as $entityType => $counts) {
      $cells = [$entityType];
      foreach (array_keys($columns) as $key) {
        $cells[] = (string) ($counts[$key] ?? 0);
      }
      $report[] = '| ' . implode(' | ', $cells) . ' |';
    }
    $report[] = '';
    if ($result['failures'] !== []) {
      $report[] = '## Failed items (left exactly as they were)';
      $report[] = '';
      foreach ($result['failures'] as $failure) {
        $report[] = '- ' . self::markdownCode($failure['item']) . ': ' . $failure['error'];
        $this->logger()->error(dt('@item failed (@error) and was left as it was.', [
          '@item' => $failure['item'],
          '@error' => $failure['error'],
        ]));
      }
      $report[] = '';
    }
    if ($result['stopped']) {
      $report[] = 'Stopped after ' . count($result['failures']) . ' failed item(s) (--max-failures).';
      $this->logger()->error(dt('Stopped after @n failed item(s) (--max-failures).', ['@n' => count($result['failures'])]));
    }
    if ($result['resume'] !== NULL) {
      $report[] = 'Resume: run again with --from=' . $result['resume'];
      $report[] = '';
      $this->logger()->warning(dt('Not every content item was handled. Carry on with --from=@from', ['@from' => $result['resume']]));
    }
    return count($result['failures']) + ($result['stopped'] ? 1 : 0);
  }

  /**
   * Builds the path rules: core, Solo and public files are refused.
   *
   * @throws \InvalidArgumentException
   *   When the Drupal root does not resolve.
   */
  private function migrationPaths(): ClassMigrationPaths {
    $public = $this->fileSystem->realpath('public://');
    $forbidden = ['core'];
    if ($this->themeList->exists('solo')) {
      $forbidden[] = $this->themeList->getPath('solo');
    }
    if (is_string($public) && $public !== '') {
      $forbidden[] = $public;
    }
    return new ClassMigrationPaths(
      DRUPAL_ROOT,
      fn (string $name): ?string => $this->themeList->exists($name) ? $this->themeList->getPath($name) : NULL,
      $forbidden,
      is_string($public) && $public !== '' ? [$public] : [],
    );
  }

  /**
   * Wraps text in a Markdown code span that survives backticks inside it.
   */
  private static function markdownCode(string $text): string {
    $fence = '`';
    while (str_contains($text, $fence)) {
      $fence .= '`';
    }
    $pad = (str_starts_with($text, '`') || str_ends_with($text, '`')) ? ' ' : '';
    return $fence . $pad . $text . $pad . $fence;
  }

}
