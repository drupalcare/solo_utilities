<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Migration;

/**
 * The rollback log of one class migration run: JSON lines, append only.
 *
 * The first line is a header; every later line is one changed value (kind
 * "content") or one changed configuration object (kind "config"). Each line
 * is flushed as it is written, so a run that stops halfway leaves a log that
 * is complete up to the last item it wrote. The log holds site content, so it
 * is created like the report: a new file only, mode 0600, outside the web
 * root (the caller resolves the path through ClassMigrationPaths).
 *
 * @internal
 */
final class RollbackLog {

  /**
   * The format version written in the header and required when reading.
   */
  public const int VERSION = 1;

  /**
   * The tool name written in the header and required when reading.
   */
  public const string TOOL = 'solo:migrate-classes';

  /**
   * The open file handle, or NULL once closed.
   *
   * @var resource|null
   */
  private $handle;

  /**
   * Opens the log.
   *
   * @param string $path
   *   The absolute path.
   * @param resource $handle
   *   The handle, opened for writing.
   */
  private function __construct(
    private readonly string $path,
    $handle,
  ) {
    $this->handle = $handle;
  }

  /**
   * Creates a new log file and writes its header.
   *
   * @param string $path
   *   The absolute path. The file must not exist.
   * @param array<string, scalar|null> $header
   *   Run details for the header line (mode, site, time, map checksum).
   *
   * @throws \RuntimeException
   *   When the file cannot be created or written.
   */
  public static function create(string $path, array $header): self {
    $handle = @fopen($path, 'x');
    if ($handle === FALSE) {
      $error = error_get_last();
      throw new \RuntimeException(sprintf('Could not create the rollback log %s: %s', $path, $error['message'] ?? 'unknown error'));
    }
    @chmod($path, 0600);
    $log = new self($path, $handle);
    $log->write(['kind' => 'header', 'tool' => self::TOOL, 'version' => self::VERSION] + $header);
    return $log;
  }

  /**
   * Returns the path of the log file.
   */
  public function path(): string {
    return $this->path;
  }

  /**
   * Appends one record and flushes it to disk.
   *
   * @param array<string, mixed> $record
   *   The record.
   *
   * @throws \RuntimeException
   *   When the record cannot be encoded or written. The caller must then roll
   *   back the item it was about to commit.
   */
  public function write(array $record): void {
    if ($this->handle === NULL) {
      throw new \RuntimeException(sprintf('The rollback log %s is closed.', $this->path));
    }
    try {
      $line = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }
    catch (\JsonException $e) {
      throw new \RuntimeException(sprintf('Could not encode a rollback record: %s', $e->getMessage()), 0, $e);
    }
    $written = fwrite($this->handle, $line);
    if ($written !== strlen($line) || !fflush($this->handle)) {
      throw new \RuntimeException(sprintf('Could not write to the rollback log %s.', $this->path));
    }
  }

  /**
   * Closes the file.
   */
  public function finish(): void {
    if ($this->handle !== NULL) {
      fclose($this->handle);
      $this->handle = NULL;
    }
  }

  /**
   * Reads a log written by create() and write().
   *
   * @param string $path
   *   The log file.
   *
   * @return list<array<string, mixed>>
   *   The records after the header, in file order.
   *
   * @throws \RuntimeException
   *   When the file is missing, has no valid header, or a line is not JSON.
   */
  public static function read(string $path): array {
    if (!is_file($path) || is_link($path) || !is_readable($path)) {
      throw new \RuntimeException(sprintf('The rollback log %s does not exist or cannot be read.', $path));
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === FALSE || $lines === []) {
      throw new \RuntimeException(sprintf('The rollback log %s is empty.', $path));
    }
    $records = [];
    foreach ($lines as $number => $line) {
      try {
        $record = json_decode($line, TRUE, 64, JSON_THROW_ON_ERROR);
      }
      catch (\JsonException $e) {
        // A line cut short by a crash can only be the last one; anything else
        // means the file was edited, and nothing in it can be trusted.
        if ($number === array_key_last($lines)) {
          break;
        }
        throw new \RuntimeException(sprintf('Line %d of the rollback log %s is not valid JSON.', $number + 1, $path), 0, $e);
      }
      if (!is_array($record)) {
        throw new \RuntimeException(sprintf('Line %d of the rollback log %s is not a record.', $number + 1, $path));
      }
      if ($number === 0) {
        if (($record['kind'] ?? NULL) !== 'header' || ($record['tool'] ?? NULL) !== self::TOOL || ($record['version'] ?? NULL) !== self::VERSION) {
          throw new \RuntimeException(sprintf('%s is not a Solo class migration rollback log (version %d).', $path, self::VERSION));
        }
        continue;
      }
      $records[] = $record;
    }
    return $records;
  }

}
