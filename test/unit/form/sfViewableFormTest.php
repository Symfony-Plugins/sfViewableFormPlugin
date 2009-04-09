<?php

include dirname(__FILE__).'/../../bootstrap/unit.php';

$t = new lime_test(18, new lime_output_color());

$yaml = <<<YML
catalogue: forms

formatters:
  table:  MyTableFormatter

forms:
  MyForm:
    _formatter: MyCustomFormatter
    _catalogue: my_form_catalogue
    _post_validator:
      invalid: The two email addresses must match.
    email:
      help:    i.e. john@example.com
      label:   Your email address
      default: john@example.com
    email_again:
      help:    'Current email is "%%email%%".'

validators:
  sfValidatorEmail:
    invalid: '"%value%" is not a valid email address.'
  sfValidatorBase:
    required: This is a required value.

widgets:
  sfWidgetFormInput:
    class: extra_class
    foo:   bar
YML;

function enhance_form($form)
{
  static $config;
  global $yaml;

  if (!$config)
  {
    $config = sfYaml::load($yaml);
  }

  $extra = new sfViewableForm();
  $extra->setConfig($config);
  $extra->enhanceForm($form);

  return $form;
}

class MyTableFormatter extends sfWidgetFormSchemaFormatterTable
{
}

class MyCustomFormatter extends sfWidgetFormSchemaFormatterList
{
}

class MyFormObject
{
  public function __call($method, $arguments)
  {
    return $method;
  }
}

class MyForm extends sfForm
{
  public function configure()
  {
    $this->setWidgets(array(
      'email' => new sfWidgetFormInput(array(), array('class' => 'form_class')),
      'email_again' => new sfWidgetFormInput(),
    ));

    $this->setValidators(array(
      'email' => new sfValidatorEmail(),
      'email_again' => new sfValidatorEmail(),
    ));

    $this->mergePostValidator(new sfValidatorSchemaCompare('email', '==', 'email_again'));
  }

  public function getObject()
  {
    return new MyFormObject();
  }
}

class AnotherForm extends sfForm
{
  public function configure()
  {
    $this->embedForm('embedded', new MyForm());
  }
}

// formatter
$t->diag('formatter');

$form = new sfForm();
enhance_form($form);

$t->is($form->getWidgetSchema()->getFormFormatter()->getTranslationCatalogue(), 'forms', '->enhanceForm() sets a global translation catalogue');
$t->isa_ok($form->getWidgetSchema()->getFormFormatter(), 'MyTableFormatter', '->enhanceForm() sets global form formatter names');

// widgets
$t->diag('widgets');

$form = new sfForm();
$form->setWidget('_catalogue', new sfWidgetFormInput());
try
{
  enhance_form($form);
  $t->fail('->enhanceForm() throws an exception if form includes reserved field names');
}
catch (RuntimeException $e)
{
  $t->pass('->enhanceForm() throws an exception if form includes reserved field names');
}

$form = new MyForm();
enhance_form($form);

$row = $form['email']->renderRow();

$t->like($row, '/foo="bar"/', '->enhanceForm() sets widget attributes based on widget class name');
$t->like($row, '/class="form_class extra_class"/', '->enhanceForm() adds to an existing class name non-destructively');
$t->like($row, '/Your email address/', '->enhanceForm() adds labels based on form class name');
$t->like($row, '/i\.e\. john@example\.com/', '->enhanceForm() adds helps based on form class name');
$t->like($row, '/value="john@example\.com"/', '->enhanceForm() adds defaults based on form class name');
$t->isa_ok($form->getWidgetSchema()->getFormFormatter(), 'MyCustomFormatter', '->enhanceForm() sets the form formatter based on form class name');
$t->is($form->getWidgetSchema()->getFormFormatter()->getTranslationCatalogue(), 'my_form_catalogue', '->enhanceForm() sets the translation catalogue based on form class name');

$row = $form['email_again']->renderRow();

$t->like($row, '/Current email is "getEmail"./', '->enhanceForm() substitutes values from object');

// validators
$t->diag('validators');

$form = new MyForm();
$form->bind(array('email' => 'foo'));
enhance_form($form);

$t->like($form['email']->renderRow(), '/"foo" is not a valid email address\./', '->enhanceForm() sets validator messages based on validator class name');
$t->like($form['email_again']->renderRow(), '/This is a required value\./', '->enhanceForm() sets validator messages based on an ancestor validator class name');

$form = new MyForm();
$form->bind(array('email' => 'abc@example.com', 'email_again' => 'def@example.com'));
enhance_form($form);

$t->like($form['email']->renderRow(), '/The two email addresses must match\./', '->enhanceForm() set post validator messages based on form class name');

// embedded forms
$t->diag('embedded forms');

$form = new AnotherForm();
enhance_form($form);

$row = $form['embedded']->renderRow();

$t->like($row, '/foo="bar"/', '->enhanceForm() sets widget attributes based on widget class name for an embedded form');
$t->like($row, '/class="form_class extra_class"/', '->enhanceForm() adds to an existing class name non-destructively for an embedded form');
$t->like($row, '/Your email address/', '->enhanceForm() adds labels based on form class name for an embedded form');
$t->like($row, '/i\.e\. john@example\.com/', '->enhanceForm() adds helps based on form class name for an embedded form');  
