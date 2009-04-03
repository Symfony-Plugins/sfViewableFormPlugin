<?php

include dirname(__FILE__).'/../../bootstrap/unit.php';

$t = new lime_test(11, new lime_output_color());

$extra = new sfViewableForm();
$extra->setConfig(array(
  'forms' => array(
    'MyForm' => array(
      '_post_validator' => array(
        'invalid' => 'The two email addresses must match.',
      ),
      'email' => array(
        'help' => 'i.e. john@example.com',
        'label' => 'Your email address',
      ),
    ),
  ),
  'validators' => array(
    'sfValidatorEmail' => array(
      'invalid' => '"%value%" is not a valid email address.',
    ),
    'sfValidatorString' => array(
      'required' => 'This is a required value.',
    ),
  ),
  'widgets' => array(
    'sfWidgetFormInput' => array(
      'class' => 'extra_class',
      'foo'   => 'bar',
    ),
  ),
));

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
}

class AnotherForm extends sfForm
{
  public function configure()
  {
    $this->embedForm('embedded', new MyForm());
  }
}

// ->>enhanceForm()
$t->diag('->enhanceForm()');

$form = new MyForm();
$extra->enhanceForm($form);

$row = $form['email']->renderRow();

$t->like($row, '/foo="bar"/', '->enhanceForm() sets widget attributes based on widget class name');
$t->like($row, '/class="form_class extra_class"/', '->enhanceForm() adds to an existing class name non-destructively');
$t->like($row, '/Your email address/', '->enhanceForm() adds labels based on form class name');
$t->like($row, '/i\.e\. john@example\.com/', '->enhanceForm() adds helps based on form class name');  

$form = new MyForm();
$form->bind(array('email' => 'foo'));
$extra->enhanceForm($form);

$t->like($form['email']->renderRow(), '/"foo" is not a valid email address\./', '->enhanceForm() sets validator messages based on validator class name');
$t->like($form['email_again']->renderRow(), '/This is a required value\./', '->enhanceForm() sets validator messages based on an ancestor validator class name');

$form = new MyForm();
$form->bind(array('email' => 'abc@example.com', 'email_again' => 'def@example.com'));
$extra->enhanceForm($form);

$t->like($form['email']->renderRow(), '/The two email addresses must match\./', '->enhanceForm() set post validator messages based on form class name');

$form = new AnotherForm();
$extra->enhanceForm($form);

$row = $form['embedded']->renderRow();

$t->like($row, '/foo="bar"/', '->enhanceForm() sets widget attributes based on widget class name for an embedded form');
$t->like($row, '/class="form_class extra_class"/', '->enhanceForm() adds to an existing class name non-destructively for an embedded form');
$t->like($row, '/Your email address/', '->enhanceForm() adds labels based on form class name for an embedded form');
$t->like($row, '/i\.e\. john@example\.com/', '->enhanceForm() adds helps based on form class name for an embedded form');  
