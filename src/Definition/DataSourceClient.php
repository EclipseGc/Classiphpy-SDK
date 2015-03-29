<?php
/**
 * Created by PhpStorm.
 * User: kris.vanderwater
 * Date: 2/8/15
 * Time: 1:51 PM
 */

namespace Classiphpy\SDK\Definition;


use Classiphpy\Definition\DefinitionInterface;
use Pharborist\DocCommentNode;
use Pharborist\Filter;
use Pharborist\FormatterFactory;
use Pharborist\Functions\ParameterNode;
use Pharborist\Objects\ClassMethodNode;
use Pharborist\Objects\ClassNode;
use Pharborist\Parser;
use Pharborist\RootNode;

class DataSourceClient implements DefinitionInterface {

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
  protected $operations;

  function __construct($name, $namespace, array $operations)
  {
    $this->name = $name;
    $this->namespace = $namespace;
    $this->operations = $operations;
  }

  /**
   * {@inheritdoc}
   */
  public static function definitionFactory(array $definition, array $defaults = [])
  {
    return new static($defaults['client_name'], $defaults['client_namespace'], $definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function iteratorFactory(array $data)
  {
    // @todo abstract this into an abstract base class in Classiphpy
    if (!DataSourceClient::validateData($data)) {
      throw new \Exception(DataSourceClient::validationErrorMessage());
    }
    $classes = [];
    $classes['DataSourceClient'] = DataSourceClient::definitionFactory($data['operations'], $data['defaults']);
    return $classes;
  }

  /**
   * {@inheritdoc}
   */
  public static function validateData(array &$data)
  {
    // @todo actually validate the data.
    return isset($data['operations']);
  }

  /**
   * {@inheritdoc}
   */
  public static function validationErrorMessage()
  {
    return 'Operations key missing';
  }

  /**
   * {@inheritdoc}
   */
  public function getName()
  {
    return $this->name;
  }

  /**
   * {@inheritdoc}
   */
  public function getNamespace()
  {
    return $this->namespace;
  }

  /**
   * {@inheritdoc}
   */
  public function getProperties()
  {
    return [
      'client' => [
        'type' => 'GuzzleClient',
      ],
      'factory' => [
        'type' => 'FactoryInterface',
      ]
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getDependencies()
  {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function __toString()
  {
    try {
      return $this->toString();
    }
    catch (\Exception $e) {
      print_r($e->getMessage());
      return '';
    }
  }

  protected function toString()
  {
    $doc = RootNode::create($this->getNamespace());
    $doc->getNamespace($this->getNamespace())->getBody()->append(Parser::parseSnippet('use GuzzleHttp\Client as GuzzleClient;'));
    $doc->getNamespace($this->getNamespace())->getBody()->append(Parser::parseSnippet('use '. $this->getNamespace() .'\FactoryInterface;'));
    $doc->getNamespace($this->getNamespace())->getBody()->append(Parser::parseSnippet("\n\n"));
    $class = ClassNode::create($this->getName());
    $constructor = ClassMethodNode::create('__construct');
    $class->appendMethod($constructor);

    $constructorDocString = '';
    foreach ($this->getProperties() as $name => $info) {
      if ($name != 'client') {
        $class->createProperty($name, isset($info['default']) ? $info['default'] : NULL, 'protected');
        if (isset($info['description'])) {
          $propertyDocString = "@var {$info['type']} $name\n  {$info['description']}";
          $constructorDocString .= "@param {$info['type']} $name\n  {$info['description']}\n\n";
        }
        else {
          $propertyDocString = "@var {$info['type']} $name";
          $constructorDocString .= "@param {$info['type']} $name\n\n";
        }
        $class->getProperty($name)->closest(Filter::isInstanceOf('\Pharborist\Objects\ClassMemberListNode'))->setDocComment(DocCommentNode::create($propertyDocString));
      }
      else {
        $constructorDocString .= "@param {$info['type']} $name\n\n";
      }
      $constructor->appendParameter(ParameterNode::create($name));
      if (isset($info['type']) && !$this->isScalar($info['type'])) {
        $constructor->getParameter($name)->setTypeHint($info['type']);
      }
      if ($name != 'client') {
        $expression = Parser::parseSnippet("\$this->{$name} = \$$name;");
        $constructor->getBody()->lastChild()->before($expression);
      }
      else {
        $expression = Parser::parseSnippet("\$this->client(\$$name);");
        $constructor->getBody()->lastChild()->before($expression);
      }
    }

    $this->appendClientMethod($class);
    $this->appendRequestMethod($class);
    $this->appendFactoryMethod($class);
    foreach($this->operations as $name => $operation) {
      $this->appendOperationMethod($class, $name, $operation);
    }

    $class->getMethod('__construct')->setDocComment(DocCommentNode::create($constructorDocString));

    $doc->getNamespace($this->getNamespace())->getBody()->append($class);
    /* @todo dispatch an event to allow subscribers to alter $doc */
    $formatter = FormatterFactory::getPsr2Formatter();
    $formatter->format($doc->getNamespace($this->getNamespace()));
    return $doc->getText();
  }

  protected function appendClientMethod(ClassNode $class) {
    $client = ClassMethodNode::create('client');
    $client->setVisibility('protected');
    $client->appendParameter(ParameterNode::create('new_client'));
    $client->getParameter('new_client')->setTypeHint('GuzzleClient')->setValue(Parser::parseExpression('NULL'));
    $code = [];
    $code[] = "static \$client;";
    $code[] = "if (!is_null(\$new_client)) {\$client = \$new_client;}";
    foreach ($code as $snippet) {
      $client->getBody()->lastChild()->before(Parser::parseSnippet($snippet));
    }
    $class->appendMethod($client);
  }

  protected function appendRequestMethod(ClassNode $class) {
    $request = ClassMethodNode::create('request');
    $request->setVisibility('protected');
    $request->appendParameter(ParameterNode::create('url'));
    $request->appendParameter(ParameterNode::create('options'));
    $request->getParameter('options')->setTypeHint('array')->setValue(Parser::parseExpression('[]'));
    $code = [];
    $code[] = "\$request = \$this->client()->get(\$url, \$options);";
    $code[] = "if (!is_object(\$request)) {
      var_export(\$request);
    }";
    $code[] = "if (\$request->getStatusCode() != 200) {
      throw new \\Exception(sprintf('Status code was not OK. %d returned instead.', \$request->getStatusCode()));
    }";
    $code[] = "return \$request;";
    foreach ($code as $snippet) {
      $request->getBody()->lastChild()->before(Parser::parseSnippet($snippet));
    }
    $class->appendMethod($request);
  }

  protected function appendFactoryMethod(ClassNode $class) {
    $method = ClassMethodNode::create('createObjectType');
    $method->setVisibility('protected');
    $method->appendParameter(ParameterNode::create('type'));
    $method->appendParameter(ParameterNode::create('data'));
    $method->getParameter('data')->setTypeHint('array')->setValue(Parser::parseExpression('[]'));
    $code = [];
    $code[] = "if (empty(\$data['dataSource'])) {
      \$data['dataSource'] = \$this;
    }";
    $code[] = "return \$this->factory->createObjectType(\$type, \$data);";
    foreach ($code as $snippet) {
      $method->getBody()->lastChild()->before(Parser::parseSnippet($snippet));
    }
    $class->appendMethod($method);
  }

  protected function appendOperationMethod(ClassNode $class, $name, array $definition) {
    $docs = '';
    if (isset($definition['description'])) {
      $docs .= "{$definition['description']}\n\n";
    }
    $operation = ClassMethodNode::create($name);
    $operation->setVisibility('public');
    $url_values = [];
    foreach($definition['parameters'] as $parameter) {
      $url_values[] = $parameter['name'];
      $operation->appendParameter(ParameterNode::create($parameter['name']));
      if (!$this->isScalar($parameter['type'])) {
        $operation->getParameter($parameter['name'])->setTypeHint($parameter['type']);
      }
      if (isset($parameter['default'])) {
        $operation->getParameter($parameter['name'])->setValue(Parser::parseExpression($parameter['default']));
      }
    }

    $matches = [];
    preg_match_all('/\{([^\}]+)\}/', $definition['target'], $matches);
    $url_parameters = [];
    foreach ($matches[1] as $id => $key) {
      $url_parameters[] = "'$key' => \${$url_values[$id]}";
    }
    $url_parameters = implode(', ', $url_parameters);

    $code = [];
    if ($definition['transport'] == 'get') {
      if ($url_parameters) {
        $code[] = "\$data = \$this->request(['{$definition['target']}', [$url_parameters]]);";
      }
      else {
        $code[] = "\$data = \$this->request('{$definition['target']}');";
      }
    }
    else {
      if ($url_parameters) {
        $code[] = "\$data = \$this->client()->{$definition['transport']}(['{$definition['target']}', [$url_parameters]]);";
      }
      else {
        $code[] = "\$data = \$this->client()->{$definition['transport']}('{$definition['target']}');";
      }
    }
    foreach($definition['parameters'] as $parameter) {
      $code[] = "\$data['{$parameter['name']}'] = \${$parameter['name']};";
      $docs .= "@param {$parameter['type']} \${$parameter['name']}\n";
    }
    $code[] = "return \$this->createObjectType('" . $definition['returns']['type'] . "', \$data);";
    foreach ($code as $snippet) {
      $operation->getBody()->lastChild()->before(Parser::parseSnippet($snippet));
    }
    $class->appendMethod($operation);
    $class->getMethod($name)->setDocComment(DocCommentNode::create($docs));
  }

  protected function isScalar($type) {
    $scalar = [
      'boolean',
      'bool',
      'integer',
      'int',
      'float',
      'string',
      'str'
    ];
    return array_search($type, $scalar);
  }

} 