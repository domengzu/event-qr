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

// Check if student ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
   header("Location: students.php");
   exit;
}

$studentId = $_GET['id'];

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

// Fetch student details
$sql = "SELECT * FROM students WHERE student_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $studentId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
   // Student not found
   header("Location: students.php");
   exit;
}

$student = $result->fetch_assoc();

// Count registrations and attendance
$regCountSql = "SELECT COUNT(*) as count FROM event_registrations WHERE student_id = ?";
$stmt = $conn->prepare($regCountSql);
$stmt->bind_param("s", $studentId);
$stmt->execute();
$regResult = $stmt->get_result();
$registrations = $regResult->fetch_assoc()['count'] ?? 0;

$attendanceCountSql = "SELECT 
                       COUNT(*) as total,
                       SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
                       SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent,
                       SUM(CASE WHEN status = 'left early' THEN 1 ELSE 0 END) as left_early
                       FROM attendance WHERE student_id = ?";
$stmt = $conn->prepare($attendanceCountSql);
$stmt->bind_param("s", $studentId);
$stmt->execute();
$attResult = $stmt->get_result();
$attendance = $attResult->fetch_assoc();

// Get event history
$eventsSql = "SELECT e.event_id, e.event_name, e.event_date, e.location, 
              r.registration_timestamp, 
              a.status as attendance_status, a.check_in_time
              FROM event_registrations r
              JOIN events e ON r.event_id = e.event_id
              LEFT JOIN attendance a ON r.event_id = a.event_id AND r.student_id = a.student_id
              WHERE r.student_id = ?
              ORDER BY e.event_date DESC
              LIMIT 10";
$stmt = $conn->prepare($eventsSql);
$stmt->bind_param("s", $studentId);
$stmt->execute();
$eventsResult = $stmt->get_result();

$events = [];
while ($row = $eventsResult->fetch_assoc()) {
   $events[] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>View Student - <?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></title>
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

      /* Student profile styles */
      .student-profile-header {
         background-color: #222831;
         color: white;
         padding: 2rem 0;
         margin-bottom: 2rem;
         border-radius: 0.5rem;
      }

      .profile-img-container {
         position: relative;
         margin-bottom: 1rem;
      }

      .profile-img {
         width: 150px;
         height: 150px;
         border-radius: 50%;
         border: 5px solid white;
         background-color: #f8f9fa;
         display: flex;
         align-items: center;
         justify-content: center;
         font-size: 3rem;
         color: #6c757d;
         margin: 0 auto;
      }

      .qr-preview {
         max-width: 100%;
         height: auto;
      }

      .info-card {
         height: 100%;
         transition: transform 0.2s;
      }

      .info-card:hover {
         transform: translateY(-5px);
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

      .stat-card {
         border-left: 4px solid;
         border-radius: 4px;
      }

      .stat-card .number {
         font-size: 2rem;
         font-weight: bold;
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
               <a href="students.php" class="active">
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

         <!-- Student Profile Content -->
         <div class="container-fluid dashboard-container">
            <div class="d-flex justify-content-between align-items-center mb-4">
               <div>
                  <h1>Student Profile</h1>
                  <nav aria-label="breadcrumb">
                     <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="students.php">Students</a></li>
                        <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></li>
                     </ol>
                  </nav>
               </div>

               <div class="btn-group" role="group">
                  <a href="edit-student.php?id=<?= urlencode($studentId) ?>" class="btn btn-primary">
                     <i class="bi bi-pencil me-2"></i>Edit Profile
                  </a>
                  <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#deleteStudentModal">
                     <i class="bi bi-trash me-2"></i>Delete
                  </button>
               </div>
            </div>

            <!-- Student Profile Header -->
            <div class="student-profile-header shadow-sm">
               <div class="container">
                  <div class="row align-items-center">
                     <div class="col-lg-2 col-md-3 text-center">
                        <div class="profile-img-container">
                           <div class="profile-img">
                              <i class="bi bi-person-fill"></i>
                           </div>
                        </div>
                     </div>
                     <div class="col-lg-7 col-md-6">
                        <h2 class="mb-1"><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></h2>
                        <p class="mb-2"><i class="bi bi-person-badge me-2"></i>Student ID: <?= htmlspecialchars($student['student_id']) ?></p>
                        <?php if (!empty($student['evsu_email'])): ?>
                           <p class="mb-2"><i class="bi bi-envelope me-2"></i><?= htmlspecialchars($student['evsu_email']) ?></p>
                        <?php endif; ?>
                        <p class="mb-0">
                           <span class="badge bg-primary me-2"><?= htmlspecialchars($student['course']) ?></span>
                           <?php if (!empty($student['year_level'])): ?>
                              <span class="badge bg-info">Year <?= $student['year_level'] ?></span>
                           <?php endif; ?>
                        </p>
                     </div>
                     <div class="col-lg-3 col-md-3 text-center text-md-end mt-3 mt-md-0">
                        <button id="showQrCodeBtn" class="btn btn-light" data-qr-code="<?= htmlspecialchars($student['qr_code']) ?>">
                           <i class="bi bi-qr-code me-2"></i>View QR Code
                        </button>
                     </div>
                  </div>
               </div>
            </div>

            <div class="row">
               <!-- Student Information Panel -->
               <div class="col-lg-8">
                  <!-- Attendance Statistics -->
                  <div class="card mb-4">
                     <div class="card-header">
                        <h5 class="mb-0">Attendance Statistics</h5>
                     </div>
                     <div class="card-body">
                        <div class="row">
                           <div class="col-md-4 mb-3">
                              <div class="card stat-card h-100" style="border-left-color: #0d6efd;">
                                 <div class="card-body">
                                    <h6 class="card-subtitle mb-2 text-muted">Total Events</h6>
                                    <p class="number mb-0"><?= $registrations ?></p>
                                 </div>
                              </div>
                           </div>
                           <div class="col-md-4 mb-3">
                              <div class="card stat-card h-100" style="border-left-color: #198754;">
                                 <div class="card-body">
                                    <h6 class="card-subtitle mb-2 text-muted">Present</h6>
                                    <p class="number mb-0"><?= $attendance['present'] ?? 0 ?></p>
                                 </div>
                              </div>
                           </div>
                           <!-- <div class="col-md-3 mb-3">
                              <div class="card stat-card h-100" style="border-left-color: #dc3545;">
                                 <div class="card-body">
                                    <h6 class="card-subtitle mb-2 text-muted">Absent</h6>
                                    <p class="number mb-0"><?= $attendance['absent'] ?? 0 ?></p>
                                 </div>
                              </div>
                           </div> -->
                           <div class="col-md-4 mb-3">
                              <div class="card stat-card h-100" style="border-left-color: #ffc107;">
                                 <div class="card-body">
                                    <h6 class="card-subtitle mb-2 text-muted">Left Early</h6>
                                    <p class="number mb-0"><?= $attendance['left_early'] ?? 0 ?></p>
                                 </div>
                              </div>
                           </div>
                        </div>

                        <?php if ($registrations > 0): ?>
                           <div class="mt-3">
                              <h6>Attendance Rate</h6>
                              <?php
                              $attendanceRate = $registrations > 0 ? (($attendance['present'] ?? 0) / $registrations) * 100 : 0;
                              $attendanceRateClass = $attendanceRate >= 80 ? 'bg-success' : ($attendanceRate >= 60 ? 'bg-warning' : 'bg-danger');
                              ?>
                              <div class="progress" style="height: 20px;">
                                 <div class="progress-bar <?= $attendanceRateClass ?>" role="progressbar"
                                    style="width: <?= $attendanceRate ?>%"
                                    aria-valuenow="<?= $attendanceRate ?>" aria-valuemin="0" aria-valuemax="100">
                                    <?= round($attendanceRate) ?>%
                                 </div>
                              </div>
                           </div>
                        <?php endif; ?>
                     </div>
                  </div>

                  <!-- Student Details Card -->
                  <div class="card mb-4">
                     <div class="card-header">
                        <h5 class="mb-0">Student Information</h5>
                     </div>
                     <div class="card-body">
                        <div class="row">
                           <div class="col-md-6 mb-3">
                              <h6 class="text-muted">Student ID</h6>
                              <p><?= htmlspecialchars($student['student_id']) ?></p>
                           </div>
                           <div class="col-md-6 mb-3">
                              <h6 class="text-muted">Full Name</h6>
                              <p><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></p>
                           </div>
                           <div class="col-md-6 mb-3">
                              <h6 class="text-muted">Course</h6>
                              <p><?= htmlspecialchars($student['course']) ?></p>
                           </div>
                           <div class="col-md-6 mb-3">
                              <h6 class="text-muted">Year Level</h6>
                              <p><?= $student['year_level'] ? 'Year ' . $student['year_level'] : 'N/A' ?></p>
                           </div>
                           <div class="col-md-6 mb-3">
                              <h6 class="text-muted">Email</h6>
                              <p><?= !empty($student['evsu_email']) ? htmlspecialchars($student['evsu_email']) : 'N/A' ?></p>
                           </div>
                           <div class="col-md-6 mb-3">
                              <h6 class="text-muted">Guardian Contact</h6>
                              <p><?= !empty($student['guardian_contact']) ? htmlspecialchars($student['guardian_contact']) : 'N/A' ?></p>
                           </div>
                           <div class="col-12 mb-3">
                              <h6 class="text-muted">QR Code</h6>
                              <p><?= htmlspecialchars($student['qr_code']) ?></p>
                           </div>
                           <div class="col-md-6 mb-3">
                              <h6 class="text-muted">Registration Date</h6>
                              <p><?= date('F d, Y', strtotime($student['created_at'])) ?></p>
                           </div>
                        </div>
                     </div>
                  </div>
               </div>

               <!-- QR Code and Event History -->
               <div class="col-lg-4">
                  <!-- QR Code Card -->
                  <div class="card mb-4">
                     <div class="card-header">
                        <h5 class="mb-0">QR Code</h5>
                     </div>
                     <div class="card-body text-center">
                        <div id="qrCodeContainer" class="mb-3"></div>
                        <button id="downloadQrBtn" class="btn btn-outline-primary btn-sm">
                           <i class="bi bi-download me-2"></i>Download QR Code
                        </button>
                     </div>
                  </div>

                  <!-- Event History Card -->
                  <div class="card">
                     <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Event History</h5>
                        <a href="student-events.php?id=<?= urlencode($studentId) ?>" class="btn btn-sm btn-outline-primary">View All</a>
                     </div>
                     <div class="card-body p-0">
                        <?php if (empty($events)): ?>
                           <div class="p-4 text-center">
                              <p class="text-muted mb-0">No event history available</p>
                           </div>
                        <?php else: ?>
                           <div class="list-group list-group-flush">
                              <?php foreach ($events as $event): ?>
                                 <a href="view-event.php?id=<?= $event['event_id'] ?>" class="list-group-item list-group-item-action">
                                    <div class="d-flex w-100 justify-content-between">
                                       <h6 class="mb-1"><?= htmlspecialchars($event['event_name']) ?></h6>
                                       <small>
                                          <?php if ($event['attendance_status'] === 'present'): ?>
                                             <span class="badge bg-success">Present</span>
                                          <?php elseif ($event['attendance_status'] === 'absent'): ?>
                                             <span class="badge bg-danger">Absent</span>
                                          <?php elseif ($event['attendance_status'] === 'left early'): ?>
                                             <span class="badge bg-warning">Left Early</span>
                                          <?php else: ?>
                                             <span class="badge bg-secondary">Not Marked</span>
                                          <?php endif; ?>
                                       </small>
                                    </div>
                                    <p class="mb-1 small">
                                       <i class="bi bi-calendar-event me-1"></i>
                                       <?= date('M d, Y', strtotime($event['event_date'])) ?>
                                    </p>
                                    <small class="text-muted">
                                       <i class="bi bi-geo-alt me-1"></i>
                                       <?= htmlspecialchars($event['location']) ?>
                                    </small>
                                 </a>
                              <?php endforeach; ?>
                           </div>
                        <?php endif; ?>
                     </div>
                  </div>
               </div>
            </div>
         </div>
      </div>
   </div>

   <!-- Delete Student Modal -->
   <div class="modal fade" id="deleteStudentModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog">
         <div class="modal-content">
            <div class="modal-header">
               <h5 class="modal-title">Confirm Delete</h5>
               <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
               <p>Are you sure you want to delete the student record for <strong><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></strong>?</p>
               <p class="text-danger">This action cannot be undone. All registration and attendance records for this student will also be deleted.</p>
            </div>
            <div class="modal-footer">
               <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
               <a href="delete-student.php?id=<?= urlencode($studentId) ?>" class="btn btn-danger">Delete Student</a>
            </div>
         </div>
      </div>
   </div>

   <?php include '../includes/logout-modal.php'; ?>

   <!-- Bootstrap JS Bundle -->
   <script src="../bootstrap/js/bootstrap.bundle.min.js"></script>
   <!-- QR Code Library -->
   <script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
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

      // Generate QR code
      document.addEventListener('DOMContentLoaded', function() {
         const qrCodeContainer = document.getElementById('qrCodeContainer');
         const qrCode = '<?= addslashes($student['qr_code']) ?>';
         const studentName = '<?= addslashes($student['first_name'] . ' ' . $student['last_name']) ?>';

         var typeNumber = 0;
         var errorCorrectionLevel = 'L';
         var qr = qrcode(typeNumber, errorCorrectionLevel);
         qr.addData(qrCode);
         qr.make();
         qrCodeContainer.innerHTML = qr.createImgTag(5, 10);

         // Set up download button
         document.getElementById('downloadQrBtn').onclick = function() {
            const img = qrCodeContainer.querySelector('img');
            const link = document.createElement('a');
            link.download = `QR_${studentName.replace(/[^a-z0-9]/gi, '_').toLowerCase()}.png`;
            link.href = img.src;
            link.click();
         };

         // Show QR code button
         document.getElementById('showQrCodeBtn').addEventListener('click', function() {
            window.scrollTo({
               top: qrCodeContainer.offsetTop - 100,
               behavior: 'smooth'
            });
         });
      });
   </script>
</body>

</html>