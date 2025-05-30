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

// Get all courses for dropdown
$courses = [];
$coursesQuery = "SELECT DISTINCT course FROM students WHERE course IS NOT NULL AND course != '' ORDER BY course";
$result = $conn->query($coursesQuery);
if ($result) {
   while ($row = $result->fetch_assoc()) {
      $courses[] = $row['course'];
   }
}

// Initialize variables for form data and errors
$formData = [
   'student_id' => '',
   'first_name' => '',
   'last_name' => '',
   'middle_name' => '',
   'evsu_email' => '',
   'personal_email' => '',
   'phone' => '',
   'course' => '',
   'year_level' => '',
   'gender' => '',
   'address' => ''
];

$errors = [];
$successMessage = '';

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
   // Get form data with validation
   $formData = [
      'student_id' => trim($_POST['student_id'] ?? ''),
      'first_name' => trim($_POST['first_name'] ?? ''),
      'last_name' => trim($_POST['last_name'] ?? ''),
      'middle_name' => trim($_POST['middle_name'] ?? ''),
      'evsu_email' => trim($_POST['evsu_email'] ?? ''),
      'personal_email' => trim($_POST['personal_email'] ?? ''),
      'phone' => trim($_POST['phone'] ?? ''),
      'course' => trim($_POST['course'] ?? ''),
      'year_level' => trim($_POST['year_level'] ?? ''),
      'gender' => trim($_POST['gender'] ?? ''),
      'address' => trim($_POST['address'] ?? '')
   ];

   // Validate required fields
   if (empty($formData['student_id'])) {
      $errors[] = 'Student ID is required.';
   } elseif (!preg_match('/^\d{4}-\d{5}-[A-Z]{2}-\d{1}$/', $formData['student_id'])) {
      $errors[] = 'Student ID format should be like "2020-12345-AB-1".';
   }

   if (empty($formData['first_name'])) {
      $errors[] = 'First name is required.';
   }

   if (empty($formData['last_name'])) {
      $errors[] = 'Last name is required.';
   }

   if (!empty($formData['evsu_email']) && !filter_var($formData['evsu_email'], FILTER_VALIDATE_EMAIL)) {
      $errors[] = 'Please enter a valid EVSU email address.';
   }

   if (!empty($formData['personal_email']) && !filter_var($formData['personal_email'], FILTER_VALIDATE_EMAIL)) {
      $errors[] = 'Please enter a valid personal email address.';
   }

   if (!empty($formData['year_level']) && (!is_numeric($formData['year_level']) || $formData['year_level'] < 1 || $formData['year_level'] > 5)) {
      $errors[] = 'Year level must be a number between 1 and 5.';
   }

   // Check if student ID already exists
   $checkSql = "SELECT student_id FROM students WHERE student_id = ?";
   $stmt = $conn->prepare($checkSql);
   $stmt->bind_param("s", $formData['student_id']);
   $stmt->execute();
   $result = $stmt->get_result();

   if ($result->num_rows > 0) {
      $errors[] = 'Student ID already exists in the system.';
   }

   // If no errors, insert new student
   if (empty($errors)) {
      try {
         // Generate QR code (student ID + timestamp)
         $qrCode = $formData['student_id'] . '-' . time();

         $insertSql = "INSERT INTO students (
               student_id, first_name, last_name, middle_name, evsu_email, personal_email, 
               phone, course, year_level, gender, address, qr_code, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

         $stmt = $conn->prepare($insertSql);
         $stmt->bind_param(
            "ssssssssssss",
            $formData['student_id'],
            $formData['first_name'],
            $formData['last_name'],
            $formData['middle_name'],
            $formData['evsu_email'],
            $formData['personal_email'],
            $formData['phone'],
            $formData['course'],
            $formData['year_level'],
            $formData['gender'],
            $formData['address'],
            $qrCode
         );

         if ($stmt->execute()) {
            // Record user activity
            $activitySql = "INSERT INTO activity_logs (staff_id, action, details, created_at) 
                           VALUES (?, 'add_student', ?, NOW())";
            $logDetails = "Added new student: {$formData['first_name']} {$formData['last_name']} ({$formData['student_id']})";
            $stmt = $conn->prepare($activitySql);
            $stmt->bind_param("is", $staffId, $logDetails);
            $stmt->execute();

            $successMessage = "Student added successfully!";

            // Reset form after successful submission
            if (!isset($_POST['add_another'])) {
               // Redirect to student list if not adding another
               header("Location: students.php?added=success&id=" . urlencode($formData['student_id']));
               exit;
            }

            // Reset form data if adding another student
            $formData = [
               'student_id' => '',
               'first_name' => '',
               'last_name' => '',
               'middle_name' => '',
               'evsu_email' => '',
               'personal_email' => '',
               'phone' => '',
               'course' => $formData['course'], // Keep the same course
               'year_level' => $formData['year_level'], // Keep the same year level
               'gender' => '',
               'address' => ''
            ];
         } else {
            $errors[] = 'Database error: ' . $stmt->error;
         }
      } catch (Exception $e) {
         $errors[] = 'Error: ' . $e->getMessage();
      }
   }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Add Student - EventQR</title>
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

      .form-card {
         background-color: #fff;
         border-radius: 10px;
         box-shadow: 0 0 10px rgba(0, 0, 0, 0.05);
         padding: 20px;
         margin-bottom: 20px;
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
         <?php include '../includes/header.php'; ?>

         <!-- Add Student Content -->
         <div class="container-fluid dashboard-container">
            <div class="d-flex justify-content-between align-items-center mb-4">
               <div>
                  <h1>Add New Student</h1>
                  <div class="text-muted">Enter student information to add to the system</div>
               </div>
               <a href="students.php" class="btn btn-outline-secondary">
                  <i class="bi bi-arrow-left me-2"></i>Back to Students
               </a>
            </div>

            <!-- Display errors if any -->
            <?php if (!empty($errors)): ?>
               <div class="alert alert-danger">
                  <ul class="mb-0">
                     <?php foreach ($errors as $error): ?>
                        <li><?= $error ?></li>
                     <?php endforeach; ?>
                  </ul>
               </div>
            <?php endif; ?>

            <!-- Display success message -->
            <?php if (!empty($successMessage)): ?>
               <div class="alert alert-success alert-dismissible fade show" role="alert">
                  <i class="bi bi-check-circle me-2"></i> <?= $successMessage ?>
                  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
               </div>
            <?php endif; ?>

            <!-- Add Student Form -->
            <div class="form-card">
               <form method="post" action="add-student.php" id="add-student-form">
                  <div class="row">
                     <!-- Left Column -->
                     <div class="col-lg-6">
                        <h4 class="mb-4">Student Information</h4>

                        <div class="mb-3">
                           <label for="student_id" class="form-label">Student ID <span class="text-danger">*</span></label>
                           <input type="text" class="form-control" id="student_id" name="student_id" value="<?= htmlspecialchars($formData['student_id']) ?>" placeholder="Format: 2020-12345-AB-1" required>
                           <div class="form-text">The official student ID format (e.g., 2020-12345-AB-1)</div>
                        </div>

                        <div class="row">
                           <div class="col-md-5 mb-3">
                              <label for="first_name" class="form-label">First Name <span class="text-danger">*</span></label>
                              <input type="text" class="form-control" id="first_name" name="first_name" value="<?= htmlspecialchars($formData['first_name']) ?>" required>
                           </div>
                           <div class="col-md-4 mb-3">
                              <label for="middle_name" class="form-label">Middle Name</label>
                              <input type="text" class="form-control" id="middle_name" name="middle_name" value="<?= htmlspecialchars($formData['middle_name']) ?>">
                           </div>
                           <div class="col-md-3 mb-3">
                              <label for="last_name" class="form-label">Last Name <span class="text-danger">*</span></label>
                              <input type="text" class="form-control" id="last_name" name="last_name" value="<?= htmlspecialchars($formData['last_name']) ?>" required>
                           </div>
                        </div>

                        <div class="mb-3">
                           <label for="gender" class="form-label">Gender</label>
                           <select class="form-select" id="gender" name="gender">
                              <option value="" <?= empty($formData['gender']) ? 'selected' : '' ?>>-- Select Gender --</option>
                              <option value="Male" <?= $formData['gender'] === 'Male' ? 'selected' : '' ?>>Male</option>
                              <option value="Female" <?= $formData['gender'] === 'Female' ? 'selected' : '' ?>>Female</option>
                              <option value="Other" <?= $formData['gender'] === 'Other' ? 'selected' : '' ?>>Other</option>
                              <option value="Prefer not to say" <?= $formData['gender'] === 'Prefer not to say' ? 'selected' : '' ?>>Prefer not to say</option>
                           </select>
                        </div>

                        <div class="mb-3">
                           <label for="address" class="form-label">Address</label>
                           <textarea class="form-control" id="address" name="address" rows="2"><?= htmlspecialchars($formData['address']) ?></textarea>
                        </div>
                     </div>

                     <!-- Right Column -->
                     <div class="col-lg-6">
                        <h4 class="mb-4">Academic & Contact Details</h4>

                        <div class="row">
                           <div class="col-md-6 mb-3">
                              <label for="course" class="form-label">Course</label>
                              <select class="form-select" id="course" name="course">
                                 <option value="">-- Select Course --</option>
                                 <?php foreach ($courses as $course): ?>
                                    <option value="<?= htmlspecialchars($course) ?>" <?= $formData['course'] === $course ? 'selected' : '' ?>><?= htmlspecialchars($course) ?></option>
                                 <?php endforeach; ?>
                                 <option value="other" <?= !in_array($formData['course'], $courses) && !empty($formData['course']) ? 'selected' : '' ?>>Other</option>
                              </select>
                           </div>
                           <div class="col-md-6 mb-3" id="otherCourseContainer" style="display: none;">
                              <label for="other_course" class="form-label">Specify Course</label>
                              <input type="text" class="form-control" id="other_course" placeholder="Enter course name" value="<?= !in_array($formData['course'], $courses) && !empty($formData['course']) ? htmlspecialchars($formData['course']) : '' ?>">
                           </div>
                           <div class="col-md-6 mb-3">
                              <label for="year_level" class="form-label">Year Level</label>
                              <select class="form-select" id="year_level" name="year_level">
                                 <option value="">-- Select Year Level --</option>
                                 <option value="1" <?= $formData['year_level'] === '1' ? 'selected' : '' ?>>1st Year</option>
                                 <option value="2" <?= $formData['year_level'] === '2' ? 'selected' : '' ?>>2nd Year</option>
                                 <option value="3" <?= $formData['year_level'] === '3' ? 'selected' : '' ?>>3rd Year</option>
                                 <option value="4" <?= $formData['year_level'] === '4' ? 'selected' : '' ?>>4th Year</option>
                                 <option value="5" <?= $formData['year_level'] === '5' ? 'selected' : '' ?>>5th Year</option>
                              </select>
                           </div>
                        </div>

                        <div class="mb-3">
                           <label for="evsu_email" class="form-label">EVSU Email</label>
                           <input type="email" class="form-control" id="evsu_email" name="evsu_email" value="<?= htmlspecialchars($formData['evsu_email']) ?>" placeholder="student@evsu.edu.ph">
                        </div>

                        <div class="mb-3">
                           <label for="personal_email" class="form-label">Personal Email</label>
                           <input type="email" class="form-control" id="personal_email" name="personal_email" value="<?= htmlspecialchars($formData['personal_email']) ?>">
                        </div>

                        <div class="mb-3">
                           <label for="phone" class="form-label">Phone Number</label>
                           <input type="tel" class="form-control" id="phone" name="phone" value="<?= htmlspecialchars($formData['phone']) ?>" placeholder="e.g., 09123456789">
                        </div>
                     </div>
                  </div>

                  <hr class="my-4">

                  <div class="row">
                     <div class="col-12">
                        <div class="d-flex justify-content-between">
                           <div class="form-check">
                              <input class="form-check-input" type="checkbox" id="add_another" name="add_another" value="1">
                              <label class="form-check-label" for="add_another">
                                 Add another student after saving
                              </label>
                           </div>
                           <div>
                              <button type="reset" class="btn btn-outline-secondary me-2">
                                 <i class="bi bi-arrow-counterclockwise me-1"></i> Reset
                              </button>
                              <button type="submit" class="btn btn-primary">
                                 <i class="bi bi-person-plus me-1"></i> Add Student
                              </button>
                           </div>
                        </div>
                     </div>
                  </div>
               </form>
            </div>

            <!-- Bulk Import Card -->
            <div class="form-card">
               <div class="d-flex justify-content-between align-items-center mb-3">
                  <h4 class="mb-0">Bulk Import Students</h4>
                  <a href="templates/student_import_template.xlsx" class="btn btn-sm btn-outline-primary" download>
                     <i class="bi bi-download me-1"></i> Download Template
                  </a>
               </div>
               <p class="text-muted">You can import multiple students at once using a CSV or Excel file.</p>
               <a href="import-students.php" class="btn btn-outline-success">
                  <i class="bi bi-upload me-1"></i> Import Students
               </a>
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

      // Format student ID as user types
      document.getElementById('student_id').addEventListener('input', function(e) {
         let value = e.target.value.replace(/[^0-9A-Za-z-]/g, '').toUpperCase();
         const parts = value.split('-');

         if (parts[0] && parts[0].length > 4) {
            parts[0] = parts[0].substring(0, 4);
         }

         if (parts[1] && parts[1].length > 5) {
            parts[1] = parts[1].substring(0, 5);
         }

         if (parts[2] && parts[2].length > 2) {
            parts[2] = parts[2].substring(0, 2);
         }

         if (parts[3] && parts[3].length > 1) {
            parts[3] = parts[3].substring(0, 1);
         }

         // Join back with hyphens
         e.target.value = parts.join('-');
      });

      // Handle "Other" course option
      document.getElementById('course').addEventListener('change', function() {
         const otherCourseContainer = document.getElementById('otherCourseContainer');
         const otherCourseInput = document.getElementById('other_course');

         if (this.value === 'other') {
            otherCourseContainer.style.display = 'block';
            otherCourseInput.required = true;
         } else {
            otherCourseContainer.style.display = 'none';
            otherCourseInput.required = false;
         }
      });

      // Set course value from "Other" field when submitting
      document.getElementById('add-student-form').addEventListener('submit', function(e) {
         const courseSelect = document.getElementById('course');
         const otherCourse = document.getElementById('other_course');

         if (courseSelect.value === 'other' && otherCourse.value.trim()) {
            courseSelect.value = otherCourse.value.trim();
         }
      });

      // Initialize "Other" course display on page load
      window.addEventListener('DOMContentLoaded', function() {
         const courseSelect = document.getElementById('course');
         if (courseSelect.value === 'other') {
            document.getElementById('otherCourseContainer').style.display = 'block';
         }
      });
   </script>
</body>

</html>