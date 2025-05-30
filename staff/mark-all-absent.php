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

// Get database connection
$conn = getDBConnection();

// Check if event_id is provided
if (isset($_GET['event_id']) && !empty($_GET['event_id'])) {
   $eventId = (int)$_GET['event_id'];

   // Get current timestamp
   $timestamp = date('Y-m-d H:i:s');

   // Begin transaction
   $conn->begin_transaction();

   try {
      // Find all students who don't have attendance records for this event
      $findStudentsSql = "SELECT s.student_id FROM students s 
                           WHERE NOT EXISTS (
                               SELECT 1 FROM attendance a 
                               WHERE a.student_id = s.student_id AND a.event_id = ?
                           )";
      $stmt = $conn->prepare($findStudentsSql);
      $stmt->bind_param("i", $eventId);
      $stmt->execute();
      $result = $stmt->get_result();

      $insertCount = 0;

      // Insert absent records for each student
      while ($row = $result->fetch_assoc()) {
         $studentId = $row['student_id'];

         $insertSql = "INSERT INTO attendance (student_id, event_id, status, created_at) 
                         VALUES (?, ?, 'absent', ?)";
         $stmt = $conn->prepare($insertSql);
         $stmt->bind_param("sis", $studentId, $eventId, $timestamp);
         $stmt->execute();
         $insertCount++;
      }

      // Commit transaction
      $conn->commit();

      if ($insertCount > 0) {
         header("Location: attendance.php?tab=attendance&updated=1&message=" . urlencode("Marked $insertCount students as absent"));
      } else {
         header("Location: attendance.php?tab=attendance&message=" . urlencode("No students needed to be marked as absent"));
      }
      exit;
   } catch (Exception $e) {
      // Roll back transaction on error
      $conn->rollback();
      header("Location: attendance.php?tab=unmarked&error=" . urlencode("Error marking students absent: " . $e->getMessage()));
      exit;
   }
} else {
   // If no event specified, redirect back
   header("Location: attendance.php?tab=unmarked&error=" . urlencode("No event specified"));
   exit;
}
