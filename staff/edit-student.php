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

$conn = getDBConnection();

// Fetch staff profile picture
$staffData = [];
$staffSql = "SELECT profile_picture FROM staff WHERE staff_id = ?";
$stmt = $conn->prepare($staffSql);
$stmt->bind_param("i", $staffId);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $result->num_rows > 0) {
   $staffData = $result->fetch_assoc();
}

// Check if student ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
   header("Location: students.php");
   exit;
}

$studentId = $_GET['id'];

// Get database connection
$conn = getDBConnection();

// Get available courses for dropdown
$courses = [];
$coursesQuery = "SELECT DISTINCT course FROM students WHERE course IS NOT NULL AND course != '' ORDER BY course";
$result = $conn->query($coursesQuery);
if ($result) {
   while ($row = $result->fetch_assoc()) {
      $courses[] = $row['course'];
   }
}

// Initialize variables
$formData = [
   'student_id' => '',
   'first_name' => '',
   'last_name' => '',
   'evsu_email' => '',
   'course' => '',
   'year_level' => '',
   'guardian_contact' => '',
];
$errors = [];
$success = false;

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
$formData = $student;

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
   // Get form data
   $formData = [
      'student_id' => trim($_POST['student_id'] ?? ''),
      'first_name' => trim($_POST['first_name'] ?? ''),
      'last_name' => trim($_POST['last_name'] ?? ''),
      'evsu_email' => trim($_POST['evsu_email'] ?? ''),
      'course' => trim($_POST['course'] ?? ''),
      'year_level' => (int)($_POST['year_level'] ?? 0),
      'guardian_contact' => trim($_POST['guardian_contact'] ?? ''),
   ];

   // Validate required fields
   if (empty($formData['student_id'])) {
      $errors['student_id'] = 'Student ID is required';
   }

   if (empty($formData['first_name'])) {
      $errors['first_name'] = 'First name is required';
   }

   if (empty($formData['last_name'])) {
      $errors['last_name'] = 'Last name is required';
   }

   // Validate student ID format (you can customize this validation)
   if (!empty($formData['student_id']) && !preg_match('/^[0-9a-zA-Z\-]+$/', $formData['student_id'])) {
      $errors['student_id'] = 'Invalid student ID format';
   }

   // Check if student_id is unique (but exclude current student)
   if ($formData['student_id'] !== $studentId) {
      $checkSql = "SELECT COUNT(*) as count FROM students WHERE student_id = ? AND student_id != ?";
      $stmt = $conn->prepare($checkSql);
      $stmt->bind_param("ss", $formData['student_id'], $studentId);
      $stmt->execute();
      $result = $stmt->get_result();
      $count = $result->fetch_assoc()['count'];

      if ($count > 0) {
         $errors['student_id'] = 'This student ID is already in use';
      }
   }

   // Validate email format
   if (!empty($formData['evsu_email']) && !filter_var($formData['evsu_email'], FILTER_VALIDATE_EMAIL)) {
      $errors['evsu_email'] = 'Invalid email format';
   }

   // Validate phone number format (if provided)
   if (!empty($formData['guardian_contact']) && !preg_match('/^[0-9\+\-\s]+$/', $formData['guardian_contact'])) {
      $errors['guardian_contact'] = 'Invalid phone number format';
   }

   // If no errors, update the student record
   if (empty($errors)) {
      try {
         // Start transaction
         $conn->begin_transaction();

         // If student_id has changed, we need to update all related records
         if ($formData['student_id'] !== $studentId) {
            // Update registrations
            $updateRegSql = "UPDATE event_registrations SET student_id = ? WHERE student_id = ?";
            $stmt = $conn->prepare($updateRegSql);
            $stmt->bind_param("ss", $formData['student_id'], $studentId);
            $stmt->execute();

            // Update attendance
            $updateAttSql = "UPDATE attendance SET student_id = ? WHERE student_id = ?";
            $stmt = $conn->prepare($updateAttSql);
            $stmt->bind_param("ss", $formData['student_id'], $studentId);
            $stmt->execute();

            // Delete old student record
            $deleteSql = "DELETE FROM students WHERE student_id = ?";
            $stmt = $conn->prepare($deleteSql);
            $stmt->bind_param("s", $studentId);
            $stmt->execute();

            // Insert new student record with updated ID
            $insertSql = "INSERT INTO students (student_id, qr_code, first_name, last_name, evsu_email, course, year_level, managed_by, created_at, guardian_contact) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)";
            $stmt = $conn->prepare($insertSql);
            $stmt->bind_param(
               "ssssssiis",
               $formData['student_id'],
               $student['qr_code'],
               $formData['first_name'],
               $formData['last_name'],
               $formData['evsu_email'],
               $formData['course'],
               $formData['year_level'],
               $staffId,
               $formData['guardian_contact']
            );
            $stmt->execute();
         } else {
            // Just update the existing record
            $updateSql = "UPDATE students SET 
                            first_name = ?,
                            last_name = ?,
                            evsu_email = ?,
                            course = ?,
                            year_level = ?,
                            managed_by = ?,
                            guardian_contact = ?
                            WHERE student_id = ?";
            $stmt = $conn->prepare($updateSql);
            $stmt->bind_param(
               "ssssisss",
               $formData['first_name'],
               $formData['last_name'],
               $formData['evsu_email'],
               $formData['course'],
               $formData['year_level'],
               $staffId,
               $formData['guardian_contact'],
               $formData['student_id']
            );
            $stmt->execute();
         }

         // Commit transaction
         $conn->commit();

         // Set success flag and redirect
         $success = true;
         header("Location: view-student.php?id=" . urlencode($formData['student_id']) . "&edited=1");
         exit;
      } catch (Exception $e) {
         // Roll back transaction on error
         $conn->rollback();
         $errors['general'] = 'An error occurred: ' . $e->getMessage();
      }
   }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Edit Student - <?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></title>
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
         <?php include '../includes/header.php' ?>

         <!-- Edit Student Content -->
         <div class="container-fluid dashboard-container">
            <div class="d-flex justify-content-between align-items-center mb-4">
               <div>
                  <h1>Edit Student</h1>
                  <nav aria-label="breadcrumb">
                     <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="students.php">Students</a></li>
                        <li class="breadcrumb-item"><a href="view-student.php?id=<?= urlencode($studentId) ?>"><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></a></li>
                        <li class="breadcrumb-item active" aria-current="page">Edit</li>
                     </ol>
                  </nav>
               </div>
               <a href="view-student.php?id=<?= urlencode($studentId) ?>" class="btn btn-outline-secondary">
                  <i class="bi bi-arrow-left me-2"></i>Back to Student Profile
               </a>
            </div>

            <?php if (isset($errors['general'])): ?>
               <div class="alert alert-danger" role="alert">
                  <?= htmlspecialchars($errors['general']) ?>
               </div>
            <?php endif; ?>

            <!-- Edit Student Form -->
            <div class="card">
               <div class="card-header">
                  <h5 class="mb-0">Student Information</h5>
               </div>
               <div class="card-body">
                  <form action="edit-student.php?id=<?= urlencode($studentId) ?>" method="post">
                     <div class="row">
                        <div class="col-md-6 mb-3">
                           <label for="student_id" class="form-label">Student ID <span class="text-danger">*</span></label>
                           <input type="text" class="form-control <?= isset($errors['student_id']) ? 'is-invalid' : '' ?>" id="student_id" name="student_id" value="<?= htmlspecialchars($formData['student_id']) ?>" required>
                           <?php if (isset($errors['student_id'])): ?>
                              <div class="invalid-feedback"><?= htmlspecialchars($errors['student_id']) ?></div>
                           <?php endif; ?>
                           <small class="form-text text-muted">Warning: Changing the student ID will update all related records.</small>
                        </div>
                        <div class="col-md-6 mb-3">
                           <label for="qr_code" class="form-label">QR Code</label>
                           <input type="text" class="form-control" id="qr_code" value="<?= htmlspecialchars($student['qr_code']) ?>" readonly disabled>
                           <small class="form-text text-muted">QR Code cannot be changed manually.</small>
                        </div>
                     </div>

                     <div class="row">
                        <div class="col-md-6 mb-3">
                           <label for="first_name" class="form-label">First Name <span class="text-danger">*</span></label>
                           <input type="text" class="form-control <?= isset($errors['first_name']) ? 'is-invalid' : '' ?>" id="first_name" name="first_name" value="<?= htmlspecialchars($formData['first_name']) ?>" required>
                           <?php if (isset($errors['first_name'])): ?>
                              <div class="invalid-feedback"><?= htmlspecialchars($errors['first_name']) ?></div>
                           <?php endif; ?>
                        </div>
                        <div class="col-md-6 mb-3">
                           <label for="last_name" class="form-label">Last Name <span class="text-danger">*</span></label>
                           <input type="text" class="form-control <?= isset($errors['last_name']) ? 'is-invalid' : '' ?>" id="last_name" name="last_name" value="<?= htmlspecialchars($formData['last_name']) ?>" required>
                           <?php if (isset($errors['last_name'])): ?>
                              <div class="invalid-feedback"><?= htmlspecialchars($errors['last_name']) ?></div>
                           <?php endif; ?>
                        </div>
                     </div>

                     <div class="row">
                        <div class="col-md-6 mb-3">
                           <label for="evsu_email" class="form-label">Email Address</label>
                           <input type="email" class="form-control <?= isset($errors['evsu_email']) ? 'is-invalid' : '' ?>" id="evsu_email" name="evsu_email" value="<?= htmlspecialchars($formData['evsu_email']) ?>">
                           <?php if (isset($errors['evsu_email'])): ?>
                              <div class="invalid-feedback"><?= htmlspecialchars($errors['evsu_email']) ?></div>
                           <?php endif; ?>
                        </div>
                        <div class="col-md-6 mb-3">
                           <label for="guardian_contact" class="form-label">Guardian Contact</label>
                           <input type="text" class="form-control <?= isset($errors['guardian_contact']) ? 'is-invalid' : '' ?>" id="guardian_contact" name="guardian_contact" value="<?= htmlspecialchars($formData['guardian_contact']) ?>">
                           <?php if (isset($errors['guardian_contact'])): ?>
                              <div class="invalid-feedback"><?= htmlspecialchars($errors['guardian_contact']) ?></div>
                           <?php endif; ?>
                        </div>
                     </div>

                     <div class="row">
                        <div class="col-md-6 mb-3">
                           <label for="course" class="form-label">Course</label>
                           <select class="form-select" id="course" name="course">
                              <option value="">Select Course</option>
                              <?php foreach ($courses as $course): ?>
                                 <option value="<?= htmlspecialchars($course) ?>" <?= $formData['course'] === $course ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($course) ?>
                                 </option>
                              <?php endforeach; ?>
                              <option value="other" <?= !in_array($formData['course'], $courses) && !empty($formData['course']) ? 'selected' : '' ?>>Other</option>
                           </select>
                           <div id="otherCourseContainer" class="mt-2 <?= !in_array($formData['course'], $courses) && !empty($formData['course']) ? '' : 'd-none' ?>">
                              <input type="text" class="form-control" id="otherCourse" placeholder="Specify course" value="<?= !in_array($formData['course'], $courses) && !empty($formData['course']) ? htmlspecialchars($formData['course']) : '' ?>">
                           </div>
                        </div>
                        <div class="col-md-6 mb-3">
                           <label for="year_level" class="form-label">Year Level</label>
                           <select class="form-select" id="year_level" name="year_level">
                              <option value="">Select Year Level</option>
                              <option value="1" <?= $formData['year_level'] == 1 ? 'selected' : '' ?>>Year 1</option>
                              <option value="2" <?= $formData['year_level'] == 2 ? 'selected' : '' ?>>Year 2</option>
                              <option value="3" <?= $formData['year_level'] == 3 ? 'selected' : '' ?>>Year 3</option>
                              <option value="4" <?= $formData['year_level'] == 4 ? 'selected' : '' ?>>Year 4</option>
                              <option value="5" <?= $formData['year_level'] == 5 ? 'selected' : '' ?>>Year 5</option>
                           </select>
                        </div>
                     </div>

                     <div class="mt-4 d-flex justify-content-between">
                        <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#deleteStudentModal">
                           <i class="bi bi-trash me-2"></i>Delete Student
                        </button>
                        <div>
                           <a href="view-student.php?id=<?= urlencode($studentId) ?>" class="btn btn-outline-secondary me-2">Cancel</a>
                           <button type="submit" class="btn btn-primary">Save Changes</button>
                        </div>
                     </div>
                  </form>
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

      // Handle "Other" course selection
      document.getElementById('course').addEventListener('change', function() {
         const otherCourseContainer = document.getElementById('otherCourseContainer');
         if (this.value === 'other') {
            otherCourseContainer.classList.remove('d-none');
            document.getElementById('otherCourse').focus();
         } else {
            otherCourseContainer.classList.add('d-none');
         }
      });

      // Handle form submission with custom course
      document.querySelector('form').addEventListener('submit', function(e) {
         const courseSelect = document.getElementById('course');
         if (courseSelect.value === 'other') {
            e.preventDefault();
            const otherCourseValue = document.getElementById('otherCourse').value.trim();
            if (otherCourseValue === '') {
               alert('Please specify the course name');
               document.getElementById('otherCourse').focus();
            } else {
               // Create and append a hidden input with the custom course
               const hiddenInput = document.createElement('input');
               hiddenInput.type = 'hidden';
               hiddenInput.name = 'course';
               hiddenInput.value = otherCourseValue;
               this.appendChild(hiddenInput);
               this.submit();
            }
         }
      });
   </script>
</body>

</html>