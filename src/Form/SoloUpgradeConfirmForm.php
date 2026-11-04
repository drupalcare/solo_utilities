<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\solo_utilities\Upgrade\UpgradeOverview;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirms, then runs the settings migration for Solo and its sub-themes.
 *
 * No batch: the migrator reads, transforms and saves one small config object
 * per theme and collection, and a second run finishes anything a first left.
 *
 * @internal
 */
final class SoloUpgradeConfirmForm extends ConfirmFormBase {

  public function __construct(
    protected UpgradeOverview $overview,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get(UpgradeOverview::class));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'solo_utilities_upgrade_confirm';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Move the Solo settings to the new names?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('This runs for Solo and every sub-theme. Each theme keeps its setting values under the new names, and the earlier settings are copied to the database first. To go back, restore them from the upgrade page before you roll back the code. Sub-theme code is not changed.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Move settings');
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
    // Which themes are waiting comes from stored settings at this moment.
    $form['#cache']['max-age'] = 0;
    if ($this->overview->waitingThemes() === []) {
      $form['description'] = ['#markup' => $this->t('No theme is waiting. There is nothing to move.')];
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
    $result = $this->overview->run();
    $form_state->setRedirectUrl($this->getCancelUrl());

    if ($result['changed'] !== []) {
      $this->messenger()->addStatus($this->formatPlural(count($result['changed']), 'Settings moved to the new names for 1 theme: @themes.', 'Settings moved to the new names for @count themes: @themes.', [
        '@themes' => implode(', ', $result['changed']),
      ]));
      $this->messenger()->addStatus($this->t('The earlier settings are saved in the database. Export your configuration so it records the new names.'));
    }
    foreach ($result['differences'] as $theme => $keys) {
      $this->messenger()->addWarning($this->t('Theme @theme had both old and new values that differ. The new value was kept for: @keys.', [
        '@theme' => $theme,
        '@keys' => implode(', ', $keys),
      ]));
    }
    if ($result['failed'] !== []) {
      $this->messenger()->addError($this->t('Not migrated: @themes. Check the log, then run this again.', [
        '@themes' => implode(', ', $result['failed']),
      ]));
    }
    if ($result['changed'] === [] && $result['failed'] === []) {
      $this->messenger()->addStatus($this->t('Nothing needed moving.'));
    }
  }

}
