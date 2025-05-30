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
if (!isset($_GET['event_id']) || !is_numeric($_GET['event_id'])) {
   header("Location: events.php");
   exit;
}

$eventId = (int)$_GET['event_id'];
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

// Get event details
$sql = "SELECT * FROM events WHERE event_id = ?";
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

// Process search parameters
$searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';
$filterStatus = isset($_GET['status']) ? $_GET['status'] : 'all';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10; // Items per page
$offset = ($page - 1) * $limit;

// Process manual attendance marking
$attendanceSuccess = false;
$attendanceError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_attendance'])) {
   $studentId = trim($_POST['student_id'] ?? '');
   $status = trim($_POST['status'] ?? 'present');

   if (empty($studentId)) {
      $attendanceError = 'Student ID is required';
   } else {
      // Check if student is registered for the event
      $checkSql = "SELECT * FROM event_registrations WHERE event_id = ? AND student_id = ?";
      $stmt = $conn->prepare($checkSql);
      $stmt->bind_param("is", $eventId, $studentId);
      $stmt->execute();
      $checkResult = $stmt->get_result();

      if ($checkResult->num_rows === 0) {
         $attendanceError = 'Student is not registered for this event';
      } else {
         // Check if attendance record already exists
         $checkAttSql = "SELECT * FROM attendance WHERE event_id = ? AND student_id = ?";
         $stmt = $conn->prepare($checkAttSql);
         $stmt->bind_param("is", $eventId, $studentId);
         $stmt->execute();
         $checkAttResult = $stmt->get_result();

         if ($checkAttResult->num_rows > 0) {
            // Update existing attendance record
            $updateSql = "UPDATE attendance SET status = ?, updated_by = ? WHERE event_id = ? AND student_id = ?";
            $stmt = $conn->prepare($updateSql);
            $stmt->bind_param("ssis", $status, $staffId, $eventId, $studentId);

            if ($stmt->execute()) {
               $attendanceSuccess = true;
            } else {
               $attendanceError = 'Failed to update attendance record';
            }
         } else {
            // Create new attendance record
            $insertSql = "INSERT INTO attendance (event_id, student_id, status, check_in_time) 
              VALUES (?, ?, ?, NOW())";
            $stmt = $conn->prepare($insertSql);
            $stmt->bind_param("iss", $eventId, $studentId, $status);

            if ($stmt->execute()) {
               $attendanceSuccess = true;
            } else {
               $attendanceError = 'Failed to create attendance record';
            }
         }
      }
   }
}

// Build the query based on filters
$baseQuery = "SELECT r.registration_id, r.event_id, r.student_id, r.registration_timestamp, 
              s.student_id, s.first_name, s.last_name, 
              s.evsu_email, s.course, s.year_level, s.qr_code,
              a.status, a.check_in_time
              FROM event_registrations r
              JOIN students s ON r.student_id = s.student_id
              LEFT JOIN attendance a ON r.event_id = a.event_id AND r.student_id = a.student_id
              WHERE r.event_id = ?";

$countQuery = "SELECT COUNT(*) as total FROM event_registrations r
              JOIN students s ON r.student_id = s.student_id
              LEFT JOIN attendance a ON r.event_id = a.event_id AND r.student_id = a.student_id
              WHERE r.event_id = ?";

$params = [$eventId];
$types = "i";

// Add search filter
if (!empty($searchTerm)) {
   $searchPattern = "%$searchTerm%";
   $baseQuery .= " AND (s.student_id LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR s.evsu_email LIKE ? OR s.course LIKE ?)";
   $countQuery .= " AND (s.student_id LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR s.evsu_email LIKE ? OR s.course LIKE ?)";
   $params = array_merge($params, [$searchPattern, $searchPattern, $searchPattern, $searchPattern, $searchPattern]);
   $types .= "sssss";
}

// Add status filter
if ($filterStatus === 'present') {
   $baseQuery .= " AND a.status = 'present'";
   $countQuery .= " AND a.status = 'present'";
} elseif ($filterStatus === 'absent') {
   $baseQuery .= " AND (a.status = 'absent' OR a.status IS NULL)";
   $countQuery .= " AND (a.status = 'absent' OR a.status IS NULL)";
} elseif ($filterStatus === 'not_marked') {
   $baseQuery .= " AND a.status IS NULL";
   $countQuery .= " AND a.status IS NULL";
}

// Add ordering and pagination
$baseQuery .= " ORDER BY r.registration_timestamp DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= "ii";

// Execute count query
$totalAttendees = 0;
$stmt = $conn->prepare($countQuery);
$stmt->bind_param(substr($types, 0, -2), ...array_slice($params, 0, -2));
$stmt->execute();
$countResult = $stmt->get_result();
if ($row = $countResult->fetch_assoc()) {
   $totalAttendees = $row['total'];
}

// Calculate total pages
$totalPages = ceil($totalAttendees / $limit);

// Adjust current page if out of bounds
if ($page < 1) $page = 1;
if ($page > $totalPages && $totalPages > 0) $page = $totalPages;

// Execute the main query
$attendees = [];
$stmt = $conn->prepare($baseQuery);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
   $attendees[] = $row;
}

// Get total attendance stats
$statsSql = "SELECT 
             COUNT(*) as total,
             SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present,
             SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent,
             SUM(CASE WHEN a.status IS NULL THEN 1 ELSE 0 END) as not_marked
             FROM event_registrations r
             LEFT JOIN attendance a ON r.event_id = a.event_id AND r.student_id = a.student_id
             WHERE r.event_id = ?";

$stmt = $conn->prepare($statsSql);
$stmt->bind_param("i", $eventId);
$stmt->execute();
$statsResult = $stmt->get_result();
$stats = $statsResult->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">

<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Manage Attendees - <?= htmlspecialchars($event['event_name']) ?></title>
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

      /* Stats Card */
      .stats-card {
         border-left: 4px solid;
         border-radius: 4px;
         transition: transform 0.2s;
      }

      .stats-card:hover {
         transform: translateY(-5px);
      }

      .stats-card .value {
         font-size: 2rem;
         font-weight: 600;
      }

      .stats-card .label {
         color: #6c757d;
         font-size: 0.875rem;
         text-transform: uppercase;
         letter-spacing: 0.5px;
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

         <!-- Manage Attendees Content -->
         <div class="container-fluid dashboard-container">
            <div class="d-flex justify-content-between align-items-center mb-4">
               <div>
                  <h1>Manage Attendees</h1>
                  <nav aria-label="breadcrumb">
                     <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="events.php">Events</a></li>
                        <li class="breadcrumb-item"><a href="view-event.php?id=<?= $eventId ?>"><?= htmlspecialchars($event['event_name']) ?></a></li>
                        <li class="breadcrumb-item active" aria-current="page">Attendees</li>
                     </ol>
                  </nav>
               </div>
               <div>
                  <a href="view-event.php?id=<?= $eventId ?>" class="btn btn-outline-secondary me-2">
                     <i class="bi bi-arrow-left me-2"></i>Back to Event
                  </a>
                  <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#markAttendanceModal">
                     <i class="bi bi-person-check me-2"></i>Mark Attendance
                  </button>
               </div>
            </div>

            <?php if ($attendanceSuccess): ?>
               <div class="alert alert-success alert-dismissible fade show" role="alert">
                  Attendance marked successfully.
                  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
               </div>
            <?php endif; ?>

            <?php if (!empty($attendanceError)): ?>
               <div class="alert alert-danger alert-dismissible fade show" role="alert">
                  <?= htmlspecialchars($attendanceError) ?>
                  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
               </div>
            <?php endif; ?>

            <!-- Attendance Stats -->
            <div class="row mb-4">
               <div class="col-md-4">
                  <div class="card stats-card h-100" style="border-left-color: #6c757d;">
                     <div class="card-body">
                        <div class="value"><?= $stats['total'] ?? 0 ?></div>
                        <div class="label">Total Registrations</div>
                     </div>
                  </div>
               </div>
               <div class="col-md-4">
                  <div class="card stats-card h-100" style="border-left-color: #28a745;">
                     <div class="card-body">
                        <div class="value"><?= $stats['present'] ?? 0 ?></div>
                        <div class="label">Present</div>
                     </div>
                  </div>
               </div>
               <!-- <div class="col-md-3">
                  <div class="card stats-card h-100" style="border-left-color: #dc3545;">
                     <div class="card-body">
                        <div class="value"><?= $stats['absent'] ?? 0 ?></div>
                        <div class="label">Absent</div>
                     </div>
                  </div>
               </div> -->
               <div class="col-md-4">
                  <div class="card stats-card h-100" style="border-left-color: #ffc107;">
                     <div class="card-body">
                        <div class="value"><?= $stats['not_marked'] ?? 0 ?></div>
                        <div class="label">Not Marked</div>
                     </div>
                  </div>
               </div>
            </div>

            <!-- Search and Filter -->
            <div class="card mb-4">
               <div class="card-body">
                  <form method="get" action="manage-attendees.php" class="row g-3">
                     <input type="hidden" name="event_id" value="<?= $eventId ?>">
                     <div class="col-md-6">
                        <div class="input-group">
                           <input type="text" class="form-control" placeholder="Search attendees..." name="search" value="<?= htmlspecialchars($searchTerm) ?>">
                           <button class="btn btn-outline-secondary" type="submit">
                              <i class="bi bi-search"></i>
                           </button>
                        </div>
                     </div>
                     <div class="col-md-4">
                        <select class="form-select" name="status" onchange="this.form.submit()">
                           <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>All Attendees</option>
                           <option value="present" <?= $filterStatus === 'present' ? 'selected' : '' ?>>Present</option>
                           <option value="absent" <?= $filterStatus === 'absent' ? 'selected' : '' ?>>Absent</option>
                           <option value="not_marked" <?= $filterStatus === 'not_marked' ? 'selected' : '' ?>>Not Marked</option>
                        </select>
                     </div>
                     <div class="col-md-2">
                        <a href="manage-attendees.php?event_id=<?= $eventId ?>" class="btn btn-outline-secondary w-100">Reset</a>
                     </div>
                  </form>
               </div>
            </div>

            <!-- Attendees Table -->
            <div class="card">
               <div class="card-header d-flex justify-content-between align-items-center">
                  <h5 class="mb-0">Attendees List</h5>
                  <a href="export-attendees.php?event_id=<?= $eventId ?>&format=csv" class="btn btn-sm btn-outline-success">
                     <i class="bi bi-file-earmark-excel me-2"></i>Export to CSV
                  </a>
               </div>
               <div class="card-body">
                  <?php if (empty($attendees)): ?>
                     <div class="alert alert-info">
                        <?php if (!empty($searchTerm) || $filterStatus !== 'all'): ?>
                           No attendees found matching your search criteria. <a href="manage-attendees.php?event_id=<?= $eventId ?>">Clear filters</a>
                        <?php else: ?>
                           No attendees have registered for this event yet.
                        <?php endif; ?>
                     </div>
                  <?php else: ?>
                     <div class="table-responsive">
                        <table class="table table-hover">
                           <thead>
                              <tr>
                                 <th>Student ID</th>
                                 <th>Name</th>
                                 <th>Email</th>
                                 <th>Course</th>
                                 <th>Year</th>
                                 <th>Registration Date</th>
                                 <th>Status</th>
                                 <th>Actions</th>
                              </tr>
                           </thead>
                           <tbody>
                              <?php foreach ($attendees as $attendee): ?>
                                 <tr>
                                    <td><?= htmlspecialchars($attendee['student_id']) ?></td>
                                    <td><?= htmlspecialchars($attendee['first_name'] . ' ' . $attendee['last_name']) ?></td>
                                    <td><?= htmlspecialchars($attendee['evsu_email']) ?></td>
                                    <td><?= htmlspecialchars($attendee['course']) ?></td>
                                    <td><?= htmlspecialchars($attendee['year_level']) ?></td>
                                    <td><?= date('M d, Y g:i A', strtotime($attendee['registration_timestamp'])) ?></td>
                                    <td>
                                       <?php if ($attendee['status'] === 'present'): ?>
                                          <span class="badge bg-success">Present</span>
                                       <?php elseif ($attendee['status'] === 'absent'): ?>
                                          <span class="badge bg-danger">Absent</span>
                                       <?php else: ?>
                                          <span class="badge bg-secondary">Not Marked</span>
                                       <?php endif; ?>
                                    </td>
                                    <td>
                                       <div class="btn-group" role="group">
                                          <button type="button" class="btn btn-sm btn-success mark-attendance" data-student-id="<?= htmlspecialchars($attendee['student_id']) ?>" data-status="present">
                                             <i class="bi bi-check-circle"></i>
                                          </button>
                                          <button type="button" class="btn btn-sm btn-danger mark-attendance" data-student-id="<?= htmlspecialchars($attendee['student_id']) ?>" data-status="absent">
                                             <i class="bi bi-x-circle"></i>
                                          </button>
                                          <a href="view-student.php?id=<?= urlencode($attendee['student_id']) ?>" class="btn btn-sm btn-info">
                                             <i class="bi bi-info-circle"></i>
                                          </a>
                                       </div>
                                    </td>
                                 </tr>
                              <?php endforeach; ?>
                           </tbody>
                        </table>
                     </div>

                     <!-- Pagination -->
                     <?php if ($totalPages > 1): ?>
                        <nav aria-label="Page navigation" class="mt-4">
                           <ul class="pagination justify-content-center">
                              <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                 <a class="page-link" href="manage-attendees.php?event_id=<?= $eventId ?>&page=<?= $page - 1 ?>&search=<?= urlencode($searchTerm) ?>&status=<?= $filterStatus ?>" aria-label="Previous">
                                    <span aria-hidden="true">&laquo;</span>
                                 </a>
                              </li>
                              <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                 <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="manage-attendees.php?event_id=<?= $eventId ?>&page=<?= $i ?>&search=<?= urlencode($searchTerm) ?>&status=<?= $filterStatus ?>">
                                       <?= $i ?>
                                    </a>
                                 </li>
                              <?php endfor; ?>
                              <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                 <a class="page-link" href="manage-attendees.php?event_id=<?= $eventId ?>&page=<?= $page + 1 ?>&search=<?= urlencode($searchTerm) ?>&status=<?= $filterStatus ?>" aria-label="Next">
                                    <span aria-hidden="true">&raquo;</span>
                                 </a>
                              </li>
                           </ul>
                        </nav>
                     <?php endif; ?>
                  <?php endif; ?>
               </div>
            </div>
         </div>
      </div>
   </div>

   <!-- Mark Attendance Modal -->
   <div class="modal fade" id="markAttendanceModal" tabindex="-1" aria-labelledby="markAttendanceModalLabel" aria-hidden="true">
      <div class="modal-dialog">
         <div class="modal-content">
            <div class="modal-header">
               <h5 class="modal-title" id="markAttendanceModalLabel">Mark Attendance</h5>
               <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" action="manage-attendees.php?event_id=<?= $eventId ?>&search=<?= urlencode($searchTerm) ?>&status=<?= $filterStatus ?>&page=<?= $page ?>">
               <div class="modal-body">
                  <div class="mb-3">
                     <label for="student_id" class="form-label">Student ID</label>
                     <input type="text" class="form-control" id="student_id" name="student_id" required>
                     <div class="form-text">Enter the student's ID number</div>
                  </div>

                  <div class="mb-3">
                     <label class="form-label d-block">Attendance Status</label>
                     <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="status" id="status_present" value="present" checked>
                        <label class="form-check-label" for="status_present">Present</label>
                     </div>
                     <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="status" id="status_absent" value="absent">
                        <label class="form-check-label" for="status_absent">Absent</label>
                     </div>
                  </div>
               </div>
               <div class="modal-footer">
                  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                  <button type="submit" name="mark_attendance" class="btn btn-primary">Mark Attendance</button>
               </div>
            </form>
         </div>
      </div>
   </div>

   <!-- Quick Attendance Form -->
   <form id="quickAttendanceForm" method="post" action="manage-attendees.php?event_id=<?= $eventId ?>&search=<?= urlencode($searchTerm) ?>&status=<?= $filterStatus ?>&page=<?= $page ?>">
      <input type="hidden" id="quick_student_id" name="student_id">
      <input type="hidden" id="quick_status" name="status">
      <input type="hidden" name="mark_attendance" value="1">
   </form>

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

      // Quick attendance marking
      document.querySelectorAll('.mark-attendance').forEach(button => {
         button.addEventListener('click', function() {
            const studentId = this.getAttribute('data-student-id');
            const status = this.getAttribute('data-status');

            document.getElementById('quick_student_id').value = studentId;
            document.getElementById('quick_status').value = status;

            if (confirm(`Mark student ${studentId} as ${status.toUpperCase()}?`)) {
               document.getElementById('quickAttendanceForm').submit();
            }
         });
      });
   </script>
</body>

</html>