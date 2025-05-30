<?php
// Set timezone to Philippine Time
date_default_timezone_set('Asia/Manila');

// Initialize session
session_start();

// Check if user is logged in
if (!isset($_SESSION['staff_id'])) {
   header('HTTP/1.1 401 Unauthorized');
   echo json_encode(['error' => 'Unauthorized access']);
   exit;
}

// Include required files
require_once '../config/database.php';

// Get database connection
$conn = getDBConnection();

// Get event ID
$eventId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($eventId <= 0) {
   header('HTTP/1.1 400 Bad Request');
   echo json_encode(['error' => 'Invalid event ID']);
   exit;
}

// Prepare and execute the query
$query = "SELECT 
            e.*,
            (SELECT COUNT(*) FROM event_registrations WHERE event_id = e.event_id) as registrations,
            (SELECT COUNT(*) FROM attendance WHERE event_id = e.event_id AND status = 'present') as attendance
          FROM events e
          WHERE e.event_id = ?";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $eventId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
   header('HTTP/1.1 404 Not Found');
   echo json_encode(['error' => 'Event not found']);
   exit;
}

// Get the event data
$event = $result->fetch_assoc();

// Determine event status
$currentDateTime = date('Y-m-d H:i:s');
$startDateTime = $event['event_date'] . ' ' . $event['start_time'];
$endDateTime = $event['event_date'] . ' ' . $event['end_time'];

if ($currentDateTime < $startDateTime) {
   $event['status'] = 'upcoming';
} elseif ($currentDateTime <= $endDateTime) {
   $event['status'] = 'ongoing';
} else {
   $event['status'] = 'ended';
}

// Format dates for display
$event['formatted_date'] = date('l, F d, Y', strtotime($event['event_date']));
$event['formatted_time'] = date('g:i A', strtotime($event['start_time'])) . ' - ' .
   date('g:i A', strtotime($event['end_time']));

// Set appropriate headers
header('Content-Type: application/json');

// Return the event as JSON
echo json_encode($event);
