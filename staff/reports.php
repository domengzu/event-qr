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
require_once 'includes/report-helpers.php'; // Add this line

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

// Define report types
$reportTypes = [
   'event_attendance' => 'Event Attendance',
   'student_participation' => 'Student Participation',
   'event_summary' => 'Event Summary',
   'attendance_trends' => 'Attendance Trends'
];

// Get all events for dropdown
$events = [];
$eventsSql = "SELECT event_id, event_name, event_date, start_time FROM events ORDER BY event_date DESC";
$result = $conn->query($eventsSql);
if ($result) {
   while ($row = $result->fetch_assoc()) {
      $events[] = $row;
   }
}

// Get all courses for filtering
$courses = [];
$coursesSql = "SELECT DISTINCT course FROM students ORDER BY course";
$result = $conn->query($coursesSql);
if ($result) {
   while ($row = $result->fetch_assoc()) {
      if (!empty($row['course'])) {
         $courses[] = $row['course'];
      }
   }
}

// Process report generation request
$reportData = [];
$chartData = [];
$reportTitle = '';
$showTable = false;
$showChart = false;
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_report'])) {
   $reportType = $_POST['report_type'] ?? '';
   $eventId = isset($_POST['event_id']) ? (int)$_POST['event_id'] : 0;
   $startDate = $_POST['start_date'] ?? '';
   $endDate = $_POST['end_date'] ?? '';
   $course = $_POST['course'] ?? '';
   $yearLevel = $_POST['year_level'] ?? '';

   // Validate inputs
   if (empty($reportType)) {
      $errorMessage = 'Please select a report type';
   } else {
      $showTable = true;

      switch ($reportType) {
         case 'event_attendance':
            // Event Attendance Report
            if (empty($eventId)) {
               $errorMessage = 'Please select an event';
               $showTable = false;
            } else {
               $reportTitle = "Attendance Report";

               // Get event details
               $eventSql = "SELECT event_name, event_date FROM events WHERE event_id = ?";
               $stmt = $conn->prepare($eventSql);
               $stmt->bind_param("i", $eventId);
               $stmt->execute();
               $eventResult = $stmt->get_result();
               if ($eventResult && $eventRow = $eventResult->fetch_assoc()) {
                  $reportTitle .= " for " . $eventRow['event_name'] . " (" . date('M d, Y', strtotime($eventRow['event_date'])) . ")";
               }

               // Build query with filters
               $query = "SELECT s.student_id, s.first_name, s.last_name, s.course, s.year_level, 
                              a.check_in_time, a.status
                              FROM students s
                              LEFT JOIN attendance a ON s.student_id = a.student_id AND a.event_id = ?
                              LEFT JOIN event_registrations r ON s.student_id = r.student_id AND r.event_id = ?
                              WHERE r.registration_id IS NOT NULL";

               $params = [$eventId, $eventId];
               $types = "ii";

               if (!empty($course)) {
                  $query .= " AND s.course = ?";
                  $params[] = $course;
                  $types .= "s";
               }

               if (!empty($yearLevel)) {
                  $query .= " AND s.year_level = ?";
                  $params[] = $yearLevel;
                  $types .= "s";
               }

               $query .= " ORDER BY s.last_name, s.first_name";

               $stmt = $conn->prepare($query);
               $stmt->bind_param($types, ...$params);
               $stmt->execute();
               $result = $stmt->get_result();

               if ($result) {
                  while ($row = $result->fetch_assoc()) {
                     $reportData[] = $row;
                  }
               }

               // Prepare chart data: attendance status counts
               $chartData = [
                  'present' => 0,
                  'late' => 0,
                  'absent' => 0,
                  'left_early' => 0
               ];

               foreach ($reportData as $row) {
                  if (empty($row['status'])) {
                     $chartData['absent']++;
                  } elseif ($row['status'] == 'present') {
                     $chartData['present']++;
                  } elseif ($row['status'] == 'late') {
                     $chartData['late']++;
                  } elseif ($row['status'] == 'left early') {
                     $chartData['left_early']++;
                  }
               }

               $showChart = true;
            }
            break;

         case 'student_participation':
            // Student Participation Report
            $reportTitle = "Student Participation Report";

            // Add date filters if provided
            $dateFilter = "";
            $params = [];
            $types = "";

            if (!empty($startDate) && !empty($endDate)) {
               $dateFilter = " AND e.event_date BETWEEN ? AND ?";
               $params[] = $startDate;
               $params[] = $endDate;
               $types .= "ss";
               $reportTitle .= " from " . date('M d, Y', strtotime($startDate)) . " to " . date('M d, Y', strtotime($endDate));
            }

            // Add course filter if provided
            if (!empty($course)) {
               $courseFilter = " AND s.course = ?";
               $params[] = $course;
               $types .= "s";
               $reportTitle .= " for " . $course;
            } else {
               $courseFilter = "";
            }

            // Add year level filter if provided
            if (!empty($yearLevel)) {
               $yearFilter = " AND s.year_level = ?";
               $params[] = $yearLevel;
               $types .= "s";
               $reportTitle .= " - Year " . $yearLevel;
            } else {
               $yearFilter = "";
            }

            $query = "SELECT s.student_id, s.first_name, s.last_name, s.course, s.year_level,
                          COUNT(DISTINCT r.event_id) as registered_events,
                          COUNT(DISTINCT a.event_id) as attended_events,
                          ROUND((COUNT(DISTINCT a.event_id) / COUNT(DISTINCT r.event_id)) * 100, 1) as attendance_rate
                          FROM students s
                          LEFT JOIN event_registrations r ON s.student_id = r.student_id
                          LEFT JOIN events e ON r.event_id = e.event_id {$dateFilter}
                          LEFT JOIN attendance a ON s.student_id = a.student_id AND a.event_id = r.event_id
                          WHERE 1=1 {$courseFilter} {$yearFilter}
                          GROUP BY s.student_id
                          ORDER BY attendance_rate DESC, registered_events DESC";

            if (!empty($types)) {
               $stmt = $conn->prepare($query);
               $stmt->bind_param($types, ...$params);
               $stmt->execute();
               $result = $stmt->get_result();
            } else {
               $result = $conn->query($query);
            }

            if ($result) {
               while ($row = $result->fetch_assoc()) {
                  $reportData[] = $row;
               }
            }

            // Prepare chart data: student attendance rates by course
            if (!empty($reportData)) {
               $courseStats = [];
               foreach ($reportData as $row) {
                  $course = $row['course'];
                  if (!isset($courseStats[$course])) {
                     $courseStats[$course] = [
                        'count' => 0,
                        'total_rate' => 0
                     ];
                  }
                  $courseStats[$course]['count']++;
                  $courseStats[$course]['total_rate'] += floatval($row['attendance_rate']);
               }

               foreach ($courseStats as $course => $stats) {
                  $chartData[] = [
                     'course' => $course,
                     'average_rate' => round($stats['total_rate'] / $stats['count'], 1)
                  ];
               }

               usort($chartData, function ($a, $b) {
                  return $b['average_rate'] <=> $a['average_rate'];
               });

               $showChart = true;
            }
            break;

         case 'event_summary':
            // Event Summary Report
            $reportTitle = "Event Summary Report";

            // Add date filters if provided
            $dateFilter = "";
            $params = [];
            $types = "";

            if (!empty($startDate) && !empty($endDate)) {
               $dateFilter = " WHERE e.event_date BETWEEN ? AND ?";
               $params[] = $startDate;
               $params[] = $endDate;
               $types .= "ss";
               $reportTitle .= " from " . date('M d, Y', strtotime($startDate)) . " to " . date('M d, Y', strtotime($endDate));
            }

            $query = "SELECT e.event_id, e.event_name, e.event_date, e.location,
                          COUNT(DISTINCT r.student_id) as registered_students,
                          COUNT(DISTINCT a.student_id) as attended_students,
                          ROUND((COUNT(DISTINCT a.student_id) / COUNT(DISTINCT r.student_id)) * 100, 1) as attendance_rate
                          FROM events e
                          LEFT JOIN event_registrations r ON e.event_id = r.event_id
                          LEFT JOIN attendance a ON e.event_id = a.event_id 
                          {$dateFilter}
                          GROUP BY e.event_id
                          ORDER BY e.event_date DESC";

            if (!empty($types)) {
               $stmt = $conn->prepare($query);
               $stmt->bind_param($types, ...$params);
               $stmt->execute();
               $result = $stmt->get_result();
            } else {
               $result = $conn->query($query);
            }

            if ($result) {
               while ($row = $result->fetch_assoc()) {
                  $reportData[] = $row;
               }
            }

            // Prepare chart data: top 5 events by attendance
            if (!empty($reportData)) {
               $eventStats = array_slice($reportData, 0, 10);  // Get top 10 events

               foreach ($eventStats as $event) {
                  $chartData[] = [
                     'event' => $event['event_name'],
                     'attendance_rate' => $event['attendance_rate'],
                     'registered' => $event['registered_students'],
                     'attended' => $event['attended_students']
                  ];
               }

               $showChart = true;
            }
            break;

         case 'attendance_trends':
            // Attendance Trends Report (by date)
            $reportTitle = "Attendance Trends Report";

            // Add date filters if provided
            $dateFilter = "";
            $params = [];
            $types = "";

            if (!empty($startDate) && !empty($endDate)) {
               $dateFilter = " WHERE e.event_date BETWEEN ? AND ?";
               $params[] = $startDate;
               $params[] = $endDate;
               $types .= "ss";
               $reportTitle .= " from " . date('M d, Y', strtotime($startDate)) . " to " . date('M d, Y', strtotime($endDate));
            }

            $query = "SELECT e.event_date,
                          COUNT(DISTINCT e.event_id) as events_count,
                          COUNT(DISTINCT r.student_id) as registered_students,
                          COUNT(DISTINCT a.student_id) as attended_students,
                          ROUND((COUNT(DISTINCT a.student_id) / 
                                CASE WHEN COUNT(DISTINCT r.student_id) = 0 THEN 1 
                                ELSE COUNT(DISTINCT r.student_id) END) * 100, 1) as attendance_rate
                          FROM events e
                          LEFT JOIN event_registrations r ON e.event_id = r.event_id
                          LEFT JOIN attendance a ON e.event_id = a.event_id 
                          {$dateFilter}
                          GROUP BY e.event_date
                          ORDER BY e.event_date";

            if (!empty($types)) {
               $stmt = $conn->prepare($query);
               $stmt->bind_param($types, ...$params);
               $stmt->execute();
               $result = $stmt->get_result();
            } else {
               $result = $conn->query($query);
            }

            if ($result) {
               while ($row = $result->fetch_assoc()) {
                  $reportData[] = $row;
               }
            }

            // Prepare chart data: attendance trends over time
            if (!empty($reportData)) {
               foreach ($reportData as $row) {
                  $chartData[] = [
                     'date' => date('M d', strtotime($row['event_date'])),
                     'attendance_rate' => $row['attendance_rate'],
                     'events' => $row['events_count']
                  ];
               }

               $showChart = true;
            }
            break;
      }
   }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Reports - EventQR</title>
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

      /* Report filters */
      .report-filters {
         background-color: #f8f9fa;
         border-radius: 10px;
         padding: 20px;
         margin-bottom: 20px;
         border: 1px solid #e9ecef;
         box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
         transition: all 0.3s ease;
      }

      .report-filters:hover {
         box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
      }

      .report-card {
         border-radius: 10px;
         overflow: hidden;
         margin-bottom: 30px;
         box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
         transition: all 0.3s ease;
      }

      .report-card:hover {
         box-shadow: 0 6px 18px rgba(0, 0, 0, 0.12);
      }

      .report-card .card-header {
         background: linear-gradient(45deg, #212529, #343a40);
         color: white;
         border-bottom: 3px solid #F3C623;
         padding: 15px 20px;
      }

      .chart-container {
         position: relative;
         height: 400px;
         margin-bottom: 25px;
         background: rgba(255, 255, 255, 0.7);
         border-radius: 8px;
         padding: 15px;
         box-shadow: inset 0 0 10px rgba(0, 0, 0, 0.05);
      }

      /* Table styles */
      .report-table {
         font-size: 0.9rem;
         border-collapse: separate;
         border-spacing: 0;
      }

      .report-table th {
         background-color: #f8f9fa;
         position: sticky;
         top: 0;
         z-index: 10;
         box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
         white-space: nowrap;
      }

      .report-table tbody tr {
         transition: all 0.2s ease;
      }

      .report-table tbody tr:hover {
         background-color: rgba(13, 110, 253, 0.04);
      }

      /* Export button */
      .export-btn {
         border-radius: 50px;
         padding: 8px 20px;
         border: none;
         transition: all 0.3s ease;
         margin-left: 10px;
      }

      .export-btn:hover {
         transform: translateY(-2px);
         box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
      }

      /* Filter toggles */
      .filter-toggle {
         cursor: pointer;
         padding: 10px;
         background: #fff;
         border-radius: 8px;
         margin-bottom: 15px;
         box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
         border-left: 4px solid #6c757d;
         transition: all 0.3s ease;
      }

      .filter-toggle:hover {
         border-left-color: #0d6efd;
         background: #f8f9fa;
      }

      .filter-toggle i {
         transition: transform 0.3s ease;
      }

      .filter-toggle.collapsed i {
         transform: rotate(-90deg);
      }

      /* Form inputs */
      .form-control,
      .form-select {
         border-radius: 6px;
         box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.075);
         transition: all 0.2s ease-in-out;
      }

      .form-control:focus,
      .form-select:focus {
         border-color: #86b7fe;
         box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25), inset 0 1px 2px rgba(0, 0, 0, 0.075);
      }

      /* Enhanced switches */
      .form-switch .form-check-input {
         height: 1.5em;
         width: 2.75em;
      }

      .form-switch .form-check-input:checked {
         background-color: #198754;
         border-color: #198754;
      }

      /* Report type selector */
      .report-type-selector {
         display: flex;
         flex-wrap: wrap;
         gap: 15px;
         margin-bottom: 20px;
      }

      .report-type-card {
         flex: 1;
         min-width: 200px;
         border-radius: 10px;
         border: 2px solid #dee2e6;
         padding: 15px;
         text-align: center;
         cursor: pointer;
         transition: all 0.3s ease;
      }

      .report-type-card:hover {
         border-color: #adb5bd;
         background-color: #f8f9fa;
      }

      .report-type-card.active {
         border-color: #0d6efd;
         background-color: rgba(13, 110, 253, 0.1);
      }

      .report-type-card i {
         font-size: 2rem;
         margin-bottom: 10px;
         color: #6c757d;
      }

      .report-type-card.active i {
         color: #0d6efd;
      }

      .report-type-card h6 {
         margin-bottom: 5px;
      }

      /* Report result */
      .report-summary {
         background-color: #f8f9fa;
         border-radius: 8px;
         padding: 15px;
         margin-bottom: 20px;
         border-left: 4px solid #0d6efd;
      }

      .summary-item {
         text-align: center;
         padding: 10px;
      }

      .summary-item .value {
         font-size: 1.8rem;
         font-weight: 700;
         color: #0d6efd;
      }

      .summary-item .label {
         color: #6c757d;
         font-size: 0.9rem;
      }

      /* Loading spinner */
      .loading-spinner {
         display: none;
         position: fixed;
         top: 0;
         left: 0;
         width: 100%;
         height: 100%;
         background-color: rgba(0, 0, 0, 0.5);
         z-index: 9999;
         align-items: center;
         justify-content: center;
      }

      .spinner-container {
         background: white;
         padding: 20px;
         border-radius: 10px;
         box-shadow: 0 0 20px rgba(0, 0, 0, 0.2);
         text-align: center;
      }

      .spinner-border {
         width: 3rem;
         height: 3rem;
         margin-bottom: 10px;
      }

      /* No data placeholders */
      .no-data-placeholder {
         text-align: center;
         padding: 40px 20px;
      }

      .no-data-placeholder i {
         font-size: 3rem;
         color: #dee2e6;
         margin-bottom: 15px;
      }

      .no-data-placeholder h5 {
         color: #6c757d;
         margin-bottom: 10px;
      }

      .no-data-placeholder p {
         color: #adb5bd;
         max-width: 300px;
         margin: 0 auto;
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
   <!-- Loading spinner -->
   <div class="loading-spinner" id="loadingSpinner">
      <div class="spinner-container">
         <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading...</span>
         </div>
         <p class="mb-0 mt-2">Generating report...</p>
      </div>
   </div>

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
               <a href="qr-scanner.php">
                  <i class="bi bi-qr-code-scan"></i> QR Scanner
               </a>
            </li>
            <li>
               <a href="reports.php" class="active">
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

         <!-- Reports Content -->
         <div class="container-fluid dashboard-container">
            <div class="d-flex justify-content-between align-items-center mb-4">
               <div>
                  <h1 class="mb-1">Reports</h1>
                  <p class="text-muted">Generate detailed reports and analytics for your events</p>
               </div>
               <div>
                  <a href="dashboard.php" class="btn btn-outline-secondary">
                     <i class="bi bi-arrow-left me-2"></i>Back to Dashboard
                  </a>
               </div>
            </div>

            <!-- Report Filters -->
            <div class="card report-filters mb-4">
               <div class="filter-toggle" data-bs-toggle="collapse" data-bs-target="#reportFilters" aria-expanded="true">
                  <div class="d-flex justify-content-between align-items-center">
                     <h5 class="mb-0"><i class="bi bi-funnel me-2"></i>Report Filters</h5>
                     <i class="bi bi-chevron-down"></i>
                  </div>
               </div>

               <div class="collapse show" id="reportFilters">
                  <form id="reportForm" method="POST">
                     <!-- Report Type Selector -->
                     <div class="report-type-selector mb-3">
                        <?php foreach ($reportTypes as $value => $label): ?>
                           <div class="report-type-card" data-report-type="<?= $value ?>">
                              <i class="bi <?= getReportTypeIcon($value) ?>"></i>
                              <h6><?= $label ?></h6>
                              <p class="small text-muted mb-0"><?= getReportTypeDescription($value) ?></p>
                              <input type="radio" name="report_type" value="<?= $value ?>" class="d-none">
                           </div>
                        <?php endforeach; ?>
                     </div>

                     <div class="row g-3">
                        <div class="col-md-6 event-filter">
                           <label for="event_id" class="form-label">Event</label>
                           <select class="form-select" id="event_id" name="event_id">
                              <option value="">-- Select Event --</option>
                              <?php foreach ($events as $event): ?>
                                 <option value="<?= $event['event_id'] ?>">
                                    <?= htmlspecialchars($event['event_name']) ?> (<?= date('M d, Y', strtotime($event['event_date'])) ?>)
                                 </option>
                              <?php endforeach; ?>
                           </select>
                        </div>

                        <div class="col-md-3 date-filter">
                           <label for="start_date" class="form-label">Start Date</label>
                           <input type="date" class="form-control" id="start_date" name="start_date">
                        </div>

                        <div class="col-md-3 date-filter">
                           <label for="end_date" class="form-label">End Date</label>
                           <input type="date" class="form-control" id="end_date" name="end_date"
                              value="<?= date('Y-m-d') ?>">
                        </div>

                        <div class="col-md-3">
                           <label for="course" class="form-label">Course</label>
                           <select class="form-select" id="course" name="course">
                              <option value="">All Courses</option>
                              <?php foreach ($courses as $c): ?>
                                 <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                              <?php endforeach; ?>
                           </select>
                        </div>

                        <div class="col-md-3">
                           <label for="year_level" class="form-label">Year Level</label>
                           <select class="form-select" id="year_level" name="year_level">
                              <option value="">All Years</option>
                              <option value="1">1st Year</option>
                              <option value="2">2nd Year</option>
                              <option value="3">3rd Year</option>
                              <option value="4">4th Year</option>
                              <option value="5">5th Year</option>
                           </select>
                        </div>

                        <div class="col-md-6">
                           <label class="form-label">Advanced Options</label>
                           <div class="d-flex flex-wrap gap-4">
                              <div class="form-check form-switch">
                                 <input class="form-check-input" type="checkbox" id="includeCharts" name="include_charts" checked>
                                 <label class="form-check-label" for="includeCharts">Include Charts</label>
                              </div>
                              <div class="form-check form-switch">
                                 <input class="form-check-input" type="checkbox" id="detailedStats" name="detailed_stats">
                                 <label class="form-check-label" for="detailedStats">Detailed Statistics</label>
                              </div>
                           </div>
                        </div>

                        <div class="col-12 d-flex justify-content-end">
                           <button type="reset" class="btn btn-outline-secondary me-2">
                              <i class="bi bi-arrow-counterclockwise me-1"></i>Reset
                           </button>
                           <button type="submit" name="generate_report" class="btn btn-primary" id="generateReportBtn">
                              <i class="bi bi-file-earmark-bar-graph me-2"></i>Generate Report
                           </button>
                        </div>
                     </div>
                  </form>
               </div>
            </div>

            <?php if (!empty($errorMessage)): ?>
               <div class="alert alert-danger alert-dismissible fade show" role="alert">
                  <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= $errorMessage ?>
                  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
               </div>
            <?php endif; ?>

            <?php if ($showTable && !empty($reportData)): ?>
               <!-- Report Result -->
               <div class="card report-card">
                  <div class="card-header d-flex justify-content-between align-items-center">
                     <h5 class="mb-0"><?= $reportTitle ?></h5>
                     <div class="btn-group report-actions">
                        <button type="button" class="btn btn-sm btn-light" id="printReport" title="Print Report">
                           <i class="bi bi-printer"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-success export-btn" id="exportToExcel">
                           <i class="bi bi-file-excel me-2"></i>Excel
                        </button>
                        <button type="button" class="btn btn-sm btn-danger export-btn" id="exportToPdf">
                           <i class="bi bi-file-pdf me-2"></i>PDF
                        </button>
                     </div>
                  </div>

                  <div class="card-body">
                     <!-- Report summary statistics -->
                     <?php if ($showSummaryStats ?? true): ?>
                        <div class="report-summary mb-4">
                           <div class="row">
                              <?php
                              // Display summary stats based on report type
                              if ($reportType === 'event_attendance'):
                                 $totalRegistered = array_sum([$chartData['present'], $chartData['late'], $chartData['absent'], $chartData['left_early']]);
                                 $presentRate = $totalRegistered > 0 ? round(($chartData['present'] / $totalRegistered) * 100) : 0;
                              ?>
                                 <div class="col-md-3 summary-item">
                                    <div class="value"><?= $totalRegistered ?></div>
                                    <div class="label">Total Registered</div>
                                 </div>
                                 <div class="col-md-3 summary-item">
                                    <div class="value"><?= $chartData['present'] + $chartData['late'] ?></div>
                                    <div class="label">Total Present</div>
                                 </div>
                                 <div class="col-md-3 summary-item">
                                    <div class="value"><?= $chartData['absent'] ?></div>
                                    <div class="label">Total Absent</div>
                                 </div>
                                 <div class="col-md-3 summary-item">
                                    <div class="value"><?= $presentRate ?>%</div>
                                    <div class="label">Attendance Rate</div>
                                 </div>
                              <?php elseif ($reportType === 'student_participation'): ?>
                                 <?php
                                 $totalStudents = count($reportData);
                                 $totalAttendance = 0;
                                 $avgAttendanceRate = 0;

                                 foreach ($reportData as $student) {
                                    $totalAttendance += $student['attended_events'];
                                    $avgAttendanceRate += $student['attendance_rate'];
                                 }

                                 $avgAttendanceRate = $totalStudents > 0 ? round($avgAttendanceRate / $totalStudents) : 0;
                                 ?>
                                 <div class="col-md-3 summary-item">
                                    <div class="value"><?= $totalStudents ?></div>
                                    <div class="label">Total Students</div>
                                 </div>
                                 <div class="col-md-3 summary-item">
                                    <div class="value"><?= $totalAttendance ?></div>
                                    <div class="label">Total Attendances</div>
                                 </div>
                                 <div class="col-md-3 summary-item">
                                    <div class="value"><?= $avgAttendanceRate ?>%</div>
                                    <div class="label">Avg. Attendance Rate</div>
                                 </div>
                              <?php endif; ?>
                           </div>
                        </div>
                     <?php endif; ?>

                     <?php if ($showChart): ?>
                        <div class="chart-container">
                           <canvas id="reportChart"></canvas>
                        </div>
                     <?php endif; ?>

                     <div class="table-responsive">
                        <table class="table table-hover table-striped report-table" id="reportTable">
                           <thead>
                              <tr>
                                 <?php
                                 if (!empty($reportData)) {
                                    foreach (array_keys($reportData[0]) as $header) {
                                       // Convert database column names to user-friendly headers
                                       $displayHeader = ucwords(str_replace('_', ' ', $header));
                                       echo "<th>" . htmlspecialchars($displayHeader) . "</th>";
                                    }
                                 }
                                 ?>
                              </tr>
                           </thead>
                           <tbody>
                              <?php foreach ($reportData as $row): ?>
                                 <tr>
                                    <?php foreach ($row as $key => $value): ?>
                                       <td>
                                          <?php
                                          if ($key == 'check_in_time' && !empty($value)) {
                                             echo date('g:i A', strtotime($value));
                                          } elseif (($key == 'event_date' || $key == 'registration_date') && !empty($value)) {
                                             echo date('M d, Y', strtotime($value));
                                          } elseif (strpos($key, 'rate') !== false && is_numeric($value)) {
                                             echo '<div class="d-flex align-items-center">';
                                             echo '<div class="me-2">' . $value . '%</div>';
                                             echo '<div class="progress flex-grow-1" style="height: 6px;">';
                                             echo '<div class="progress-bar bg-' . getRateColor($value) . '" role="progressbar" style="width: ' . $value . '%" aria-valuenow="' . $value . '" aria-valuemin="0" aria-valuemax="100"></div>';
                                             echo '</div>';
                                             echo '</div>';
                                          } elseif ($key == 'status' && !empty($value)) {
                                             echo '<span class="badge bg-' . getStatusColor($value) . '">' . ucfirst($value) . '</span>';
                                          } else {
                                             echo htmlspecialchars($value ?: 'N/A');
                                          }
                                          ?>
                                       </td>
                                    <?php endforeach; ?>
                                 </tr>
                              <?php endforeach; ?>
                           </tbody>
                        </table>
                     </div>
                  </div>
                  <div class="card-footer text-muted d-flex justify-content-between">
                     <span>Generated on: <?= date('F d, Y h:i A') ?></span>
                     <span>Total records: <?= count($reportData) ?></span>
                  </div>
               </div>
            <?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errorMessage)): ?>
               <div class="card report-card">
                  <div class="card-body no-data-placeholder">
                     <i class="bi bi-clipboard-x"></i>
                     <h5>No Data Found</h5>
                     <p>There are no records matching your selected criteria. Try adjusting your filters.</p>
                     <button class="btn btn-outline-primary mt-3" id="adjustFiltersBtn">
                        <i class="bi bi-sliders me-2"></i>Adjust Filters
                     </button>
                  </div>
               </div>
            <?php endif; ?>
         </div>
      </div>
   </div>

   <?php include '../includes/logout-modal.php'; ?>

   <!-- Bootstrap JS Bundle -->
   <script src="../bootstrap/js/bootstrap.bundle.min.js"></script>
   <!-- Chart.js -->
   <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
   <!-- SheetJS (xlsx) for Excel Export -->
   <script src="https://cdn.sheetjs.com/xlsx-0.19.3/package/dist/xlsx.full.min.js"></script>
   <!-- html2pdf.js for PDF Export -->
   <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

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

      // Show loading spinner during form submission
      document.getElementById('reportForm').addEventListener('submit', function() {
         document.getElementById('loadingSpinner').style.display = 'flex';
      });

      // Report type card selection
      document.querySelectorAll('.report-type-card').forEach(function(card) {
         card.addEventListener('click', function() {
            // Remove active class from all cards
            document.querySelectorAll('.report-type-card').forEach(function(c) {
               c.classList.remove('active');
            });

            // Add active class to clicked card
            this.classList.add('active');

            // Set the hidden radio input
            this.querySelector('input[type="radio"]').checked = true;

            // Update visible filter fields based on report type
            const reportType = this.dataset.reportType;
            toggleFilterVisibility(reportType);
         });
      });

      // Function to toggle filter visibility based on report type
      function toggleFilterVisibility(reportType) {
         const eventFilter = document.querySelector('.event-filter');
         const dateFilters = document.querySelectorAll('.date-filter');

         if (reportType === 'event_attendance') {
            eventFilter.style.display = 'block';
            dateFilters.forEach(el => el.style.display = 'none');
         } else {
            eventFilter.style.display = 'none';
            dateFilters.forEach(el => el.style.display = 'block');
         }
      }

      // Handle the "Adjust Filters" button
      document.getElementById('adjustFiltersBtn')?.addEventListener('click', function() {
         // Expand the filters section if collapsed
         const filterCollapse = bootstrap.Collapse.getInstance(document.getElementById('reportFilters'));
         if (filterCollapse && !document.getElementById('reportFilters').classList.contains('show')) {
            filterCollapse.show();
         }

         // Scroll to the filters section
         document.querySelector('.report-filters').scrollIntoView({
            behavior: 'smooth'
         });
      });

      // Initialize Chart.js if data is available
      <?php if ($showChart && !empty($chartData)): ?>
         const ctx = document.getElementById('reportChart').getContext('2d');
         let reportChart;

         <?php $reportType = $_POST['report_type'] ?? ''; ?>

         <?php if ($reportType === 'event_attendance'): ?>
            // Pie chart for attendance status with enhanced styling
            reportChart = new Chart(ctx, {
               type: 'doughnut',
               data: {
                  labels: ['Present', 'Late', 'Absent', 'Left Early'],
                  datasets: [{
                     data: [
                        <?= $chartData['present'] ?>,
                        <?= $chartData['late'] ?>,
                        <?= $chartData['absent'] ?>,
                        <?= $chartData['left_early'] ?>
                     ],
                     backgroundColor: [
                        'rgba(40, 167, 69, 0.8)', // green - present
                        'rgba(255, 193, 7, 0.8)', // yellow - late
                        'rgba(220, 53, 69, 0.8)', // red - absent
                        'rgba(23, 162, 184, 0.8)' // cyan - left early
                     ],
                     borderColor: [
                        'rgba(40, 167, 69, 1)',
                        'rgba(255, 193, 7, 1)',
                        'rgba(220, 53, 69, 1)',
                        'rgba(23, 162, 184, 1)'
                     ],
                     borderWidth: 1,
                     hoverOffset: 15
                  }]
               },
               options: {
                  responsive: true,
                  maintainAspectRatio: false,
                  cutout: '65%',
                  plugins: {
                     legend: {
                        position: 'right',
                        labels: {
                           padding: 20,
                           font: {
                              size: 12
                           },
                           usePointStyle: true,
                           pointStyle: 'circle'
                        }
                     },
                     tooltip: {
                        callbacks: {
                           label: function(context) {
                              const label = context.label || '';
                              const value = context.raw;
                              const total = context.dataset.data.reduce((acc, curr) => acc + curr, 0);
                              const percentage = Math.round((value / total) * 100);
                              return `${label}: ${value} (${percentage}%)`;
                           }
                        },
                        padding: 12,
                        backgroundColor: 'rgba(0, 0, 0, 0.8)'
                     }
                  },
                  animation: {
                     animateScale: true,
                     animateRotate: true
                  }
               }
            });
         <?php elseif ($reportType === 'student_participation'): ?>
            // Bar chart for attendance rate by course with enhanced styling
            reportChart = new Chart(ctx, {
               type: 'bar',
               data: {
                  labels: [
                     <?php foreach ($chartData as $item): ?> '<?= addslashes($item['course']) ?>',
                     <?php endforeach; ?>
                  ],
                  datasets: [{
                     label: 'Average Attendance Rate (%)',
                     data: [
                        <?php foreach ($chartData as $item): ?>
                           <?= $item['average_rate'] ?>,
                        <?php endforeach; ?>
                     ],
                     backgroundColor: 'rgba(54, 162, 235, 0.7)',
                     borderColor: 'rgba(54, 162, 235, 1)',
                     borderWidth: 1,
                     borderRadius: 5,
                     hoverBackgroundColor: 'rgba(54, 162, 235, 0.9)'
                  }]
               },
               options: {
                  responsive: true,
                  maintainAspectRatio: false,
                  scales: {
                     y: {
                        beginAtZero: true,
                        max: 100,
                        ticks: {
                           callback: function(value) {
                              return value + '%';
                           },
                           font: {
                              size: 11
                           }
                        },
                        grid: {
                           color: 'rgba(0, 0, 0, 0.05)'
                        }
                     },
                     x: {
                        grid: {
                           display: false
                        },
                        ticks: {
                           font: {
                              size: 11
                           }
                        }
                     }
                  },
                  plugins: {
                     legend: {
                        display: true,
                        position: 'top',
                        labels: {
                           font: {
                              size: 12
                           }
                        }
                     },
                     tooltip: {
                        padding: 12,
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        titleFont: {
                           size: 13
                        },
                        bodyFont: {
                           size: 12
                        },
                        callbacks: {
                           label: function(context) {
                              return `Attendance Rate: ${context.raw}%`;
                           }
                        }
                     }
                  },
                  animation: {
                     duration: 1000
                  }
               }
            });
         <?php elseif ($reportType === 'event_summary'): ?>
            // Bar chart for events attendance
            reportChart = new Chart(ctx, {
               type: 'bar',
               data: {
                  labels: [
                     <?php foreach ($chartData as $item): ?> '<?= addslashes($item['event']) ?>',
                     <?php endforeach; ?>
                  ],
                  datasets: [{
                        label: 'Attendance Rate (%)',
                        data: [
                           <?php foreach ($chartData as $item): ?>
                              <?= $item['attendance_rate'] ?>,
                           <?php endforeach; ?>
                        ],
                        backgroundColor: 'rgba(75, 192, 192, 0.7)',
                        borderColor: 'rgba(75, 192, 192, 1)',
                        borderWidth: 1,
                        yAxisID: 'y'
                     },
                     {
                        label: 'Registered Students',
                        data: [
                           <?php foreach ($chartData as $item): ?>
                              <?= $item['registered'] ?>,
                           <?php endforeach; ?>
                        ],
                        backgroundColor: 'rgba(153, 102, 255, 0.7)',
                        borderColor: 'rgba(153, 102, 255, 1)',
                        borderWidth: 1,
                        yAxisID: 'y1',
                        type: 'line'
                     }
                  ]
               },
               options: {
                  responsive: true,
                  maintainAspectRatio: false,
                  scales: {
                     y: {
                        beginAtZero: true,
                        max: 100,
                        ticks: {
                           callback: function(value) {
                              return value + '%';
                           }
                        },
                        position: 'left',
                        title: {
                           display: true,
                           text: 'Attendance Rate (%)'
                        }
                     },
                     y1: {
                        beginAtZero: true,
                        position: 'right',
                        grid: {
                           drawOnChartArea: false
                        },
                        title: {
                           display: true,
                           text: 'Number of Students'
                        }
                     }
                  }
               }
            });
         <?php elseif ($reportType === 'attendance_trends'): ?>
            // Line chart for attendance trends over time
            reportChart = new Chart(ctx, {
               type: 'line',
               data: {
                  labels: [
                     <?php foreach ($chartData as $item): ?> '<?= $item['date'] ?>',
                     <?php endforeach; ?>
                  ],
                  datasets: [{
                     label: 'Attendance Rate (%)',
                     data: [
                        <?php foreach ($chartData as $item): ?>
                           <?= $item['attendance_rate'] ?>,
                        <?php endforeach; ?>
                     ],
                     backgroundColor: 'rgba(255, 99, 132, 0.2)',
                     borderColor: 'rgba(255, 99, 132, 1)',
                     tension: 0.3,
                     fill: true
                  }]
               },
               options: {
                  responsive: true,
                  maintainAspectRatio: false,
                  scales: {
                     y: {
                        beginAtZero: true,
                        max: 100,
                        ticks: {
                           callback: function(value) {
                              return value + '%';
                           }
                        }
                     }
                  }
               }
            });
         <?php endif; ?>
      <?php endif; ?>

      // Export to Excel functionality
      document.getElementById('exportToExcel').addEventListener('click', function() {
         document.getElementById('loadingSpinner').style.display = 'flex';
         setTimeout(function() {
            const table = document.getElementById('reportTable');
            const fileName = '<?= str_replace(' ', '_', $reportTitle) ?>_<?= date('Y-m-d') ?>';

            // Extract headers and data from table
            const headers = Array.from(table.querySelectorAll('thead th')).map(th => th.textContent.trim());
            const data = [];

            // Get all rows
            const rows = table.querySelectorAll('tbody tr');
            rows.forEach(row => {
               const rowData = {};
               // Get all cells in this row
               const cells = row.querySelectorAll('td');

               // Map each cell to its corresponding header
               cells.forEach((cell, i) => {
                  // Get the plain text content without HTML
                  let value = cell.textContent.trim();
                  // For percentage columns, remove the progress bar text
                  if (headers[i].includes('Rate')) {
                     value = value.split('%')[0] + '%';
                  }
                  rowData[headers[i]] = value;
               });

               data.push(rowData);
            });

            // Convert table to worksheet
            const ws = XLSX.utils.json_to_sheet(data);

            // Create workbook and add worksheet
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, 'Student Data');

            // Add metadata
            wb.Props = {
               Title: fileName,
               Subject: "Student Attendance Report",
               Author: "EVSU-EventQR",
               CreatedDate: new Date()
            };

            // Save file
            XLSX.writeFile(wb, fileName + '.xlsx');
            document.getElementById('loadingSpinner').style.display = 'none';
         }, 500);
      });

      // Export to PDF functionality
      document.getElementById('exportToPdf').addEventListener('click', function() {
         const fileName = '<?= str_replace(' ', '_', $reportTitle) ?>_<?= date('Y-m-d') ?>';

         // Display loading spinner
         document.getElementById('loadingSpinner').style.display = 'flex';

         try {
            // Create a dedicated PDF content container with A4 dimensions
            // A4 size in mm: 210mm × 297mm
            const pdfContent = document.createElement('div');
            pdfContent.style.backgroundColor = '#ffffff';
            pdfContent.style.padding = '10mm';
            pdfContent.style.fontFamily = 'Arial, sans-serif';
            pdfContent.style.color = '#333';
            pdfContent.style.width = '190mm'; // 210mm - 20mm padding
            pdfContent.style.minHeight = '277mm'; // 297mm - 20mm padding
            pdfContent.style.margin = '0 auto';
            pdfContent.style.boxSizing = 'border-box';

            // Add header with logo and title
            pdfContent.innerHTML = `
               <div style="text-align:center; margin-bottom:15px; padding-bottom:10px; border-bottom:2px solid #F3C623;">
                  <h2 style="margin:0; color:#222831; font-size:24px;">EVSU-EventQR</h2>
                  <h3 style="margin:5px 0; color:#333; font-size:20px;"><?= $reportTitle ?></h3>
                  <p style="margin:0; color:#777; font-size:12px;">Generated on: <?= date('F d, Y h:i A') ?></p>
               </div>
            `;

            // Add summary statistics if available
            <?php if (isset($reportType) && $reportType === 'event_attendance'): ?>
               pdfContent.innerHTML += `
                  <div style="margin-bottom:15px; padding:10px; background-color:#f8f9fa; border-left:4px solid #0d6efd; border-radius:5px;">
                     <div style="display:flex; justify-content:space-around; flex-wrap:wrap;">
                        <div style="text-align:center; padding:5px 15px;">
                           <div style="font-size:18px; font-weight:bold; color:#0d6efd;">
                              <?= array_sum([$chartData['present'], $chartData['late'], $chartData['absent'], $chartData['left_early']]) ?>
                           </div>
                           <div style="font-size:11px; color:#6c757d;">Total Registered</div>
                        </div>
                        <div style="text-align:center; padding:5px 15px;">
                           <div style="font-size:18px; font-weight:bold; color:#0d6efd;">
                              <?= $chartData['present'] + $chartData['late'] ?>
                           </div>
                           <div style="font-size:11px; color:#6c757d;">Total Present</div>
                        </div>
                        <div style="text-align:center; padding:5px 15px;">
                           <div style="font-size:18px; font-weight:bold; color:#0d6efd;">
                              <?= $chartData['absent'] ?>
                           </div>
                           <div style="font-size:11px; color:#6c757d;">Total Absent</div>
                        </div>
                        <div style="text-align:center; padding:5px 15px;">
                           <div style="font-size:18px; font-weight:bold; color:#0d6efd;">
                              <?= $totalRegistered > 0 ? round(($chartData['present'] / $totalRegistered) * 100) : 0 ?>%
                           </div>
                           <div style="font-size:11px; color:#6c757d;">Attendance Rate</div>
                        </div>
                     </div>
                  </div>
               `;
            <?php elseif (isset($reportType) && $reportType === 'student_participation'): ?>
               pdfContent.innerHTML += `
                  <div style="margin-bottom:15px; padding:10px; background-color:#f8f9fa; border-left:4px solid #0d6efd; border-radius:5px;">
                     <div style="display:flex; justify-content:space-around; flex-wrap:wrap;">
                        <div style="text-align:center; padding:5px 15px;">
                           <div style="font-size:18px; font-weight:bold; color:#0d6efd;">
                              <?= count($reportData) ?>
                           </div>
                           <div style="font-size:11px; color:#6c757d;">Total Students</div>
                        </div>
                        <div style="text-align:center; padding:5px 15px;">
                           <div style="font-size:18px; font-weight:bold; color:#0d6efd;">
                              <?php
                              $totalAttendance = 0;
                              $avgAttendanceRate = 0;
                              foreach ($reportData as $student) {
                                 $totalAttendance += $student['attended_events'];
                                 $avgAttendanceRate += $student['attendance_rate'];
                              }
                              echo $totalAttendance;
                              ?>
                           </div>
                           <div style="font-size:11px; color:#6c757d;">Total Attendances</div>
                        </div>
                        <div style="text-align:center; padding:5px 15px;">
                           <div style="font-size:18px; font-weight:bold; color:#0d6efd;">
                              <?= count($reportData) > 0 ? round($avgAttendanceRate / count($reportData)) : 0 ?>%
                           </div>
                           <div style="font-size:11px; color:#6c757d;">Avg. Attendance Rate</div>
                        </div>
                     </div>
                  </div>
               `;
            <?php endif; ?>

            // Create table HTML - using direct HTML for reliable rendering
            let tableHTML = `<table style="width:100%; border-collapse:collapse; margin-bottom:15px; font-size:9pt;">
               <thead>
                  <tr style="background-color:#f8f9fa;">`;

            // Add table headers
            <?php if (!empty($reportData)): ?>
               <?php foreach (array_keys($reportData[0]) as $header): ?>
                  tableHTML += `<th style="padding:8px; text-align:left; border:1px solid #dee2e6; font-weight:bold;">
                     <?= ucwords(str_replace('_', ' ', $header)) ?>
                  </th>`;
               <?php endforeach; ?>
            <?php endif; ?>

            tableHTML += `</tr></thead><tbody>`;

            // Add table data rows
            <?php foreach ($reportData as $i => $row): ?>
               tableHTML += `<tr style="background-color:<?= $i % 2 === 0 ? '#ffffff' : '#f9f9f9' ?>">`;
               <?php foreach ($row as $key => $value): ?>
                  tableHTML += `<td style="padding:6px; border:1px solid #dee2e6;">`;
                  <?php if ($key == 'check_in_time' && !empty($value)): ?>
                     tableHTML += `<?= date('g:i A', strtotime($value)) ?>`;
                  <?php elseif (($key == 'event_date' || $key == 'registration_date') && !empty($value)): ?>
                     tableHTML += `<?= date('M d, Y', strtotime($value)) ?>`;
                  <?php elseif (strpos($key, 'rate') !== false && is_numeric($value)): ?>
                     tableHTML += `<span style="color:<?= getColorForRate($value) ?>; font-weight:500;"><?= $value ?>%</span>`;
                  <?php elseif ($key == 'status' && !empty($value)): ?>
                     tableHTML += `<span style="color:<?= getStatusColorHex($value) ?>; font-weight:500;"><?= ucfirst($value) ?></span>`;
                  <?php else: ?>
                     tableHTML += `<?= htmlspecialchars($value ?: 'N/A') ?>`;
                  <?php endif; ?>
                  tableHTML += `</td>`;
               <?php endforeach; ?>
               tableHTML += `</tr>`;
            <?php endforeach; ?>

            tableHTML += `</tbody></table>`;

            pdfContent.innerHTML += tableHTML;

            // Add footer
            pdfContent.innerHTML += `
               <div style="text-align:center; margin-top:20px; padding-top:10px; border-top:1px solid #dee2e6; font-size:8pt; color:#777;">
                  <p style="margin:3px 0;">Total records: <?= count($reportData) ?></p>
                  <p style="margin:3px 0;">This report contains confidential student information. Please handle with care.</p>
                  <p style="margin:3px 0;">© <?= date('Y') ?> EVSU-EventQR System. All rights reserved.</p>
               </div>
            `;

            // Create preview container
            const previewContainer = document.createElement('div');
            previewContainer.id = 'pdfPreview';
            previewContainer.style.position = 'fixed';
            previewContainer.style.top = '0';
            previewContainer.style.left = '0';
            previewContainer.style.right = '0';
            previewContainer.style.bottom = '0';
            previewContainer.style.backgroundColor = 'rgba(0,0,0,0.7)';
            previewContainer.style.zIndex = '9999';
            previewContainer.style.display = 'flex';
            previewContainer.style.flexDirection = 'column';
            previewContainer.style.alignItems = 'center';
            previewContainer.style.justifyContent = 'center';
            previewContainer.style.padding = '20px';

            // Preview header
            const previewHeader = document.createElement('div');
            previewHeader.style.width = '80%';
            previewHeader.style.maxWidth = '1000px';
            previewHeader.style.display = 'flex';
            previewHeader.style.justifyContent = 'space-between';
            previewHeader.style.alignItems = 'center';
            previewHeader.style.padding = '15px';
            previewHeader.style.backgroundColor = '#fff';
            previewHeader.style.borderTopLeftRadius = '5px';
            previewHeader.style.borderTopRightRadius = '5px';
            previewHeader.style.borderBottom = '1px solid #dee2e6';

            previewHeader.innerHTML = `
               <h5 style="margin:0; font-size:16px;">Preview: <?= $reportTitle ?> (PDF)</h5>
               <div>
                  <button id="cancelPdfExport" class="btn btn-outline-secondary btn-sm me-2">Cancel</button>
                  <button id="confirmPdfExport" class="btn btn-primary btn-sm">Export PDF</button>
               </div>
            `;

            // Preview content wrapper
            const previewContent = document.createElement('div');
            previewContent.style.width = '80%';
            previewContent.style.maxWidth = '1000px';
            previewContent.style.backgroundColor = '#fff';
            previewContent.style.borderBottomLeftRadius = '5px';
            previewContent.style.borderBottomRightRadius = '5px';
            previewContent.style.padding = '15px';
            previewContent.style.boxShadow = '0 5px 20px rgba(0,0,0,0.2)';
            previewContent.style.maxHeight = 'calc(90vh - 70px)';
            previewContent.style.overflow = 'auto';

            // Add PDF content to preview
            const previewContentInner = document.createElement('div');
            previewContentInner.style.border = '1px solid #dee2e6';
            previewContentInner.style.padding = '10px';
            previewContentInner.style.backgroundColor = '#f8f9fa';
            previewContentInner.appendChild(pdfContent.cloneNode(true));
            previewContent.appendChild(previewContentInner);

            // Add to DOM
            previewContainer.appendChild(previewHeader);
            previewContainer.appendChild(previewContent);
            document.body.appendChild(previewContainer);

            // Hide loading spinner
            document.getElementById('loadingSpinner').style.display = 'none';

            // Handle cancel
            document.getElementById('cancelPdfExport').addEventListener('click', function() {
               document.body.removeChild(previewContainer);
            });

            // Handle export confirmation
            document.getElementById('confirmPdfExport').addEventListener('click', function() {
               // Show loading spinner
               document.getElementById('loadingSpinner').style.display = 'flex';

               // Remove preview
               document.body.removeChild(previewContainer);

               // Create a fresh copy of the content for PDF export
               const exportContainer = pdfContent.cloneNode(true);

               // Configure PDF export options with improved layout settings
               const opt = {
                  margin: [15, 10, 15, 10], // Slightly increased top/bottom margins
                  filename: fileName + '.pdf',
                  image: {
                     type: 'jpeg',
                     quality: 0.98
                  },
                  html2canvas: {
                     scale: 2,
                     useCORS: true,
                     letterRendering: true,
                     allowTaint: true,
                     scrollX: 0,
                     scrollY: 0,
                     windowWidth: document.documentElement.offsetWidth,
                     windowHeight: document.documentElement.offsetHeight
                  },
                  jsPDF: {
                     unit: 'mm',
                     format: 'a4',
                     orientation: 'portrait', // Default to portrait
                     compress: true
                  },
                  pagebreak: {
                     mode: 'avoid-all',
                     before: '.page-break'
                  }
               };

               // Determine best orientation based on table columns
               const columnCount = <?= isset($reportData[0]) ? count(array_keys($reportData[0])) : 0 ?>;
               if (columnCount > 5) {
                  opt.jsPDF.orientation = 'landscape';

                  // Adjust table font size for landscape mode to fit more data
                  const tableCells = exportContainer.querySelectorAll('table td, table th');
                  tableCells.forEach(cell => {
                     cell.style.fontSize = '8pt';
                     cell.style.padding = '4px';
                  });
               }

               // Create and append a temporary container for better rendering
               const tempContainer = document.createElement('div');
               tempContainer.style.width = opt.jsPDF.orientation === 'landscape' ? '277mm' : '210mm';
               tempContainer.style.margin = '0';
               tempContainer.style.padding = '0';
               tempContainer.style.visibility = 'hidden';
               tempContainer.style.position = 'absolute';
               tempContainer.style.left = '-9999px';
               tempContainer.appendChild(exportContainer);
               document.body.appendChild(tempContainer);

               // Generate PDF with adjusted content
               html2pdf()
                  .from(exportContainer)
                  .set(opt)
                  .toPdf()
                  .get('pdf')
                  .then((pdf) => {
                     // Ensure proper A4 dimensions
                     const pagesCount = pdf.internal.getNumberOfPages();

                     // Set A4 dimensions based on orientation
                     const width = opt.jsPDF.orientation === 'landscape' ? 297 : 210; // mm
                     const height = opt.jsPDF.orientation === 'landscape' ? 210 : 297; // mm

                     for (let i = 1; i <= pagesCount; i++) {
                        pdf.setPage(i);
                        pdf.internal.pageSize.width = width;
                        pdf.internal.pageSize.height = height;
                     }

                     // Save the PDF and clean up
                     pdf.save(fileName + '.pdf');
                     document.getElementById('loadingSpinner').style.display = 'none';
                     document.body.removeChild(tempContainer);
                  })
                  .catch(error => {
                     console.error('PDF generation failed:', error);
                     document.getElementById('loadingSpinner').style.display = 'none';
                     document.body.removeChild(tempContainer);
                     alert('Error generating PDF: ' + error.message);
                  });
            });
         } catch (error) {
            console.error('Error preparing PDF preview:', error);
            document.getElementById('loadingSpinner').style.display = 'none';
            alert('Error preparing PDF preview: ' + error.message);
         }
      });

      // Helper function for status color in PDF
      function getStatusColorForPdf(status) {
         status = status.toLowerCase();
         if (status.includes('present')) return '#28a745';
         if (status.includes('late')) return '#ffc107';
         if (status.includes('absent')) return '#dc3545';
         if (status.includes('early')) return '#17a2b8';
         return '#6c757d';
      }

      // Print functionality
      document.getElementById('printReport')?.addEventListener('click', function() {
         window.print();
      });

      // Initialize tooltips
      var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
      var tooltipList = tooltipTriggerList.map(function(tooltipTriggerEl) {
         return new bootstrap.Tooltip(tooltipTriggerEl)
      })

      // Set initial state based on query params 
      document.addEventListener('DOMContentLoaded', function() {
         // Get report type from URL or POST data
         const urlParams = new URLSearchParams(window.location.search);
         const reportType = urlParams.get('report_type') || '<?= $_POST['report_type'] ?? '' ?>';

         if (reportType) {
            const reportCard = document.querySelector(`.report-type-card[data-report-type="${reportType}"]`);
            if (reportCard) {
               reportCard.click();
            }
         }
      });
   </script>
</body>

</html>

<?php
// Helper function for status color in hex format (for PDF)
function getStatusColorHex($status)
{
   $status = strtolower($status);
   if ($status === 'present') return '#28a745';
   if ($status === 'late') return '#ffc107';
   if ($status === 'absent') return '#dc3545';
   if ($status === 'left early') return '#17a2b8';
   return '#6c757d';
}

// Helper function for rate color
function getColorForRate($rate)
{
   if ($rate >= 90) return '#28a745';
   if ($rate >= 75) return '#17a2b8';
   if ($rate >= 50) return '#ffc107';
   return '#dc3545';
}
