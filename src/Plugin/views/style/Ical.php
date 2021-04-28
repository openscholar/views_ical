<?php

namespace Drupal\views_ical\Plugin\views\style;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\date_recur\Plugin\views\field\DateRecurDate;
use Drupal\views\Plugin\views\style\StylePluginBase;
use Drupal\Core\Url;
use Drupal\views_ical\ViewsIcalHelperInterface;
use Eluceo\iCal\Component\Event;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Style plugin to render an iCal feed.
 *
 * @ingroup views_style_plugins
 *
 * @ViewsStyle(
 *   id = "ical",
 *   title = @Translation("iCal Feed"),
 *   help = @Translation("Display the results as an iCal feed."),
 *   theme = "views_view_ical",
 *   display_types = {"feed"}
 * )
 */
class Ical extends StylePluginBase {
  protected $usesFields = TRUE;
  protected $usesGrouping = FALSE;
  protected $usesRowPlugin = TRUE;

  /**
   * Entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The helper service.
   *
   * @var \Drupal\views_ical\ViewsIcalHelperInterface
   */
  protected $helper;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityFieldManagerInterface $entity_field_manager, ViewsIcalHelperInterface $helper) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityFieldManager = $entity_field_manager;
    $this->helper = $helper;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_field.manager'),
      $container->get('views_ical.helper')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();

    $options['date_field'] = ['default' => NULL];
    $options['summary_field'] = ['default' => NULL];
    $options['location_field'] = ['default' => NULL];
    $options['description_field'] = ['default' => NULL];

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);
    /** @var array $field_options */
    $field_options = $this->displayHandler->getFieldLabels();

    $form['date_field'] = array(
      '#type' => 'select',
      '#title' => $this->t('Date field'),
      '#options' => $field_options,
      '#default_value' => $this->options['date_field'],
      '#description' => $this->t('Please identify the field to use as the iCal date for each item in this view.'),
      '#required' => TRUE,
    );

    $form['summary_field'] = array(
      '#type' => 'select',
      '#title' => $this->t('SUMMARY field'),
      '#options' => $field_options,
      '#default_value' => $this->options['summary_field'],
      '#description' => $this->t('You may optionally change the SUMMARY component for each event in the iCal output. Choose which text field you would like to be output as the SUMMARY.'),
    );

    $form['location_field'] = array(
      '#type' => 'select',
      '#title' => $this->t('LOCATION field'),
      '#options' => $field_options,
      '#default_value' => $this->options['location_field'],
      '#description' => $this->t('You may optionally include a LOCATION component for each event in the iCal output. Choose which text field you would like to be output as the LOCATION.'),
    );

    $form['description_field'] = array(
      '#type' => 'select',
      '#title' => $this->t('DESCRIPTION field'),
      '#options' => $field_options,
      '#default_value' => $this->options['description_field'],
      '#description' => $this->t('You may optionally include a DESCRIPTION component for each event in the iCal output. Choose which text field you would like to be output as the DESCRIPTION.'),
    );
  }

  public function attachTo(array &$build, $display_id, Url $feed_url, $title) {
    $url_options = [];
    $input = $this->view->getExposedInput();
    if ($input) {
      $url_options['query'] = $input;
    }
    $url_options['absolute'] = TRUE;

    $url = $feed_url->setOptions($url_options)->toString();

    $this->view->feedIcons[] = [];

    // Attach a link to the iCal feed, which is an alternate representation.
    $build['#attached']['html_head_link'][][] = [
      'rel' => 'alternate',
      'type' => 'application/calendar',
      'href' => $url,
      'title' => $title,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    if (empty($this->view->rowPlugin)) {
      trigger_error('Drupal\views_ical\Plugin\views\style\Ical: Missing row plugin', E_WARNING);
      return [];
    }
    $date_field_type = $this->getDateFieldType();

    $events = [];
    $timezone = $this->getTimezone();

    foreach ($this->view->result as $row_index => $row) {
      // Use date_recur's API to generate the events.
      // Recursive events will be automatically handled here.
      if ($date_field_type === 'date_recur') {
        $this->helper->addDateRecurEvent($events, $row->_entity, $timezone, $this->options);
      }
      else {
        $this->helper->addEvent($events, $row->_entity, $timezone, $this->options);
      }
    }

    $build = [
      '#theme' => $this->themeFunctions(),
      '#view' => $this->view,
      '#options' => $this->options,
      '#rows' => $events,
    ];
    unset($this->view->row_index);
    return $build;
  }

  /**
   * Get Date field type value.
   *
   * @return string
   *   Date field type.
   */
  protected function getDateFieldType(): string {
    $date_field_name = $this->options['date_field'];
    $view_date_field = $this->view->field[$date_field_name];
    if ($view_date_field instanceof DateRecurDate) {
      return 'date_recur';
    }
    $entity_type = $view_date_field->definition['entity_type'];
    /** @var \Drupal\Core\Field\FieldDefinitionInterface[] $field_storage_definitions */
    $field_storage_definitions = $this->entityFieldManager->getFieldStorageDefinitions($entity_type);
    $date_field_definition = $field_storage_definitions[$date_field_name];
    /** @var string $date_field_type */
    return $date_field_definition->getType();
  }

  /**
   * @return \DateTimeZone
   */
  protected function getTimezone(): \DateTimeZone {
    $user_timezone = \drupal_get_user_timezone();
    $view_field = $this->view->field[$this->options['date_field']];
    $timezone = new \DateTimeZone($user_timezone);
    if (empty($view_field->options['settings']['timezone_override'])) {
      return $timezone;
    }

    // Make sure the events are made as per the configuration in view.
    /** @var string $timezone_override */
    $timezone_override = $view_field->options['settings']['timezone_override'];
    if ($timezone_override) {
      $timezone = new \DateTimeZone($timezone_override);
    }
    return $timezone;
  }

}
