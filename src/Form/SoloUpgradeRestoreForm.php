<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\solo_utilities\Upgrade\SettingsMigrator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirms, then writes the settings held before the migration back.
 *
 * @internal
 */
final class SoloUpgradeRestoreForm extends ConfirmFormBase {

  public function __construct(
    protected SettingsMigrator $migrator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get(SettingsMigrator::class));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'solo_utilities_upgrade_restore';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Restore the earlier Solo settings?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('Each theme with saved settings gets its earlier settings back, under the old names. Any settings saved since the migration are discarded. Solo 2 does not read the old names, so the site uses default settings until you either roll the code back to Solo 1 or move the settings to the new names again from the upgrade page. A configuration export that already has the new names is not changed. This cannot be undone.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Restore settings');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('solo_utilities.upgrade');
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Its state.
   *
   * @return array<string, mixed>
   *   The form.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildForm($form, $form_state);
    // Which backups exist comes from key-value storage, which has no cache tag.
    $form['#cache']['max-age'] = 0;
    $held = array_filter($this->migrator->themes(), $this->migrator->hasBackup(...));
    if ($held === []) {
      $form['description'] = ['#markup' => $this->t('No earlier settings are saved. There is nothing to restore.')];
      unset($form['actions']['submit']);
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Its state.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $result = $this->migrator->restore();
    $form_state->setRedirectUrl($this->getCancelUrl());

    if ($result['restored'] !== []) {
      $this->messenger()->addStatus($this->t('Earlier settings restored for: @themes. The site uses default settings until you roll the code back or move the settings to the new names again.', [
        '@themes' => implode(', ', $result['restored']),
      ]));
    }
    if ($result['skipped'] !== []) {
      $this->messenger()->addWarning($this->t('Skipped because the theme is no longer on this site: @themes.', [
        '@themes' => implode(', ', $result['skipped']),
      ]));
    }
    if ($result['failed'] !== []) {
      $this->messenger()->addError($this->t('Not restored: @themes. Check the log.', [
        '@themes' => implode(', ', $result['failed']),
      ]));
    }
    if ($result['restored'] === [] && $result['skipped'] === [] && $result['failed'] === []) {
      $this->messenger()->addStatus($this->t('No earlier settings are saved. There is nothing to restore.'));
    }
  }

}
