<?php

namespace Drupal\views_ical;

use Drupal\Core\Entity\ContentEntityInterface;
use Eluceo\iCal\Component\Event;

/**
 * Helper methods for views_ical.
 */
final class ViewsIcalHelper implements ViewsIcalHelperInterface {

  /**
   * Creates an event with default data.
   *
   * Event summary, location and description are set as defaults.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to be used for default data.
   * @param array $field_mapping
   *   Views field option and entity field name mapping.
   *   Example:
   *   [
   *     'date_field' => 'field_event_date',
   *     'summary_field' => 'field_event_summary',
   *     'description_field' => 'field_event_description',
   *   ]
   *   End of example.
   *
   * @return \Eluceo\iCal\Component\Event
   *   A new event.
   *
   * @see \Drupal\views_ical\Plugin\views\style\Ical::defineOptions
   */
  protected function createDefaultEvent(ContentEntityInterface $entity, array $field_mapping): Event {
    $event = new Event();

    if (isset($field_mapping['summary_field'])) {
      /** @var \Drupal\Core\Field\FieldItemInterface $summary */
      $summary = $entity->{$field_mapping['summary_field']}->first();
      if (!empty($summary)) {
        $event->setSummary($summary->getValue()['value']);
      }
    }

    if (isset($field_mapping['location_field'])) {
      /** @var \Drupal\Core\Field\FieldItemInterface $location */
      $location = $entity->{$field_mapping['location_field']}->first();
      if (!empty($location)) {
        $event->setLocation($location->getValue()['value']);
      }
    }

    if (isset($field_mapping['description_field'])) {
      /** @var \Drupal\Core\Field\FieldItemInterface $description */
      $description = $entity->{$field_mapping['description_field']}->first();
      if (!empty($description)) {
        $event->setDescription(\strip_tags($description->getValue()['value']));
      }
    }

    $event->setUseTimezone(TRUE);

    return $event;
  }

  /**
   * {@inheritdoc}
   */
  public function addEvent(array &$events, ContentEntityInterface $entity, \DateTimeZone $timezone, array $field_mapping): void {
    $utc_timezone = new \DateTimeZone('UTC');

    foreach ($entity->get($field_mapping['date_field'])->getValue() as $date_entry) {
      $event = $this->createDefaultEvent($entity, $field_mapping);

      $start_datetime = new \DateTime($date_entry['value'], $utc_timezone);
      $start_datetime->setTimezone($timezone);
      $event->setDtStart($start_datetime);

      if (!empty($date_entry['end_value'])) {
        $end_datetime = new \DateTime($date_entry['end_value'], $utc_timezone);
        $end_datetime->setTimezone($timezone);
        $event->setDtEnd($end_datetime);
      }

      $events[] = $event;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function addDateRecurEvent(array &$events, ContentEntityInterface $entity, \DateTimeZone $timezone, array $field_mapping): void {
    /** @var \Drupal\date_recur\Plugin\Field\FieldType\DateRecurItem[] $field_items */
    $field_items = $entity->{$field_mapping['date_field']};

    foreach ($field_items as $index => $item) {
      /** @var \Drupal\date_recur\DateRange[] $occurrences */
      $occurrences = $item->getHelper()->getOccurrences();

      foreach ($occurrences as $occurrence) {
        $event = $this->createDefaultEvent($entity, $field_mapping);

        /** @var \DateTime $start_datetime */
        $start_datetime = $occurrence->getStart();
        $start_datetime->setTimezone($timezone);
        $event->setDtStart($start_datetime);

        /** @var \DateTime $end_datetime */
        $end_datetime = $occurrence->getEnd();
        $end_datetime->setTimezone($timezone);
        $event->setDtEnd($end_datetime);

        $events[] = $event;
      }
    }
  }

}
