<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Form;

use Drupal\Core\Entity\EntityConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Provides a form for deleting a Color Schemes Rule entity.
 */
class ColorSchemesRuleDeleteForm extends EntityConfirmFormBase {

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to delete the Color Schemes Rule %label?', [
      '%label' => $this->entity->label(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('entity.color_schemes_rule.collection', [], ['absolute' => TRUE]);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Delete');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->entity->delete();

    $this->messenger()->addMessage(
      $this->t('Deleted the Color Schemes Rule %label.', [
        '%label' => $this->entity->label(),
      ])
    );

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
