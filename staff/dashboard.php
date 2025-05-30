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

// Fetch enhanced statistics for dashboard
$stats = [
   'totalEvents' => 0,
   'upcomingEvents' => 0,
   'todayEvents' => 0,
   'totalStudents' => 0,
   'ongoingEvents' => 0,
   'totalAttendance' => 0,
   'todayAttendance' => 0,
];

// Get total events count
$eventsSql = "SELECT COUNT(*) as count FROM events";
$result = $conn->query($eventsSql);
if ($result && $row = $result->fetch_assoc()) {
   $stats['totalEvents'] = $row['count'];
}

// Get upcoming events count
$currentDate = date('Y-m-d');
$currentDateTime = date('Y-m-d H:i:s');
$upcomingSql = "SELECT COUNT(*) as count FROM events WHERE event_date > ? OR (event_date = ? AND CONCAT(event_date, ' ', start_time) > ?)";

$stmt = $conn->prepare($upcomingSql);
$stmt->bind_param("sss", $currentDate, $currentDate, $currentDateTime);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $row = $result->fetch_assoc()) {
   $stats['upcomingEvents'] = $row['count'];
}

// Get today's events count
$todaySql = "SELECT COUNT(*) as count FROM events WHERE event_date = ?";
$stmt = $conn->prepare($todaySql);
$stmt->bind_param("s", $currentDate);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $row = $result->fetch_assoc()) {
   $stats['todayEvents'] = $row['count'];
}

// Get ongoing events count
$ongoingSql = "SELECT COUNT(*) as count FROM events 
               WHERE CONCAT(event_date, ' ', start_time) <= ? 
               AND CONCAT(event_date, ' ', end_time) >= ?";
$stmt = $conn->prepare($ongoingSql);
$stmt->bind_param("ss", $currentDateTime, $currentDateTime);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $row = $result->fetch_assoc()) {
   $stats['ongoingEvents'] = $row['count'];
}

// Get total students count
$studentsSql = "SELECT COUNT(*) as count FROM students";
$result = $conn->query($studentsSql);
if ($result && $row = $result->fetch_assoc()) {
   $stats['totalStudents'] = $row['count'];
}

// Get total attendance count
$attendanceSql = "SELECT COUNT(*) as count FROM attendance WHERE status = 'present'";
$result = $conn->query($attendanceSql);
if ($result && $row = $result->fetch_assoc()) {
   $stats['totalAttendance'] = $row['count'];
}

// Get today's attendance count
$todayAttendanceSql = "SELECT COUNT(*) as count 
                       FROM attendance a 
                       JOIN events e ON a.event_id = e.event_id 
                       WHERE e.event_date = ? AND a.status = 'present'";
$stmt = $conn->prepare($todayAttendanceSql);
$stmt->bind_param("s", $currentDate);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $row = $result->fetch_assoc()) {
   $stats['todayAttendance'] = $row['count'];
}

// Get recent events with more details
$recentEventsSql = "SELECT e.*, 
                   (SELECT COUNT(*) FROM event_registrations WHERE event_id = e.event_id) as registrations,
                   (SELECT COUNT(*) FROM attendance WHERE event_id = e.event_id AND status = 'present') as attendance
                   FROM events e 
                   ORDER BY e.event_date DESC, e.start_time DESC LIMIT 5";
$recentEvents = [];
$result = $conn->query($recentEventsSql);
if ($result) {
   while ($row = $result->fetch_assoc()) {
      $recentEvents[] = $row;
   }
}

// Get ongoing events for the quick access panel
$ongoingEventsSql = "SELECT event_id, event_name, event_date, start_time, end_time, location,
                    CONCAT(event_date, ' ', end_time) as end_datetime
                    FROM events 
                    WHERE CONCAT(event_date, ' ', start_time) <= ? 
                    AND CONCAT(event_date, ' ', end_time) >= ?
                    ORDER BY event_date ASC, start_time ASC";
$stmt = $conn->prepare($ongoingEventsSql);
$stmt->bind_param("ss", $currentDateTime, $currentDateTime);
$stmt->execute();
$result = $stmt->get_result();
$ongoingEvents = [];

while ($row = $result->fetch_assoc()) {
   // Calculate time remaining until end
   $endTime = strtotime($row['end_datetime']);
   $currentTime = time();
   $timeRemaining = $endTime - $currentTime;

   $hours = floor($timeRemaining / 3600);
   $minutes = floor(($timeRemaining % 3600) / 60);

   $row['time_remaining'] = sprintf(
      "%d hr%s, %d min%s",
      $hours,
      ($hours != 1 ? 's' : ''),
      $minutes,
      ($minutes != 1 ? 's' : '')
   );

   $ongoingEvents[] = $row;
}

// Get recent check-ins for activity feed
$recentCheckInsSql = "SELECT a.check_in_time, a.status, 
                     s.student_id, s.first_name, s.last_name, 
                     e.event_id, e.event_name
                     FROM attendance a
                     JOIN students s ON a.student_id = s.student_id
                     JOIN events e ON a.event_id = e.event_id
                     WHERE a.check_in_time IS NOT NULL
                     ORDER BY a.check_in_time DESC
                     LIMIT 8";
$recentCheckIns = [];
$result = $conn->query($recentCheckInsSql);
if ($result) {
   while ($row = $result->fetch_assoc()) {
      $recentCheckIns[] = $row;
   }
}

// Get system notifications (events requiring attention)
$notificationsSql = "SELECT e.event_id, e.event_name, e.event_date, e.start_time, e.end_time,
                    (SELECT COUNT(*) FROM event_registrations WHERE event_id = e.event_id) as registrations,
                    (SELECT COUNT(*) FROM attendance WHERE event_id = e.event_id AND status = 'present') as attendance
                    FROM events e
                    WHERE e.event_date < CURRENT_DATE
                    ORDER BY e.event_date DESC
                    LIMIT 5";
$systemNotifications = [];
$result = $conn->query($notificationsSql);
if ($result) {
   while ($row = $result->fetch_assoc()) {
      // Check if attendance is significantly lower than registrations
      if ($row['registrations'] > 0 && ($row['attendance'] / $row['registrations']) < 0.7) {
         $row['type'] = 'low_attendance';
         $row['message'] = 'Low attendance rate compared to registrations';
         $systemNotifications[] = $row;
      }
   }
}

// Update last activity time
$_SESSION['last_activity'] = time();
?>

<!DOCTYPE html>
<html lang="en">

<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Staff Dashboard - EventQR</title>
   <!-- Bootstrap CSS -->
   <link href="../bootstrap/css/bootstrap.min.css" rel="stylesheet">
   <link rel="stylesheet" href="../assets/main.css">
   <!-- Bootstrap Icons -->
   <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
   <!-- FullCalendar CSS -->
   <link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" rel="stylesheet">
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

      /* Stat cards */
      .stat-card {
         border-radius: 10px;
         box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
         margin-bottom: 20px;
         border-left: 4px solid #F3C623;
         transition: transform 0.2s;
      }

      .stat-card:hover {
         transform: translateY(-5px);
      }

      .stat-icon {
         width: 60px;
         height: 60px;
         border-radius: 50%;
         display: flex;
         align-items: center;
         justify-content: center;
         font-size: 24px;
      }

      /* Enhanced dashboard styles */
      .dashboard-welcome {
         background: linear-gradient(135deg, #4e73df 0%, #224abe 100%);
         color: white;
         border-radius: 10px;
         padding: 20px;
         margin-bottom: 20px;
         box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
      }

      .ongoing-event-card {
         border-left: 4px solid #28a745;
      }

      .time-remaining {
         font-size: 0.85rem;
         padding: 3px 8px;
         border-radius: 12px;
         background-color: rgba(40, 167, 69, 0.1);
         border: 1px solid rgba(40, 167, 69, 0.2);
         color: #28a745;
      }

      .activity-item {
         padding: 10px 0;
         border-bottom: 1px solid #eee;
      }

      .activity-item:last-child {
         border-bottom: none;
      }

      .activity-time {
         font-size: 0.8rem;
         color: #6c757d;
      }

      .notification-dot {
         position: absolute;
         top: 5px;
         right: 5px;
         width: 8px;
         height: 8px;
         border-radius: 50%;
         background-color: #dc3545;
      }

      .status-badge {
         font-size: 0.75rem;
         padding: 0.15rem 0.5rem;
      }

      /* Calendar style */
      .calendar-day {
         width: 14.28%;
         aspect-ratio: 1/1;
         max-width: 50px;
         max-height: 50px;
         display: flex;
         align-items: center;
         justify-content: center;
         border-radius: 50%;
         font-weight: bold;
         position: relative;
      }

      .calendar-day.has-event {
         background-color: rgba(13, 110, 253, 0.1);
         color: #0d6efd;
         cursor: pointer;
      }

      .calendar-day.today {
         background-color: #0d6efd;
         color: white;
      }

      .calendar-day.has-event.today {
         background-color: #0d6efd;
         color: white;
      }

      .calendar-day .event-dot {
         position: absolute;
         bottom: 2px;
         width: 4px;
         height: 4px;
         border-radius: 50%;
         background-color: #0d6efd;
      }

      .calendar-day.today .event-dot {
         background-color: white;
      }

      /* Full Calendar Customizations */
      #calendar {
         height: 500px;
      }

      .fc-daygrid-day-number {
         font-size: 0.9em;
         font-weight: 500;
      }

      .fc-event {
         cursor: pointer;
         font-size: 0.8em;
      }

      .fc-day-today {
         background-color: rgba(13, 110, 253, 0.05) !important;
      }

      .fc-toolbar-title {
         font-size: 1.5em !important;
      }

      .fc-header-toolbar {
         margin-bottom: 0.5em !important;
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
               <a href="dashboard.php" class="active">
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
               <a href="qr-scanner.php">
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
               <a href="#" data-bs-toggle="modal" data-bs-target="#logoutModal">
                  <i class="bi bi-box-arrow-right"></i> Logout
               </a>
            </li>
         </ul>
      </nav>

      <!-- Page Content -->
      <div id="content" class="content">
         <!-- Header -->
         <?php include '../includes/header.php'; ?>

         <!-- Dashboard Content -->
         <div class="container-fluid dashboard-container">
            <!-- Welcome Section -->
            <div class="dashboard-welcome mb-4">
               <div class="d-md-flex justify-content-between align-items-center">
                  <div>
                     <h1 class="mb-1">Welcome back, <?= htmlspecialchars($fullName) ?>!</h1>
                     <p class="mb-0">Today is <?= date('l, F d, Y') ?> | Current time: <?= date('h:i A') ?> (PHT)</p>
                  </div>
                  <div class="mt-3 mt-md-0">
                     <a href="event-status-checker.php" class="btn btn-light">
                        <i class="bi bi-calendar-check me-2"></i>Event Status Checker
                     </a>
                  </div>
               </div>
            </div>

            <!-- Statistics Cards -->
            <div class="row">
               <div class="col-xl-3 col-md-6">
                  <div class="card stat-card">
                     <div class="card-body d-flex align-items-center">
                        <div class="stat-icon bg-primary text-white me-3">
                           <i class="bi bi-calendar-event"></i>
                        </div>
                        <div>
                           <h2 class="mb-0"><?= $stats['totalEvents'] ?></h2>
                           <p class="text-muted mb-0">Total Events</p>
                        </div>
                     </div>
                  </div>
               </div>
               <div class="col-xl-3 col-md-6">
                  <div class="card stat-card">
                     <div class="card-body d-flex align-items-center">
                        <div class="stat-icon bg-success text-white me-3">
                           <i class="bi bi-calendar-check"></i>
                        </div>
                        <div>
                           <h2 class="mb-0"><?= $stats['upcomingEvents'] ?></h2>
                           <p class="text-muted mb-0">Upcoming Events</p>
                        </div>
                     </div>
                  </div>
               </div>
               <div class="col-xl-3 col-md-6">
                  <div class="card stat-card">
                     <div class="card-body d-flex align-items-center">
                        <div class="stat-icon bg-warning text-white me-3">
                           <i class="bi bi-calendar-date"></i>
                        </div>
                        <div>
                           <h2 class="mb-0"><?= $stats['todayEvents'] ?></h2>
                           <p class="text-muted mb-0">Today's Events</p>
                        </div>
                     </div>
                  </div>
               </div>
               <div class="col-xl-3 col-md-6">
                  <div class="card stat-card">
                     <div class="card-body d-flex align-items-center">
                        <div class="stat-icon bg-info text-white me-3">
                           <i class="bi bi-people"></i>
                        </div>
                        <div>
                           <h2 class="mb-0"><?= $stats['totalStudents'] ?></h2>
                           <p class="text-muted mb-0">Total Students</p>
                        </div>
                     </div>
                  </div>
               </div>
            </div>

            <!-- Ongoing Events Alert (if any) -->
            <?php if (!empty($ongoingEvents)): ?>
               <div class="alert alert-success d-flex align-items-center mb-4" role="alert">
                  <i class="bi bi-broadcast me-2 fs-4"></i>
                  <div>
                     <strong>Attention!</strong> There <?= count($ongoingEvents) == 1 ? 'is' : 'are' ?> currently
                     <strong><?= count($ongoingEvents) ?></strong> event<?= count($ongoingEvents) == 1 ? '' : 's' ?> in progress.
                     <a href="#ongoing-events" class="alert-link">Check details below</a>.
                  </div>
               </div>
            <?php endif; ?>

            <!-- Main Content Rows -->
            <div class="row">
               <!-- Left Column -->
               <div class="col-lg-8">
                  <!-- Full Month Calendar -->
                  <div class="card mb-4">
                     <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-calendar3 me-2"></i>Event Calendar</h5>
                        <a href="events.php" class="btn btn-sm btn-outline-primary">Manage Events</a>
                     </div>
                     <div class="card-body">
                        <div id="calendar"></div>
                     </div>
                  </div>

                  <!-- Ongoing Events Section (if any) -->
                  <?php if (!empty($ongoingEvents)): ?>
                     <div class="card mb-4" id="ongoing-events">
                        <div class="card-header bg-white d-flex justify-content-between align-items-center">
                           <h5 class="mb-0"><i class="bi bi-broadcast me-2"></i>Ongoing Events</h5>
                        </div>
                        <div class="card-body p-0">
                           <div class="list-group list-group-flush">
                              <?php foreach ($ongoingEvents as $event): ?>
                                 <div class="list-group-item p-3">
                                    <div class="d-flex justify-content-between align-items-center">
                                       <div>
                                          <h6 class="mb-1"><?= htmlspecialchars($event['event_name']) ?></h6>
                                          <p class="mb-1 small text-muted">
                                             <i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($event['location']) ?>
                                             <span class="mx-2">|</span>
                                             <i class="bi bi-clock me-1"></i><?= date('g:i A', strtotime($event['start_time'])) ?> - <?= date('g:i A', strtotime($event['end_time'])) ?>
                                          </p>
                                       </div>
                                       <div class="text-end">
                                          <span class="time-remaining d-inline-block mb-2">
                                             <i class="bi bi-hourglass-split me-1"></i><?= $event['time_remaining'] ?> left
                                          </span>
                                          <div>
                                             <a href="qr-scanner.php?event_id=<?= $event['event_id'] ?>" class="btn btn-sm btn-success">
                                                <i class="bi bi-qr-code-scan me-1"></i>Scan QR
                                             </a>
                                          </div>
                                       </div>
                                    </div>
                                 </div>
                              <?php endforeach; ?>
                           </div>
                        </div>
                     </div>
                  <?php endif; ?>

                  <!-- Today's Events Card -->
                  <div class="card mb-4">
                     <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-calendar-day me-2"></i>Today's Events</h5>
                        <a href="events.php" class="btn btn-sm btn-outline-primary">View All Events</a>
                     </div>
                     <div class="card-body p-0">
                        <?php
                        // Get today's events
                        $currentDate = date('Y-m-d');
                        $currentTime = date('H:i:s');
                        $todayEventsSql = "SELECT event_id, event_name, start_time, end_time, location,
                                          CONCAT(event_date, ' ', start_time) as start_datetime,
                                          CONCAT(event_date, ' ', end_time) as end_datetime
                                          FROM events WHERE event_date = ? ORDER BY start_time";
                        $stmt = $conn->prepare($todayEventsSql);
                        $stmt->bind_param("s", $currentDate);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        $todayEvents = [];

                        while ($row = $result->fetch_assoc()) {
                           // Determine event status based on current time
                           $currentDateTime = date('Y-m-d H:i:s');
                           if ($currentDateTime < $row['start_datetime']) {
                              $row['status'] = 'upcoming';
                              $row['status_badge'] = '<span class="badge bg-primary status-badge">Upcoming</span>';
                              $row['check_in_status'] = '<span class="badge bg-info status-badge">Not Open</span>';

                              // Calculate time until start
                              $seconds = strtotime($row['start_datetime']) - time();
                              $hours = floor($seconds / 3600);
                              $minutes = floor(($seconds % 3600) / 60);

                              if ($hours > 0) {
                                 $row['time_info'] = "Starts in $hours hr" . ($hours != 1 ? 's' : '') . ", $minutes min";
                              } else {
                                 $row['time_info'] = "Starts in $minutes min";
                              }
                           } elseif ($currentDateTime >= $row['start_datetime'] && $currentDateTime <= $row['end_datetime']) {
                              $row['status'] = 'ongoing';
                              $row['status_badge'] = '<span class="badge bg-success status-badge">Ongoing</span>';
                              $row['check_in_status'] = '<span class="badge bg-success status-badge">Available</span>';

                              // Calculate time until end
                              $seconds = strtotime($row['end_datetime']) - time();
                              $hours = floor($seconds / 3600);
                              $minutes = floor(($seconds % 3600) / 60);
                              $row['time_info'] = "Ends in $hours hr" . ($hours != 1 ? 's' : '') . ", $minutes min";
                           } else {
                              $row['status'] = 'ended';
                              $row['status_badge'] = '<span class="badge bg-secondary status-badge">Ended</span>';
                              $row['check_in_status'] = '<span class="badge bg-danger status-badge">Closed</span>';

                              // Calculate time since ended
                              $seconds = time() - strtotime($row['end_datetime']);
                              $hours = floor($seconds / 3600);
                              $minutes = floor(($seconds % 3600) / 60);
                              $row['time_info'] = "Ended $hours hr" . ($hours != 1 ? 's' : '') . " ago";
                           }
                           $todayEvents[] = $row;
                        }
                        ?>

                        <?php if (empty($todayEvents)): ?>
                           <div class="p-4 text-center">
                              <i class="bi bi-calendar-x fs-1 text-muted"></i>
                              <p class="mt-3">No events scheduled for today.</p>
                              <a href="create-event.php" class="btn btn-primary">
                                 <i class="bi bi-plus-circle me-2"></i>Create New Event
                              </a>
                           </div>
                        <?php else: ?>
                           <div class="list-group list-group-flush">
                              <?php foreach ($todayEvents as $event): ?>
                                 <div class="list-group-item p-3 <?= $event['status'] === 'ongoing' ? 'bg-light' : '' ?>">
                                    <div class="d-flex justify-content-between align-items-center">
                                       <div>
                                          <div class="d-flex align-items-center">
                                             <h6 class="mb-0"><?= htmlspecialchars($event['event_name']) ?></h6>
                                             <div class="ms-2"><?= $event['status_badge'] ?></div>
                                          </div>
                                          <p class="mb-0 small text-muted">
                                             <i class="bi bi-clock me-1"></i>
                                             <?= date('g:i A', strtotime($event['start_time'])) ?> -
                                             <?= date('g:i A', strtotime($event['end_time'])) ?>
                                             <i class="bi bi-geo-alt ms-2 me-1"></i>
                                             <?= htmlspecialchars($event['location']) ?>
                                          </p>
                                          <p class="mb-0 small mt-1">
                                             <i class="bi bi-hourglass-split me-1"></i>
                                             <span class="text-<?= $event['status'] === 'ongoing' ? 'success' : ($event['status'] === 'upcoming' ? 'primary' : 'secondary') ?>">
                                                <?= $event['time_info'] ?>
                                             </span>
                                          </p>
                                       </div>
                                       <div>
                                          <?php if ($event['status'] === 'ongoing'): ?>
                                             <a href="qr-scanner.php?event_id=<?= $event['event_id'] ?>" class="btn btn-sm btn-success">
                                                <i class="bi bi-qr-code-scan me-1"></i>Scan QR
                                             </a>
                                          <?php elseif ($event['status'] === 'upcoming'): ?>
                                             <button class="btn btn-sm btn-outline-secondary" disabled>
                                                <i class="bi bi-clock me-1"></i>Not Started
                                             </button>
                                          <?php else: ?>
                                             <a href="view-event.php?id=<?= $event['event_id'] ?>" class="btn btn-sm btn-outline-secondary">
                                                <i class="bi bi-file-earmark-text me-1"></i>View Report
                                             </a>
                                          <?php endif; ?>
                                       </div>
                                    </div>
                                 </div>
                              <?php endforeach; ?>
                           </div>
                        <?php endif; ?>
                     </div>
                  </div>

                  <!-- Recent Events Section -->
                  <div class="card mb-4">
                     <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-calendar-range me-2"></i>Recent Events</h5>
                        <a href="events.php" class="btn btn-sm btn-outline-primary">View All</a>
                     </div>
                     <div class="card-body">
                        <?php if (empty($recentEvents)): ?>
                           <p class="text-muted text-center py-3">No events found</p>
                        <?php else: ?>
                           <div class="table-responsive">
                              <table class="table table-hover align-middle">
                                 <thead class="table-light">
                                    <tr>
                                       <th>Event Name</th>
                                       <th>Date</th>
                                       <th>Location</th>
                                       <th>Status</th>
                                       <th>Attendance</th>
                                       <th>Actions</th>
                                    </tr>
                                 </thead>
                                 <tbody>
                                    <?php foreach ($recentEvents as $event): ?>
                                       <tr>
                                          <td>
                                             <div class="fw-bold"><?= htmlspecialchars($event['event_name']) ?></div>
                                          </td>
                                          <td><?= date('M d, Y', strtotime($event['event_date'])) ?></td>
                                          <td><?= htmlspecialchars($event['location']) ?></td>
                                          <td>
                                             <?php
                                             $eventDate = $event['event_date'];
                                             $startTime = $event['start_time'];
                                             $endTime = $event['end_time'];
                                             $startDateTime = "$eventDate $startTime";
                                             $endDateTime = "$eventDate $endTime";
                                             $currentDateTime = date('Y-m-d H:i:s');

                                             if ($currentDateTime < $startDateTime) {
                                                echo '<span class="badge bg-primary">Upcoming</span>';
                                             } else if ($currentDateTime >= $startDateTime && $currentDateTime <= $endDateTime) {
                                                echo '<span class="badge bg-success">In Progress</span>';
                                             } else {
                                                echo '<span class="badge bg-secondary">Past</span>';
                                             }
                                             ?>
                                          </td>
                                          <td>
                                             <div class="d-flex align-items-center">
                                                <div class="me-2"><?= $event['attendance'] ?>/<?= $event['registrations'] ?></div>
                                                <?php if ($event['registrations'] > 0): ?>
                                                   <div class="progress flex-grow-1" style="height: 6px;">
                                                      <div class="progress-bar" role="progressbar"
                                                         style="width: <?= min(100, ($event['attendance'] / $event['registrations']) * 100) ?>%"
                                                         aria-valuenow="<?= $event['attendance'] ?>"
                                                         aria-valuemin="0"
                                                         aria-valuemax="<?= $event['registrations'] ?>">
                                                      </div>
                                                   </div>
                                                <?php endif; ?>
                                             </div>
                                          </td>
                                          <td>
                                             <a href="view-event.php?id=<?= $event['event_id'] ?>" class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-eye"></i>
                                             </a>
                                          </td>
                                       </tr>
                                    <?php endforeach; ?>
                                 </tbody>
                              </table>
                           </div>
                        <?php endif; ?>
                     </div>
                  </div>
               </div>

               <!-- Right Column -->
               <div class="col-lg-4">
                  <!-- Quick Actions Card -->
                  <div class="card mb-4">
                     <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="bi bi-lightning-charge me-2"></i>Quick Actions</h5>
                     </div>
                     <div class="card-body">
                        <div class="d-grid gap-2">
                           <a href="create-event.php" class="btn btn-primary">
                              <i class="bi bi-plus-circle me-2"></i> Create New Event
                           </a>
                           <a href="qr-scanner.php" class="btn btn-success">
                              <i class="bi bi-qr-code-scan me-2"></i> Scan QR Code
                           </a>
                           <a href="reports.php" class="btn btn-info text-white">
                              <i class="bi bi-file-earmark-bar-graph me-2"></i> Generate Report
                           </a>
                           <a href="attendance.php" class="btn btn-outline-primary">
                              <i class="bi bi-check2-square me-2"></i> Manage Attendance
                           </a>
                        </div>
                     </div>
                  </div>

                  <!-- Recent Activity Feed -->
                  <div class="card mb-4">
                     <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="bi bi-activity me-2"></i>Recent Activity</h5>
                     </div>
                     <div class="card-body p-0">
                        <?php if (empty($recentCheckIns)): ?>
                           <div class="p-4 text-center text-muted">
                              No recent activity recorded.
                           </div>
                        <?php else: ?>
                           <div class="list-group list-group-flush">
                              <?php foreach ($recentCheckIns as $checkIn): ?>
                                 <div class="activity-item p-3">
                                    <div class="d-flex">
                                       <div class="me-3 text-center">
                                          <div class="bg-light rounded-circle p-2">
                                             <i class="bi bi-person-check text-primary"></i>
                                          </div>
                                       </div>
                                       <div>
                                          <p class="mb-0">
                                             <strong><?= htmlspecialchars($checkIn['first_name'] . ' ' . $checkIn['last_name']) ?></strong>
                                             checked in to
                                             <a href="view-event.php?id=<?= $checkIn['event_id'] ?>" class="text-decoration-none">
                                                <?= htmlspecialchars($checkIn['event_name']) ?>
                                             </a>
                                          </p>
                                          <div class="activity-time">
                                             <?= date('M d, g:i A', strtotime($checkIn['check_in_time'])) ?>
                                          </div>
                                       </div>
                                    </div>
                                 </div>
                              <?php endforeach; ?>
                           </div>
                        <?php endif; ?>
                     </div>
                  </div>

                  <!-- Attendance Summary Card -->
                  <div class="card mb-4">
                     <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="bi bi-bar-chart me-2"></i>Attendance Summary</h5>
                     </div>
                     <div class="card-body">
                        <div class="mb-3">
                           <div class="d-flex justify-content-between align-items-center mb-1">
                              <span>Today's Attendance</span>
                              <span class="badge bg-primary"><?= $stats['todayAttendance'] ?></span>
                           </div>
                           <div class="progress" style="height: 8px;">
                              <div class="progress-bar bg-primary" role="progressbar" style="width: 100%" aria-valuenow="100" aria-valuemin="0" aria-valuemax="100"></div>
                           </div>
                        </div>

                        <div class="mb-3">
                           <div class="d-flex justify-content-between align-items-center mb-1">
                              <span>Total Attendance</span>
                              <span class="badge bg-info"><?= $stats['totalAttendance'] ?></span>
                           </div>
                           <div class="progress" style="height: 8px;">
                              <div class="progress-bar bg-info" role="progressbar" style="width: 100%" aria-valuenow="100" aria-valuemin="0" aria-valuemax="100"></div>
                           </div>
                        </div>

                        <div class="mb-3">
                           <div class="d-flex justify-content-between align-items-center mb-1">
                              <span>Ongoing Events</span>
                              <span class="badge bg-success"><?= $stats['ongoingEvents'] ?></span>
                           </div>
                           <div class="progress" style="height: 8px;">
                              <div class="progress-bar bg-success" role="progressbar" style="width: 100%" aria-valuenow="100" aria-valuemin="0" aria-valuemax="100"></div>
                           </div>
                        </div>

                        <div class="text-center mt-3">
                           <a href="reports.php" class="btn btn-sm btn-outline-secondary">
                              <i class="bi bi-graph-up me-1"></i>Detailed Reports
                           </a>
                        </div>
                     </div>
                  </div>

                  <!-- System Notifications -->
                  <?php if (!empty($systemNotifications)): ?>
                     <div class="card mb-4">
                        <div class="card-header bg-warning text-dark">
                           <h5 class="mb-0"><i class="bi bi-bell me-2"></i>Notifications</h5>
                        </div>
                        <div class="card-body p-0">
                           <div class="list-group list-group-flush">
                              <?php foreach ($systemNotifications as $notification): ?>
                                 <div class="list-group-item p-3">
                                    <h6 class="mb-1">
                                       <i class="bi bi-exclamation-triangle me-2 text-warning"></i>
                                       Low Attendance
                                    </h6>
                                    <p class="mb-1 small">
                                       <?= htmlspecialchars($notification['event_name']) ?> (<?= date('M d', strtotime($notification['event_date'])) ?>)
                                       had <?= $notification['attendance'] ?> out of <?= $notification['registrations'] ?> registered attendees
                                       (<?= round(($notification['attendance'] / $notification['registrations']) * 100) ?>%).
                                    </p>
                                    <div class="mt-2">
                                       <a href="view-event.php?id=<?= $notification['event_id'] ?>" class="btn btn-sm btn-outline-warning">
                                          <i class="bi bi-eye me-1"></i>Review Event
                                       </a>
                                    </div>
                                 </div>
                              <?php endforeach; ?>
                           </div>
                        </div>
                     </div>
                  <?php endif; ?>

                  <!-- System Information -->
                  <div class="card">
                     <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>System Info</h5>
                     </div>
                     <div class="card-body">
                        <ul class="list-group list-group-flush">
                           <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                              <span>PHP Version</span>
                              <span class="badge bg-secondary"><?= phpversion() ?></span>
                           </li>
                           <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                              <span>Server Time (PHT)</span>
                              <span class="badge bg-secondary"><?= date('Y-m-d H:i:s') ?></span>
                           </li>
                           <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                              <span>Last Login</span>
                              <span class="badge bg-secondary">
                                 <?= isset($_SESSION['login_time']) ? date('Y-m-d H:i', $_SESSION['login_time']) : 'N/A' ?>
                              </span>
                           </li>
                        </ul>
                        <div class="mt-3 d-grid">
                           <a href="logout.php" class="btn btn-danger">
                              <i class="bi bi-box-arrow-right me-2"></i>Direct Logout
                           </a>
                        </div>
                     </div>
                  </div>
               </div>
            </div>

            <!-- Admin Tools Card -->
            <div class="card mb-4">
               <div class="card-header bg-white d-flex justify-content-between align-items-center">
                  <h5 class="mb-0"><i class="bi bi-tools me-2"></i>Admin Tools</h5>
               </div>
               <div class="card-body">
                  <div class="row g-3">
                     <div class="col-md-4">
                        <div class="card h-100">
                           <div class="card-body">
                              <h6 class="card-title"><i class="bi bi-calendar-check me-2"></i>Process Attendance</h6>
                              <p class="card-text small text-muted">
                                 Mark students as absent for past events if they didn't scan their QR codes during event hours.
                                 Also updates late arrivals and early departures.
                              </p>
                              <div class="d-flex gap-2">
                                 <a href="mark-absent.php" class="btn btn-primary btn-sm">
                                    <i class="bi bi-check2-all me-1"></i>Process All Events
                                 </a>
                                 <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#selectEventModal">
                                    <i class="bi bi-list-check me-1"></i>Select Event
                                 </button>
                              </div>
                           </div>
                        </div>
                     </div>
                     <div class="col-md-4">
                        <div class="card h-100">
                           <div class="card-body">
                              <h6 class="card-title"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Export Data</h6>
                              <p class="card-text small text-muted">
                                 Generate attendance reports, export data in CSV/Excel format, and analyze attendance patterns.
                              </p>
                              <a href="reports.php" class="btn btn-outline-primary btn-sm">
                                 <i class="bi bi-file-earmark-arrow-down me-1"></i>Export Reports
                              </a>
                           </div>
                        </div>
                     </div>
                     <div class="col-md-4">
                        <div class="card h-100">
                           <div class="card-body">
                              <h6 class="card-title"><i class="bi bi-send me-2"></i>Notification Center</h6>
                              <p class="card-text small text-muted">
                                 Send reminders to registered students, verify attendance, or contact absent students.
                              </p>
                              <a href="notifications.php" class="btn btn-outline-primary btn-sm">
                                 <i class="bi bi-bell me-1"></i>Send Notifications
                              </a>
                           </div>
                        </div>
                     </div>
                  </div>
               </div>
            </div>
         </div>
      </div>
   </div>

   <!-- Select Event for Processing Modal -->
   <div class="modal fade" id="selectEventModal" tabindex="-1" aria-labelledby="selectEventModalLabel" aria-hidden="true">
      <div class="modal-dialog">
         <div class="modal-content">
            <div class="modal-header">
               <h5 class="modal-title" id="selectEventModalLabel">Select Event to Process</h5>
               <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
               <form action="mark-absent.php" method="get">
                  <div class="mb-3">
                     <label for="event_id" class="form-label">Choose Event:</label>
                     <select name="event_id" id="event_id" class="form-select" required>
                        <option value="">Select an event...</option>
                        <?php
                        // Get completed events
                        $pastEventsSql = "SELECT event_id, event_name, event_date, start_time, end_time 
                                         FROM events 
                                         WHERE CONCAT(event_date, ' ', end_time) <= NOW()
                                         ORDER BY event_date DESC, end_time DESC
                                         LIMIT 20";
                        $pastEvents = $conn->query($pastEventsSql);
                        if ($pastEvents && $pastEvents->num_rows > 0) {
                           while ($event = $pastEvents->fetch_assoc()) {
                              echo '<option value="' . $event['event_id'] . '">';
                              echo htmlspecialchars($event['event_name']) . ' - ';
                              echo date('M d, Y', strtotime($event['event_date'])) . ' ';
                              echo date('g:i A', strtotime($event['start_time'])) . '-' . date('g:i A', strtotime($event['end_time']));
                              echo '</option>';
                           }
                        }
                        ?>
                     </select>
                     <div class="form-text">
                        Only completed events are shown. Processing will update absent, late, and early departure statuses.
                     </div>
                  </div>
                  <button type="submit" class="btn btn-primary">
                     <i class="bi bi-arrow-right-circle me-1"></i>Process Selected Event
                  </button>
               </form>
            </div>
         </div>
      </div>
   </div>

   <!-- Include Logout Confirmation Modal -->
   <?php include '../includes/logout-modal.php'; ?>

   <!-- Event Details Modal -->
   <div class="modal fade" id="eventDetailsModal" tabindex="-1" aria-labelledby="eventDetailsModalLabel" aria-hidden="true">
      <div class="modal-dialog">
         <div class="modal-content">
            <div class="modal-header">
               <h5 class="modal-title" id="eventDetailsModalLabel">Event Details</h5>
               <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
               <div class="d-flex justify-content-center mb-3">
                  <div class="spinner-border text-primary" role="status">
                     <span class="visually-hidden">Loading...</span>
                  </div>
               </div>
               <div id="eventDetails" style="display: none;">
                  <h5 id="eventTitle" class="mb-3"></h5>
                  <div class="mb-3">
                     <i class="bi bi-calendar-date me-2"></i><span id="eventDate"></span>
                  </div>
                  <div class="mb-3">
                     <i class="bi bi-clock me-2"></i><span id="eventTime"></span>
                  </div>
                  <div class="mb-3">
                     <i class="bi bi-geo-alt me-2"></i><span id="eventLocation"></span>
                  </div>
                  <div class="mb-3" id="eventDescriptionContainer">
                     <i class="bi bi-info-circle me-2"></i><span id="eventDescription"></span>
                  </div>
               </div>
            </div>
            <div class="modal-footer">
               <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
               <a href="#" id="viewEventBtn" class="btn btn-primary">View Details</a>
               <a href="#" id="scanQrBtn" class="btn btn-success">Scan QR</a>
            </div>
         </div>
      </div>
   </div>

   <!-- Bootstrap JS Bundle -->
   <script src="../bootstrap/js/bootstrap.bundle.min.js"></script>

   <!-- FullCalendar JS -->
   <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>

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

      // Initialize FullCalendar
      document.addEventListener('DOMContentLoaded', function() {
         const calendarEl = document.getElementById('calendar');
         const modal = new bootstrap.Modal(document.getElementById('eventDetailsModal'));

         const calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            height: 'auto',
            headerToolbar: {
               left: 'prev,next today',
               center: 'title',
               right: 'dayGridMonth,listWeek'
            },
            buttonText: {
               today: 'Today',
               month: 'Month',
               list: 'List'
            },
            themeSystem: 'bootstrap5',
            events: 'get-events.php',
            eventClick: function(info) {
               // Show loading spinner
               document.querySelector('#eventDetailsModal .spinner-border').style.display = 'block';
               document.getElementById('eventDetails').style.display = 'none';

               // Fetch event details
               fetch('get-event-details.php?id=' + info.event.id)
                  .then(response => response.json())
                  .then(data => {
                     // Hide spinner, show details
                     document.querySelector('#eventDetailsModal .spinner-border').style.display = 'none';
                     document.getElementById('eventDetails').style.display = 'block';

                     // Populate modal with event details
                     document.getElementById('eventTitle').textContent = data.event_name;
                     document.getElementById('eventDate').textContent = data.formatted_date;
                     document.getElementById('eventTime').textContent = data.formatted_time;
                     document.getElementById('eventLocation').textContent = data.location;

                     if (data.description) {
                        document.getElementById('eventDescriptionContainer').style.display = 'block';
                        document.getElementById('eventDescription').textContent = data.description;
                     } else {
                        document.getElementById('eventDescriptionContainer').style.display = 'none';
                     }

                     // Setup buttons
                     document.getElementById('viewEventBtn').href = 'view-event.php?id=' + data.event_id;
                     document.getElementById('scanQrBtn').href = 'qr-scanner.php?event_id=' + data.event_id;

                     // Show/hide scan button based on event status
                     if (data.status === 'ongoing') {
                        document.getElementById('scanQrBtn').style.display = 'inline-block';
                     } else {
                        document.getElementById('scanQrBtn').style.display = 'none';
                     }

                     // Show modal
                     modal.show();
                  })
                  .catch(error => {
                     console.error('Error fetching event details:', error);
                  });

               // Prevent default action
               info.jsEvent.preventDefault();
            },
            eventTimeFormat: {
               hour: 'numeric',
               minute: '2-digit',
               meridiem: 'short'
            },
            dayMaxEvents: true
         });

         calendar.render();
      });

      // Auto refresh the page every 5 minutes to keep data current
      setTimeout(function() {
         window.location.reload();
      }, 5 * 60 * 1000);
   </script>
</body>

</html>