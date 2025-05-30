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

// Check if event ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
   header("Location: events.php");
   exit;
}

$eventId = (int)$_GET['id'];

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

// Fetch event details
$sql = "SELECT e.*, s.full_name as creator_name 
        FROM events e 
        LEFT JOIN staff s ON e.created_by = s.staff_id 
        WHERE e.event_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $eventId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
   // Event not found
   header("Location: events.php");
   exit;
}

$event = $result->fetch_assoc();

// Get registrations count
$regSql = "SELECT COUNT(*) as count FROM event_registrations WHERE event_id = ?";
$stmt = $conn->prepare($regSql);
$stmt->bind_param("i", $eventId);
$stmt->execute();
$regResult = $stmt->get_result();
$registrations = $regResult->fetch_assoc()['count'] ?? 0;

// Get attendance count
$attSql = "SELECT 
           SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
           SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent,
           COUNT(*) as total
           FROM attendance WHERE event_id = ?";
$stmt = $conn->prepare($attSql);
$stmt->bind_param("i", $eventId);
$stmt->execute();
$attResult = $stmt->get_result();
$attendance = $attResult->fetch_assoc();

// Check creation success message
$created = isset($_GET['created']) && $_GET['created'] == 1;
$updated = isset($_GET['updated']) && $_GET['updated'] == 1;
?>

<!DOCTYPE html>
<html lang="en">

<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title><?= htmlspecialchars($event['event_name']) ?> - Staff Portal</title>
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

      /* Event detail styles */
      .event-banner {
         height: 200px;
         background-color: #222831;
         background-size: cover;
         background-position: center;
         border-radius: 0.25rem;
         margin-bottom: 20px;
         display: flex;
         align-items: center;
         justify-content: center;
         color: white;
         font-size: 2rem;
         font-weight: bold;
         text-shadow: 0 0 10px rgba(0, 0, 0, 0.5);
      }

      .event-badge {
         font-size: 1rem;
         padding: 0.5em 1em;
      }

      .info-card {
         height: 100%;
      }

      .info-card .card-body {
         display: flex;
         flex-direction: column;
      }

      .info-card .card-title {
         margin-bottom: 0.25rem;
         font-size: 0.875rem;
         color: #6c757d;
         text-transform: uppercase;
      }

      .info-card .card-text {
         font-size: 1.25rem;
         font-weight: bold;
         margin-bottom: 0;
         flex-grow: 1;
         display: flex;
         align-items: center;
      }

      .info-card .card-text i {
         font-size: 1.5rem;
         margin-right: 10px;
         opacity: 0.6;
      }

      .description-card {
         white-space: pre-line;
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
               <a href="events.php" class="active">
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

         <!-- View Event Content -->
         <div class="container-fluid dashboard-container">
            <div class="d-flex justify-content-between align-items-center mb-4">
               <div>
                  <h1 class="mb-0"><?= htmlspecialchars($event['event_name']) ?></h1>
                  <nav aria-label="breadcrumb">
                     <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="events.php">Events</a></li>
                        <li class="breadcrumb-item active" aria-current="page">View Event</li>
                     </ol>
                  </nav>
               </div>

               <div class="btn-group" role="group">
                  <a href="edit-event.php?id=<?= $eventId ?>" class="btn btn-primary">
                     <i class="bi bi-pencil me-2"></i>Edit Event
                  </a>
                  <a href="manage-attendees.php?event_id=<?= $eventId ?>" class="btn btn-success">
                     <i class="bi bi-people me-2"></i>Manage Attendees
                  </a>
                  <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#deleteEventModal">
                     <i class="bi bi-trash me-2"></i>Delete
                  </button>
               </div>
            </div>

            <?php if ($created): ?>
               <div class="alert alert-success alert-dismissible fade show" role="alert">
                  Event created successfully!
                  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
               </div>
            <?php endif; ?>

            <?php if ($updated): ?>
               <div class="alert alert-success alert-dismissible fade show" role="alert">
                  Event updated successfully!
                  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
               </div>
            <?php endif; ?>

            <!-- Event Banner -->
            <div class="event-banner" style="background-image: linear-gradient(rgba(0,0,0,0.5), rgba(0,0,0,0.5)), url('../assets/images/event-placeholder.jpg')">
               <div class="text-center">
                  <h2 class="mb-0"><?= htmlspecialchars($event['event_name']) ?></h2>
                  <?php
                  $eventDate = strtotime($event['event_date']);
                  $today = strtotime(date('Y-m-d'));

                  if ($eventDate > $today) {
                     echo '<span class="badge bg-primary event-badge">Upcoming</span>';
                  } elseif ($eventDate == $today) {
                     echo '<span class="badge bg-success event-badge">Today</span>';
                  } else {
                     echo '<span class="badge bg-secondary event-badge">Past</span>';
                  }
                  ?>
               </div>
            </div>

            <!-- Event Quick Facts -->
            <div class="row mb-4">
               <div class="col-md-3 mb-3">
                  <div class="card info-card">
                     <div class="card-body">
                        <h5 class="card-title">Date</h5>
                        <p class="card-text">
                           <i class="bi bi-calendar-event"></i>
                           <?= date('F d, Y', strtotime($event['event_date'])) ?>
                        </p>
                     </div>
                  </div>
               </div>
               <div class="col-md-3 mb-3">
                  <div class="card info-card">
                     <div class="card-body">
                        <h5 class="card-title">Time</h5>
                        <p class="card-text">
                           <i class="bi bi-clock"></i>
                           <?= date('g:i A', strtotime($event['start_time'])) ?> - <?= date('g:i A', strtotime($event['end_time'])) ?>
                        </p>
                     </div>
                  </div>
               </div>
               <div class="col-md-3 mb-3">
                  <div class="card info-card">
                     <div class="card-body">
                        <h5 class="card-title">Location</h5>
                        <p class="card-text">
                           <i class="bi bi-geo-alt"></i>
                           <?= htmlspecialchars($event['location']) ?>
                        </p>
                     </div>
                  </div>
               </div>
               <div class="col-md-3 mb-3">
                  <div class="card info-card">
                     <div class="card-body">
                        <h5 class="card-title">Created by</h5>
                        <p class="card-text">
                           <i class="bi bi-person"></i>
                           <?= htmlspecialchars($event['creator_name'] ?? 'Unknown') ?>
                        </p>
                     </div>
                  </div>
               </div>
            </div>

            <div class="row">
               <div class="col-lg-8">
                  <!-- Event Details -->
                  <div class="card mb-4">
                     <div class="card-header">
                        <h5 class="mb-0">Event Details</h5>
                     </div>
                     <div class="card-body">
                        <div class="row">
                           <!-- Event Image -->
                           <?php if (!empty($event['event_image'] ?? '')): ?>
                              <div class="col-md-4 mb-4">
                                 <img src="<?= '../' . htmlspecialchars($event['event_image']) ?>" alt="Event Image" class="img-fluid rounded">
                              </div>
                              <div class="col-md-8">
                              <?php else: ?>
                                 <div class="col-12">
                                 <?php endif; ?>
                                 <h4 class="mb-3"><?= htmlspecialchars($event['event_name']) ?></h4>

                                 <p class="mb-3">
                                    <i class="bi bi-calendar-event me-2 text-primary"></i>
                                    <?= date('F d, Y', strtotime($event['event_date'])) ?>
                                 </p>

                                 <p class="mb-3">
                                    <i class="bi bi-clock me-2 text-primary"></i>
                                    <?= date('g:i A', strtotime($event['start_time'])) ?> - <?= date('g:i A', strtotime($event['end_time'])) ?>
                                 </p>

                                 <p class="mb-3">
                                    <i class="bi bi-geo-alt me-2 text-primary"></i>
                                    <?= htmlspecialchars($event['location']) ?>
                                 </p>

                                 <div class="mt-4">
                                    <h5>Description</h5>
                                    <p class="text-muted"><?= nl2br(htmlspecialchars($event['description'] ?: 'No description provided.')) ?></p>
                                 </div>
                                 </div>
                              </div>
                        </div>
                     </div>
                  </div>

                  <!-- Attendance & Registration Stats -->
                  <div class="col-lg-4">
                     <!-- Registration Stats -->
                     <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                           <h5 class="mb-0">Registration Stats</h5>
                           <a href="manage-attendees.php?event_id=<?= $eventId ?>" class="btn btn-sm btn-outline-primary">View All</a>
                        </div>
                        <div class="card-body">
                           <div class="d-flex align-items-center mb-3">
                              <div class="bg-primary rounded p-3 me-3">
                                 <i class="bi bi-people-fill text-white"></i>
                              </div>
                              <div>
                                 <h3 class="mb-0"><?= $registrations ?></h3>
                                 <div class="text-muted">Registered Participants</div>
                              </div>
                           </div>

                           <div class="progress mb-3" style="height: 10px;">
                              <div class="progress-bar" role="progressbar" style="width: 100%" aria-valuenow="100" aria-valuemin="0" aria-valuemax="100"></div>
                           </div>

                           <div class="text-muted">
                              <small>
                                 Last registration: <?= date('M d, Y H:i', strtotime($event['updated_at'])) ?>
                              </small>
                           </div>
                        </div>
                     </div>

                     <!-- Attendance Stats -->
                     <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                           <h5 class="mb-0">Attendance Stats</h5>
                           <a href="attendance-report.php?event_id=<?= $eventId ?>" class="btn btn-sm btn-outline-primary">View Report</a>
                        </div>
                        <div class="card-body">
                           <div class="mb-3">
                              <div class="d-flex justify-content-between mb-1">
                                 <span>Present</span>
                                 <span><?= $attendance['present'] ?? 0 ?> / <?= $registrations ?></span>
                              </div>
                              <?php
                              $presentPercentage = $registrations > 0 ? (($attendance['present'] ?? 0) / $registrations) * 100 : 0;
                              ?>
                              <div class="progress" style="height: 10px;">
                                 <div class="progress-bar bg-success" role="progressbar" style="width: <?= $presentPercentage ?>%"
                                    aria-valuenow="<?= $presentPercentage ?>" aria-valuemin="0" aria-valuemax="100"></div>
                              </div>
                           </div>

                           <div class="mb-0">
                              <div class="d-flex justify-content-between mb-1">
                                 <span>Absent</span>
                                 <span><?= $attendance['absent'] ?? 0 ?> / <?= $registrations ?></span>
                              </div>
                              <?php
                              $absentPercentage = $registrations > 0 ? (($attendance['absent'] ?? 0) / $registrations) * 100 : 0;
                              ?>
                              <div class="progress" style="height: 10px;">
                                 <div class="progress-bar bg-danger" role="progressbar" style="width: <?= $absentPercentage ?>%"
                                    aria-valuenow="<?= $absentPercentage ?>" aria-valuemin="0" aria-valuemax="100"></div>
                              </div>
                           </div>
                        </div>
                     </div>

                     <!-- Quick Actions Card -->
                     <div class="card">
                        <div class="card-header">
                           <h5 class="mb-0">Quick Actions</h5>
                        </div>
                        <div class="card-body">
                           <div class="d-grid gap-2">
                              <a href="qr-scanner.php?event=<?= $eventId ?>" class="btn btn-primary">
                                 <i class="bi bi-qr-code-scan me-2"></i>Scan QR Codes
                              </a>
                              <a href="export-attendees.php?event_id=<?= $eventId ?>" class="btn btn-info">
                                 <i class="bi bi-file-earmark-excel me-2"></i>Export Data
                              </a>
                              <a href="../event-details.php?id=<?= $eventId ?>" class="btn btn-outline-secondary" target="_blank">
                                 <i class="bi bi-box-arrow-up-right me-2"></i>Public Event Page
                              </a>
                           </div>
                        </div>
                     </div>
                  </div>
               </div>
            </div>
         </div>
      </div>

      <!-- Delete Event Confirmation Modal -->
      <div class="modal fade" id="deleteEventModal" tabindex="-1" aria-hidden="true">
         <div class="modal-dialog">
            <div class="modal-content">
               <div class="modal-header">
                  <h5 class="modal-title">Confirm Delete</h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
               </div>
               <div class="modal-body">
                  <p>Are you sure you want to delete "<strong><?= htmlspecialchars($event['event_name']) ?></strong>"?</p>
                  <p class="text-danger">This action cannot be undone, and all registrations and attendance data will be permanently deleted!</p>
               </div>
               <div class="modal-footer">
                  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                  <a href="delete-event.php?id=<?= $eventId ?>" class="btn btn-danger">Delete Event</a>
               </div>
            </div>
         </div>
      </div>

      <?php include '../includes/logout-modal.php'; ?>

      <!-- Bootstrap JS Bundle -->
      <script src="../bootstrap/js/bootstrap.bundle.min.js"></script>
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
      </script>
</body>

</html>