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

// Check if student ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
   header("Location: students.php?delete_error=" . urlencode('No student ID provided'));
   exit;
}

$studentId = $_GET['id'];

// Get database connection
$conn = getDBConnection();

try {
   // Start transaction to ensure data consistency
   $conn->begin_transaction();

   // Get student details for logging purposes
   $studentSql = "SELECT first_name, last_name FROM students WHERE student_id = ?";
   $stmt = $conn->prepare($studentSql);
   $stmt->bind_param("s", $studentId);
   $stmt->execute();
   $result = $stmt->get_result();
   $studentInfo = $result->fetch_assoc();

   if (!$studentInfo) {
      // Student not found
      throw new Exception("Student record not found");
   }

   $studentName = $studentInfo['first_name'] . ' ' . $studentInfo['last_name'];

   // 1. Delete attendance records
   $attendanceSql = "DELETE FROM attendance WHERE student_id = ?";
   $stmt = $conn->prepare($attendanceSql);
   $stmt->bind_param("s", $studentId);
   $stmt->execute();

   // 2. Delete event registrations
   $registrationSql = "DELETE FROM event_registrations WHERE student_id = ?";
   $stmt = $conn->prepare($registrationSql);
   $stmt->bind_param("s", $studentId);
   $stmt->execute();

   // 3. Delete the student record
   $deleteSql = "DELETE FROM students WHERE student_id = ?";
   $stmt = $conn->prepare($deleteSql);
   $stmt->bind_param("s", $studentId);
   $stmt->execute();

   // Check if student was actually deleted
   if ($stmt->affected_rows === 0) {
      throw new Exception("Failed to delete student");
   }

   // Log the deletion
   $staffId = $_SESSION['staff_id'];
   $logSql = "INSERT INTO activity_log (staff_id, action_type, action_details, target_id, target_type) 
               VALUES (?, 'delete', ?, ?, 'student')";
   $logDetails = "Deleted student: $studentName (ID: $studentId)";

   // Only insert log if the table exists
   try {
      $stmt = $conn->prepare($logSql);
      $stmt->bind_param("iss", $staffId, $logDetails, $studentId);
      $stmt->execute();
   } catch (Exception $e) {
      // Silently ignore errors with logging - it's not critical
   }

   // Commit transaction
   $conn->commit();

   // Redirect with success message
   header("Location: students.php?delete=success");
   exit;
} catch (Exception $e) {
   // Roll back transaction on error
   $conn->rollback();

   // Log the error
   error_log("Error deleting student: " . $e->getMessage());

   // Redirect with error message
   header("Location: students.php?delete_error=" . urlencode($e->getMessage()));
   exit;
}
