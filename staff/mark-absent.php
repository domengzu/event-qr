<?php
// Initialize session if called from web interface
if (php_sapi_name() !== 'cli') {
   session_start();

   // Check if user is logged in when accessed via web
   if (!isset($_SESSION['staff_id'])) {
      header("Location: login.php");
      exit;
   }
}

// Include database connection
require_once '../config/database.php';

// Get database connection
$conn = getDBConnection();

// Get specific event ID if provided
$event_id = isset($_GET['event_id']) ? intval($_GET['event_id']) : 0;

// Prepare the query to find events that have ended
$currentDateTime = date('Y-m-d H:i:s');

$eventQuery = "SELECT e.event_id, e.event_name, e.event_date, e.start_time, e.end_time, 
                      CONCAT(e.event_date, ' ', e.start_time) as event_start_datetime,
                      CONCAT(e.event_date, ' ', e.end_time) as event_end_datetime
               FROM events e 
               WHERE CONCAT(e.event_date, ' ', e.end_time) <= ?";

// If specific event is requested, add condition
if ($event_id > 0) {
   $eventQuery .= " AND e.event_id = ?";
   $stmt = $conn->prepare($eventQuery);
   $stmt->bind_param("si", $currentDateTime, $event_id);
} else {
   // Only process events that ended in the last 24 hours for automatic runs
   if (php_sapi_name() === 'cli') {
      $eventQuery .= " AND CONCAT(e.event_date, ' ', e.end_time) >= DATE_SUB(?, INTERVAL 24 HOUR)";
      $stmt = $conn->prepare($eventQuery);
      $stmt->bind_param("ss", $currentDateTime, $currentDateTime);
   } else {
      $stmt = $conn->prepare($eventQuery);
      $stmt->bind_param("s", $currentDateTime);
   }
}

$stmt->execute();
$result = $stmt->get_result();

$markedCount = 0;
$processedEvents = [];
$graceTimeMinutes = 15; // Grace period in minutes for late arrivals

while ($event = $result->fetch_assoc()) {
   // Calculate accurate event start and end times
   $eventStartDateTime = $event['event_start_datetime'];
   $eventEndDateTime = $event['event_end_datetime'];

   // Calculate grace period for late arrival
   $eventStartWithGrace = date('Y-m-d H:i:s', strtotime("$eventStartDateTime + $graceTimeMinutes minutes"));

   error_log("Processing event: {$event['event_name']} (ID: {$event['event_id']})");
   error_log("Event start: $eventStartDateTime, Event end: $eventEndDateTime");

   // For each past event, find students who are registered but don't have attendance records
   // These students never checked in during the event time frame
   $query = "INSERT INTO attendance (student_id, event_id, status, check_in_time)
             SELECT er.student_id, er.event_id, 'absent', NOW()
             FROM event_registrations er
             LEFT JOIN attendance a ON er.student_id = a.student_id AND a.event_id = er.event_id
             WHERE er.event_id = ? AND a.attendance_id IS NULL";

   $insertStmt = $conn->prepare($query);
   $insertStmt->bind_param("i", $event['event_id']);
   $insertStmt->execute();
   $absentCount = $insertStmt->affected_rows;
   $markedCount += $absentCount;

   // Find students who checked in late (after grace period)
   // and update their status if not already marked as late
   $lateQuery = "UPDATE attendance
                SET status = 'late'
                WHERE event_id = ?
                  AND check_in_time > ?
                  AND check_in_time <= ?
                  AND (status = 'present' OR status IS NULL)";

   $lateStmt = $conn->prepare($lateQuery);
   $lateStmt->bind_param("iss", $event['event_id'], $eventStartWithGrace, $eventEndDateTime);
   $lateStmt->execute();
   $lateCount = $lateStmt->affected_rows;

   // Count students who checked in but never checked out
   // and mark them as "left early" if needed (but only if they were supposed to check out during event hours)
   $noCheckoutQuery = "UPDATE attendance
                     SET status = 'left early'
                     WHERE event_id = ?
                       AND check_in_time IS NOT NULL
                       AND check_out_time IS NULL
                       AND status = 'present'";

   $noCheckoutStmt = $conn->prepare($noCheckoutQuery);
   $noCheckoutStmt->bind_param("i", $event['event_id']);
   $noCheckoutStmt->execute();
   $earlyCount = $noCheckoutStmt->affected_rows;

   // Find partial attendances - only if they checked out before event end time
   // (less than half the event duration) and mark them as partial
   $eventDurationSeconds = strtotime($eventEndDateTime) - strtotime($eventStartDateTime);
   $halfEventDuration = $eventDurationSeconds / 2;

   $partialAttendanceQuery = "UPDATE attendance
                             SET status = 'partial'
                             WHERE event_id = ?
                               AND (
                                   /* Only check-ins after event start + half duration */
                                   (check_in_time > ? AND TIMESTAMPDIFF(SECOND, check_in_time, ?) < ?)
                                   OR 
                                   /* Only check-outs before event end AND less than half attendance */
                                   (check_out_time IS NOT NULL 
                                    AND check_out_time < ? 
                                    AND TIMESTAMPDIFF(SECOND, ?, check_out_time) < ?)
                               )
                               AND status = 'present'";

   $partialStmt = $conn->prepare($partialAttendanceQuery);
   $partialStmt->bind_param(
      "issisis",
      $event['event_id'],
      $eventStartDateTime,
      $eventEndDateTime,
      $halfEventDuration,
      $eventEndDateTime,
      $eventStartDateTime,
      $halfEventDuration
   );
   $partialStmt->execute();
   $partialCount = $partialStmt->affected_rows;

   // Log the operation
   $processedEvents[] = [
      'id' => $event['event_id'],
      'name' => $event['event_name'],
      'date' => $event['event_date'],
      'start_time' => $event['start_time'],
      'end_time' => $event['end_time'],
      'marked_absent' => $absentCount,
      'marked_late' => $lateCount,
      'marked_early' => $earlyCount,
      'marked_partial' => $partialCount
   ];

   // Log detailed information
   error_log("Event {$event['event_name']} (ID: {$event['event_id']}) - " .
      "Marked {$absentCount} as absent, {$lateCount} as late, " .
      "{$earlyCount} as left early, {$partialCount} as partial attendance");
}

// For CLI execution (cron job)
if (php_sapi_name() === 'cli') {
   if ($markedCount > 0 || count($processedEvents) > 0) {
      echo "Success: Processed " . count($processedEvents) . " events.\n";
      foreach ($processedEvents as $event) {
         echo "- Event {$event['name']} ({$event['date']} {$event['start_time']}-{$event['end_time']}):\n";
         echo "  * {$event['marked_absent']} students marked absent\n";
         echo "  * {$event['marked_late']} students marked late\n";
         echo "  * {$event['marked_early']} students marked as left early\n";
         echo "  * {$event['marked_partial']} students marked as partial attendance\n";
      }
   } else {
      echo "No students needed to be marked as absent, late, or left early.\n";
   }
} else {
   // For web interface
   $totalChanged = $markedCount;
   foreach ($processedEvents as $event) {
      $totalChanged += ($event['marked_late'] + $event['marked_early'] + $event['marked_partial']);
   }

   $message = "Processed attendance for " . count($processedEvents) . " events. Total records updated: " . $totalChanged;

   if ($event_id > 0) {
      header("Location: attendance.php?event_id={$event_id}&auto_marked={$totalChanged}&message=" . urlencode($message));
   } else {
      header("Location: attendance.php?auto_marked={$totalChanged}&message=" . urlencode($message));
   }
   exit;
}
