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

// Get user information
$staffId = $_SESSION['staff_id'];
$username = $_SESSION['username'];
$fullName = $_SESSION['full_name'] ?? $username;

// Get database connection
$conn = getDBConnection();

// Fetch staff profile picture
$staffSql = "SELECT profile_picture FROM staff WHERE staff_id = ?";
$stmt = $conn->prepare($staffSql);
$stmt->bind_param("i", $staffId);
$stmt->execute();
$result = $stmt->get_result();
$staffData = [];
if ($result && $result->num_rows > 0) {
   $staffData = $result->fetch_assoc();
}

// Get today's events for dropdown
$todayEvents = [];
$currentDate = date('Y-m-d');
$currentTime = date('H:i:s');
$todayEventsSql = "SELECT event_id, event_name, location, start_time, end_time,
                   CASE 
                      WHEN CONCAT(event_date, ' ', start_time) > ? THEN 'upcoming'
                      WHEN CONCAT(event_date, ' ', end_time) < ? THEN 'completed'
                      ELSE 'ongoing'
                   END as event_status
                   FROM events 
                   WHERE event_date = ? 
                   ORDER BY start_time";
$stmt = $conn->prepare($todayEventsSql);
$currentDateTime = $currentDate . ' ' . $currentTime;
$stmt->bind_param("sss", $currentDateTime, $currentDateTime, $currentDate);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
   $todayEvents[] = $row;
}

// Group today's events by status
$ongoingEvents = [];
$upcomingTodayEvents = [];
$completedTodayEvents = [];

foreach ($todayEvents as $event) {
   if ($event['event_status'] === 'ongoing') {
      $ongoingEvents[] = $event;
   } elseif ($event['event_status'] === 'upcoming') {
      $upcomingTodayEvents[] = $event;
   } else {
      $completedTodayEvents[] = $event;
   }
}

// Get upcoming events for dropdown (excluding today)
$upcomingEvents = [];
$upcomingEventsSql = "SELECT event_id, event_name, event_date, location, start_time 
                      FROM events 
                      WHERE event_date > ? 
                      ORDER BY event_date, start_time 
                      LIMIT 10";
$stmt = $conn->prepare($upcomingEventsSql);
$stmt->bind_param("s", $currentDate);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
   $upcomingEvents[] = $row;
}

// Get recently scanned students for this staff member (last 10)
$recentScans = [];
$recentScansSql = "SELECT a.attendance_id, a.student_id, a.event_id, a.check_in_time, a.status,
                   s.first_name, s.last_name, s.course, s.year_level,
                   e.event_name
                   FROM attendance a
                   JOIN students s ON a.student_id = s.student_id
                   JOIN events e ON a.event_id = e.event_id
                   ORDER BY a.check_in_time DESC
                   LIMIT 10";
$stmt = $conn->prepare($recentScansSql);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
   $recentScans[] = $row;
}

// Check for result message (passed via URL parameters)
$successMessage = '';
$errorMessage = '';

if (isset($_GET['success']) && $_GET['success'] == 1) {
   $successMessage = isset($_GET['message']) ?
      urldecode($_GET['message']) :
      'Attendance marked successfully!';
}

if (isset($_GET['error']) && !empty($_GET['error'])) {
   $errorMessage = urldecode($_GET['error']);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>QR Scanner - EventQR</title>
   <!-- Bootstrap CSS -->
   <link href="../bootstrap/css/bootstrap.min.css" rel="stylesheet">
   <link rel="stylesheet" href="../assets/main.css">
   <!-- Bootstrap Icons -->
   <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
   <!-- Dashboard styles -->
   <style>
      body {
         background-color: #f5f5f5;
         overflow-x: hidden;
      }

      /* Sidebar styles */
      .sidebar {
         position: fixed;
         top: 0;
         left: 0;
         height: 100vh;
         width: 250px;
         background-color: #222831;
         color: #fff;
         transition: all 0.3s;
         z-index: 1000;
      }

      .sidebar-header {
         padding: 20px;
         background-color: #1b2027;
         text-align: center;
      }

      .sidebar-nav {
         padding: 0;
         list-style: none;
      }

      .sidebar-nav li a {
         display: flex;
         align-items: center;
         padding: 15px 20px;
         color: #fff;
         text-decoration: none;
         transition: all 0.2s;
      }

      .sidebar-nav li a:hover,
      .sidebar-nav li a.active {
         background-color: #1b2027;
         color: #F3C623;
      }

      .sidebar-nav li a i {
         margin-right: 10px;
         width: 20px;
         text-align: center;
      }

      /* Content styles */
      .content {
         margin-left: 250px;
         padding: 20px;
         transition: all 0.3s;
         width: calc(100% - 250px);
      }

      /* Dashboard container */
      .dashboard-container {
         width: 100%;
         padding: 0;
         max-width: none;
      }

      /* Header styles */
      .dashboard-header {
         background-color: #fff;
         border-bottom: 1px solid #ddd;
         padding: 15px 25px;
         margin-bottom: 20px;
         display: flex;
         justify-content: space-between;
         align-items: center;
         box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
      }

      /* Scanner specific styles */
      #scanner-container {
         max-width: 100%;
         max-height: 100%;
         overflow: hidden;
         position: relative;
      }

      #scanner-container video {
         width: 100%;
         height: auto;
         border-radius: 10px;
      }

      #scanner-overlay {
         position: absolute;
         top: 0;
         left: 0;
         right: 0;
         bottom: 0;
         display: flex;
         align-items: center;
         justify-content: center;
         background-color: rgba(0, 0, 0, 0.7);
         color: white;
         border-radius: 10px;
         z-index: 1000;
      }

      .scanner-guide {
         position: absolute;
         top: 50%;
         left: 50%;
         width: 250px;
         height: 250px;
         transform: translate(-50%, -50%);
         border: 2px solid #F3C623;
         border-radius: 10px;
         box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.3);
         z-index: 100;
         pointer-events: none;
      }

      .scanner-corner {
         position: absolute;
         width: 20px;
         height: 20px;
         border-color: #F3C623;
         border-width: 4px;
         border-style: solid;
      }

      .top-left {
         top: -2px;
         left: -2px;
         border-right: none;
         border-bottom: none;
      }

      .top-right {
         top: -2px;
         right: -2px;
         border-left: none;
         border-bottom: none;
      }

      .bottom-left {
         bottom: -2px;
         left: -2px;
         border-right: none;
         border-top: none;
      }

      .bottom-right {
         bottom: -2px;
         right: -2px;
         border-left: none;
         border-top: none;
      }

      .scan-result-box {
         border-radius: 10px;
         margin-bottom: 20px;
         transition: all 0.3s ease;
      }

      .scan-success {
         background-color: #d4edda;
         border-color: #c3e6cb;
      }

      .scan-error {
         background-color: #f8d7da;
         border-color: #f5c6cb;
      }

      .scan-waiting {
         background-color: #fff3cd;
         border-color: #ffeeba;
      }

      /* Mobile optimization */
      @media (max-width: 992px) {
         .sidebar {
            margin-left: -250px;
         }

         .sidebar.active {
            margin-left: 0;
         }

         .content {
            margin-left: 0;
            width: 100%;
         }

         .content.active {
            margin-left: 250px;
         }

         #sidebarCollapse {
            display: block;
         }
      }

      #sidebarCollapse {
         display: none;
      }

      /* User dropdown */
      .user-dropdown .dropdown-toggle::after {
         display: none;
      }

      .user-dropdown .dropdown-menu {
         right: 0;
         left: auto;
      }

      .user-dropdown img {
         width: 40px;
         height: 40px;
         border-radius: 50%;
         object-fit: cover;
      }
   </style>
</head>

<body>
   <div class="wrapper d-flex">
      <!-- Sidebar -->
      <nav id="sidebar" class="sidebar">
         <div class="sidebar-header">
            <div class="login-logo">EVSU-EVENT<span style="color: #F3C623;">QR</span></div>
            <div class="small text-white-50">Staff Portal</div>
         </div>

         <ul class="sidebar-nav">
            <li>
               <a href="dashboard.php">
                  <i class="bi bi-speedometer2"></i> Dashboard
               </a>
            </li>
            <li>
               <a href="events.php">
                  <i class="bi bi-calendar-event"></i> Events
               </a>
            </li>
            <li>
               <a href="students.php">
                  <i class="bi bi-people"></i> Students
               </a>
            </li>
            <li>
               <a href="attendance.php">
                  <i class="bi bi-check2-square"></i> Attendance
               </a>
            </li>
            <li>
               <a href="qr-scanner.php" class="active">
                  <i class="bi bi-qr-code-scan"></i> QR Scanner
               </a>
            </li>
            <li>
               <a href="reports.php">
                  <i class="bi bi-graph-up"></i> Reports
               </a>
            </li>
            <li>
               <a href="settings.php">
                  <i class="bi bi-gear"></i> Settings
               </a>
            </li>
            <li>
               <a href="logout.php">
                  <i class="bi bi-box-arrow-right"></i> Logout
               </a>
            </li>
         </ul>
      </nav>

      <!-- Page Content -->
      <div id="content" class="content">
         <!-- Header -->
         <?php include '../includes/header.php'; ?>

         <!-- QR Scanner Content -->
         <div class="container-fluid dashboard-container">
            <h1 class="mb-4">QR Code Scanner</h1>

            <?php if (!empty($successMessage)): ?>
               <div class="alert alert-success alert-dismissible fade show" role="alert">
                  <i class="bi bi-check-circle me-2"></i> <?= htmlspecialchars($successMessage) ?>
                  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
               </div>
            <?php endif; ?>

            <?php if (!empty($errorMessage)): ?>
               <div class="alert alert-danger alert-dismissible fade show" role="alert">
                  <i class="bi bi-exclamation-triangle me-2"></i> <?= htmlspecialchars($errorMessage) ?>
                  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
               </div>
            <?php endif; ?>

            <div class="row">
               <!-- Scanner Column -->
               <div class="col-lg-7 mb-4">
                  <div class="card">
                     <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Scan QR Code</h5>
                        <div class="btn-group" role="group">
                           <button type="button" class="btn btn-sm btn-outline-primary" id="flip-camera">
                              <i class="bi bi-camera-video me-1"></i> Switch Camera
                           </button>
                           <button type="button" class="btn btn-sm btn-outline-secondary" id="pause-camera">
                              <i class="bi bi-pause-fill me-1"></i> Pause
                           </button>
                        </div>
                     </div>
                     <div class="card-body">
                        <div class="row mb-4">
                           <div class="col-md-5 mb-3">
                              <label for="event-select" class="form-label">Select Event</label>
                              <select class="form-select" id="event-select" required>
                                 <option value="">Select an event</option>

                                 <!-- Today's Ongoing Events - Highest Priority -->
                                 <?php if (!empty($ongoingEvents)): ?>
                                    <optgroup label="Currently Ongoing">
                                       <?php foreach ($ongoingEvents as $event): ?>
                                          <option value="<?= $event['event_id'] ?>" class="fw-bold text-success">
                                             <?= htmlspecialchars($event['event_name']) ?>
                                             (<?= date('g:i A', strtotime($event['start_time'])) ?> - <?= date('g:i A', strtotime($event['end_time'])) ?>)
                                             - NOW ACTIVE
                                          </option>
                                       <?php endforeach; ?>
                                    </optgroup>
                                 <?php endif; ?>

                                 <!-- Today's Upcoming Events -->
                                 <?php if (!empty($upcomingTodayEvents)): ?>
                                    <optgroup label="Later Today">
                                       <?php foreach ($upcomingTodayEvents as $event): ?>
                                          <option value="<?= $event['event_id'] ?>">
                                             <?= htmlspecialchars($event['event_name']) ?>
                                             (<?= date('g:i A', strtotime($event['start_time'])) ?>)
                                          </option>
                                       <?php endforeach; ?>
                                    </optgroup>
                                 <?php endif; ?>

                                 <!-- Future Events -->
                                 <?php if (!empty($upcomingEvents)): ?>
                                    <optgroup label="Upcoming Events">
                                       <?php foreach ($upcomingEvents as $event): ?>
                                          <option value="<?= $event['event_id'] ?>">
                                             <?= htmlspecialchars($event['event_name']) ?>
                                             (<?= date('M d', strtotime($event['event_date'])) ?>)
                                          </option>
                                       <?php endforeach; ?>
                                    </optgroup>
                                 <?php endif; ?>

                                 <!-- Today's Completed Events - Only for reference or post-event check-in -->
                                 <?php if (!empty($completedTodayEvents)): ?>
                                    <optgroup label="Completed Today (For Review)">
                                       <?php foreach ($completedTodayEvents as $event): ?>
                                          <option value="<?= $event['event_id'] ?>" class="text-muted">
                                             <?= htmlspecialchars($event['event_name']) ?>
                                             (<?= date('g:i A', strtotime($event['start_time'])) ?>) - ENDED
                                          </option>
                                       <?php endforeach; ?>
                                    </optgroup>
                                 <?php endif; ?>
                              </select>
                              <?php if (!empty($ongoingEvents)): ?>
                                 <div class="form-text text-success">
                                    <i class="bi bi-info-circle"></i>
                                    There are <?= count($ongoingEvents) ?> events currently in progress.
                                 </div>
                              <?php endif; ?>
                           </div>
                           <div class="col-md-3 mb-3">
                              <label for="attendance-type" class="form-label">Attendance Type</label>
                              <select class="form-select" id="attendance-type">
                                 <option value="present">Present</option>
                                 <option value="late">Late</option>
                                 <option value="left early">Left Early</option>
                              </select>
                           </div>
                           <div class="col-md-4 mb-3">
                              <label for="scan-mode" class="form-label">Scan Mode</label>
                              <div class="btn-group w-100" role="group" aria-label="Scan mode">
                                 <input type="radio" class="btn-check" name="scan-mode" id="mode-check-in" value="check_in" autocomplete="off" checked>
                                 <label class="btn btn-outline-primary" for="mode-check-in">Check In</label>

                                 <input type="radio" class="btn-check" name="scan-mode" id="mode-check-out" value="check_out" autocomplete="off">
                                 <label class="btn btn-outline-secondary" for="mode-check-out">Check Out</label>
                              </div>
                           </div>
                        </div>

                        <div id="scanner-container" class="position-relative">
                           <div id="scanner-overlay" class="d-flex flex-column justify-content-center align-items-center">
                              <h3>Select an event to start scanning</h3>
                              <p>You must select an event before scanning QR codes</p>
                           </div>
                           <div id="video-container">
                              <div class="scanner-guide">
                                 <div class="scanner-corner top-left"></div>
                                 <div class="scanner-corner top-right"></div>
                                 <div class="scanner-corner bottom-left"></div>
                                 <div class="scanner-corner bottom-right"></div>
                              </div>
                           </div>
                        </div>

                        <div class="mt-3">
                           <div class="form-check form-switch">
                              <input class="form-check-input" type="checkbox" id="auto-submit-toggle" checked>
                              <label class="form-check-label" for="auto-submit-toggle">
                                 Automatically mark attendance on successful scan
                              </label>
                           </div>
                        </div>

                        <div id="scan-result" class="card mt-3 d-none scan-result-box">
                           <div class="card-body">
                              <h5 class="card-title" id="scan-result-title">Scanning...</h5>
                              <div id="scan-result-content"></div>
                              <div class="mt-3 text-end" id="scan-result-actions"></div>
                           </div>
                        </div>

                        <!-- New: Continuous Scanning Results History -->
                        <div class="card mt-3">
                           <div class="card-header d-flex justify-content-between align-items-center">
                              <h5 class="mb-0">Scan History</h5>
                              <button class="btn btn-sm btn-outline-secondary" id="clear-history-btn">
                                 <i class="bi bi-trash me-1"></i>Clear History
                              </button>
                           </div>
                           <div class="card-body p-0">
                              <div id="scan-history" class="list-group list-group-flush">
                                 <div class="p-4 text-center text-muted">
                                    <i class="bi bi-qr-code-scan fs-3"></i>
                                    <p class="mt-2 mb-0">No scans yet. Start scanning QR codes.</p>
                                 </div>
                              </div>
                           </div>
                        </div>

                        <div class="mt-3">
                           <p class="mb-1"><i class="bi bi-info-circle me-2"></i> Scanner Instructions:</p>
                           <ol class="small text-muted ms-4">
                              <li>Select an event from the dropdown</li>
                              <li>Position the student's QR code within the scanner frame</li>
                              <li>Hold steady until the code is recognized</li>
                              <li>Verify student information before confirming attendance</li>
                           </ol>
                        </div>
                     </div>
                  </div>
               </div>

               <!-- Recent Scans Column -->
               <div class="col-lg-5">
                  <div class="card">
                     <div class="card-header">
                        <h5 class="mb-0">Recent Scans</h5>
                     </div>
                     <div class="card-body p-0">
                        <?php if (empty($recentScans)): ?>
                           <div class="p-4 text-center text-muted">
                              <i class="bi bi-clock-history fs-3"></i>
                              <p class="mt-2 mb-0">No recent scan history</p>
                           </div>
                        <?php else: ?>
                           <div class="list-group list-group-flush">
                              <?php foreach ($recentScans as $scan): ?>
                                 <div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-center">
                                       <h6 class="mb-1"><?= htmlspecialchars($scan['first_name'] . ' ' . $scan['last_name']) ?></h6>
                                       <?php if ($scan['status'] == 'present'): ?>
                                          <span class="badge bg-success">Present</span>
                                       <?php elseif ($scan['status'] == 'late'): ?>
                                          <span class="badge bg-warning">Late</span>
                                       <?php elseif ($scan['status'] == 'left early'): ?>
                                          <span class="badge bg-info">Left Early</span>
                                       <?php else: ?>
                                          <span class="badge bg-secondary">Unknown</span>
                                       <?php endif; ?>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center small text-muted mt-1">
                                       <span><?= htmlspecialchars($scan['student_id']) ?></span>
                                       <span><?= date('g:i A', strtotime($scan['check_in_time'])) ?></span>
                                    </div>
                                    <div class="small text-muted">
                                       Event: <?= htmlspecialchars($scan['event_name']) ?>
                                    </div>
                                 </div>
                              <?php endforeach; ?>
                           </div>
                        <?php endif; ?>
                     </div>
                  </div>

                  <!-- Manual Attendance Card -->
                  <div class="card mt-4">
                     <div class="card-header">
                        <h5 class="mb-0">Manual Attendance</h5>
                     </div>
                     <div class="card-body">
                        <form id="manual-attendance-form">
                           <div class="mb-3">
                              <label for="manual-student-id" class="form-label">Student ID</label>
                              <input type="text" class="form-control" id="manual-student-id" required>
                           </div>
                           <div class="mb-3">
                              <label for="manual-event" class="form-label">Event</label>
                              <select class="form-select" id="manual-event" required>
                                 <option value="">Select an event</option>

                                 <!-- Today's Ongoing Events - Highest Priority -->
                                 <?php if (!empty($ongoingEvents)): ?>
                                    <optgroup label="Currently Ongoing">
                                       <?php foreach ($ongoingEvents as $event): ?>
                                          <option value="<?= $event['event_id'] ?>" class="fw-bold text-success">
                                             <?= htmlspecialchars($event['event_name']) ?>
                                             (<?= date('g:i A', strtotime($event['start_time'])) ?> - <?= date('g:i A', strtotime($event['end_time'])) ?>)
                                             - NOW ACTIVE
                                          </option>
                                       <?php endforeach; ?>
                                    </optgroup>
                                 <?php endif; ?>

                                 <!-- Today's Upcoming Events -->
                                 <?php if (!empty($upcomingTodayEvents)): ?>
                                    <optgroup label="Later Today">
                                       <?php foreach ($upcomingTodayEvents as $event): ?>
                                          <option value="<?= $event['event_id'] ?>">
                                             <?= htmlspecialchars($event['event_name']) ?>
                                             (<?= date('g:i A', strtotime($event['start_time'])) ?>)
                                          </option>
                                       <?php endforeach; ?>
                                    </optgroup>
                                 <?php endif; ?>

                                 <!-- Future Events -->
                                 <?php if (!empty($upcomingEvents)): ?>
                                    <optgroup label="Upcoming Events">
                                       <?php foreach ($upcomingEvents as $event): ?>
                                          <option value="<?= $event['event_id'] ?>">
                                             <?= htmlspecialchars($event['event_name']) ?>
                                             (<?= date('M d', strtotime($event['event_date'])) ?>)
                                          </option>
                                       <?php endforeach; ?>
                                    </optgroup>
                                 <?php endif; ?>

                                 <!-- Today's Completed Events - Only for reference or post-event check-in -->
                                 <?php if (!empty($completedTodayEvents)): ?>
                                    <optgroup label="Completed Today (For Review)">
                                       <?php foreach ($completedTodayEvents as $event): ?>
                                          <option value="<?= $event['event_id'] ?>" class="text-muted">
                                             <?= htmlspecialchars($event['event_name']) ?>
                                             (<?= date('g:i A', strtotime($event['start_time'])) ?>) - ENDED
                                          </option>
                                       <?php endforeach; ?>
                                    </optgroup>
                                 <?php endif; ?>
                              </select>
                           </div>
                           <div class="mb-3">
                              <label for="manual-status" class="form-label">Status</label>
                              <select class="form-select" id="manual-status" required>
                                 <option value="present">Present</option>
                                 <option value="late">Late</option>
                                 <option value="absent">Absent</option>
                                 <option value="left early">Left Early</option>
                              </select>
                           </div>
                           <button type="submit" class="btn btn-primary w-100">
                              <i class="bi bi-check-lg me-2"></i>Mark Attendance
                           </button>
                        </form>
                     </div>
                  </div>
               </div>
            </div>
         </div>
      </div>
   </div>

   <?php include '../includes/logout-modal.php'; ?>

   <!-- Bootstrap JS Bundle -->
   <script src="../bootstrap/js/bootstrap.bundle.min.js"></script>
   <!-- HTML5 QR Scanner Library -->
   <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
   <script>
      // Toggle sidebar on mobile
      document.getElementById('sidebarCollapse').addEventListener('click', function() {
         document.getElementById('sidebar').classList.toggle('active');
         document.getElementById('content').classList.toggle('active');
      });

      // Check screen size on load
      (function() {
         if (window.innerWidth < 992) {
            document.getElementById('sidebarCollapse').style.display = 'block';
         }
      })();

      // Update display on window resize
      window.addEventListener('resize', function() {
         if (window.innerWidth < 992) {
            document.getElementById('sidebarCollapse').style.display = 'block';
         } else {
            document.getElementById('sidebarCollapse').style.display = 'none';
            document.getElementById('sidebar').classList.remove('active');
            document.getElementById('content').classList.remove('active');
         }
      });

      // QR Scanner Logic
      document.addEventListener('DOMContentLoaded', function() {
         // Clear any URL parameters that might cause alerts to reappear
         if (window.history.replaceState) {
            const cleanUrl = window.location.protocol + "//" +
               window.location.host +
               window.location.pathname;
            window.history.replaceState({
               path: cleanUrl
            }, '', cleanUrl);
         }

         // Auto-dismiss alerts from PHP that might exist on page load
         const initialAlerts = document.querySelectorAll('.alert.alert-success, .alert.alert-danger');
         initialAlerts.forEach(alert => {
            setTimeout(() => {
               const closeBtn = alert.querySelector('.btn-close');
               if (closeBtn) closeBtn.click();
            }, 3000);
         });

         const html5QrCode = new Html5Qrcode("video-container");
         let currentCamera = 'environment'; // Default is back camera
         let isScanning = false;
         let scanPaused = false;
         const scannerOverlay = document.getElementById('scanner-overlay');
         const eventSelect = document.getElementById('event-select');
         const attendanceType = document.getElementById('attendance-type');
         const autoSubmitToggle = document.getElementById('auto-submit-toggle');
         const scanResult = document.getElementById('scan-result');
         const scanResultTitle = document.getElementById('scan-result-title');
         const scanResultContent = document.getElementById('scan-result-content');
         const scanResultActions = document.getElementById('scan-result-actions');
         const flipCameraButton = document.getElementById('flip-camera');
         const pauseCameraButton = document.getElementById('pause-camera');
         const scanHistory = document.getElementById('scan-history');
         const clearHistoryBtn = document.getElementById('clear-history-btn');

         // Track scanned codes to prevent duplicates in quick succession
         let lastScannedCode = '';
         let lastScanTime = 0;
         let scannedStudents = new Set();
         let scanHistoryItems = [];
         const SCAN_COOLDOWN = 3000; // 3 seconds cooldown between same-code scans

         // Manual attendance form
         const manualForm = document.getElementById('manual-attendance-form');
         const manualStudentId = document.getElementById('manual-student-id');
         const manualEvent = document.getElementById('manual-event');
         const manualStatus = document.getElementById('manual-status');

         // Track QR scanning mode
         let scanMode = 'check_in'; // Default scan mode

         // Function to start scanner
         function startScanner() {
            scannerOverlay.style.display = 'none';
            const config = {
               fps: 10,
               qrbox: {
                  width: 200,
                  height: 200
               },
               aspectRatio: 1.0
            };

            html5QrCode.start({
                  facingMode: currentCamera
               },
               config,
               onScanSuccess,
               onScanFailure
            ).then(() => {
               isScanning = true;
               pauseCameraButton.innerHTML = '<i class="bi bi-pause-fill me-1"></i> Pause';
            }).catch(err => {
               console.error("Unable to start scanning.", err);
               alert("Failed to start camera. Please check permissions.");
            });
         }

         // Function to stop scanner
         function stopScanner() {
            if (isScanning) {
               html5QrCode.stop().then(() => {
                  isScanning = false;
               }).catch(err => {
                  console.error("Failed to stop scanner.", err);
               });
            }
         }

         // Function to toggle pause/resume
         function togglePause() {
            if (!isScanning) return;

            if (scanPaused) {
               html5QrCode.resume();
               scanPaused = false;
               pauseCameraButton.innerHTML = '<i class="bi bi-pause-fill me-1"></i> Pause';
            } else {
               html5QrCode.pause();
               scanPaused = true;
               pauseCameraButton.innerHTML = '<i class="bi bi-play-fill me-1"></i> Resume';
            }
         }

         // Switch camera function
         function flipCamera() {
            stopScanner();
            currentCamera = currentCamera === 'environment' ? 'user' : 'environment';
            if (eventSelect.value) {
               startScanner();
            } else {
               scannerOverlay.style.display = 'flex';
            }
         }

         // QR Code scan success handler
         function onScanSuccess(qrCodeMessage) {
            // Check if this is a duplicate scan within cooldown period
            const now = Date.now();
            if (qrCodeMessage === lastScannedCode && now - lastScanTime < SCAN_COOLDOWN) {
               return; // Ignore duplicate scans within cooldown period
            }

            // Update tracking variables
            lastScannedCode = qrCodeMessage;
            lastScanTime = now;

            // Play feedback sound
            let scanSound = new Audio('../assets/sounds/beep.mp3');
            scanSound.play().catch(error => {
               // Ignore audio playback errors
               console.log('Audio playback prevented: user interaction needed first');
            });

            // Continue scanning without pausing
            validateQrCodeContinuous(qrCodeMessage);
         }

         // QR Code scan failure handler
         function onScanFailure(error) {
            // We don't need to handle failures, just continue scanning
            // Only display errors in console for debugging
            // console.error(`QR Code scanning failed: ${error}`);
         }

         // Event listener for scan mode toggle
         document.querySelectorAll('input[name="scan-mode"]').forEach(radio => {
            radio.addEventListener('change', function() {
               scanMode = this.value;
               console.log('Scan mode changed to:', scanMode);

               // Update UI based on scan mode
               if (scanMode === 'check_in') {
                  document.querySelector('#attendance-type').disabled = false;
                  document.querySelector('label[for="attendance-type"]').classList.remove('text-muted');
               } else {
                  document.querySelector('#attendance-type').disabled = true;
                  document.querySelector('label[for="attendance-type"]').classList.add('text-muted');
               }
            });
         });

         // Function to process QR code without interrupting scanning
         function validateQrCodeContinuous(qrCode) {
            const eventId = eventSelect.value;
            if (!eventId) {
               displayTemporaryMessage('Please select an event first.');
               return;
            }

            // Get the selected event name for fallback
            const selectedOption = eventSelect.options[eventSelect.selectedIndex];
            const selectedEventName = selectedOption.text;

            // Show a temporary scanning message
            displayTemporaryMessage('Scanning...', false, 10000, 'scan-message');

            fetch('process-qr-scan.php', {
                  method: 'POST',
                  headers: {
                     'Content-Type': 'application/json',
                  },
                  body: JSON.stringify({
                     qr_code: qrCode,
                     event_id: eventId,
                     mode: scanMode
                  }),
               })
               .then(response => response.json())
               .then(data => {
                  // Remove the scanning message
                  document.querySelectorAll('.scan-message').forEach(el => el.remove());

                  // Ensure event data is available by falling back to selected event if needed
                  if (!data.event) {
                     data.event = {
                        event_id: eventId,
                        event_name: selectedEventName
                     };
                  }

                  // Handle event not started yet case
                  if (data.event_not_started) {
                     displayTemporaryMessage(`Cannot check in: ${data.message}`, false, 8000);

                     // Add to scan history as error
                     const errorDetails = {
                        student_id: 'Error',
                        first_name: 'Event',
                        last_name: 'Not Started'
                     };
                     addToScanHistory(errorDetails, data.event, 'error', false, data.message);
                     return;
                  }

                  // Handle event ended case
                  if (data.event_ended) {
                     displayTemporaryMessage(`Cannot check in: ${data.message}`, false, 8000);

                     // Add to scan history as error
                     const errorDetails = {
                        student_id: 'Error',
                        first_name: 'Event',
                        last_name: 'Ended'
                     };
                     addToScanHistory(errorDetails, data.event, 'error', false, data.message);
                     return;
                  }

                  // Handle checkout_ready case
                  if (data.success && data.checkout_ready) {
                     // Show checkout confirmation
                     processCheckout(data.student.student_id, eventId, data.student, data.event);
                     return;
                  }

                  if (data.success && !data.checkout_ready) {
                     // Add to scan history - Regular check-in process
                     addToScanHistory(data.student, data.event, attendanceType.value, true);

                     // Auto-submit if enabled (only for check-ins)
                     if (scanMode === 'check_in' && autoSubmitToggle.checked) {
                        markAttendanceContinuous(data.student.student_id, eventId, attendanceType.value);
                     }
                  } else if (data.can_checkout && scanMode === 'check_in') {
                     // Already checked in but can checkout
                     addToScanHistory(data.student, data.event, 'already-in', false, data.message);
                     displayTemporaryMessage(data.message + '. Switch to Check Out mode to record exit.', false, 5000);
                  } else if (data.needs_registration) {
                     // Add to scan history as not registered
                     let errorStatus = 'not-registered';
                     let errorMessage = data.message;

                     // If student is registered for other events, show that info
                     if (data.registered_events && data.registered_events.length > 0) {
                        errorStatus = 'wrong-event';

                        // Create message showing which events they are registered for
                        let eventsList = data.registered_events.map(e =>
                           `${e.event_name} (${e.event_date})`).join(', ');

                        if (data.registered_events.length > 2) {
                           const firstEvents = data.registered_events.slice(0, 2);
                           eventsList = firstEvents.map(e =>
                              `${e.event_name} (${e.event_date})`).join(', ');
                           eventsList += ` and ${data.registered_events.length - 2} more`;
                        }

                        errorMessage = `Student is registered for different event(s): ${eventsList}`;
                     }

                     addToScanHistory(data.student, data.event, errorStatus, false, errorMessage);
                     displayTemporaryMessage(errorMessage, false, 5000);
                  } else {
                     // Show error in scan history
                     if (data.student) {
                        addToScanHistory(data.student, data.event, 'error', false, data.message);
                        displayTemporaryMessage(data.message, false, 5000);
                     } else {
                        // Special handling for "Student not found" errors
                        if (data.message && data.message.includes('Student not found')) {
                           // Log the QR code value for debugging
                           console.log('Unrecognized QR code:', data.qr_code_value);

                           const errorMsg = `${data.message}. Please verify your QR code or try a different student ID.`;
                           displayTemporaryMessage(errorMsg, false, 10000);

                           // Add a debugging entry to the scan history
                           const debugInfo = {
                              student_id: 'Unknown',
                              first_name: 'Invalid',
                              last_name: 'QR Code'
                           };

                           addToScanHistory(debugInfo, data.event, 'error', false,
                              `QR code not recognized. Value: "${data.qr_code_value || qrCode}" (${qrCode.length} chars)`);
                        } else {
                           displayTemporaryMessage(data.message || 'An error occurred processing the QR code', false, 5000);
                        }
                     }
                  }
               })
               .catch(error => {
                  console.error('Error:', error);
                  displayTemporaryMessage('Failed to process QR code. Check network connection.', false, 8000);

                  // Add generic error to history
                  const errorDetails = {
                     student_id: 'Error',
                     first_name: 'System',
                     last_name: 'Error'
                  };
                  addToScanHistory(errorDetails, {
                        event_name: selectedEventName
                     }, 'error', false,
                     'Network or server error processing QR code');
               });
         }

         // Process checkout from check-in scan
         function processCheckout(studentId, eventId, student, event) {
            fetch('process-checkout.php', {
                  method: 'POST',
                  headers: {
                     'Content-Type': 'application/json',
                  },
                  body: JSON.stringify({
                     student_id: studentId,
                     event_id: eventId
                  }),
               })
               .then(response => response.json())
               .then(data => {
                  if (data.success) {
                     // Show success message
                     let successMessage = `Successfully checked out ${student.first_name} ${student.last_name} at ${data.formatted_time}`;

                     // If student left early, add that info to the message
                     if (data.left_early) {
                        successMessage += ' (Left Early)';
                     }

                     displayTemporaryMessage(successMessage, true, 3000);

                     // Add to scan history with appropriate status
                     const status = data.left_early ? 'left early' : 'checked-out';
                     const statusMsg = data.left_early ?
                        `Left early at ${data.formatted_time}` :
                        `Checked out at ${data.formatted_time}`;

                     addToScanHistory(student, event, status, true, statusMsg);
                  } else {
                     // Show error
                     displayTemporaryMessage(data.message, false, 5000);

                     // Add to scan history
                     addToScanHistory(student, event, 'error', false, data.message);
                  }
               })
               .catch(error => {
                  console.error('Error:', error);
                  displayTemporaryMessage('Failed to process check-out.');
               });
         }

         // New: Function to display temporary message without interrupting scanning
         function displayTemporaryMessage(message, isSuccess = false, timeout = 5000, customClass = '') {
            const alertDiv = document.createElement('div');
            const alertClass = isSuccess ? 'alert-success' : 'alert-warning';
            const iconClass = isSuccess ? 'bi-check-circle' : 'bi-exclamation-triangle';

            alertDiv.className = `alert ${alertClass} alert-dismissible fade show notification-alert ${customClass}`;
            alertDiv.innerHTML = `
               <i class="bi ${iconClass} me-2"></i> ${message}
               <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            `;

            // Remove any existing notifications with the same custom class if specified
            if (customClass) {
               document.querySelectorAll(`.notification-alert.${customClass}`).forEach(alert => alert.remove());
            }

            // Insert at top of page below the header
            const container = document.querySelector('.dashboard-container');
            container.insertBefore(alertDiv, container.firstChild);

            // Auto-dismiss after specified timeout
            setTimeout(() => {
               if (alertDiv.parentNode) {
                  alertDiv.classList.remove('show');
                  setTimeout(() => alertDiv.remove(), 300);
               }
            }, timeout);
         }

         // New: Function to mark attendance without interrupting scanning
         function markAttendanceContinuous(studentId, eventId, status) {
            fetch('mark-attendance.php', {
                  method: 'POST',
                  headers: {
                     'Content-Type': 'application/json',
                  },
                  body: JSON.stringify({
                     student_id: studentId,
                     event_id: eventId,
                     status: status,
                     auto_register: true
                  }),
               })
               .then(response => response.json())
               .then(data => {
                  if (data.success) {
                     // Show success message with shorter timeout (3 seconds)
                     displayTemporaryMessage(data.message, true, 3000);

                     // Update scan history item to show confirmed
                     updateScanHistoryStatus(studentId, 'confirmed');
                  } else {
                     // Show error but stay on page - use default 5 second timeout
                     displayTemporaryMessage(data.message);

                     // Update scan history item to show error
                     updateScanHistoryStatus(studentId, 'error', data.message);
                  }
               })
               .catch(error => {
                  console.error('Error:', error);
                  displayTemporaryMessage('Failed to mark attendance. Please try again.');
               });
         }

         // New: Function to add an entry to scan history
         function addToScanHistory(student, event, status, isValid, errorMsg = '') {
            // Clear the "No scans yet" message if present
            if (scanHistory.querySelector('.text-center.text-muted')) {
               scanHistory.innerHTML = '';
            }

            // Create status badge
            let statusBadge = '';
            switch (status) {
               case 'present':
                  statusBadge = '<span class="badge bg-success">Present</span>';
                  break;
               case 'late':
                  statusBadge = '<span class="badge bg-warning">Late</span>';
                  break;
               case 'left early':
                  statusBadge = '<span class="badge bg-info">Left Early</span>';
                  break;
               case 'checked-out':
                  statusBadge = '<span class="badge bg-secondary">Checked Out</span>';
                  break;
               case 'already-in':
                  statusBadge = '<span class="badge bg-info">Already In</span>';
                  break;
               case 'not-registered':
                  statusBadge = '<span class="badge bg-secondary">Not Registered</span>';
                  break;
               case 'wrong-event':
                  statusBadge = '<span class="badge bg-danger">Wrong Event</span>';
                  break;
               case 'error':
                  statusBadge = '<span class="badge bg-danger">Error</span>';
                  break;
               default:
                  statusBadge = '<span class="badge bg-secondary">Unknown</span>';
            }

            // Generate a unique ID for this scan
            const scanId = `scan-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;

            // If event is not provided, get the currently selected event
            let eventData = event || {};
            if (!event && eventSelect.value) {
               // Find the selected event in the dropdown
               const selectedOption = eventSelect.options[eventSelect.selectedIndex];
               eventData = {
                  event_id: eventSelect.value,
                  event_name: selectedOption.text
               };
            }

            // Add register button for not-registered students or those registered for wrong events
            const showRegisterButton = !isValid && (status === 'not-registered' || status === 'wrong-event');

            // Create history item
            const historyItem = document.createElement('div');
            historyItem.className = 'list-group-item' + (isValid ? '' : ' list-group-item-warning');
            historyItem.id = scanId;
            historyItem.dataset.studentId = student.student_id;
            historyItem.innerHTML = `
               <div class="d-flex justify-content-between align-items-center">
                  <h6 class="mb-1">${student.first_name} ${student.last_name}</h6>
                  ${statusBadge}
               </div>
               <div class="d-flex justify-content-between align-items-center small text-muted mt-1">
                  <span>${student.student_id}</span>
                  <span>${new Date().toLocaleTimeString()}</span>
               </div>
               <div class="small text-muted">
                  Event: ${eventData.event_name || 'Unknown'}
               </div>
               ${errorMsg ? `<div class="small ${isValid ? 'text-success' : 'text-danger'} mt-1">${errorMsg}</div>` : ''}
               ${showRegisterButton ? `
                  <div class="mt-2">
                     <button class="btn btn-sm btn-warning register-attend-btn" data-student-id="${student.student_id}" data-event-id="${eventData.event_id}">
                        Register & Mark Attendance
                     </button>
                  </div>
               ` : ''}
            `;

            // Add to scan history at the top
            scanHistory.insertBefore(historyItem, scanHistory.firstChild);

            // Keep only the latest 20 items
            scanHistoryItems.unshift({
               id: scanId,
               studentId: student.student_id,
               status: status
            });

            if (scanHistoryItems.length > 20) {
               const removedItem = scanHistoryItems.pop();
               const elementToRemove = document.getElementById(removedItem.id);
               if (elementToRemove) {
                  elementToRemove.remove();
               }
            }

            // Add event listener for register & attend button
            const registerBtn = historyItem.querySelector('.register-attend-btn');
            if (registerBtn) {
               registerBtn.addEventListener('click', function() {
                  const studentId = this.dataset.studentId;
                  const eventId = this.dataset.eventId;
                  markAttendanceContinuous(studentId, eventId, attendanceType.value);
               });
            }
         }

         // New: Function to update scan history status
         function updateScanHistoryStatus(studentId, newStatus, errorMsg = '') {
            // Find matching history items
            for (let item of scanHistoryItems) {
               if (item.studentId === studentId) {
                  const historyElement = document.getElementById(item.id);
                  if (historyElement) {
                     // Update status badge
                     let statusBadge;
                     switch (newStatus) {
                        case 'confirmed':
                           statusBadge = '<span class="badge bg-success">Confirmed</span>';
                           historyElement.classList.remove('list-group-item-warning');
                           break;
                        case 'error':
                           statusBadge = '<span class="badge bg-danger">Error</span>';
                           historyElement.classList.add('list-group-item-warning');
                           break;
                        default:
                           return; // Invalid status
                     }

                     // Update the badge
                     const badgeContainer = historyElement.querySelector('.d-flex.justify-content-between.align-items-center');
                     if (badgeContainer) {
                        const oldBadge = badgeContainer.querySelector('.badge');
                        if (oldBadge) {
                           oldBadge.outerHTML = statusBadge;
                        }
                     }

                     // Add or update error message
                     if (errorMsg) {
                        let errorDiv = historyElement.querySelector('.text-danger');
                        if (errorDiv) {
                           errorDiv.textContent = errorMsg;
                        } else {
                           errorDiv = document.createElement('div');
                           errorDiv.className = 'small text-danger mt-1';
                           errorDiv.textContent = errorMsg;
                           historyElement.appendChild(errorDiv);
                        }
                     }

                     // Remove the register button if status is confirmed
                     const registerBtn = historyElement.querySelector('.register-attend-btn');
                     if (registerBtn && newStatus === 'confirmed') {
                        registerBtn.remove();
                     }

                     // Update item in array
                     item.status = newStatus;
                  }
               }
            }
         }

         // Event listener for clear history button
         clearHistoryBtn.addEventListener('click', function() {
            scanHistory.innerHTML = `
               <div class="p-4 text-center text-muted">
                  <i class="bi bi-qr-code-scan fs-3"></i>
                  <p class="mt-2 mb-0">No scans yet. Start scanning QR codes.</p>
               </div>
            `;
            scanHistoryItems = [];
         });

         // Function to reset scan result display
         function resetScanResult() {
            scanResult.classList.add('d-none');
            scanResult.classList.remove('scan-success', 'scan-error', 'scan-waiting');
            scanResultContent.innerHTML = '';
            scanResultActions.innerHTML = '';
         }

         // Event listener for event selection
         eventSelect.addEventListener('change', function() {
            if (this.value) {
               if (!isScanning) {
                  startScanner();
               } else {
                  scannerOverlay.style.display = 'none';
               }
            } else {
               scannerOverlay.style.display = 'flex';
               stopScanner();
            }
         });

         // Flip camera button
         flipCameraButton.addEventListener('click', flipCamera);

         // Pause/Resume button
         pauseCameraButton.addEventListener('click', togglePause);

         // Manual attendance form submit
         manualForm.addEventListener('submit', function(e) {
            e.preventDefault();

            if (!manualStudentId.value || !manualEvent.value || !manualStatus.value) {
               alert('Please fill in all required fields.');
               return;
            }

            // Use the continuous attendance marking function
            fetch('process-manual-attendance.php', {
                  method: 'POST',
                  headers: {
                     'Content-Type': 'application/json',
                  },
                  body: JSON.stringify({
                     student_id: manualStudentId.value,
                     event_id: manualEvent.value,
                     status: manualStatus.value
                  }),
               })
               .then(response => response.json())
               .then(data => {
                  if (data.success) {
                     displayTemporaryMessage(data.message, true, 3000);

                     // Add to scan history
                     addToScanHistory(data.student, data.event, data.status, true);

                     // Reset form
                     manualStudentId.value = '';
                     manualForm.reset();

                  } else {
                     displayTemporaryMessage(data.message || 'Failed to mark attendance', false, 5000);
                  }
               })
               .catch(error => {
                  console.error('Error:', error);
                  displayTemporaryMessage('Failed to mark attendance. Please try again.');
               });
         });

         // Handle page visibility changes
         document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
               // Page is hidden, pause scanner if running
               if (isScanning && !scanPaused) {
                  html5QrCode.pause();
                  scanPaused = true;
                  pauseCameraButton.innerHTML = '<i class="bi bi-play-fill me-1"></i> Resume';
               }
            } else {
               // Page is visible again, resume scanner if it was running
               if (isScanning && scanPaused && eventSelect.value) {
                  html5QrCode.resume();
                  scanPaused = false;
                  pauseCameraButton.innerHTML = '<i class="bi bi-pause-fill me-1"></i> Pause';
               }
            }
         });
      });
   </script>
</body>

</html>