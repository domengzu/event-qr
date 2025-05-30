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

// Check if event ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
   header("Location: events.php");
   exit;
}

$eventId = (int)$_GET['id'];
$conn = getDBConnection();

try {
   // Begin transaction
   $conn->begin_transaction();

   // First, delete related records (attendance, registrations)
   $deleteAttendanceSql = "DELETE FROM attendance WHERE event_id = ?";
   $stmt = $conn->prepare($deleteAttendanceSql);
   $stmt->bind_param("i", $eventId);
   $stmt->execute();

   $deleteRegistrationsSql = "DELETE FROM event_registrations WHERE event_id = ?";
   $stmt = $conn->prepare($deleteRegistrationsSql);
   $stmt->bind_param("i", $eventId);
   $stmt->execute();

   // Then, delete the event itself
   $deleteEventSql = "DELETE FROM events WHERE event_id = ?";
   $stmt = $conn->prepare($deleteEventSql);
   $stmt->bind_param("i", $eventId);
   $stmt->execute();

   // Commit the transaction
   $conn->commit();

   // Redirect with success message
   header("Location: events.php?delete=success");
   exit;
} catch (Exception $e) {
   // Roll back the transaction in case of error
   $conn->rollback();

   // Redirect with error message
   header("Location: events.php?delete_error=" . urlencode($e->getMessage()));
   exit;
}
