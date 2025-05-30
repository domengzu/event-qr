<?php
// Set timezone to Philippine Time
date_default_timezone_set('Asia/Manila');

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

// Get user information
$staffId = $_SESSION['staff_id'];
$username = $_SESSION['username'];
$fullName = $_SESSION['full_name'] ?? $username;

// Get current time for comparison
$currentDateTime = date('Y-m-d H:i:s');
$currentDate = date('Y-m-d');

// Get today's and upcoming events
$eventsSql = "SELECT event_id, event_name, event_date, start_time, end_time, location,
              CONCAT(event_date, ' ', start_time) as start_datetime,
              CONCAT(event_date, ' ', end_time) as end_datetime
              FROM events 
              WHERE event_date >= ?
              ORDER BY event_date ASC, start_time ASC
              LIMIT 20";
$stmt = $conn->prepare($eventsSql);
$stmt->bind_param("s", $currentDate);
$stmt->execute();
$result = $stmt->get_result();
$events = [];

while ($row = $result->fetch_assoc()) {
   // Calculate event status
   $startDateTime = $row['start_datetime'];
   $endDateTime = $row['end_datetime'];

   if ($currentDateTime < $startDateTime) {
      $row['status'] = 'upcoming';
      $row['status_text'] = 'Upcoming';
      $row['status_class'] = 'bg-primary';
   } elseif ($currentDateTime >= $startDateTime && $currentDateTime <= $endDateTime) {
      $row['status'] = 'ongoing';
      $row['status_text'] = 'In Progress';
      $row['status_class'] = 'bg-success';
   } else {
      $row['status'] = 'ended';
      $row['status_text'] = 'Ended';
      $row['status_class'] = 'bg-secondary';
   }

   // Format display dates
   $row['formatted_date'] = date('F d, Y', strtotime($row['event_date']));
   $row['formatted_start'] = date('g:i A', strtotime($row['start_time']));
   $row['formatted_end'] = date('g:i A', strtotime($row['end_time']));

   // Calculate time remaining or time since ended
   if ($row['status'] === 'upcoming') {
      $seconds = strtotime($startDateTime) - strtotime($currentDateTime);
      $days = floor($seconds / 86400);
      $hours = floor(($seconds % 86400) / 3600);
      $minutes = floor(($seconds % 3600) / 60);

      if ($days > 0) {
         $row['time_info'] = "Starts in $days day" . ($days > 1 ? 's' : '') . ", $hours hr" . ($hours != 1 ? 's' : '');
      } else {
         $row['time_info'] = "Starts in $hours hr " . ($hours != 1 ? 's' : '') . ", $minutes min" . ($minutes != 1 ? 's' : '');
      }
   } elseif ($row['status'] === 'ongoing') {
      $seconds = strtotime($endDateTime) - strtotime($currentDateTime);
      $hours = floor($seconds / 3600);
      $minutes = floor(($seconds % 3600) / 60);
      $row['time_info'] = "Ends in $hours hr" . ($hours != 1 ? 's' : '') . ", $minutes min" . ($minutes != 1 ? 's' : '');
   } else {
      $seconds = strtotime($currentDateTime) - strtotime($endDateTime);
      $days = floor($seconds / 86400);
      $hours = floor(($seconds % 86400) / 3600);

      if ($days > 0) {
         $row['time_info'] = "Ended $days day" . ($days > 1 ? 's' : '') . " ago";
      } else {
         $row['time_info'] = "Ended $hours hour" . ($hours != 1 ? 's' : '') . " ago";
      }
   }

   $events[] = $row;
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Event Status Checker - EventQR</title>
   <!-- Bootstrap CSS -->
   <link href="../bootstrap/css/bootstrap.min.css" rel="stylesheet">
   <link rel="stylesheet" href="../assets/main.css">
   <!-- Bootstrap Icons -->
   <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
   <style>
      /* Add your standard dashboard styles here */
   </style>
</head>

<body>
   <div class="container mt-5">
      <div class="card shadow">
         <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-calendar-check me-2"></i>Event Status Checker</h5>
            <a href="dashboard.php" class="btn btn-sm btn-outline-light">Back to Dashboard</a>
         </div>
         <div class="card-body">
            <div class="alert alert-info">
               <i class="bi bi-info-circle-fill me-2"></i>
               <strong>Current server time (PHT):</strong> <?= date('F d, Y g:i:s A') ?>
            </div>

            <h5 class="mt-3 mb-3">Event Status & Check-in Availability</h5>

            <?php if (empty($events)): ?>
               <div class="alert alert-warning">
                  No upcoming or recent events found.
               </div>
            <?php else: ?>
               <div class="table-responsive">
                  <table class="table table-hover table-striped">
                     <thead class="table-light">
                        <tr>
                           <th>Event Name</th>
                           <th>Date</th>
                           <th>Time</th>
                           <th>Status</th>
                           <th>Check-in Available</th>
                           <th>Actions</th>
                        </tr>
                     </thead>
                     <tbody>
                        <?php foreach ($events as $event): ?>
                           <tr>
                              <td><strong><?= htmlspecialchars($event['event_name']) ?></strong></td>
                              <td><?= $event['formatted_date'] ?></td>
                              <td><?= $event['formatted_start'] ?> - <?= $event['formatted_end'] ?></td>
                              <td>
                                 <span class="badge <?= $event['status_class'] ?>"><?= $event['status_text'] ?></span>
                                 <div class="small text-muted mt-1"><?= $event['time_info'] ?></div>
                              </td>
                              <td>
                                 <?php if ($event['status'] === 'ended'): ?>
                                    <span class="badge bg-danger">No (Event Ended)</span>
                                 <?php elseif ($event['status'] === 'ongoing'): ?>
                                    <span class="badge bg-success">Yes (Until <?= $event['formatted_end'] ?>)</span>
                                 <?php elseif ($event['status'] === 'upcoming'): ?>
                                    <span class="badge bg-info">Not Yet (Available at <?= $event['formatted_start'] ?>)</span>
                                 <?php endif; ?>
                              </td>
                              <td>
                                 <?php if ($event['status'] !== 'ended'): ?>
                                    <a href="qr-scanner.php?event_id=<?= $event['event_id'] ?>" class="btn btn-sm btn-primary">
                                       <i class="bi bi-qr-code-scan me-1"></i> Scan QR
                                    </a>
                                 <?php else: ?>
                                    <a href="view-event.php?id=<?= $event['event_id'] ?>" class="btn btn-sm btn-secondary">
                                       <i class="bi bi-eye me-1"></i> View Report
                                    </a>
                                 <?php endif; ?>
                              </td>
                           </tr>
                        <?php endforeach; ?>
                     </tbody>
                  </table>
               </div>
            <?php endif; ?>

            <div class="card mt-4">
               <div class="card-header bg-light">
                  <h6 class="mb-0">Check-in Rules</h6>
               </div>
               <div class="card-body">
                  <ul class="mb-0">
                     <li>Students can only check in between the event start time and end time</li>
                     <li>Check-in is automatically enabled at the scheduled event start time</li>
                     <li>Check-in is automatically disabled once the event end time is reached</li>
                     <li>For early or late admissions, use the "Manual Attendance" feature on the QR Scanner page</li>
                  </ul>
               </div>
            </div>
         </div>
      </div>
   </div>
</body>
<!-- Bootstrap JS -->
<script src="../bootstrap/js/bootstrap.bundle.min.js"></script>
</html> 
</body>

</html>