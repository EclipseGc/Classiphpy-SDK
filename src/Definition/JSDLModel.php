<?php
/**
 * Created by PhpStorm.
 * User: kris.vanderwater
 * Date: 2/8/15
 * Time: 11:19 AM
 */

namespace Classiphpy\SDK\Definition;

use Classiphpy\Definition\DefaultDefinition;

class JSDLModel extends DefaultDefinition {
  /**
   * {@inheritdoc}
   */
  public static function definitionFactory(array $definition, array $defaults = []) {
    foreach (array_keys($defaults) as $key) {
      // If the definition has values for this key
      if (!empty($definition[$key])) {
        // and the definition and defaults are both arrays
        if (is_array($definition[$key]) && is_array($defaults[$key])) {
          // add them together.
          $definition[$key] += $defaults[$key];
        }
      }
      // If the definition has no values for this yet, trust the defaults.
      else {
        $definition[$key] = $defaults[$key];
      }
    }
    return new static($definition['name'], $definition['namespace'] . "\\" . $definition['name'], $definition['properties']);
  }

  /**
   * {@inheritdoc}
   */
  public static function iteratorFactory(array $data) {
    if (!JSDLModel::validateData($data)) {
      throw new \Exception(JSDLModel::validationErrorMessage());
    }
    $classes = [];
    foreach ($data['types'] as $type) {
      if ($type['type'] == 'object') {
        /**foreach ($data['operations'] as $operation) {
          if ($operation['return']['type'] == $type) {
            $matches = [];
            preg_match_all('/\{([^\}]+)\}/', $operation['target'], $matches);
          }
        }*/
        $classes[] = JSDLModel::definitionFactory($type, $data['defaults']);
      }
    }
    return $classes;
  }

  /**
   * {@inheritdoc}
   */
  public static function validateData(array &$data) {
    return isset($data['types']);
  }

  /**
   * {@inheritdoc}
   */
  public static function validationErrorMessage() {
    /** @todo Better message please... */
    return 'Invalid data';
  }

}