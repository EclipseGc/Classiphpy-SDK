<?php
/**
 * Created by PhpStorm.
 * User: kris.vanderwater
 * Date: 2/14/15
 * Time: 2:48 PM
 */

namespace Classiphpy\SDK\Definition;


use Classiphpy\Definition\DefinitionInterface;
use Pharborist\FormatterFactory;
use Pharborist\Objects\ClassMethodNode;
use Pharborist\Parser;
use Pharborist\RootNode;
use Pharborist\Objects\ClassNode;

class Wrapper implements DefinitionInterface {

  /**
   * @var string
   */
  protected $name;

  /**
   * @var string
   */
  protected $namespace;

  /**
   * @var array
   */
  protected $defaults;

  /**
   * {@inheritdoc}
   */
  public static function definitionFactory(array $definition, array $defaults = []) {
    return new static($defaults['wrapper_name'], $defaults['wrapper_namespace'], $defaults);
  }

  /**
   * {@inheritdoc}
   */
  public static function iteratorFactory(array $data) {
    return [Wrapper::definitionFactory($data, $data['defaults'])];
  }

  /**
   * {@inheritdoc}
   */
  public static function validateData(array &$data) {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public static function validationErrorMessage() {
    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function getName() {
    return $this->name;
  }

  /**
   * {@inheritdoc}
   */
  public function getNamespace() {
    return $this->namespace;
  }

  /**
   * {@inheritdoc}
   */
  public function getProperties() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getDependencies() {
    return [];
  }

  public function __construct($name, $namespace, array $defaults) {
    $this->name = $name;
    $this->namespace = $namespace;
    $this->defaults = $defaults;
  }

  /**
   * {@inheritdoc}
   */
  public function __toString() {
    try {
      return $this->toString();
    }
    catch (\Exception $e) {
      print_r($e->getMessage());
      return '';
    }
  }

  protected function toString() {
    $doc = RootNode::create($this->getNamespace());
    $doc->getNamespace($this->getNamespace())->getBody()->append(Parser::parseSnippet('use GuzzleHttp\Client as GuzzleClient;'));
    $doc->getNamespace($this->getNamespace())->getBody()->append(Parser::parseSnippet('use '. $this->defaults['client_namespace'] .'\Factory;'));
    // If the wrapper and Client namespaces are different, create a use
    // statement for the Client.
    if ($this->getNamespace() != $this->defaults['client_namespace']) {
      $doc->getNamespace($this->getNamespace())->getBody()->append(Parser::parseSnippet("use {$this->defaults['client_namespace']}\\Client;"));
    }
    $doc->getNamespace($this->getNamespace())->getBody()->append(Parser::parseSnippet("\n\n"));
    $class = ClassNode::create($this->getName());

    $constant = Parser::parseSnippet("const API_PATH = {$this->defaults['api_path']};");
    //$constant->appendTo($class);
    $create = ClassMethodNode::create('create');
    $create->setStatic(TRUE);
    $code = [];
    $code[] = "\$config = [
      'base_url' => static::API_PATH,
    ];";
    $code[] = "return new Client(new GuzzleClient(\$config), new Factory());";
    foreach ($code as $snippet) {
      $create->getBody()->lastChild()->before(Parser::parseSnippet($snippet));
    }
    $class->appendMethod($create);
    $doc->getNamespace($this->getNamespace())->getBody()->append($class);
    /* @todo dispatch an event to allow subscribers to alter $doc */
    $formatter = FormatterFactory::getPsr2Formatter();
    $formatter->format($doc->getNamespace($this->getNamespace()));
    return $doc->getText();
  }

}