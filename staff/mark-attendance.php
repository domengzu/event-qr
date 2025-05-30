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

// Validate status
$validStatuses = ['present', 'absent', 'late', 'left early'];
if (!in_array($status, $validStatuses)) {
   echo json_encode(['success' => false, 'message' => 'Invalid attendance status']);
   exit;
}

// Validate student ID
if (empty($studentId)) {
   echo json_encode(['success' => false, 'message' => 'Invalid student ID']);
   exit;
}

try {
   // Begin transaction
   $conn->begin_transaction();

   // Check if student exists
   $studentSql = "SELECT * FROM students WHERE student_id = ?";
   $stmt = $conn->prepare($studentSql);
   $stmt->bind_param("s", $studentId);
   $stmt->execute();
   $studentResult = $stmt->get_result();

   if ($studentResult->num_rows === 0) {
      throw new Exception('Student not found');
   }

   $student = $studentResult->fetch_assoc();

   // Check if event exists
   $eventSql = "SELECT * FROM events WHERE event_id = ?";
   $stmt = $conn->prepare($eventSql);
   $stmt->bind_param("i", $eventId);
   $stmt->execute();
   $eventResult = $stmt->get_result();

   if ($eventResult->num_rows === 0) {
      throw new Exception('Event not found');
   }

   $event = $eventResult->fetch_assoc();

   // Check if student is registered for this event
   $registrationSql = "SELECT * FROM event_registrations WHERE event_id = ? AND student_id = ?";
   $stmt = $conn->prepare($registrationSql);
   $stmt->bind_param("is", $eventId, $studentId);
   $stmt->execute();
   $registrationResult = $stmt->get_result();

   if ($registrationResult->num_rows === 0) {
      // Register the student for the event automatically
      $registerSql = "INSERT INTO event_registrations (event_id, student_id, registration_timestamp) VALUES (?, ?, NOW())";
      $stmt = $conn->prepare($registerSql);
      $stmt->bind_param("is", $eventId, $studentId);
      $stmt->execute();
   }

   // Check if attendance record already exists
   $checkAttendanceSql = "SELECT * FROM attendance WHERE event_id = ? AND student_id = ?";
   $stmt = $conn->prepare($checkAttendanceSql);
   $stmt->bind_param("is", $eventId, $studentId);
   $stmt->execute();
   $attendanceResult = $stmt->get_result();

   if ($attendanceResult->num_rows > 0) {
      // Update existing attendance record
      $updateSql = "UPDATE attendance SET 
                     status = ?, 
                     check_in_time = NOW() 
                     WHERE event_id = ? AND student_id = ?";
      $stmt = $conn->prepare($updateSql);
      $stmt->bind_param("sis", $status, $eventId, $studentId);
      $stmt->execute();

      $message = "Attendance updated for " . $student['first_name'] . " " . $student['last_name'];
   } else {
      // Create new attendance record
      $insertSql = "INSERT INTO attendance 
                     (event_id, student_id, status, check_in_time) 
                     VALUES (?, ?, ?, NOW())";
      $stmt = $conn->prepare($insertSql);
      $stmt->bind_param("iss", $eventId, $studentId, $status);
      $stmt->execute();

      $message = "Attendance marked for " . $student['first_name'] . " " . $student['last_name'];
   }

   // Commit transaction
   $conn->commit();

   // Return success response
   echo json_encode([
      'success' => true,
      'message' => $message,
      'student' => [
         'student_id' => $student['student_id'],
         'first_name' => $student['first_name'],
         'last_name' => $student['last_name']
      ],
      'status' => $status
   ]);
} catch (Exception $e) {
   // Rollback transaction on error
   $conn->rollback();

   // Return error response
   echo json_encode([
      'success' => false,
      'message' => 'Error: ' . $e->getMessage()
   ]);
}
