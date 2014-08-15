<?php

namespace Drupal\og\Controller;

use Drupal\entity\Entity\EntityFormDisplay;
use Drupal\og\OgFieldBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Drupal\Component\Utility\Tags;

class OG {

  /**
   * Create an organic groups field in a bundle.
   *
   * @param $field_name
   *   The field name.
   * @param $entity_type
   *   The entity type.
   * @param $bundle
   *   The bundle name.
   */
  public static function CreateField($field_name, $entity_type, $bundle) {
    $og_field = self::FieldsInfo($field_name)
      ->setEntityType($entity_type)
      ->setBundle($bundle);

    $field = entity_load('field_storage_config', $entity_type . '.' . $og_field->fieldDefinition()->getName());

    if (!$field) {
      // The field storage config not exists. Create it.
      $og_field->fieldDefinition()->save();
    }

    // Allow overriding the field name.
    // todo: ask if we need this.
//    $og_field['field']['field_name'] = $field_name;
//    if (empty($field)) {
//      $og_field['field']->save();
//    }

    $instance = entity_load('field_instance_config', $entity_type . '.' . $bundle . '.' . $field_name);

    if (!$instance) {
      $og_field->instanceDefinition()->save();
      // Clear the entity property info cache, as OG fields might add different
      // entity property info.
      self::invalidateCache();
    }

    // Add the field to the form display manager.
    $displayForm = EntityFormDisplay::load($entity_type . '.' . $bundle . '.default');
    if (!$displayForm->getComponent($field_name) && $widgetDefinition = $og_field->widgetDefinition()) {
      $displayForm->setComponent($field_name, $widgetDefinition);
      $displayForm->save();
    }

    // Define the view mode for the field.
    if ($fieldViewModes = $og_field->viewModesDefinition()) {
      $viewModes = entity_load_multiple('entity_view_display', array_keys($fieldViewModes));

      foreach ($viewModes as $key => $viewMode) {
        $viewMode->setComponent($field_name, $fieldViewModes[$key])->save();
      }
    }
  }

  /**
   * Get all the modules fields that can be assigned to fieldable entities.
   *
   * @param $field_name
   *   The field name that was registered for the definition.
   *
   * @return OgFieldBase|bool
   *   An array with the field and instance definitions, or FALSE if not.
   *
   * todo: pass the entity type and entity bundle to plugin definition.
   */
  public static function FieldsInfo($field_name = NULL) {
    $config = \Drupal::service('plugin.manager.og.fields');
    $fields_config = $config->getDefinitions();

    if ($field_name) {
      return isset($fields_config[$field_name]) ? $config->createInstance($field_name) : NULL;
    }

    return $fields_config;
  }

  /**
   * Invalidate cache.
   *
   * @param $gids
   *   Array with group IDs that their cache should be invalidated.
   */
  public static function invalidateCache($gids = array()) {
    // Reset static cache.
    $caches = array(
      'og_user_access',
      'og_user_access_alter',
      'og_role_permissions',
      'og_get_user_roles',
      'og_get_permissions',
      'og_get_group_audience_fields',
      'og_get_entity_groups',
      'og_get_membership',
      'og_get_field_og_membership_properties',
      'og_get_user_roles',
    );

    foreach ($caches as $cache) {
      drupal_static_reset($cache);
    }

    // Let other OG modules know we invalidate cache.
    \Drupal::moduleHandler()->invokeAll('og_invalidate_cache', $gids);
  }

  /**
   * Autocomplete the label of an entity.
   *
   * @param Request $request
   *   The request object that contains the typed tags.
   * @param string $type
   *   The widget type (i.e. 'single' or 'tags').
   * @param string $field_name
   *   The name of the entity reference field.
   * @param string $entity_type
   *   The entity type.
   * @param string $bundle_name
   *   The bundle name.
   * @param string $entity_id
   *   (optional) The entity ID the entity reference field is attached to.
   *   Defaults to ''.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   Throws access denied when either the field or field instance does not
   *   exists or the user does not have access to edit the field.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The matched labels as json.
   */
  public static function handleAutocomplete(Request $request, $type, $field_name, $entity_type, $bundle_name, $entity_id) {
//    dpm($request->query->get('q'));

    // todo: Add the label.
    $query = \Drupal::entityQuery($entity_type)
      ->condition(OG_GROUP_FIELD, 1)
      ->execute();

    return;

    $definitions = \Drupal::entityManager()->getFieldDefinitions($entity_type, $bundle_name);

    if (!isset($definitions[$field_name])) {
      throw new AccessDeniedHttpException();
    }

    $field_definition = $definitions[$field_name];
    $access_control_handler = \Drupal::entityManager()->getAccessControlHandler($entity_type);
    if ($field_definition->getType() != 'entity_reference' || !$access_control_handler->fieldAccess('edit', $field_definition)) {
      throw new AccessDeniedHttpException();
    }

    // Get the typed string, if exists from the URL.
    $items_typed = $request->query->get('q');
    $items_typed = Tags::explode($items_typed);
    $last_item = drupal_strtolower(array_pop($items_typed));

    $prefix = '';
    // The user entered a comma-separated list of entity labels, so we generate
    // a prefix.
    if ($type == 'tags' && !empty($last_item)) {
      $prefix = count($items_typed) ? Tags::implode($items_typed) . ', ' : '';
    }

    //$matches = $this->entityReferenceAutocomplete->getMatches($field_definition, $entity_type, $bundle_name, $entity_id, $prefix, $last_item);
    $matches[] = array('value' => $prefix . 2, 'label' => 'a');

    return new JsonResponse($matches);
  }
}