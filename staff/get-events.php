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

// Parse the start and end parameters
$start = isset($_GET['start']) ? $_GET['start'] : date('Y-m-d', strtotime('-1 month'));
$end = isset($_GET['end']) ? $_GET['end'] : date('Y-m-d', strtotime('+1 month'));

// Prepare and execute the query
$query = "SELECT 
            event_id,
            event_name as title,
            CONCAT(event_date, 'T', start_time) as start,
            CONCAT(event_date, 'T', end_time) as end,
            location as description,
            CASE 
              WHEN CONCAT(event_date, ' ', start_time) > NOW() THEN '#0d6efd' -- Upcoming: Blue
              WHEN CONCAT(event_date, ' ', end_time) >= NOW() AND CONCAT(event_date, ' ', start_time) <= NOW() THEN '#198754' -- Ongoing: Green
              ELSE '#6c757d' -- Past: Gray
            END as backgroundColor
          FROM events
          WHERE event_date BETWEEN ? AND ?
          ORDER BY event_date ASC, start_time ASC";

$stmt = $conn->prepare($query);
$stmt->bind_param("ss", $start, $end);
$stmt->execute();
$result = $stmt->get_result();

// Format the events data
$events = [];
while ($row = $result->fetch_assoc()) {
   $events[] = $row;
}

// Set appropriate headers
header('Content-Type: application/json');

// Return the events as JSON
echo json_encode($events);
