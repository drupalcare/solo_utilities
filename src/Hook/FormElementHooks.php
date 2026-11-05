<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Hook;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;
use Drupal\solo_utilities\Feature\FormDecorator;
use Drupal\solo_utilities\Feature\SearchResult;
use Drupal\solo_utilities\Settings\SettingsReader;
use Drupal\solo_utilities\Theme\SoloThemeDetectionInterface;

/**
 * Routes form preprocess and form alter hooks to the features that own them.
 *
 * Order::Last keeps Solo after other modules, where its theme hooks ran in 1.x.
 * The node form alter lives in SiteFeatureHooks, as that hook has one home.
 *
 * @internal
 */
final class FormElementHooks {

  public function __construct(
    private readonly SoloThemeDetectionInterface $detection,
    private readonly SettingsReader $settingsReader,
    private readonly FormDecorator $forms,
    private readonly SearchResult $search,
  ) {}

  /**
   * Implements hook_preprocess_HOOK() for form-element.html.twig.
   *
   * @param array<string, mixed> $variables
   *   The template variables.
   */
  #[Hook('preprocess_form_element', order: Order::Last)]
  public function preprocessFormElement(array &$variables): void {
    if ($this->detection->isActive()) {
      $this->forms->preprocessFormElement($variables);
    }
  }

  /**
   * Implements hook_preprocess_HOOK() for form-element-label.html.twig.
   *
   * @param array<string, mixed> $variables
   *   The template variables.
   */
  #[Hook('preprocess_form_element_label', order: Order::Last)]
  public function preprocessFormElementLabel(array &$variables): void {
    if ($this->detection->isActive()) {
      $this->forms->preprocessFormElementLabel($variables);
    }
  }

  /**
   * Implements hook_preprocess_HOOK() for input.html.twig.
   *
   * @param array<string, mixed> $variables
   *   The template variables.
   */
  #[Hook('preprocess_input', order: Order::Last)]
  public function preprocessInput(array &$variables): void {
    if ($this->detection->isActive()) {
      $this->forms->preprocessInput($variables);
    }
  }

  /**
   * Implements hook_preprocess_HOOK() for fieldset.html.twig.
   *
   * @param array<string, mixed> $variables
   *   The template variables.
   */
  #[Hook('preprocess_fieldset', order: Order::Last)]
  public function preprocessFieldset(array &$variables): void {
    if ($this->detection->isActive()) {
      $this->forms->preprocessFieldset($variables);
    }
  }

  /**
   * Implements hook_preprocess_HOOK() for form.html.twig.
   *
   * @param array<string, mixed> $variables
   *   The template variables.
   */
  #[Hook('preprocess_form', order: Order::Last)]
  public function preprocessForm(array &$variables): void {
    if ($this->detection->isActive()) {
      $this->forms->preprocessForm($variables);
    }
  }

  /**
   * Implements hook_preprocess_HOOK() for container.html.twig.
   *
   * @param array<string, mixed> $variables
   *   The template variables.
   */
  #[Hook('preprocess_container', order: Order::Last)]
  public function preprocessContainer(array &$variables): void {
    if ($this->detection->isActive()) {
      $this->forms->preprocessContainer($variables);
    }
  }

  /**
   * Implements hook_preprocess_HOOK() for field-multiple-value-form.html.twig.
   *
   * @param array<string, mixed> $variables
   *   The template variables.
   */
  #[Hook('preprocess_field_multiple_value_form', order: Order::Last)]
  public function preprocessFieldMultipleValueForm(array &$variables): void {
    if ($this->detection->isActive()) {
      $this->forms->preprocessFieldMultipleValueForm($variables);
    }
  }

  /**
   * Implements hook_preprocess_HOOK() for views-view-table.html.twig.
   *
   * @param array<string, mixed> $variables
   *   The template variables.
   */
  #[Hook('preprocess_views_view_table', order: Order::Last)]
  public function preprocessViewsViewTable(array &$variables): void {
    if ($this->detection->isActive()) {
      $this->forms->preprocessViewsTable($variables);
    }
  }

  /**
   * Implements hook_form_FORM_ID_alter() for the node preview form.
   *
   * @param array<mixed> $form
   *   The form.
   */
  #[Hook('form_node_preview_form_select_alter', order: Order::Last)]
  public function formNodePreviewFormSelectAlter(array &$form): void {
    if ($this->detection->isActive()) {
      $this->forms->alterNodePreviewForm($form);
    }
  }

  /**
   * Implements hook_form_BASE_FORM_ID_alter() for comment_form.
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  #[Hook('form_comment_form_alter', order: Order::Last)]
  public function formCommentFormAlter(array &$form, FormStateInterface $form_state): void {
    if ($this->detection->isActive()) {
      $this->forms->alterCommentForm($form, $form_state);
    }
  }

  /**
   * Implements hook_form_FORM_ID_alter() for views_exposed_form.
   *
   * @param array<mixed> $form
   *   The form.
   */
  #[Hook('form_views_exposed_form_alter', order: Order::Last)]
  public function formViewsExposedFormAlter(array &$form): void {
    if ($this->detection->isActive()) {
      $this->forms->alterViewsExposedForm($form);
    }
  }

  /**
   * Implements hook_form_FORM_ID_alter() for search_form.
   *
   * @param array<mixed> $form
   *   The form.
   */
  #[Hook('form_search_form_alter', order: Order::Last)]
  public function formSearchFormAlter(array &$form): void {
    if ($this->detection->isActive()) {
      $this->search->alterSearchForm($form, $this->settingsReader->forTheme($this->detection->activeThemeName()));
    }
  }

  /**
   * Implements hook_form_FORM_ID_alter() for search_block_form.
   *
   * @param array<mixed> $form
   *   The form.
   */
  #[Hook('form_search_block_form_alter', order: Order::Last)]
  public function formSearchBlockFormAlter(array &$form): void {
    if ($this->detection->isActive()) {
      $this->search->alterSearchBlockForm($form, $this->settingsReader->forTheme($this->detection->activeThemeName()));
    }
  }

}
