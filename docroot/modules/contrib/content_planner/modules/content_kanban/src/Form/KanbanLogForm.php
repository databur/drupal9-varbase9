<?php

namespace Drupal\content_kanban\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form controller for Kanban Log edit forms.
 *
 * @ingroup content_kanban
 */
class KanbanLogForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\content_kanban\Entity\KanbanLog $entity */
    $form = parent::buildForm($form, $form_state);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $entity = $this->entity;

    $status = parent::save($form, $form_state);

    switch ($status) {
      case SAVED_NEW:
        $this->messenger()->addMessage($this->t('Created the %label Kanban Log.', [
          '%label' => $entity->label(),
        ]));
        break;

      default:
        $this->messenger()->addMessage($this->t('Saved the %label Kanban Log.', [
          '%label' => $entity->label(),
        ]));
    }
    $form_state->setRedirect('entity.content_kanban_log.canonical', ['content_kanban_log' => $entity->id()]);
  }

}
