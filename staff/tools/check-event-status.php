<?php
// Initialize session
session_start();

// Check if user is logged in
if (!isset($_SESSION['staff_id'])) {
   header("Location: ../login.php");
   exit;
}

// Include required files
require_once '../../config/database.php';

// Get database connection
$conn = getDBConnection();

// Ensure proper timezone
date_default_timezone_set('Asia/Manila');

// Get all events
$events = [];
$sql = "SELECT * FROM events ORDER BY event_date DESC, end_time DESC LIMIT 25";
$result = $conn->query($sql);

if ($result) {
   while ($row = $result->fetch_assoc()) {
      $events[] = $row;
   }
}

// Get current server time
$serverTime = date('Y-m-d H:i:s');
$serverTimeStamp = strtotime($serverTime);
?>

<!DOCTYPE html>
<html lang="en">

<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Event Status Checker - Admin Tool</title>
   <link href="../../bootstrap/css/bootstrap.min.css" rel="stylesheet">
   <link rel="stylesheet" href="../../assets/main.css">
   <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
   <style>
      .event-time-debug {
         font-family: monospace;
         background-color: #f8f9fa;
         padding: 8px;
         margin-top: 8px;
         border-radius: 4px;
         font-size: 14px;
      }

      .timestamp {
         font-weight: bold;
      }
   </style>
</head>

<body>
   <div class="container mt-5 mb-5">
      <div class="d-flex justify-content-between align-items-center mb-4">
         <h1>Event Status Debug Tool</h1>
         <a href="../dashboard.php" class="btn btn-outline-primary">
            <i class="bi bi-arrow-left me-2"></i>Return to Dashboard
         </a>
      </div>

      <div class="alert alert-info">
         <h5><i class="bi bi-info-circle me-2"></i>Time Information</h5>
         <p><strong>Current server time:</strong> <?= $serverTime ?> (Unix timestamp: <?= $serverTimeStamp ?>)</p>
         <p><strong>Timezone setting:</strong> <?= date_default_timezone_get() ?></p>
         <p><strong>PHP version:</strong> <?= phpversion() ?></p>
      </div>

      <div class="card">
         <div class="card-header bg-primary text-white">
            <h5 class="mb-0">Event Status Check</h5>
         </div>
         <div class="card-body p-0">
            <div class="table-responsive">
               <table class="table table-bordered table-hover mb-0">
                  <thead class="table-light">
                     <tr>
                        <th>ID</th>
                        <th>Event Name</th>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Completed?</th>
                        <th>Debug Info</th>
                     </tr>
                  </thead>
                  <tbody>
                     <?php foreach ($events as $event): ?>
                        <?php
                        $eventDateTime = $event['event_date'] . ' ' . $event['end_time'];
                        $eventTimestamp = strtotime($eventDateTime);
                        $isCompleted = ($serverTimeStamp > $eventTimestamp);
                        ?>
                        <tr>
                           <td><?= $event['event_id'] ?></td>
                           <td><strong><?= htmlspecialchars($event['event_name']) ?></strong></td>
                           <td><?= $event['event_date'] ?></td>
                           <td><?= $event['start_time'] ?> - <?= $event['end_time'] ?></td>
                           <td>
                              <?php if ($isCompleted): ?>
                                 <span class="badge bg-secondary"><i class="bi bi-check-circle me-1"></i>Yes</span>
                              <?php else: ?>
                                 <span class="badge bg-primary"><i class="bi bi-clock me-1"></i>No</span>
                              <?php endif; ?>
                           </td>
                           <td>
                              <div class="event-time-debug">
                                 Event end time: <span class="timestamp"><?= $eventDateTime ?></span><br>
                                 Unix timestamp: <span class="timestamp"><?= $eventTimestamp ?></span><br>
                                 Comparison: <?= $serverTimeStamp ?> <?= $serverTimeStamp > $eventTimestamp ? '>' : '≤' ?> <?= $eventTimestamp ?>
                              </div>
                           </td>
                        </tr>
                     <?php endforeach; ?>
                  </tbody>
               </table>
            </div>
         </div>
      </div>

      <div class="mt-3 card">
         <div class="card-body">
            <h5>How This Works</h5>
            <p>This tool helps diagnose event status issues by:</p>
            <ol>
               <li>Setting the timezone explicitly to Asia/Manila (PHT)</li>
               <li>Converting dates and times to Unix timestamps for reliable comparison</li>
               <li>Showing detailed debug information for each event</li>
            </ol>
            <p>If events still aren't showing as completed when they should be, check:</p>
            <ul>
               <li>Whether your server's system time is correct</li>
               <li>If there are any timezone settings in php.ini that might be overriding our settings</li>
            </ul>
         </div>
      </div>
   </div>

   <script src="../../bootstrap/js/bootstrap.bundle.min.js"></script>
</body>

</html>