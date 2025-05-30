<?php
// Initialize session
session_start();

// Check if user is logged in
if (!isset($_SESSION['staff_id'])) {
   header('Content-Type: application/json');
   echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
   exit;
}

// Include database connection
require_once '../config/database.php';

// Get database connection
$conn = getDBConnection();

// Process JSON data from POST request
$json = file_get_contents('php://input');
$data = json_decode($json, true);

// Get required parameters
$studentId = isset($data['student_id']) ? $data['student_id'] : '';
$eventId = isset($data['event_id']) ? $data['event_id'] : '';

// Validate parameters
if (empty($studentId) || empty($eventId)) {
   header('Content-Type: application/json');
   echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
   exit;
}

// Ensure student has checked in for this event
$checkSql = "SELECT a.attendance_id, e.end_time, e.event_date 
            FROM attendance a 
            JOIN events e ON a.event_id = e.event_id 
            WHERE a.student_id = ? AND a.event_id = ? AND a.check_in_time IS NOT NULL";

$stmt = $conn->prepare($checkSql);
$stmt->bind_param("si", $studentId, $eventId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
   header('Content-Type: application/json');
   echo json_encode(['success' => false, 'message' => 'No check-in record found for this student']);
   exit;
}

// Get event end time and attendance record
$attendanceData = $result->fetch_assoc();
$eventEndTime = $attendanceData['event_date'] . ' ' . $attendanceData['end_time']; // Event end datetime
$attendanceId = $attendanceData['attendance_id'];

// Record check-out time
date_default_timezone_set('Asia/Manila'); // Set to Philippine timezone
$currentTime = date('Y-m-d H:i:s');

// Check if student is leaving early (before event end time)
$leftEarly = (strtotime($currentTime) < strtotime($eventEndTime));

// Define the status based on checkout time
$status = $leftEarly ? 'left early' : 'present'; // Keep as 'present' if checking out on time

// Update attendance record with check-out time and update status if left early
$updateSql = "UPDATE attendance SET check_out_time = ?, status = ? WHERE attendance_id = ?";
$stmt = $conn->prepare($updateSql);
$stmt->bind_param("ssi", $currentTime, $status, $attendanceId);
$result = $stmt->execute();

if ($result) {
   header('Content-Type: application/json');
   echo json_encode([
      'success' => true,
      'message' => 'Check-out recorded successfully',
      'formatted_time' => date('h:i A', strtotime($currentTime)),
      'left_early' => $leftEarly,
      'check_out_time' => $currentTime
   ]);
} else {
   header('Content-Type: application/json');
   echo json_encode(['success' => false, 'message' => 'Failed to record check-out: ' . $conn->error]);
}

$stmt->close();
$conn->close();
