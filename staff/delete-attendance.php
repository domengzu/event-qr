<?php
// Initialize session
session_start();

// Check if user is logged in
if (!isset($_SESSION['staff_id'])) {
   header("Location: login.php");
   exit;
}

// Include required files
require_once '../config/database.php';

// Check if attendance ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
   header("Location: attendance.php?error=" . urlencode("Invalid attendance record ID"));
   exit;
}

$attendanceId = (int)$_GET['id'];
$staffId = $_SESSION['staff_id'];

// Get database connection
$conn = getDBConnection();

try {
   // First, get the attendance details for logging purposes
   $detailsSql = "SELECT a.*, s.first_name, s.last_name, e.event_name 
                  FROM attendance a 
                  JOIN students s ON a.student_id = s.student_id 
                  JOIN events e ON a.event_id = e.event_id 
                  WHERE a.attendance_id = ?";
   $stmt = $conn->prepare($detailsSql);
   $stmt->bind_param("i", $attendanceId);
   $stmt->execute();
   $result = $stmt->get_result();

   if ($result->num_rows === 0) {
      throw new Exception("Attendance record not found");
   }

   $record = $result->fetch_assoc();
   $studentName = $record['first_name'] . ' ' . $record['last_name'];
   $eventName = $record['event_name'];

   // Now delete the attendance record
   $deleteSql = "DELETE FROM attendance WHERE attendance_id = ?";
   $stmt = $conn->prepare($deleteSql);
   $stmt->bind_param("i", $attendanceId);

   if (!$stmt->execute()) {
      throw new Exception("Failed to delete attendance record: " . $conn->error);
   }

   if ($stmt->affected_rows === 0) {
      throw new Exception("No attendance record was deleted");
   }

   // Log the deletion
   $logSql = "INSERT INTO activity_log (staff_id, action_type, action_details, target_id, target_type) 
              VALUES (?, 'delete', ?, ?, 'attendance')";
   $logDetails = "Deleted attendance record for $studentName at event '$eventName'";

   // Only log if the activity_log table exists (it's not critical)
   try {
      $stmt = $conn->prepare($logSql);
      $stmt->bind_param("iss", $staffId, $logDetails, $attendanceId);
      $stmt->execute();
   } catch (Exception $e) {
      // Silently ignore logging errors
   }

   // Redirect with success message
   header("Location: attendance.php?deleted=1");
   exit;
} catch (Exception $e) {
   // Redirect with error message
   header("Location: attendance.php?error=" . urlencode($e->getMessage()));
   exit;
}
