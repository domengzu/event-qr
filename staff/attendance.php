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

// Process filter parameters
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 15; // Records per page
$offset = ($page - 1) * $limit;

$searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';
$eventFilter = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';
$dateFilter = isset($_GET['date']) ? trim($_GET['date']) : '';

// Get events for filter dropdown
$events = [];
$eventsSql = "SELECT event_id, event_name, event_date FROM events ORDER BY event_date DESC";
$result = $conn->query($eventsSql);
if ($result) {
   while ($row = $result->fetch_assoc()) {
      $events[] = $row;
   }
}

// Build the SQL query with filters
$sql = "SELECT a.attendance_id, a.student_id, a.event_id, a.check_in_time, a.check_out_time, a.status,
              s.first_name, s.last_name, s.course, s.year_level,
              e.event_name, e.event_date, e.location
              FROM attendance a
              JOIN students s ON a.student_id = s.student_id
              JOIN events e ON a.event_id = e.event_id
              WHERE 1=1";

$countSql = "SELECT COUNT(*) as total FROM attendance a
             JOIN students s ON a.student_id = s.student_id
             JOIN events e ON a.event_id = e.event_id
             WHERE 1=1";

$params = [];
$types = '';

// Apply search filter if provided
if (!empty($searchTerm)) {
   $searchPattern = "%$searchTerm%";
   $sql .= " AND (s.student_id LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR e.event_name LIKE ?)";
   $countSql .= " AND (s.student_id LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR e.event_name LIKE ?)";
   $params = array_merge($params, [$searchPattern, $searchPattern, $searchPattern, $searchPattern]);
   $types .= 'ssss';
}

// Apply event filter if provided
if (!empty($eventFilter)) {
   $sql .= " AND a.event_id = ?";
   $countSql .= " AND a.event_id = ?";
   $params[] = $eventFilter;
   $types .= 'i';
}

// Apply status filter if provided
if (!empty($statusFilter)) {
   $sql .= " AND a.status = ?";
   $countSql .= " AND a.status = ?";
   $params[] = $statusFilter;
   $types .= 's';
}

// Apply date filter if provided
if (!empty($dateFilter)) {
   $sql .= " AND e.event_date = ?";
   $countSql .= " AND e.event_date = ?";
   $params[] = $dateFilter;
   $types .= 's';
}

// Execute the count query for pagination
$totalRecords = 0;

if (!empty($params)) {
   $stmt = $conn->prepare($countSql);
   $stmt->bind_param($types, ...$params);
   $stmt->execute();
   $result = $stmt->get_result();
   if ($row = $result->fetch_assoc()) {
      $totalRecords = $row['total'];
   }
} else {
   $result = $conn->query($countSql);
   if ($row = $result->fetch_assoc()) {
      $totalRecords = $row['total'];
   }
}

// Calculate total pages for pagination
$totalPages = ceil($totalRecords / $limit);

// Adjust current page if it's out of range
if ($page < 1) $page = 1;
if ($page > $totalPages && $totalPages > 0) $page = $totalPages;

// Add ordering and pagination to the main query
$sql .= " ORDER BY e.event_date DESC, a.check_in_time DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= 'ii';

// Execute the main query
$records = [];
if (!empty($params)) {
   $stmt = $conn->prepare($sql);
   $stmt->bind_param($types, ...$params);
   $stmt->execute();
   $result = $stmt->get_result();

   while ($row = $result->fetch_assoc()) {
      $records[] = $row;
   }
} else {
   $sql .= " LIMIT $limit OFFSET $offset";
   $result = $conn->query($sql);

   if ($result) {
      while ($row = $result->fetch_assoc()) {
         $records[] = $row;
      }
   }
}

// Get attendance statistics
$statsSql = "SELECT 
             COUNT(*) as total,
             SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
             SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent,
             SUM(CASE WHEN status = 'left early' THEN 1 ELSE 0 END) as left_early
             FROM attendance";
$result = $conn->query($statsSql);
$stats = $result->fetch_assoc();

// Check for success message
$updateSuccess = isset($_GET['updated']) && $_GET['updated'] == 1;
$deleteSuccess = isset($_GET['deleted']) && $_GET['deleted'] == 1;
$error = isset($_GET['error']) ? $_GET['error'] : '';
?>

<!DOCTYPE html>
<html lang="en">

<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Attendance Management - EventQR</title>
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

      /* Stat cards */
      .stat-card {
         border-radius: 10px;
         box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
         margin-bottom: 20px;
         border-left: 4px solid;
         transition: transform 0.2s;
      }

      .stat-card:hover {
         transform: translateY(-5px);
      }

      /* Table styles */
      .attendance-table th {
         white-space: nowrap;
      }

      .attendance-table .actions {
         white-space: nowrap;
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

         .table-responsive {
            font-size: 0.9rem;
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

         <!-- Attendance Content -->
         <div class="container-fluid dashboard-container">
            <div class="d-flex justify-content-between align-items-center mb-4">
               <h1>Attendance Management</h1>
               <div>
                  <a href="qr-scanner.php" class="btn btn-success me-2">
                     <i class="bi bi-qr-code-scan me-2"></i>Scan QR Code
                  </a>
                  <a href="attendance-reports.php" class="btn btn-primary">
                     <i class="bi bi-file-earmark-text me-2"></i>Generate Report
                  </a>
               </div>
            </div>

            <?php if ($updateSuccess): ?>
               <div class="alert alert-success alert-dismissible fade show" role="alert">
                  Attendance record updated successfully.
                  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
               </div>
            <?php endif; ?>

            <?php if ($deleteSuccess): ?>
               <div class="alert alert-success alert-dismissible fade show" role="alert">
                  Attendance record deleted successfully.
                  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
               </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
               <div class="alert alert-danger alert-dismissible fade show" role="alert">
                  <?= htmlspecialchars($error) ?>
                  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
               </div>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="row mb-4">
               <div class="col-md-4">
                  <div class="card stat-card h-100" style="border-left-color: #6c757d;">
                     <div class="card-body">
                        <h5 class="card-title mb-1">Total Records</h5>
                        <h2 class="mb-0"><?= number_format($stats['total'] ?? 0) ?></h2>
                     </div>
                  </div>
               </div>
               <div class="col-md-4">
                  <div class="card stat-card h-100" style="border-left-color: #28a745;">
                     <div class="card-body">
                        <h5 class="card-title mb-1">Present</h5>
                        <h2 class="mb-0"><?= number_format($stats['present'] ?? 0) ?></h2>
                     </div>
                  </div>
               </div>
               <!-- <div class="col-md-3">
                  <div class="card stat-card h-100" style="border-left-color: #dc3545;">
                     <div class="card-body">
                        <h5 class="card-title mb-1">Absent</h5>
                        <h2 class="mb-0"><?= number_format($stats['absent'] ?? 0) ?></h2>
                     </div>
                  </div>
               </div> -->
               <div class="col-md-4">
                  <div class="card stat-card h-100" style="border-left-color: #ffc107;">
                     <div class="card-body">
                        <h5 class="card-title mb-1">Left Early</h5>
                        <h2 class="mb-0"><?= number_format($stats['left_early'] ?? 0) ?></h2>
                     </div>
                  </div>
               </div>
            </div>

            <!-- Filters and Search -->
            <div class="card mb-4 shadow-sm">
               <div class="card-body">
                  <form action="attendance.php" method="get" class="row g-3">
                     <!-- Search input -->
                     <div class="col-md-4">
                        <div class="input-group">
                           <input type="text" class="form-control" placeholder="Search by student ID, name, or event..." name="search" value="<?= htmlspecialchars($searchTerm) ?>">
                           <button class="btn btn-primary" type="submit">
                              <i class="bi bi-search me-1"></i> Search
                           </button>
                        </div>
                     </div>

                     <!-- Event filter -->
                     <div class="col-md-3">
                        <select class="form-select" name="event_id" onchange="this.form.submit()">
                           <option value="">All Events</option>
                           <?php foreach ($events as $event): ?>
                              <option value="<?= $event['event_id'] ?>" <?= $eventFilter == $event['event_id'] ? 'selected' : '' ?>>
                                 <?= htmlspecialchars($event['event_name']) ?>
                                 (<?= date('M d, Y', strtotime($event['event_date'])) ?>)
                              </option>
                           <?php endforeach; ?>
                        </select>
                     </div>

                     <!-- Status filter -->
                     <div class="col-md-2">
                        <select class="form-select" name="status" onchange="this.form.submit()">
                           <option value="">All Statuses</option>
                           <option value="present" <?= $statusFilter === 'present' ? 'selected' : '' ?>>Present</option>
                           <option value="absent" <?= $statusFilter === 'absent' ? 'selected' : '' ?>>Absent</option>
                           <option value="left early" <?= $statusFilter === 'left early' ? 'selected' : '' ?>>Left Early</option>
                        </select>
                     </div>

                     <!-- Date filter -->
                     <div class="col-md-2">
                        <input type="date" class="form-control" name="date" value="<?= htmlspecialchars($dateFilter) ?>" onchange="this.form.submit()">
                     </div>

                     <!-- Reset filters -->
                     <div class="col-md-1">
                        <a href="attendance.php" class="btn btn-outline-secondary w-100">Reset</a>
                     </div>
                  </form>
               </div>
            </div>

            <!-- Attendance Records Table -->
            <div class="card shadow-sm">
               <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                  <h5 class="mb-0"><i class="bi bi-table me-2"></i>Attendance Records</h5>
                  <a href="export-attendance.php<?= !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '' ?>" class="btn btn-sm btn-success">
                     <i class="bi bi-download me-1"></i>Export Data
                  </a>
               </div>
               <div class="card-body">
                  <?php if (empty($records)): ?>
                     <div class="alert alert-info d-flex align-items-center">
                        <i class="bi bi-info-circle-fill me-2 fs-4"></i>
                        <div>
                           <?php if (!empty($searchTerm) || !empty($eventFilter) || !empty($statusFilter) || !empty($dateFilter)): ?>
                              No attendance records found matching your search criteria. <a href="attendance.php" class="alert-link">Clear filters</a>
                           <?php else: ?>
                              No attendance records found in the database.
                           <?php endif; ?>
                        </div>
                     </div>
                  <?php else: ?>
                     <div class="table-responsive">
                        <table class="table table-hover attendance-table align-middle">
                           <thead class="table-light">
                              <tr>
                                 <th>Attendance ID</th>
                                 <th>Student</th>
                                 <th>Event</th>
                                 <th>Date</th>
                                 <th>Check-in Time</th>
                                 <th>Check-out Time</th>
                                 <th>Status</th>
                                 <th>Actions</th>
                              </tr>
                           </thead>
                           <tbody>
                              <?php foreach ($records as $record): ?>
                                 <tr>
                                    <td><?= $record['attendance_id'] ?></td>
                                    <td>
                                       <a href="view-student.php?id=<?= urlencode($record['student_id']) ?>" class="text-decoration-none">
                                          <?= htmlspecialchars($record['first_name'] . ' ' . $record['last_name']) ?>
                                       </a>
                                       <div class="small text-muted"><?= htmlspecialchars($record['student_id']) ?></div>
                                    </td>
                                    <td>
                                       <a href="view-event.php?id=<?= $record['event_id'] ?>" class="text-decoration-none">
                                          <?= htmlspecialchars($record['event_name']) ?>
                                       </a>
                                    </td>
                                    <td><?= date('M d, Y', strtotime($record['event_date'])) ?></td>
                                    <td>
                                       <?= !empty($record['check_in_time']) ? date('g:i A', strtotime($record['check_in_time'])) : 'N/A' ?>
                                    </td>
                                    <td>
                                       <?= !empty($record['check_out_time']) ? date('g:i A', strtotime($record['check_out_time'])) : '—' ?>
                                    </td>
                                    <td>
                                       <?php if ($record['status'] === 'present'): ?>
                                          <span class="badge rounded-pill bg-success">Present</span>
                                       <?php elseif ($record['status'] === 'absent'): ?>
                                          <span class="badge rounded-pill bg-danger">Absent</span>
                                       <?php elseif ($record['status'] === 'left early'): ?>
                                          <span class="badge rounded-pill bg-warning">Left Early</span>
                                       <?php else: ?>
                                          <span class="badge rounded-pill bg-secondary">Unknown</span>
                                       <?php endif; ?>
                                    </td>
                                    <td class="actions">
                                       <div class="btn-group btn-group-sm" role="group">
                                          <a href="edit-attendance.php?id=<?= $record['attendance_id'] ?>"
                                             class="btn btn-outline-primary" title="Edit" data-bs-toggle="tooltip">
                                             <i class="bi bi-pencil"></i>
                                          </a>
                                          <button type="button" class="btn btn-outline-danger" title="Delete" data-bs-toggle="tooltip"
                                             onclick="confirmDelete(<?= $record['attendance_id'] ?>, '<?= addslashes($record['first_name'] . ' ' . $record['last_name']) ?>')">
                                             <i class="bi bi-trash"></i>
                                          </button>
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
                              <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                                 <a class="page-link" href="attendance.php?page=<?= $page - 1 ?>&search=<?= urlencode($searchTerm) ?>&event_id=<?= $eventFilter ?>&status=<?= urlencode($statusFilter) ?>&date=<?= urlencode($dateFilter) ?>" aria-label="Previous">
                                    <span aria-hidden="true">&laquo;</span>
                                 </a>
                              </li>

                              <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                 <li class="page-item <?= ($page == $i) ? 'active' : '' ?>">
                                    <a class="page-link" href="attendance.php?page=<?= $i ?>&search=<?= urlencode($searchTerm) ?>&event_id=<?= $eventFilter ?>&status=<?= urlencode($statusFilter) ?>&date=<?= urlencode($dateFilter) ?>">
                                       <?= $i ?>
                                    </a>
                                 </li>
                              <?php endfor; ?>

                              <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
                                 <a class="page-link" href="attendance.php?page=<?= $page + 1 ?>&search=<?= urlencode($searchTerm) ?>&event_id=<?= $eventFilter ?>&status=<?= urlencode($statusFilter) ?>&date=<?= urlencode($dateFilter) ?>" aria-label="Next">
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

   <!-- Delete Confirmation Modal -->
   <div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog">
         <div class="modal-content">
            <div class="modal-header">
               <h5 class="modal-title">Confirm Delete</h5>
               <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
               <p>Are you sure you want to delete this attendance record for <span id="studentNameToDelete" class="fw-bold"></span>?</p>
               <p class="text-danger">This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
               <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
               <a href="#" id="confirmDeleteBtn" class="btn btn-danger">Delete Record</a>
            </div>
         </div>
      </div>
   </div>

   <!-- Include Logout Confirmation Modal -->
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

      // Delete confirmation
      let deleteModal;

      document.addEventListener('DOMContentLoaded', function() {
         deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
      });

      function confirmDelete(attendanceId, studentName) {
         document.getElementById('studentNameToDelete').textContent = studentName;
         document.getElementById('confirmDeleteBtn').href = `delete-attendance.php?id=${attendanceId}`;
         deleteModal.show();
      }

      // Enable tooltips
      document.addEventListener('DOMContentLoaded', function() {
         var tooltips = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
         tooltips.map(function(tooltip) {
            return new bootstrap.Tooltip(tooltip);
         });
      });
   </script>
</body>

</html>