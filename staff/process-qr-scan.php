<?php
// Initialize session
session_start();

// Check if user is logged in
if (!isset($_SESSION['staff_id'])) {
   header('Content-Type: application/json');
   echo json_encode(['success' => false, 'message' => 'Session expired. Please login again.']);
   exit;
}

// Enable error logging for debugging
error_log("QR Scan request started at " . date('Y-m-d H:i:s'));

// Include required files
require_once '../config/database.php';

// Get database connection
$conn = getDBConnection();

// Get JSON data from request
$jsonData = file_get_contents('php://input');
$data = json_decode($jsonData, true);

// Log the raw input for debugging
error_log("Received data: " . $jsonData);

// Check if required data is present
if (!isset($data['qr_code']) || !isset($data['event_id'])) {
   header('Content-Type: application/json');
   echo json_encode(['success' => false, 'message' => 'Missing required data.']);
   $conn->close();
   exit;
}

$qrCode = trim($data['qr_code']); // Clean whitespace
$eventId = (int)$data['event_id'];
$mode = isset($data['mode']) ? $data['mode'] : 'check_in';

// Log the processing attempt
error_log("Processing QR code: '$qrCode' for event: $eventId in mode: $mode");

// ===== CRITICAL: Start with a clean connection and statements =====
// Get event details first
$eventSql = "SELECT event_id, event_name, event_date, start_time, end_time, location,
            CONCAT(event_date, ' ', start_time) as event_start_datetime,
            CONCAT(event_date, ' ', end_time) as event_end_datetime
            FROM events WHERE event_id = ?";
$eventStmt = $conn->prepare($eventSql);
$eventStmt->bind_param("i", $eventId);
$eventStmt->execute();
$eventResult = $eventStmt->get_result();

if ($eventResult->num_rows === 0) {
   header('Content-Type: application/json');
   echo json_encode(['success' => false, 'message' => 'Event not found.']);
   $eventStmt->close();
   $conn->close();
   exit;
}

$event = $eventResult->fetch_assoc();
$eventStmt->close();
error_log("Found event: {$event['event_name']}");

// Check if we're attempting check-in after event has ended or before it has started
if ($mode === 'check_in') {
   // Ensure proper timezone
   date_default_timezone_set('Asia/Manila');

   // Get current date and time
   $currentDateTime = date('Y-m-d H:i:s');

   // Get event start and end datetime (combining date and time)
   $eventStartDateTime = $event['event_date'] . ' ' . $event['start_time'];
   $eventEndDateTime = $event['event_date'] . ' ' . $event['end_time'];

   // Log dates for debugging
   error_log("Current time: $currentDateTime");
   error_log("Event start: $eventStartDateTime, Event end: $eventEndDateTime");

   // Check if the event has already ended
   if ($currentDateTime > $eventEndDateTime) {
      header('Content-Type: application/json');
      echo json_encode([
         'success' => false,
         'message' => 'Event has ended on ' . date('F j, Y', strtotime($event['event_date'])) .
            ' at ' . date('g:i A', strtotime($event['end_time'])) . '. Check-in is no longer available.',
         'event' => $event,
         'event_ended' => true
      ]);
      $conn->close();
      exit;
   }

   // Check if the event hasn't started yet
   if ($currentDateTime < $eventStartDateTime) {
      header('Content-Type: application/json');
      echo json_encode([
         'success' => false,
         'message' => 'Event has not started yet. Check-in will be available on ' .
            date('F j, Y', strtotime($event['event_date'])) .
            ' at ' . date('g:i A', strtotime($event['start_time'])) . '.',
         'event' => $event,
         'event_not_started' => true
      ]);
      $conn->close();
      exit;
   }
}

// ===== IMPORTANT: Student identification section =====
// This is where the mixing of student data was likely happening
// We need to maintain separate statements for each query

// Try direct match with student ID first (most common case)
$studentSql = "SELECT student_id, first_name, last_name, course, year_level 
              FROM students WHERE student_id = ?";
$studentStmt = $conn->prepare($studentSql);
$studentStmt->bind_param("s", $qrCode);
$studentStmt->execute();
$studentResult = $studentStmt->get_result();
$studentFound = false;
$student = null;

if ($studentResult->num_rows > 0) {
   $student = $studentResult->fetch_assoc();
   $studentFound = true;
   error_log("Found student by direct ID match: {$student['student_id']} - {$student['first_name']} {$student['last_name']}");
}
$studentStmt->close();

// If not found, try JSON parsing (assuming QR might contain JSON data)
if (!$studentFound) {
   $jsonData = @json_decode($qrCode, true);
   if (is_array($jsonData) && isset($jsonData['student_id'])) {
      $jsonStudentId = $jsonData['student_id'];
      error_log("Trying JSON extracted ID: $jsonStudentId");

      $studentSql = "SELECT student_id, first_name, last_name, course, year_level 
                      FROM students WHERE student_id = ?";
      $studentStmt = $conn->prepare($studentSql);
      $studentStmt->bind_param("s", $jsonStudentId);
      $studentStmt->execute();
      $studentResult = $studentStmt->get_result();

      if ($studentResult->num_rows > 0) {
         $student = $studentResult->fetch_assoc();
         $studentFound = true;
         error_log("Found student through JSON parsing: {$student['student_id']}");
      }
      $studentStmt->close();
   }
}

// Last resort: try to extract a student ID from the QR content
if (!$studentFound) {
   error_log("Student not found directly, trying pattern extraction from: $qrCode");

   // Check for QR_STUDENTID_HASH format (e.g. QR_2025-22293_683878545b8d1)
   if (preg_match('/QR_([0-9\-]+)_[a-z0-9]+/i', $qrCode, $matches)) {
      $extractedId = $matches[1]; // This will capture the student ID part (e.g. 2025-22293)
      error_log("Extracted student ID from QR code format: $extractedId");

      // Try exact match with the extracted ID
      $studentSql = "SELECT student_id, first_name, last_name, course, year_level 
                    FROM students WHERE student_id = ?";
      $studentStmt = $conn->prepare($studentSql);
      $studentStmt->bind_param("s", $extractedId);
      $studentStmt->execute();
      $studentResult = $studentStmt->get_result();

      if ($studentResult->num_rows > 0) {
         $student = $studentResult->fetch_assoc();
         $studentFound = true;
         error_log("Found student by extracted ID from QR format: {$student['student_id']} - {$student['first_name']} {$student['last_name']}");
      }
      $studentStmt->close();

      // If not found with exact match, try with LIKE
      if (!$studentFound) {
         $likePattern = "%$extractedId%";
         $studentSql = "SELECT student_id, first_name, last_name, course, year_level 
                       FROM students WHERE student_id LIKE ?";
         $studentStmt = $conn->prepare($studentSql);
         $studentStmt->bind_param("s", $likePattern);
         $studentStmt->execute();
         $studentResult = $studentStmt->get_result();

         if ($studentResult->num_rows > 0) {
            $student = $studentResult->fetch_assoc();
            $studentFound = true;
            error_log("Found student by LIKE search with extracted ID: {$student['student_id']}");
         }
         $studentStmt->close();
      }
   }
   // Try other extraction patterns if the specific format didn't match
   else if (preg_match('/\b(\d{4}[-]?\d{4,5})\b|\b(\d{4}[-]?\d{4,5}[-]?\d{4})\b/', $qrCode, $matches)) {
      $extractedId = $matches[0];
      error_log("Extracted possible student ID: $extractedId");

      $studentSql = "SELECT student_id, first_name, last_name, course, year_level 
                      FROM students WHERE student_id = ? OR student_id LIKE ?";
      $studentStmt = $conn->prepare($studentSql);
      $likePattern = "%$extractedId%";
      $studentStmt->bind_param("ss", $extractedId, $likePattern);
      $studentStmt->execute();
      $studentResult = $studentStmt->get_result();

      if ($studentResult->num_rows > 0) {
         $student = $studentResult->fetch_assoc();
         $studentFound = true;
         error_log("Found student by extracted ID: {$student['student_id']}");
      }
      $studentStmt->close();
   }
}

// If student not found, return detailed error
if (!$studentFound) {
   header('Content-Type: application/json');
   echo json_encode([
      'success' => false,
      'message' => 'Student not found. QR code: ' . htmlspecialchars($qrCode),
      'qr_code_value' => $qrCode,
      'event' => $event
   ]);
   error_log("FAILURE: Student not found for QR code: $qrCode");
   $conn->close();
   exit;
}

// At this point we have a valid student
// Check for existing attendance records with a FRESH statement
$attendanceSql = "SELECT attendance_id, check_in_time, check_out_time, status 
                 FROM attendance 
                 WHERE student_id = ? AND event_id = ?";
$attendanceStmt = $conn->prepare($attendanceSql);
$attendanceStmt->bind_param("si", $student['student_id'], $eventId);
$attendanceStmt->execute();
$attendanceResult = $attendanceStmt->get_result();

// Handle existing attendance records
if ($attendanceResult->num_rows > 0) {
   $attendanceRecord = $attendanceResult->fetch_assoc();
   $attendanceStmt->close();

   error_log("Found existing attendance for student {$student['student_id']} at event $eventId");

   // Check-in mode but already checked in
   if ($mode === 'check_in') {
      header('Content-Type: application/json');
      echo json_encode([
         'success' => false,
         'message' => 'Student already checked in at ' . date('g:i A', strtotime($attendanceRecord['check_in_time'])),
         'student' => $student,
         'event' => $event,
         'attendance' => $attendanceRecord,
         'can_checkout' => ($attendanceRecord['check_out_time'] === null),
      ]);
      $conn->close();
      exit;
   }
   // Check-out mode but already checked out
   else if ($mode === 'check_out' && $attendanceRecord['check_out_time'] !== null) {
      header('Content-Type: application/json');
      echo json_encode([
         'success' => false,
         'message' => 'Student already checked out at ' . date('g:i A', strtotime($attendanceRecord['check_out_time'])),
         'student' => $student,
         'event' => $event,
         'attendance' => $attendanceRecord
      ]);
      $conn->close();
      exit;
   }
   // Check-out mode and not yet checked out
   else if ($mode === 'check_out' && $attendanceRecord['check_out_time'] === null) {
      header('Content-Type: application/json');
      echo json_encode([
         'success' => true,
         'message' => 'Ready for check-out',
         'student' => $student,
         'event' => $event,
         'attendance' => $attendanceRecord,
         'checkout_ready' => true
      ]);
      $conn->close();
      exit;
   }
} else {
   $attendanceStmt->close();
}

// Check if student is registered for this event with a FRESH statement
$registrationSql = "SELECT * FROM event_registrations 
                   WHERE student_id = ? AND event_id = ?";
$registrationStmt = $conn->prepare($registrationSql);
$registrationStmt->bind_param("si", $student['student_id'], $eventId);
$registrationStmt->execute();
$registrationResult = $registrationStmt->get_result();
$isRegistered = ($registrationResult->num_rows > 0);
$registrationStmt->close();

// If not registered, check other events they might be registered for
if (!$isRegistered) {
   $otherEventsSql = "SELECT e.event_id, e.event_name, e.event_date 
                      FROM event_registrations er 
                      JOIN events e ON er.event_id = e.event_id 
                      WHERE er.student_id = ? AND er.event_id != ?";
   $otherEventsStmt = $conn->prepare($otherEventsSql);
   $otherEventsStmt->bind_param("si", $student['student_id'], $eventId);
   $otherEventsStmt->execute();
   $otherEventsResult = $otherEventsStmt->get_result();

   $otherEvents = [];
   while ($row = $otherEventsResult->fetch_assoc()) {
      $otherEvents[] = $row;
   }
   $otherEventsStmt->close();

   header('Content-Type: application/json');
   echo json_encode([
      'success' => false,
      'needs_registration' => true,
      'message' => 'Student is not registered for this event.',
      'student' => $student,
      'event' => $event,
      'registered_events' => $otherEvents
   ]);
   $conn->close();
   exit;
}

// All validation passed - student is ready for check-in
error_log("SUCCESS: Student {$student['student_id']} validated for event {$event['event_name']}");
header('Content-Type: application/json');
echo json_encode([
   'success' => true,
   'message' => 'Student validated successfully.',
   'student' => $student,
   'event' => $event
]);

// Close connection
$conn->close();
exit;
