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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['event_id'])) {
   $eventId = $_POST['event_id'];

   // Update all attendance records for this event that don't have a checkout time
   $sql = "UPDATE attendance 
            SET check_out_time = NOW() 
            WHERE event_id = ? AND check_out_time IS NULL";
   $stmt = $conn->prepare($sql);
   $stmt->bind_param("i", $eventId);
   $stmt->execute();

   $affectedRows = $stmt->affected_rows;

   // Get event name for the message
   $eventName = "";
   $sql = "SELECT event_name FROM events WHERE event_id = ?";
   $stmt = $conn->prepare($sql);
   $stmt->bind_param("i", $eventId);
   $stmt->execute();
   $result = $stmt->get_result();
   if ($row = $result->fetch_assoc()) {
      $eventName = $row['event_name'];
   }

   // Redirect back with message
   $message = "Successfully checked out {$affectedRows} students from " . ($eventName ? "'{$eventName}'" : "the event");
   header("Location: manual-checkout.php?message=" . urlencode($message) . "&type=success&event_id=" . $eventId);
   exit;
} else {
   // If accessed directly without POST data
   header("Location: manual-checkout.php");
   exit;
}
