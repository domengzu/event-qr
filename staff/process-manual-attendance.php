<?php
// Initialize session
session_start();

// Check if user is logged in
if (!isset($_SESSION['staff_id'])) {
   http_response_code(401);
   echo json_encode(['success' => false, 'message' => 'Unauthorized']);
   exit;
}

// Include required files
require_once '../config/database.php';

// Get staff information
$staffId = $_SESSION['staff_id'];

// Get database connection
$conn = getDBConnection();

// Get JSON data from POST request
$jsonData = file_get_contents('php://input');
$data = json_decode($jsonData, true);

// Validate input
if (!isset($data['student_id']) || !isset($data['event_id']) || !isset($data['status'])) {
   http_response_code(400);
   echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
   exit;
}

$studentId = trim($data['student_id']);
$eventId = (int)$data['event_id'];
$status = trim($data['status']);

try {
   // Get student information
   $studentSql = "SELECT * FROM students WHERE student_id = ?";
   $stmt = $conn->prepare($studentSql);
   $stmt->bind_param("s", $studentId);
   $stmt->execute();
   $result = $stmt->get_result();

   if ($result->num_rows === 0) {
      echo json_encode(['success' => false, 'message' => 'Student not found']);
      exit;
   }

   $student = $result->fetch_assoc();

   // Get event information
   $eventSql = "SELECT * FROM events WHERE event_id = ?";
   $stmt = $conn->prepare($eventSql);
   $stmt->bind_param("i", $eventId);
   $stmt->execute();
   $result = $stmt->get_result();

   if ($result->num_rows === 0) {
      echo json_encode(['success' => false, 'message' => 'Event not found']);
      exit;
   }

   $event = $result->fetch_assoc();

   // Begin transaction
   $conn->begin_transaction();

   // Check if student is registered
   $regSql = "SELECT * FROM event_registrations WHERE student_id = ? AND event_id = ?";
   $stmt = $conn->prepare($regSql);
   $stmt->bind_param("si", $studentId, $eventId);
   $stmt->execute();
   $result = $stmt->get_result();

   if ($result->num_rows === 0) {
      // Register student
      $registerSql = "INSERT INTO event_registrations (student_id, event_id, registration_timestamp) VALUES (?, ?, NOW())";
      $stmt = $conn->prepare($registerSql);
      $stmt->bind_param("si", $studentId, $eventId);
      $stmt->execute();
   }

   // Check if attendance record exists
   $attendanceSql = "SELECT * FROM attendance WHERE student_id = ? AND event_id = ?";
   $stmt = $conn->prepare($attendanceSql);
   $stmt->bind_param("si", $studentId, $eventId);
   $stmt->execute();
   $result = $stmt->get_result();

   if ($result->num_rows > 0) {
      // Update existing record
      $updateSql = "UPDATE attendance SET status = ?, check_in_time = NOW() WHERE student_id = ? AND event_id = ?";
      $stmt = $conn->prepare($updateSql);
      $stmt->bind_param("ssi", $status, $studentId, $eventId);
      $stmt->execute();
   } else {
      // Create new record
      $insertSql = "INSERT INTO attendance (student_id, event_id, status, check_in_time) VALUES (?, ?, ?, NOW())";
      $stmt = $conn->prepare($insertSql);
      $stmt->bind_param("sis", $studentId, $eventId, $status);
      $stmt->execute();
   }

   // Commit transaction
   $conn->commit();

   // Return success response with student and event info for the history
   echo json_encode([
      'success' => true,
      'message' => 'Attendance recorded for ' . $student['first_name'] . ' ' . $student['last_name'],
      'student' => [
         'student_id' => $student['student_id'],
         'first_name' => $student['first_name'],
         'last_name' => $student['last_name'],
         'course' => $student['course']
      ],
      'event' => [
         'event_id' => $event['event_id'],
         'event_name' => $event['event_name']
      ],
      'status' => $status
   ]);
} catch (Exception $e) {
   // Rollback on error
   if ($conn->inTransaction()) {
      $conn->rollback();
   }

   echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
