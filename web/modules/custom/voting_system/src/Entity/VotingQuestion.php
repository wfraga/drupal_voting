<?php

declare(strict_types=1);

namespace Drupal\voting_system\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\user\EntityOwnerTrait;
use Drupal\voting_system\VotingQuestionInterface;

/**
 * Defines the voting question entity class.
 *
 * @ContentEntityType(
 *   id = "voting_question",
 *   label = @Translation("Voting Question"),
 *   label_collection = @Translation("Voting Questions"),
 *   label_singular = @Translation("voting question"),
 *   label_plural = @Translation("voting questions"),
 *   label_count = @PluralTranslation(
 *     singular = "@count voting questions",
 *     plural = "@count voting questions",
 *   ),
 *   handlers = {
 *     "list_builder" = "Drupal\voting_system\VotingQuestionListBuilder",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "access" = "Drupal\voting_system\VotingQuestionAccessControlHandler",
 *     "form" = {
 *       "add" = "Drupal\voting_system\Form\VotingQuestionForm",
 *       "edit" = "Drupal\voting_system\Form\VotingQuestionForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm",
 *       "delete-multiple-confirm" = "Drupal\Core\Entity\Form\DeleteMultipleForm",
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\voting_system\Routing\VotingQuestionHtmlRouteProvider",
 *     },
 *   },
 *   base_table = "voting_question",
 *   admin_permission = "administer voting_question",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid",
 *     "owner" = "uid",
 *   },
 *   links = {
 *     "collection" = "/admin/content/voting-question",
 *     "add-form" = "/admin/content/voting-question/add",
 *     "canonical" = "/admin/content/voting-question/{voting_question}",
 *     "edit-form" = "/admin/content/voting-question/{voting_question}",
 *     "delete-form" = "/admin/content/voting-question/{voting_question}/delete",
 *     "delete-multiple-form" = "/admin/content/voting-question/delete-multiple",
 *   },
 *   field_ui_base_route = "entity.voting_question.settings",
 * )
 */
final class VotingQuestion extends ContentEntityBase implements VotingQuestionInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    if ($this->get('field_machine_name')->isEmpty() && !$this->label->isEmpty()) {
      $label = $this->label();

      // Gera o ID base
      $base_id = \Drupal::service('transliteration')->transliterate($label, 'pt-br', '_');
      $base_id = strtolower($base_id);
      $base_id = preg_replace('/[^a-z0-9_]+/', '_', $base_id);
      $base_id = trim($base_id, '_');

      $unique_id = $base_id;
      $counter = 1;

      // Loop para garantir unicidade (Integridade de Dados)
      while (static::exists($unique_id)) {
        $unique_id = $base_id . '_' . $counter;
        $counter++;
      }

      $this->set('field_machine_name', $unique_id);
    }
  }

  /**
   * Helper para verificar se o ID já existe no banco.
   */
  public static function exists($id) {
    $query = \Drupal::entityTypeManager()
      ->getStorage('voting_question')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_machine_name', $id)
      ->execute();

    return (bool) $query;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {

    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Label'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'string',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['status'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Status'))
      ->setDefaultValue(TRUE)
      ->setSetting('on_label', 'Enabled')
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'settings' => [
          'display_label' => FALSE,
        ],
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'type' => 'boolean',
        'label' => 'above',
        'weight' => 0,
        'settings' => [
          'format' => 'enabled-disabled',
        ],
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Author'))
      ->setSetting('target_type', 'user')
      ->setDefaultValueCallback(self::class . '::getDefaultEntityOwner')
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => 60,
          'placeholder' => '',
        ],
        'weight' => 15,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'author',
        'weight' => 15,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Authored on'))
      ->setDescription(t('The time that the voting question was created.'))
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'timestamp',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', [
        'type' => 'datetime_timestamp',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time that the voting question was last edited.'));

    return $fields;
  }

}
