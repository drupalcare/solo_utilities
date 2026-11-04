<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\solo_utilities\Service\SoloReadinessScanner;
use Drupal\solo_utilities\Upgrade\UpgradeOverview;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The Solo upgrade page: themes, occupied regions and upgrade state.
 *
 * @internal
 */
final class SoloUpgradeController extends ControllerBase {

  public function __construct(
    private readonly UpgradeOverview $overview,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get(UpgradeOverview::class));
  }

  /**
   * Builds the page.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function page(): array {
    $themes = $this->overview->themes();
    $findings = $this->overview->findings();
    $waiting = array_filter($themes, static fn(array $theme): bool => $theme['state'] === UpgradeOverview::STATE_WAITING);
    $backups = array_filter($themes, static fn(array $theme): bool => $theme['backup']);

    return [
      '#theme' => 'solo_upgrade',
      '#solo_found' => $themes !== [],
      '#themes' => array_map($this->themeRow(...), $themes),
      '#waiting' => $waiting === [] ? '' : $this->formatPlural(count($waiting), '1 theme is waiting for its settings to move to the new names: @names.', '@count themes are waiting for their settings to move to the new names: @names.', [
        '@names' => implode(', ', array_column($waiting, 'label')),
      ]),
      '#run' => $waiting === [] ? NULL : [
        '#type' => 'link',
        '#title' => $this->t('Move settings to the new names'),
        '#url' => Url::fromRoute('solo_utilities.upgrade_run'),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ],
      '#done' => $this->doneItems($themes),
      '#needs' => $this->needsGroups($themes, $findings),
      '#backup' => $backups === [] ? $this->t('No earlier settings are saved. The migration copies them to the database before it changes a theme for the first time.') : $this->t('Earlier settings are saved in the database for: @names. To go back, restore them before you roll back the code, either here or with the drush command solo:settings-restore. Restoring discards any settings saved after the migration.', [
        '@names' => implode(', ', array_column($backups, 'label')),
      ]),
      '#restore' => $backups === [] ? NULL : [
        '#type' => 'link',
        '#title' => $this->t('Restore earlier settings'),
        '#url' => Url::fromRoute('solo_utilities.upgrade_restore'),
        '#attributes' => ['class' => ['button', 'button--danger']],
      ],
      '#attached' => ['library' => ['solo_utilities/upgrade-page']],
      // The scan reads files on disk and the backup is in key-value storage.
      // Neither has a cache tag, so no stored copy of this page can be trusted.
      '#cache' => [
        'contexts' => ['user.permissions'],
        'tags' => $this->overview->cacheTags(),
        'max-age' => 0,
      ],
    ];
  }

  /**
   * One theme, ready for the template.
   *
   * @param array<string, mixed> $theme
   *   An entry of UpgradeOverview::themes().
   *
   * @return array<string, mixed>
   *   Template variables.
   */
  private function themeRow(array $theme): array {
    $occupied = array_filter($theme['regions'], static fn(array $region): bool => $region['count'] > 0);
    $has_layout = $theme['installed'] && $this->overview->hasBlocks();
    [$tone, $label] = match ($theme['state']) {
      UpgradeOverview::STATE_MIGRATED => ['ok', $this->t('Settings migrated')],
      UpgradeOverview::STATE_WAITING => ['wait', $this->t('Waiting')],
      UpgradeOverview::STATE_UNMAPPED => ['warn', $this->t('Keys left unmapped')],
      UpgradeOverview::STATE_NO_SETTINGS => ['none', $this->t('No saved settings')],
      default => ['warn', $this->t('Could not be read')],
    };
    return [
      'name' => $theme['name'],
      'label' => $theme['label'],
      'base' => $theme['base'],
      'default' => $theme['default'],
      'installed' => $theme['installed'],
      'tone' => $tone,
      'state' => $label,
      'summary' => $theme['installed'] ? $this->t('@occupied of @total regions hold enabled blocks', [
        '@occupied' => count($occupied),
        '@total' => count($theme['regions']),
      ]) : '',
      'regions' => array_map(fn(array $region): array => [
        'label' => $region['label'],
        'occupied' => $region['count'] > 0,
        'count' => $region['count'] > 0 ? $this->formatPlural($region['count'], '1 block', '@count blocks') : $this->t('Empty'),
      ], $theme['regions']),
      'layout_url' => $has_layout ? Url::fromRoute('block.admin_display_theme', ['theme' => $theme['name']])->toString() : '',
    ];
  }

  /**
   * The green list: what is finished with nothing asked of the site builder.
   *
   * @param list<array<string, mixed>> $themes
   *   The entries of UpgradeOverview::themes().
   *
   * @return list<\Stringable|string>
   *   Translated lines.
   */
  private function doneItems(array $themes): array {
    $items = [];
    foreach ($themes as $theme) {
      if ($theme['state'] === UpgradeOverview::STATE_NO_SETTINGS) {
        $items[] = $this->t('@theme: no saved settings, so nothing to move.', ['@theme' => $theme['label']]);
      }
      elseif ($theme['state'] === UpgradeOverview::STATE_MIGRATED || ($theme['state'] === UpgradeOverview::STATE_UNMAPPED && $theme['unmovable'] === [])) {
        $items[] = $this->t('@theme: every Solo setting uses its new name.', ['@theme' => $theme['label']]);
      }
    }
    return $items;
  }

  /**
   * The amber list: what only a person can finish, grouped by owner.
   *
   * @param list<array<string, mixed>> $themes
   *   The entries of UpgradeOverview::themes().
   * @param array<string, mixed>|null $findings
   *   SoloReadinessScanner::scan(), or NULL when it could not run.
   *
   * @return list<array{title: string|\Stringable, path: string, rows: list<array{location: string, type: string|\Stringable, detail: string|\Stringable}>}>
   *   Groups that have at least one row.
   */
  private function needsGroups(array $themes, ?array $findings): array {
    $groups = [];
    if ($findings === NULL) {
      $groups[] = $this->group($this->t('Code scan'), '', [
        $this->row('', $this->t('Not scanned'), $this->t('The sub-theme code could not be scanned, so this list may be incomplete. Check the log.')),
      ]);
    }

    foreach ($themes as $theme) {
      $rows = [];
      if ($theme['state'] === UpgradeOverview::STATE_ERROR) {
        $rows[] = $this->row('', $this->t('Settings'), $this->t('The settings for this theme could not be read or did not pass validation. Check the log.'));
      }
      if ($theme['unmovable'] !== []) {
        $rows[] = $this->row('', $this->t('Settings'), $this->t('These values could not be moved and still use their old names: @keys.', ['@keys' => implode(', ', $theme['unmovable'])]));
      }
      if ($theme['unknown'] !== []) {
        $rows[] = $this->row('', $this->t('Settings'), $this->t('These keys are not in the settings map and were left as they are. If your sub-theme added them, you do not need to do anything: @keys.', ['@keys' => implode(', ', $theme['unknown'])]));
      }
      $rows = array_merge($rows, $this->findingRows($theme['name'] === 'solo' ? ($findings['solo_overrides'] ?? []) : ($findings['subthemes'][$theme['name']]['findings'] ?? [])));
      if ($rows !== []) {
        $groups[] = $this->group($theme['label'], $findings['subthemes'][$theme['name']]['path'] ?? '', $rows);
      }
    }

    foreach ($findings['custom_modules'] ?? [] as $name => $module) {
      $groups[] = $this->group((string) $name, $module['path'], $this->findingRows($module['findings']));
    }

    $unprovable = $this->overview->unprovable();
    if ($unprovable !== []) {
      $groups[] = $this->group($this->t('Settings with no theme on disk'), '', [
        $this->row('', $this->t('Settings'), $this->t('These hold old Solo keys and were not changed, because no Solo theme on disk uses them. Restore the theme code and run the migration again, or delete the configuration: @names.', ['@names' => implode(', ', $unprovable)])),
      ]);
    }
    return $groups;
  }

  /**
   * Scanner findings as table rows.
   *
   * The detail text comes from the scanner, which Drush shares, in English.
   *
   * @param list<array{type: string, file: string, line: int, detail: string}> $findings
   *   Scanner findings.
   *
   * @return list<array{location: string, type: string|\Stringable, detail: string|\Stringable}>
   *   Table rows.
   */
  private function findingRows(array $findings): array {
    $rows = [];
    foreach ($findings as $finding) {
      $location = $finding['file'] . ($finding['line'] > 0 ? ':' . $finding['line'] : '');
      $rows[] = $this->row($location, $this->findingType($finding['type']), $finding['detail']);
    }
    return $rows;
  }

  /**
   * The label of a scanner finding type.
   */
  private function findingType(string $type): string|\Stringable {
    return match ($type) {
      SoloReadinessScanner::TYPE_FUNCTION_CALL => $this->t('Solo function call'),
      SoloReadinessScanner::TYPE_SOLO_INCLUDE => $this->t('Solo template path'),
      SoloReadinessScanner::TYPE_LIBRARY_OVERRIDE => $this->t('Library override'),
      SoloReadinessScanner::TYPE_COPIED_TEMPLATE => $this->t('Copied template'),
      SoloReadinessScanner::TYPE_CONFIG_READ => $this->t('Config read'),
      SoloReadinessScanner::TYPE_SETTINGS_CALL => $this->t('Setting read'),
      SoloReadinessScanner::TYPE_FORM_ALTER => $this->t('Form alter'),
      SoloReadinessScanner::TYPE_CONFIG_OVERRIDE => $this->t('Config override'),
      default => $type,
    };
  }

  /**
   * One group of the amber list.
   *
   * @param string|\Stringable $title
   *   The theme or module label.
   * @param string $path
   *   Where its files are, or an empty string.
   * @param list<array{location: string, type: string|\Stringable, detail: string|\Stringable}> $rows
   *   The rows.
   *
   * @return array{title: string|\Stringable, path: string, rows: list<array{location: string, type: string|\Stringable, detail: string|\Stringable}>}
   *   The group.
   */
  private function group(string|\Stringable $title, string $path, array $rows): array {
    return ['title' => $title, 'path' => $path, 'rows' => $rows];
  }

  /**
   * One row of the amber list.
   *
   * @return array{location: string, type: string|\Stringable, detail: string|\Stringable}
   *   The row.
   */
  private function row(string $location, string|\Stringable $type, string|\Stringable $detail): array {
    return ['location' => $location, 'type' => $type, 'detail' => $detail];
  }

}
