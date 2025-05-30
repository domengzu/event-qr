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
$limit = 15; // Students per page
$offset = ($page - 1) * $limit;

$searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';
$courseFilter = isset($_GET['course']) ? trim($_GET['course']) : '';
$yearFilter = isset($_GET['year']) ? trim($_GET['year']) : '';

// Build the SQL query with filters
$sql = "SELECT * FROM students WHERE 1=1";
$countSql = "SELECT COUNT(*) as total FROM students WHERE 1=1";
$params = [];
$types = '';

// Apply search filter if provided
if (!empty($searchTerm)) {
   $searchPattern = "%$searchTerm%";
   $sql .= " AND (student_id LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR evsu_email LIKE ?)";
   $countSql .= " AND (student_id LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR evsu_email LIKE ?)";
   $params = array_merge($params, [$searchPattern, $searchPattern, $searchPattern, $searchPattern]);
   $types .= 'ssss';
}

// Apply course filter if provided
if (!empty($courseFilter)) {
   $sql .= " AND course = ?";
   $countSql .= " AND course = ?";
   $params[] = $courseFilter;
   $types .= 's';
}

// Apply year filter if provided
if (!empty($yearFilter) && is_numeric($yearFilter)) {
   $sql .= " AND year_level = ?";
   $countSql .= " AND year_level = ?";
   $params[] = $yearFilter;
   $types .= 'i';
}

// Get distinct courses and years for filters
$courses = [];
$coursesQuery = "SELECT DISTINCT course FROM students WHERE course IS NOT NULL AND course != '' ORDER BY course";
$result = $conn->query($coursesQuery);
if ($result) {
   while ($row = $result->fetch_assoc()) {
      $courses[] = $row['course'];
   }
}

$years = [];
$yearsQuery = "SELECT DISTINCT year_level FROM students WHERE year_level IS NOT NULL ORDER BY year_level";
$result = $conn->query($yearsQuery);
if ($result) {
   while ($row = $result->fetch_assoc()) {
      $years[] = $row['year_level'];
   }
}

// Execute the count query for pagination
$totalStudents = 0;

if (!empty($params)) {
   $stmt = $conn->prepare($countSql);
   $stmt->bind_param($types, ...$params);
   $stmt->execute();
   $result = $stmt->get_result();
   if ($row = $result->fetch_assoc()) {
      $totalStudents = $row['total'];
   }
} else {
   $result = $conn->query($countSql);
   if ($row = $result->fetch_assoc()) {
      $totalStudents = $row['total'];
   }
}

// Calculate total pages for pagination
$totalPages = ceil($totalStudents / $limit);

// Adjust current page if it's out of range
if ($page < 1) $page = 1;
if ($page > $totalPages && $totalPages > 0) $page = $totalPages;

// Add ordering and pagination
$sql .= " ORDER BY last_name, first_name LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= 'ii';

// Execute the main query
$students = [];
$stmt = $conn->prepare($sql);
if (!empty($params)) {
   $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
   $students[] = $row;
}

// Check if any student was deleted
$deleteSuccess = isset($_GET['delete']) && $_GET['delete'] === 'success';
$deleteError = isset($_GET['delete_error']) ? $_GET['delete_error'] : '';
?>

<!DOCTYPE html>
<html lang="en">

<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Students Management - EventQR</title>
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

      /* Students table */
      .students-table th {
         white-space: nowrap;
      }

      .students-table .student-actions {
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

         <!-- Students Content -->
         <div class="container-fluid dashboard-container">
            <div class="d-flex justify-content-between align-items-center mb-4">
               <h1>Students Management</h1>
               <a href="add-student.php" class="btn btn-primary">
                  <i class="bi bi-plus-circle me-2"></i>Add New Student
               </a>
            </div>

            <?php if ($deleteSuccess): ?>
               <div class="alert alert-success alert-dismissible fade show" role="alert">
                  Student record deleted successfully.
                  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
               </div>
            <?php endif; ?>

            <?php if (!empty($deleteError)): ?>
               <div class="alert alert-danger alert-dismissible fade show" role="alert">
                  Error deleting student record: <?= htmlspecialchars($deleteError) ?>
                  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
               </div>
            <?php endif; ?>

            <!-- Filters and Search -->
            <div class="card mb-4">
               <div class="card-body">
                  <form action="students.php" method="get" class="row g-3">
                     <!-- Search input -->
                     <div class="col-md-5">
                        <div class="input-group">
                           <input type="text" class="form-control" placeholder="Search by ID, name, or email..." name="search" value="<?= htmlspecialchars($searchTerm) ?>">
                           <button class="btn btn-outline-secondary" type="submit">
                              <i class="bi bi-search"></i>
                           </button>
                        </div>
                     </div>

                     <!-- Course filter -->
                     <div class="col-md-3">
                        <select class="form-select" name="course" onchange="this.form.submit()">
                           <option value="">All Courses</option>
                           <?php foreach ($courses as $course): ?>
                              <option value="<?= htmlspecialchars($course) ?>" <?= $courseFilter === $course ? 'selected' : '' ?>>
                                 <?= htmlspecialchars($course) ?>
                              </option>
                           <?php endforeach; ?>
                        </select>
                     </div>

                     <!-- Year filter -->
                     <div class="col-md-2">
                        <select class="form-select" name="year" onchange="this.form.submit()">
                           <option value="">All Years</option>
                           <?php foreach ($years as $year): ?>
                              <option value="<?= $year ?>" <?= $yearFilter == $year ? 'selected' : '' ?>>
                                 Year <?= $year ?>
                              </option>
                           <?php endforeach; ?>
                        </select>
                     </div>

                     <!-- Reset filters -->
                     <div class="col-md-2">
                        <a href="students.php" class="btn btn-outline-secondary w-100">Reset</a>
                     </div>
                  </form>
               </div>
            </div>

            <!-- Students Table -->
            <div class="card">
               <div class="card-header d-flex justify-content-between align-items-center">
                  <div>
                     <h5 class="mb-0">Students List</h5>
                     <div class="small text-muted"><?= number_format($totalStudents) ?> students found</div>
                  </div>
                  <div>
                     <div class="btn-group">
                        <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                           <i class="bi bi-upload me-1"></i> Import
                        </button>
                        <ul class="dropdown-menu">
                           <li><a class="dropdown-item" href="import-students.php?format=csv">CSV Format</a></li>
                           <li><a class="dropdown-item" href="import-students.php?format=excel">Excel Format</a></li>
                        </ul>
                     </div>
                     <div class="btn-group ms-2">
                        <button type="button" class="btn btn-sm btn-outline-success dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                           <i class="bi bi-download me-1"></i> Export
                        </button>
                        <ul class="dropdown-menu">
                           <li><a class="dropdown-item" href="export-students.php?format=csv">CSV Format</a></li>
                           <li><a class="dropdown-item" href="export-students.php?format=excel">Excel Format</a></li>
                           <li><a class="dropdown-item" href="export-students.php?format=pdf">PDF Format</a></li>
                        </ul>
                     </div>
                  </div>
               </div>
               <div class="card-body">
                  <?php if (empty($students)): ?>
                     <div class="alert alert-info">
                        <?php if (!empty($searchTerm) || !empty($courseFilter) || !empty($yearFilter)): ?>
                           No students found matching your filters. <a href="students.php">Clear filters</a>
                        <?php else: ?>
                           No students found in the database. <a href="add-student.php">Add your first student</a> or <a href="import-students.php">import students</a>.
                        <?php endif; ?>
                     </div>
                  <?php else: ?>
                     <div class="table-responsive">
                        <table class="table table-hover students-table">
                           <thead>
                              <tr>
                                 <th>Student ID</th>
                                 <th>Name</th>
                                 <th>Email</th>
                                 <th>Course</th>
                                 <th>Year</th>
                                 <th>QR Code</th>
                                 <th>Actions</th>
                              </tr>
                           </thead>
                           <tbody>
                              <?php foreach ($students as $student): ?>
                                 <tr>
                                    <td><?= htmlspecialchars($student['student_id']) ?></td>
                                    <td><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></td>
                                    <td><?= htmlspecialchars($student['evsu_email']) ?></td>
                                    <td><?= htmlspecialchars($student['course']) ?></td>
                                    <td><?= $student['year_level'] ? 'Year ' . $student['year_level'] : 'N/A' ?></td>
                                    <td>
                                       <button class="btn btn-sm btn-outline-primary show-qr-btn" data-qr-code="<?= htmlspecialchars($student['qr_code']) ?>" data-student-name="<?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?>">
                                          <i class="bi bi-qr-code"></i> View QR
                                       </button>
                                    </td>
                                    <td class="student-actions">
                                       <a href="view-student.php?id=<?= urlencode($student['student_id']) ?>" class="btn btn-sm btn-outline-info">
                                          <i class="bi bi-eye"></i>
                                       </a>
                                       <a href="edit-student.php?id=<?= urlencode($student['student_id']) ?>" class="btn btn-sm btn-outline-secondary">
                                          <i class="bi bi-pencil"></i>
                                       </a>
                                       <button type="button" class="btn btn-sm btn-outline-danger"
                                          onclick="confirmDelete('<?= htmlspecialchars(addslashes($student['student_id'])) ?>', '<?= htmlspecialchars(addslashes($student['first_name'] . ' ' . $student['last_name'])) ?>')">
                                          <i class="bi bi-trash"></i>
                                       </button>
                                    </td>
                                 </tr>
                              <?php endforeach; ?>
                           </tbody>
                        </table>
                     </div>

                     <!-- Pagination -->
                     <?php if ($totalPages > 1): ?>
                        <nav aria-label="Students pagination">
                           <ul class="pagination justify-content-center mt-4">
                              <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                                 <a class="page-link" href="students.php?page=<?= $page - 1 ?>&search=<?= urlencode($searchTerm) ?>&course=<?= urlencode($courseFilter) ?>&year=<?= $yearFilter ?>" aria-label="Previous">
                                    <span aria-hidden="true">&laquo;</span>
                                 </a>
                              </li>

                              <?php
                              // Show limited page numbers with ellipsis
                              $startPage = max(1, min($page - 2, $totalPages - 4));
                              $endPage = min($totalPages, max($page + 2, 5));

                              if ($startPage > 1) {
                                 echo '<li class="page-item"><a class="page-link" href="students.php?page=1&search=' . urlencode($searchTerm) . '&course=' . urlencode($courseFilter) . '&year=' . $yearFilter . '">1</a></li>';
                                 if ($startPage > 2) {
                                    echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                 }
                              }

                              for ($i = $startPage; $i <= $endPage; $i++) {
                                 echo '<li class="page-item ' . ($page == $i ? 'active' : '') . '">';
                                 echo '<a class="page-link" href="students.php?page=' . $i . '&search=' . urlencode($searchTerm) . '&course=' . urlencode($courseFilter) . '&year=' . $yearFilter . '">' . $i . '</a>';
                                 echo '</li>';
                              }

                              if ($endPage < $totalPages) {
                                 if ($endPage < $totalPages - 1) {
                                    echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                 }
                                 echo '<li class="page-item"><a class="page-link" href="students.php?page=' . $totalPages . '&search=' . urlencode($searchTerm) . '&course=' . urlencode($courseFilter) . '&year=' . $yearFilter . '">' . $totalPages . '</a></li>';
                              }
                              ?>

                              <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
                                 <a class="page-link" href="students.php?page=<?= $page + 1 ?>&search=<?= urlencode($searchTerm) ?>&course=<?= urlencode($courseFilter) ?>&year=<?= $yearFilter ?>" aria-label="Next">
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

   <!-- QR Code Modal -->
   <div class="modal fade" id="qrCodeModal" tabindex="-1" aria-labelledby="qrCodeModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
         <div class="modal-content">
            <div class="modal-header">
               <h5 class="modal-title" id="qrCodeModalLabel">Student QR Code</h5>
               <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
               <h6 id="qrStudentName" class="mb-3"></h6>
               <div id="qrCodeDisplay" class="mb-3"></div>
               <button id="downloadQrBtn" class="btn btn-outline-primary">
                  <i class="bi bi-download"></i> Download QR Code
               </button>
            </div>
         </div>
      </div>
   </div>

   <!-- Delete Confirmation Modal -->
   <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
      <div class="modal-dialog">
         <div class="modal-content">
            <div class="modal-header">
               <h5 class="modal-title" id="deleteModalLabel">Confirm Delete</h5>
               <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
               <p>Are you sure you want to delete the student record for <span id="studentNameToDelete" class="fw-bold"></span>?</p>
               <p class="text-danger">This action cannot be undone. All registration and attendance records for this student will also be deleted.</p>
            </div>
            <div class="modal-footer">
               <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
               <a href="#" id="confirmDeleteBtn" class="btn btn-danger">Delete</a>
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

      // QR Code display functionality
      document.querySelectorAll('.show-qr-btn').forEach(button => {
         button.addEventListener('click', function() {
            const qrCode = this.getAttribute('data-qr-code');
            const studentName = this.getAttribute('data-student-name');

            document.getElementById('qrStudentName').textContent = studentName;

            // Generate QR code
            const qrDiv = document.getElementById('qrCodeDisplay');
            qrDiv.innerHTML = '';

            var typeNumber = 0;
            var errorCorrectionLevel = 'L';
            var qr = qrcode(typeNumber, errorCorrectionLevel);
            qr.addData(qrCode);
            qr.make();
            qrDiv.innerHTML = qr.createImgTag(5, 10);

            // Set up download button
            document.getElementById('downloadQrBtn').onclick = function() {
               const img = qrDiv.querySelector('img');
               const link = document.createElement('a');
               link.download = `QR_${studentName.replace(/[^a-z0-9]/gi, '_').toLowerCase()}.png`;
               link.href = img.src;
               link.click();
            };

            // Show modal
            const qrModal = new bootstrap.Modal(document.getElementById('qrCodeModal'));
            qrModal.show();
         });
      });

      // Delete confirmation
      let deleteModal;

      document.addEventListener('DOMContentLoaded', function() {
         deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
      });

      function confirmDelete(studentId, studentName) {
         document.getElementById('studentNameToDelete').textContent = studentName;
         document.getElementById('confirmDeleteBtn').href = `delete-student.php?id=${encodeURIComponent(studentId)}`;
         deleteModal.show();
      }
   </script>
</body>

</html>