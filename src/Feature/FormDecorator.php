<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Feature;

use Drupal\comment\CommentInterface;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\solo_utilities\Render\AttributeHelper;
use Drupal\solo_utilities\Render\FormElementInspector;

/**
 * Form theming: classes on form elements, and Solo's own form alters.
 *
 * @internal
 */
final class FormDecorator {

  use StringTranslationTrait;

  /**
   * Wrapper classes by form element type.
   */
  private const FIELD_TYPE_CLASSES = [
    'date' => 'solo-field-date',
    'datetime' => 'solo-field-datetime',
    'datelist' => 'solo-field-datelist',
    'daterange' => 'solo-field-daterange',
    'datetime_timestamp' => 'solo-field-timestamp',
    'textfield' => 'solo-field-text',
    'select' => 'solo-field-select',
    'checkbox' => 'solo-field-checkbox',
    'checkboxes' => 'solo-field-checkboxes',
    'radio' => 'solo-field-radio',
    'radios' => 'solo-field-radios',
    'textarea' => 'solo-field-textarea',
    'file' => 'solo-field-file',
    'managed_file' => 'solo-field-managed-file',
    'entity_autocomplete' => 'solo-field-autocomplete',
    'number' => 'solo-field-number',
    'email' => 'solo-field-email',
    'tel' => 'solo-field-tel',
    'url' => 'solo-field-url',
  ];

  /**
   * Words in an element name that suggest it holds a date.
   */
  private const DATE_NAME_WORDS = [
    'date',
    'created',
    'changed',
    'published',
    'authored',
    'start',
    'end',
    'from',
    'to',
  ];

  /**
   * HTML input types styled as text-like inputs.
   */
  private const TEXT_INPUT_TYPES = [
    'text',
    'email',
    'tel',
    'number',
    'search',
    'password',
    'date',
    'time',
    'file',
    'color',
    'datetime-local',
    'url',
    'month',
    'week',
  ];

  public function __construct(
    private readonly AttributeHelper $attributes,
    private readonly FormElementInspector $inspector,
    TranslationInterface $stringTranslation,
  ) {
    $this->setStringTranslation($stringTranslation);
  }

  /**
   * Adds the state, field, type and date classes of a form element.
   *
   * @param array<string, mixed> $variables
   *   The form element template variables.
   */
  public function preprocessFormElement(array &$variables): void {
    $element = is_array($variables['element'] ?? NULL) ? $variables['element'] : [];
    $classes = [];
    if (!empty($element['#value'])) {
      $classes[] = 'has-value';
    }
    if (!empty($element['#required'])) {
      $classes[] = 'is-required';
    }
    if (!empty($element['#errors'])) {
      $classes[] = 'has-error';
    }
    if (isset($element['#field_name'])) {
      $classes[] = 'field--' . Html::getClass($element['#field_name']);
    }
    $type = $element['#type'] ?? NULL;
    if ($type === 'checkbox' || $type === 'radio') {
      $classes[] = 'form-type-boolean';
    }
    if (is_string($type) && isset(self::FIELD_TYPE_CLASSES[$type])) {
      $classes[] = self::FIELD_TYPE_CLASSES[$type];
    }
    if ($this->nameSuggestsDate($element['#name'] ?? '')) {
      $classes[] = 'solo-date-related';
    }
    $this->attributes->addClasses($variables, $classes);

    if (!empty($variables['description']['attributes'])) {
      $variables['description']['attributes']->addClass('form-item__description');
    }
    if (!empty($variables['disabled'])) {
      $variables['label'] ??= [];
      if (is_array($variables['label'])) {
        $this->attributes->addClasses($variables['label'], 'is-disabled', '#attributes');
      }
    }
  }

  /**
   * Adds the form item label class.
   *
   * @param array<string, mixed> $variables
   *   The form element label template variables.
   */
  public function preprocessFormElementLabel(array &$variables): void {
    $this->attributes->addClasses($variables, 'form-item__label');
  }

  /**
   * Adds an input's type, size and upload classes, and its title attribute.
   *
   * @param array<string, mixed> $variables
   *   The input template variables.
   */
  public function preprocessInput(array &$variables): void {
    $element = is_array($variables['element'] ?? NULL) ? $variables['element'] : [];
    $this->attributes->ensureClassArray($variables);
    if (($element['#title_display'] ?? NULL) === 'attribute' && !empty((string) ($element['#title'] ?? ''))) {
      $variables['attributes']['title'] = (string) $element['#title'];
    }
    // The element's own attributes first: they hold what was asked for.
    $from_element = $element['#attributes'] ?? [];
    $type_html = (string) ($from_element['type'] ?? $variables['attributes']['type'] ?? '');
    $size = $from_element['size'] ?? $variables['attributes']['size'] ?? NULL;
    $accept = $from_element['accept'] ?? $variables['attributes']['accept'] ?? NULL;
    $classes = [];

    if (in_array($type_html, self::TEXT_INPUT_TYPES, TRUE)) {
      $classes[] = 'form-element';
      $classes[] = Html::getClass('form-element--type-' . $type_html);
      $classes[] = Html::getClass('form-element--api-' . ($element['#type'] ?? ''));
      if (!empty($element['#autocomplete_route_name'])) {
        $variables['autocomplete_message'] = $this->t('Loading…');
      }
    }
    if ($type_html === 'checkbox' || $type_html === 'radio') {
      $classes[] = 'form-boolean';
      $classes[] = Html::getClass('form-boolean--type-' . $type_html);
    }
    if ($size !== NULL) {
      $classes[] = match (TRUE) {
        (int) $size <= 10 => 'input-small',
        (int) $size <= 30 => 'input-medium',
        default => 'input-large',
      };
    }
    if ($type_html === 'file' && $accept !== NULL) {
      foreach (['image', 'pdf', 'video'] as $kind) {
        if (str_contains((string) $accept, $kind)) {
          $classes[] = 'input-' . $kind . '-upload';
        }
      }
    }
    $this->attributes->addClasses($variables, $classes);
  }

  /**
   * Adds a fieldset's uniform type and inline-ready classes.
   *
   * @param array<string, mixed> $variables
   *   The fieldset template variables.
   */
  public function preprocessFieldset(array &$variables): void {
    $this->attributes->ensureClassArray($variables);
    if (!is_array($variables['element'] ?? NULL)) {
      return;
    }
    $children = $this->inspector->childKeys($variables['element']);
    $types = [];
    foreach ($children as $child) {
      if (isset($variables['element'][$child]['#type'])) {
        $types[$variables['element'][$child]['#type']] = TRUE;
      }
    }
    $classes = [];
    if (count($types) === 1) {
      $classes[] = 'fieldset-uniform-' . Html::getClass((string) array_key_first($types));
    }
    if (count($children) >= 2 && count($children) <= 3) {
      $classes[] = 'fieldset-inline-ready';
    }
    $this->attributes->addClasses($variables, $classes);
  }

  /**
   * Marks the entity reference fields inside a form.
   *
   * @param array<string, mixed> $variables
   *   The form template variables.
   */
  public function preprocessForm(array &$variables): void {
    if (is_array($variables['element'] ?? NULL)) {
      $this->inspector->markEntityReferenceFields($variables['element']);
    }
  }

  /**
   * Adds a container's Ajax, child count, button and field type classes.
   *
   * @param array<string, mixed> $variables
   *   The container template variables.
   */
  public function preprocessContainer(array &$variables): void {
    $this->attributes->ensureClassArray($variables);
    $attributes = is_array($variables['attributes']) ? $variables['attributes'] : [];
    $classes = [];

    // The id may be an array (#3609300, #3630728, the filebrowser module).
    $id = $attributes['id'] ?? '';
    $id = is_array($id) ? reset($id) : $id;
    if (is_string($id) && str_contains($id, 'ajax-wrapper')) {
      $classes[] = 'ajax-container';
    }
    if (!is_array($variables['element'] ?? NULL)) {
      $this->attributes->addClasses($variables, $classes);
      return;
    }
    $element = $variables['element'];
    $children = $this->inspector->childKeys($element);
    $classes[] = 'container-children-' . count($children);

    if (in_array('form-actions', $attributes['class'] ?? [], TRUE)) {
      $buttons = 0;
      foreach ($children as $child) {
        if (in_array($element[$child]['#type'] ?? '', ['submit', 'button'], TRUE)) {
          $buttons++;
        }
      }
      $classes[] = 'actions-buttons-' . $buttons;
    }

    $types = $this->inspector->fieldTypes($element);
    foreach ($types as $type => $count) {
      $classes[] = 'contains-' . Html::getClass($type);
      if ($count > 1) {
        $classes[] = 'contains-multiple-' . Html::getClass($type);
      }
    }
    if (($types['date'] ?? 0) === 2) {
      $classes[] = 'solo-date-range';
    }
    if ($this->inspector->hasMinMaxFields($element)) {
      $classes[] = 'solo-has-date-range';
    }
    $this->attributes->addClasses($variables, $classes);
  }

  /**
   * Adds a multiple-value form's item count and tabledrag classes.
   *
   * @param array<string, mixed> $variables
   *   The field multiple value form template variables.
   */
  public function preprocessFieldMultipleValueForm(array &$variables): void {
    $element = is_array($variables['element'] ?? NULL) ? $variables['element'] : [];
    $value = $element['#value'] ?? NULL;
    $count = is_array($value) || $value instanceof \Countable ? count($value) : 0;
    $classes = ['field-multiple-count-' . $count];
    if (!empty($element['#tabledrag'])) {
      $classes[] = 'has-tabledrag';
    }
    $this->attributes->addClasses($variables, $classes);
  }

  /**
   * Marks the sortable headings of a Views table.
   *
   * @param array<string, mixed> $variables
   *   The Views table template variables.
   */
  public function preprocessViewsTable(array &$variables): void {
    foreach ($variables['header'] ?? [] as $cell) {
      if (empty($cell['url'])) {
        continue;
      }
      $query = UrlHelper::parse($cell['url'])['query'];
      if (isset($query['order'], $query['sort'])) {
        $cell['attributes']->addClass('sortable-heading');
      }
    }
  }

  /**
   * Adds the button classes of the node preview bar.
   *
   * @param array<mixed> $form
   *   The form.
   */
  public function alterNodePreviewForm(array &$form): void {
    $form['backlink']['#options']['attributes']['class'][] = 'button';
    $form['backlink']['#options']['attributes']['class'][] = 'button--small';
    $form['backlink']['#options']['attributes']['class'][] = 'button--icon-back';
    $form['backlink']['#options']['attributes']['class'][] = 'button--primary';
    $form['view_mode']['#attributes']['class'][] = 'form-element--small';
  }

  /**
   * Gives a node form Solo's template and meta containers.
   *
   * @param array<mixed> $form
   *   The form.
   */
  public function alterNodeForm(array &$form): void {
    $form['#theme'] = ['node_edit_form'];
    $form['advanced']['#type'] = 'container';
    $form['meta']['#type'] = 'container';
    $form['meta']['#access'] = TRUE;
    $form['meta']['changed']['#wrapper_attributes']['class'][] = 'container-inline';
    $form['meta']['author']['#wrapper_attributes']['class'][] = 'container-inline';
    $form['revision_information']['#type'] = 'container';
    $form['revision_information']['#group'] = 'meta';
  }

  /**
   * Names the comment form's submit button by whether it replies.
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function alterCommentForm(array &$form, FormStateInterface $form_state): void {
    $form_object = $form_state->getFormObject();
    $comment = $form_object instanceof EntityFormInterface ? $form_object->getEntity() : NULL;
    if ($comment instanceof CommentInterface) {
      $form['actions']['submit']['#value'] = $comment->hasParentComment() ? $this->t('Reply to comment') : $this->t('Post comment');
    }
  }

  /**
   * Adds classes to a Views exposed form by the field types it holds.
   *
   * @param array<mixed> $form
   *   The form.
   */
  public function alterViewsExposedForm(array &$form): void {
    $form['#attributes']['class'][] = 'form--inline';
    foreach ($this->inspector->childKeys($form) as $key) {
      if (!is_array($form[$key] ?? NULL) || !in_array($form[$key]['#type'] ?? NULL, ['fieldset', 'container'], TRUE)) {
        continue;
      }
      $types = $this->inspector->fieldTypes($form[$key]);
      if (isset($types['date-range-field'])) {
        $form[$key]['#attributes']['class'][] = 'solo-date-range';
      }
      if (isset($types['fieldset'])) {
        $form[$key]['#attributes']['class'][] = 'solo-has-fieldset';
      }
      if (!isset($types['date-range-field']) && isset($types['date'])) {
        $form[$key]['#attributes']['class'][] = 'solo-single-date-container';
      }
    }
  }

  /**
   * Whether an element's name holds a word that suggests a date.
   *
   * @param mixed $name
   *   The #name; it may be an array, whose first value then counts (#3609300).
   */
  private function nameSuggestsDate(mixed $name): bool {
    $name = is_array($name) ? reset($name) : $name;
    if (!is_string($name) || $name === '') {
      return FALSE;
    }
    foreach (self::DATE_NAME_WORDS as $word) {
      if (stripos($name, $word) !== FALSE) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
