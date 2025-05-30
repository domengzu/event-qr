<?php

/**
 * Event utility functions for EventQR system
 */

/**
 * Check if an event has ended (including grace period)
 * 
 * @param array|object $event Event data with event_date and end_time
 * @param int $graceMinutes Number of minutes to allow after end time (default: 1)
 * @return bool True if event has ended, false if still active
 */
function hasEventEnded($event, $graceMinutes = 1)
{
   // Check if we have the required data
   if (!isset($event['event_date']) || !isset($event['end_time'])) {
      return false; // Can't determine if ended without date and time
   }

   // Get current time
   $currentDateTime = new DateTime();

   // Create DateTime for event end time plus grace period
   $eventEndTime = new DateTime($event['event_date'] . ' ' . $event['end_time']);
   $eventEndTime->add(new DateInterval("PT{$graceMinutes}M")); // Add grace period

   // Compare times
   return $currentDateTime > $eventEndTime;
}

/**
 * Check if an event has started
 * 
 * @param array|object $event Event data with event_date and start_time
 * @return bool True if event has started, false if not yet started
 */
function hasEventStarted($event)
{
   // Check if we have the required data
   if (!isset($event['event_date']) || !isset($event['start_time'])) {
      return false; // Can't determine if started without date and time
   }

   // Get current time
   $currentDateTime = new DateTime();

   // Create DateTime for event start time
   $eventStartTime = new DateTime($event['event_date'] . ' ' . $event['start_time']);

   // Compare times
   return $currentDateTime >= $eventStartTime;
}

/**
 * Get remaining time for an event in minutes
 * 
 * @param array|object $event Event data with event_date and end_time
 * @return int|null Minutes remaining until event ends, negative if already ended, null if invalid data
 */
function getEventRemainingMinutes($event)
{
   // Check if we have the required data
   if (!isset($event['event_date']) || !isset($event['end_time'])) {
      return null; // Can't calculate without date and time
   }

   // Get current time
   $currentDateTime = new DateTime();

   // Create DateTime for event end time
   $eventEndTime = new DateTime($event['event_date'] . ' ' . $event['end_time']);

   // Calculate difference in minutes
   $interval = $currentDateTime->diff($eventEndTime);
   $minutes = ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;

   // If current time is after event end, return negative minutes
   if ($currentDateTime > $eventEndTime) {
      $minutes = -$minutes;
   }

   return $minutes;
}
