<?php

/**
 * The sfViewableForm class is used to enhance a project's forms.
 * 
 * @package    sfViewableFormPlugin
 * @subpackage form
 * @author     Kris Wallsmith <kris.wallsmith@symfony-project.com>
 * @version    SVN: $Id$
 */
class sfViewableForm
{
  protected
    $dispatcher = null,
    $config     = array(),
    $enhanced   = array();

  /**
   * Connects to the 'template.filter_parameters' event.
   * 
   * @param sfApplicationConfiguration $configuration
   */
  static public function connect(sfApplicationConfiguration $configuration)
  {
    $configuration->getEventDispatcher()->connect('template.filter_parameters', array(new self($configuration), 'filterTemplateParameters'));
  }

  /**
   * Constructor.
   * 
   * @see initialize()
   */
  public function __construct(sfApplicationConfiguration $configuration = null)
  {
    $this->initialize($configuration);
  }

  /**
   * Initialize.
   * 
   * @param sfApplicationConfiguration $configuration
   */
  public function initialize(sfApplicationConfiguration $configuration = null)
  {
    if ($configuration instanceof sfApplicationConfiguration)
    {
      $this->setEventDispatcher($configuration->getEventDispatcher());

      if ($file = $configuration->getConfigCache()->checkConfig('config/forms.yml', true))
      {
        $config = include $file;
        $this->setConfig($config);
      }
    }
  }

  /**
   * Returns the event dispatcher.
   * 
   * @return sfEventDispatcher|null
   */
  public function getEventDispatcher()
  {
    return $this->dispatcher;
  }

  /**
   * Sets the event dispatcher.
   * 
   * @param sfEventDispatcher $dispatcher
   */
  public function setEventDispatcher(sfEventDispatcher $dispatcher)
  {
    $this->dispatcher = $dispatcher;
  }

  /**
   * Returns the configuration array.
   * 
   * @return array
   */
  public function getConfig()
  {
    return $this->config;
  }

  /**
   * Sets the configuration array.
   * 
   * @param array $config
   */
  public function setConfig($config)
  {
    $this->config = $config;
  }

  /**
   * Returns true if the form has been enhanced.
   * 
   * @param  sfForm $form
   * 
   * @return boolean
   */
  public function hasEnhanced(sfForm $form)
  {
    return in_array($form, $this->enhanced, true);
  }

  /**
   * Enhances any forms before they're passed to the template.
   * 
   * @param  sfEvent $event
   * @param  array   $parameters
   * 
   * @return array
   */
  public function filterTemplateParameters(sfEvent $event, array $parameters)
  {
    foreach ($parameters as $parameter)
    {
      if ($parameter instanceof sfForm && !$this->hasEnhanced($parameter))
      {
        $this->enhanceForm($parameter);

        if ($this->dispatcher)
        {
          $this->dispatcher->filter(new sfEvent($this, 'template.filter_form_parameter'), $parameter);
        }

        if ($parameter->hasErrors())
        {
          $event = $this->dispatcher->notifyUntil(new sfEvent($parameter, 'form.validation_failure'));
          if (!$event->isProcessed())
          {
            $this->handleFormErrors($parameter);
          }
        }
      }
    }

    return $parameters;
  }

  /**
   * Handles a form with validation errors.
   * 
   * @param sfForm $form
   */
  public function handleFormErrors(sfForm $form)
  {
    $context  = sfContext::getInstance();
    $request  = $context->getRequest();
    $response = $context->getResponse();

    if ($status = sfConfig::get('app_viewable_form_validation_error_http_status', false))
    {
      $response->setStatusCode($status);
    }

    if ($request->isXmlHttpRequest() && sfConfig::get('app_viewable_form_send_ajax_json_errors'))
    {
      $response->setContentType('application/json');
      $response->setContent(json_encode($this->mapErrorSchemaToArray($form->getErrorSchema()))."\n");
      $response->send();

      throw new sfStopException();
    }
  }

  /**
   * Converts an error schema to an array of scalars.
   * 
   * @return array
   */
  protected function mapErrorSchemaToArray(sfValidatorErrorSchema $errors)
  {
    $array = array();
    foreach ($errors->getNamedErrors() as $field => $error)
    {
      if ($error instanceof sfValidatorErrorSchema)
      {
        $array[$field] = $this->mapErrorSchemaToArray($error);
      }
      else
      {
        $array[$field] = $error->getMessage();
      }
    }

    $global = array();
    foreach ($errors->getGlobalErrors() as $error)
    {
      if ($error instanceof sfValidatorErrorSchema)
      {
        $global[] = $this->mapErrorSchemaToArray($error);
      }
      else
      {
        $global[] = $error->getMessage();
      }
    }

    $array = array_merge(array(
      sfConfig::get('app_viewable_form_global_errors_json_key', '_global_errors') => $global,
    ), $array);

    return $array;
  }

  /**
   * Enhances a form.
   * 
   * @param sfForm $form
   */
  public function enhanceForm(sfForm $form)
  {
    $this->enhanceFormFields($form->getFormFieldSchema(), get_class($form), $form->getEmbeddedForms(), method_exists($form, 'getObject') ? $form->getObject() : null);
    $this->enhanced[] = $form;
  }

  /**
   * Enhances form fields.
   * 
   * @param sfFormFieldSchema $fieldSchema    Form fields to enhance
   * @param string            $formClass      The name of the form class these fields are from
   * @param array             $embeddedForms  An array of forms embedded in the fields' form
   * @param mixed             $object         The current form's model object
   */
  protected function enhanceFormFields(sfFormFieldSchema $fieldSchema, $formClass, array $embeddedForms = array(), $object = null)
  {
    // enhance schemas
    $this->enhanceWidget($fieldSchema->getWidget(), $object);
    if ($fieldSchema->hasError())
    {
      $this->enhanceValidator($fieldSchema->getError()->getValidator(), $object);
    }

    // loop through the fields and apply the global configuration
    foreach ($fieldSchema as $field)
    {
      if ($field instanceof sfFormFieldSchema)
      {
        if (isset($embeddedForms[$field->getName()]))
        {
          $form = $embeddedForms[$field->getName()];
          $this->enhanceFormFields($field, get_class($form), $form->getEmbeddedForms(), method_exists($form, 'getObject') ? $form->getObject() : null);
        }
        else
        {
          $this->enhanceFormFields($field, $formClass);
        }
      }

      $this->enhanceWidget($field->getWidget(), $object);

      if ($field->hasError())
      {
        $validator = $field->getError()->getValidator();

        $this->enhanceValidator($validator, $object);

        if ($validator instanceof sfValidatorSchema)
        {
          if ($preValidator = $validator->getPreValidator())
          {
            $this->enhanceValidator($preValidator, $object, true);
          }
          if ($postValidator = $validator->getPostValidator())
          {
            $this->enhanceValidator($postValidator, $object, true);
          }
        }
      }
    }

    // loop through the form's lineage and apply configuration
    foreach (self::getLineage($formClass) as $class)
    {
      if (isset($this->config['forms'][$class]))
      {
        foreach ($this->config['forms'][$class] as $name => $params)
        {
          $params = $this->replaceConstants($params, $object);

          if ('_formatter' == $name)
          {
            $fieldSchema->getWidget()->setFormFormatterName($params);

            // parameter can be a class name
            if (class_exists($params) && is_subclass_of($params, 'sfWidgetFormSchemaFormatter'))
            {
              $fieldSchema->getWidget()->addFormFormatter($params, new $params($fieldSchema->getWidget()));
            }

            continue;
          }

          if (preg_match('/^_(pre|post)_validator$/', $name, $match))
          {
            $method = 'get'.ucwords($match[1]).'Validator';

            if (($error = $fieldSchema->getError()) && ($validator = $error->getValidator()->$method()))
            {
              $validator->setMessages(array_merge($validator->getMessages(), $params));
            }

            continue;
          }

          $field = $fieldSchema[$name];
          $widget = $field->getWidget();
          $validator = $field->hasError() ? $field->getError() : null;

          if (isset($params['label']))
          {
            $fieldSchema->getWidget()->setLabel($name, $params['label']);
          }

          if (isset($params['default']))
          {
            $fieldSchema->getWidget()->setDefault($name, $params['default']);
          }

          if (isset($params['help']))
          {
            $fieldSchema->getWidget()->setHelp($name, $params['help']);
          }

          if (isset($params['attributes']))
          {
            $this->extendWidgetAttributes($widget, $params['attributes'], $object);
          }

          if ($validator && isset($params['messages']))
          {
            $validator->setMessages(array_merge($validator->getMessages(), $params['messages']));
          }
        }
      }
    }
  }

  /**
   * Enhances a widget.
   * 
   * @param sfWidget $widget
   * @param mixed    $object
   */
  public function enhanceWidget(sfWidget $widget, $object = null)
  {
    // form formatters
    if ($widget instanceof sfWidgetFormSchema)
    {
      $name = $widget->getFormFormatterName();
      if (isset($this->config['formatters'][$name]))
      {
        $formatter = $this->config['formatters'][$name];
        $widget->addFormFormatter($name, new $formatter($widget));
      }
    }

    foreach (self::getLineage($widget) as $class)
    {
      if (isset($this->config['widgets'][$class]))
      {
        $config = $this->processWidgetConfig($this->config['widgets'][$class]);

        foreach ($config['options'] as $name => $value)
        {
          $widget->setOption($name, $value);
        }

        $this->extendWidgetAttributes($widget, $config['attributes'], $object);
      }
    }
  }

  /**
   * Enhances a validator.
   * 
   * @param sfValidatorBase $validator
   * @param mixed           $object
   * @param boolean         $recursive Enhance validator schema recursively
   */
  public function enhanceValidator(sfValidatorBase $validator, $object = null, $recursive = false)
  {
    foreach (self::getLineage($validator) as $class)
    {
      if (isset($this->config['validators'][$class]))
      {
        $config = $this->processValidatorConfig($this->config['validators'][$class]);

        foreach ($config['options'] as $name => $value)
        {
          $validator->setOption($name, $value);
        }

        foreach ($config['messages'] as $code => $message)
        {
          $validator->setMessage($code, $message);
        }
      }
    }

    if ($recursive && $validator instanceof sfValidatorSchema)
    {
      foreach ($validator->getFields() as $v)
      {
        $this->enhanceValidator($v, $object, $recursive);
      }
    }

    if (method_exists($validator, 'getValidators'))
    {
      foreach ($validator->getValidators() as $v)
      {
        $this->enhanceValidator($v, $object, $recursive);
      }
    }
  }

  /**
   * Extends a widget's attributes.
   * 
   * @param sfWidget $widget
   * @param array    $attributes
   * @param mixed    $object
   */
  protected function extendWidgetAttributes(sfWidget $widget, array $attributes, $object = null)
  {
    foreach ($attributes as $name => $value)
    {
      if ('class' == $name)
      {
        // non-destructive
        $current = $widget->getAttribute('class');
        $widget->setAttribute($name, $current ? $current.' '.$value : $value);
      }
      else
      {
        $widget->setAttribute($name, $value);
      }
    }
  }

  /**
   * Processes an array of widget configuration values.
   * 
   * @param  array $config
   * @param  mixed $object
   * 
   * @return array An associative array of options and attributes
   */
  protected function processWidgetConfig(array $config, $object = null)
  {
    if (!isset($config['options']) && !isset($config['attributes']))
    {
      $config = array('attributes' => $config);
    }

    $config = array_merge(array('options' => array(), 'attributes' => array()), $config);
    $config = $this->replaceConstants($config, $object);

    return $config;
  }

  /**
   * Processes an array of validator configuration values.
   * 
   * @param  array $config
   * @param  mixed $object
   * 
   * @return array An associative array of options and attributes
   */
  protected function processValidatorConfig(array $config, $object = null)
  {
    if (!isset($config['options']) && !isset($config['messages']))
    {
      $config = array('messages' => $config);
    }

    $config = array_merge(array('options' => array(), 'messages' => array()), $config);
    $config = $this->replaceConstants($config, $object);

    return $config;
  }

  /**
   * Replaces constants in a string.
   * 
   * If $subject is an array it is processed recursively.
   * 
   * @param  string|array $subject
   * @param  mixed        $object
   * 
   * @return string
   */
  protected function replaceConstants($subject, $object = null)
  {
    if (is_array($subject))
    {
      foreach ($subject as $key => $value)
      {
        $subject[$key] = $this->replaceConstants($value, $object);
      }

      return $subject;
    }

    if (is_null($object) || false === strpos($subject, '%'))
    {
      return $subject;
    }

    if (preg_match_all('/%(\w+)%/', $subject, $matches))
    {
      foreach ($matches[1] as $i => $name)
      {
        $method = 'get'.sfInflector::camelize($name);
        $subject = str_replace($matches[0][$i], $object->$method(), $subject);
      }
    }

    return $subject;
  }

  /**
   * Returns an object's lineage.
   * 
   * @param  string|object $class
   * 
   * @return array
   */
  static public function getLineage($class)
  {
    if (is_object($class))
    {
      $class = get_class($class);
    }

    $classes = array();
    do
    {
      $classes[] = $class;
    }
    while ($class = get_parent_class($class));

    $lineage = array_reverse($classes);

    return $lineage;
  }
}
