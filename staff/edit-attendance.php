<?php
// Initialize session
session_start();

// Check if user is logged in
if (!isset($_SESSION['staff_id'])) {
   header("Location: login.php");
   exit;
}

// Include database connection
require_once '../config/database.php';

// Get database connection
$conn = getDBConnection();

// Get attendance ID
$attendanceId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($attendanceId <= 0) {
   header("Location: attendance.php?error=" . urlencode("Invalid attendance record ID"));
   exit;
}

// Get user information
$staffId = $_SESSION['staff_id'];
$username = $_SESSION['username'];
$fullName = $_SESSION['full_name'] ?? $username;

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

// Fetch the attendance record
$sql = "SELECT a.*, s.first_name, s.last_name, e.event_name, e.event_date, e.start_time, e.end_time
        FROM attendance a
        JOIN students s ON a.student_id = s.student_id
        JOIN events e ON a.event_id = e.event_id
        WHERE a.attendance_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $attendanceId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
   header("Location: attendance.php?error=" . urlencode("Attendance record not found"));
   exit;
}

$record = $result->fetch_assoc();

// Handle form submission
$errorMessage = '';
$successMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
   $status = $_POST['status'] ?? '';
   $checkInTime = $_POST['check_in_time'] ?? '';
   $checkOutTime = $_POST['check_out_time'] ?? '';

   if (empty($status)) {
      $errorMessage = "Status is required";
   } else {
      // Validate check-in/check-out times if provided
      if (!empty($checkInTime)) {
         $checkInTime = date('Y-m-d H:i:s', strtotime($checkInTime));
      }

      if (!empty($checkOutTime)) {
         $checkOutTime = date('Y-m-d H:i:s', strtotime($checkOutTime));

         // If both check-in and check-out are provided, validate order
         if (!empty($checkInTime) && strtotime($checkOutTime) < strtotime($checkInTime)) {
            $errorMessage = "Check-out time cannot be earlier than check-in time";
         }

         // Handle "left early" status automatic update
         // Only if checkout is before event end time
         if (empty($errorMessage)) {
            $eventEndDateTime = $record['event_date'] . ' ' . $record['end_time'];

            // If checkout time is before event end time, set status to "left early" (only if current status is "present")
            if (strtotime($checkOutTime) < strtotime($eventEndDateTime) && $status === 'present') {
               $status = 'left early';
            }

            // If checkout time is after event end time, keep present status
            if (strtotime($checkOutTime) >= strtotime($eventEndDateTime) && $status === 'left early') {
               $status = 'present'; // They stayed for the whole event
            }
         }
      }

      if (empty($errorMessage)) {
         // Update the record
         $updateSql = "UPDATE attendance SET status = ?";
         $types = "s";
         $params = [$status];

         if (!empty($checkInTime)) {
            $updateSql .= ", check_in_time = ?";
            $types .= "s";
            $params[] = $checkInTime;
         }

         if (!empty($checkOutTime)) {
            $updateSql .= ", check_out_time = ?";
            $types .= "s";
            $params[] = $checkOutTime;
         }

         $updateSql .= " WHERE attendance_id = ?";
         $types .= "i";
         $params[] = $attendanceId;

         $updateStmt = $conn->prepare($updateSql);
         $updateStmt->bind_param($types, ...$params);

         if ($updateStmt->execute()) {
            header("Location: attendance.php?updated=1");
            exit;
         } else {
            $errorMessage = "Error updating attendance record: " . $conn->error;
         }
      }
   }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Edit Attendance - EventQR</title>
   <!-- Bootstrap CSS -->
   <link href="../bootstrap/css/bootstrap.min.css" rel="stylesheet">
   <link rel="stylesheet" href="../assets/main.css">
   <!-- Bootstrap Icons -->
   <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
   <!-- Flatpickr for datetime picking -->
   <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">

   <!-- Same styles as other staff pages -->
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

      /* Dashboard header */
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
               <a href="attendance.php" class="active">
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

         <!-- Edit Attendance Content -->
         <div class="container">
            <div class="d-flex justify-content-between align-items-center mb-4">
               <h1>Edit Attendance Record</h1>
               <a href="attendance.php" class="btn btn-outline-secondary">
                  <i class="bi bi-arrow-left me-2"></i>Back to Attendance
               </a>
            </div>

            <?php if (!empty($errorMessage)) : ?>
               <div class="alert alert-danger alert-dismissible fade show" role="alert">
                  <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($errorMessage) ?>
                  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
               </div>
            <?php endif; ?>

            <!-- Student & Event Info Card -->
            <div class="card mb-4">
               <div class="card-header bg-light">
                  <h5 class="mb-0">Record Information</h5>
               </div>
               <div class="card-body">
                  <div class="row">
                     <div class="col-md-6">
                        <h6 class="mb-3">Student Information</h6>
                        <p><strong>Name:</strong> <?= htmlspecialchars($record['first_name'] . ' ' . $record['last_name']) ?></p>
                        <p><strong>ID:</strong> <?= htmlspecialchars($record['student_id']) ?></p>
                     </div>
                     <div class="col-md-6">
                        <h6 class="mb-3">Event Information</h6>
                        <p><strong>Event:</strong> <?= htmlspecialchars($record['event_name']) ?></p>
                        <p><strong>Date:</strong> <?= date('F d, Y', strtotime($record['event_date'])) ?></p>
                        <p><strong>Time:</strong> <?= date('g:i A', strtotime($record['start_time'])) ?> - <?= date('g:i A', strtotime($record['end_time'])) ?></p>
                     </div>
                  </div>
               </div>
            </div>

            <!-- Edit Form -->
            <div class="card shadow-sm">
               <div class="card-header bg-white py-3">
                  <h5 class="mb-0">Attendance Details</h5>
               </div>
               <div class="card-body">
                  <form action="edit-attendance.php?id=<?= $attendanceId ?>" method="post">
                     <div class="row mb-3">
                        <div class="col-md-4">
                           <label for="status" class="form-label">Attendance Status</label>
                           <select name="status" id="status" class="form-select">
                              <option value="present" <?= $record['status'] === 'present' ? 'selected' : '' ?>>Present</option>
                              <option value="late" <?= $record['status'] === 'late' ? 'selected' : '' ?>>Late</option>
                              <option value="absent" <?= $record['status'] === 'absent' ? 'selected' : '' ?>>Absent</option>
                              <option value="left early" <?= $record['status'] === 'left early' ? 'selected' : '' ?>>Left Early</option>
                           </select>
                           <div class="form-text">
                              <i class="bi bi-info-circle"></i> If student checks out before the event ends, status will automatically change to "Left Early"
                           </div>
                        </div>

                        <div class="col-md-4">
                           <label for="check_in_time" class="form-label">Check-in Time</label>
                           <input type="text" class="form-control datetime-picker" id="check_in_time" name="check_in_time"
                              value="<?= !empty($record['check_in_time']) ? $record['check_in_time'] : '' ?>">
                        </div>

                        <div class="col-md-4">
                           <label for="check_out_time" class="form-label">Check-out Time</label>
                           <input type="text" class="form-control datetime-picker" id="check_out_time" name="check_out_time"
                              value="<?= !empty($record['check_out_time']) ? $record['check_out_time'] : '' ?>">
                        </div>
                     </div>

                     <div class="mb-3">
                        <label class="form-label">Event Time:</label>
                        <div class="form-text">
                           <i class="bi bi-clock"></i> Event runs from
                           <strong><?= date('g:i A', strtotime($record['start_time'])) ?></strong> to
                           <strong><?= date('g:i A', strtotime($record['end_time'])) ?></strong> on
                           <strong><?= date('F d, Y', strtotime($record['event_date'])) ?></strong>
                        </div>
                     </div>

                     <hr>

                     <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                        <a href="attendance.php" class="btn btn-outline-secondary me-md-2">Cancel</a>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                     </div>
                  </form>
               </div>
            </div>
         </div>
      </div>
   </div>

   <?php include '../includes/logout-modal.php'; ?>

   <!-- Bootstrap JS Bundle -->
   <script src="../bootstrap/js/bootstrap.bundle.min.js"></script>
   <!-- Flatpickr for datetime picking -->
   <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

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

      // Initialize datetime pickers
      document.addEventListener('DOMContentLoaded', function() {
         flatpickr(".datetime-picker", {
            enableTime: true,
            dateFormat: "Y-m-d H:i:S",
            time_24hr: true
         });

         // Show warning when changing checkout time to before event end time
         const checkOutField = document.getElementById('check_out_time');
         const statusField = document.getElementById('status');
         const eventEndTime = "<?= $record['event_date'] . ' ' . $record['end_time'] ?>";

         checkOutField.addEventListener('change', function() {
            if (this.value) {
               const checkoutTime = new Date(this.value);
               const eventEnd = new Date(eventEndTime);

               if (checkoutTime < eventEnd && statusField.value === 'present') {
                  statusField.value = 'left early';
                  alert('Status has been changed to "Left Early" because checkout time is before the event end time.');
               }
            }
         });
      });
   </script>
</body>

</html>