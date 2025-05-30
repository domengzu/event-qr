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

// Initialize variables
$eventName = '';
$location = '';
$startTime = '';
$endTime = '';
$eventDate = '';
$description = '';
$errors = [];
$success = false;

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
   // Get and validate form data
   $eventName = trim($_POST['event_name'] ?? '');
   $location = trim($_POST['location'] ?? '');
   $startTime = trim($_POST['start_time'] ?? '');
   $endTime = trim($_POST['end_time'] ?? '');
   $eventDate = trim($_POST['event_date'] ?? '');
   $description = trim($_POST['description'] ?? '');

   // Validate required fields
   if (empty($eventName)) {
      $errors[] = 'Event name is required';
   }

   if (empty($location)) {
      $errors[] = 'Location is required';
   }

   if (empty($startTime)) {
      $errors[] = 'Start time is required';
   }

   if (empty($endTime)) {
      $errors[] = 'End time is required';
   }

   if (empty($eventDate)) {
      $errors[] = 'Event date is required';
   } elseif (strtotime($eventDate) < strtotime(date('Y-m-d'))) {
      $errors[] = 'Event date cannot be in the past';
   }

   // Handle event image upload
   $imagePath = '';
   if (!empty($_FILES['event_image']['name'])) {
      $targetDir = "../uploads/events/";

      // Create directory if it doesn't exist
      if (!file_exists($targetDir)) {
         mkdir($targetDir, 0777, true);
      }

      $fileName = time() . '_' . basename($_FILES["event_image"]["name"]);
      $targetFilePath = $targetDir . $fileName;
      $fileType = pathinfo($targetFilePath, PATHINFO_EXTENSION);

      // Check file size and type
      $allowTypes = array('jpg', 'jpeg', 'png');
      if (in_array(strtolower($fileType), $allowTypes)) {
         if ($_FILES['event_image']['size'] < 2000000) { // 2MB limit
            if (move_uploaded_file($_FILES["event_image"]["tmp_name"], $targetFilePath)) {
               $imagePath = "uploads/events/" . $fileName;
            } else {
               $errors[] = "Failed to upload image.";
            }
         } else {
            $errors[] = "Image file is too large. Maximum file size is 2MB.";
         }
      } else if (!empty($_FILES['event_image']['name'])) {
         $errors[] = "Only JPG, JPEG, and PNG files are allowed.";
      }
   }

   // Try to insert with the column if it exists
   if (empty($errors)) {
      try {
         $conn = getDBConnection();

         // Check if the events table has the event_image column
         $checkColumnSql = "SHOW COLUMNS FROM events LIKE 'event_image'";
         $columnResult = $conn->query($checkColumnSql);
         $hasEventImageColumn = $columnResult->num_rows > 0;

         if ($hasEventImageColumn) {
            // If column exists, include it
            $sql = "INSERT INTO events (event_name, location, start_time, end_time, event_date, description, event_image, created_by)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssssssi", $eventName, $location, $startTime, $endTime, $eventDate, $description, $imagePath, $staffId);
         } else {
            // If column doesn't exist, exclude it
            $sql = "INSERT INTO events (event_name, location, start_time, end_time, event_date, description, created_by)
                   VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssssssi", $eventName, $location, $startTime, $endTime, $eventDate, $description, $staffId);
         }

         if ($stmt->execute()) {
            $eventId = $conn->insert_id;
            header("Location: view-event.php?id=$eventId&created=1");
            exit;
         } else {
            $errors[] = 'Error creating event: ' . $stmt->error;
         }
      } catch (Exception $e) {
         $errors[] = "Database error: " . $e->getMessage();
      }
   }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Create Event - EventQR</title>
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

         <!-- Create Event Content -->
         <div class="container-fluid dashboard-container">
            <div class="d-flex justify-content-between align-items-center mb-4">
               <h1>Create New Event</h1>
               <a href="events.php" class="btn btn-outline-secondary">
                  <i class="bi bi-arrow-left me-2"></i>Back to Events
               </a>
            </div>

            <?php if (!empty($errors)): ?>
               <div class="alert alert-danger alert-dismissible fade show" role="alert">
                  <strong>Error!</strong>
                  <ul class="mb-0">
                     <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                     <?php endforeach; ?>
                  </ul>
                  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
               </div>
            <?php endif; ?>

            <div class="card">
               <div class="card-body">
                  <form action="create-event.php" method="post" enctype="multipart/form-data">
                     <div class="row mb-3">
                        <div class="col-md-6">
                           <label for="event_name" class="form-label">Event Name <span class="text-danger">*</span></label>
                           <input type="text" class="form-control" id="event_name" name="event_name" value="<?= htmlspecialchars($eventName) ?>" required>
                        </div>
                        <div class="col-md-6">
                           <label for="location" class="form-label">Location <span class="text-danger">*</span></label>
                           <input type="text" class="form-control" id="location" name="location" value="<?= htmlspecialchars($location) ?>" required>
                        </div>
                     </div>

                     <div class="row mb-3">
                        <div class="col-md-4">
                           <label for="event_date" class="form-label">Event Date <span class="text-danger">*</span></label>
                           <input type="date" class="form-control" id="event_date" name="event_date" value="<?= htmlspecialchars($eventDate) ?>" min="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-4">
                           <label for="start_time" class="form-label">Start Time <span class="text-danger">*</span></label>
                           <input type="time" class="form-control" id="start_time" name="start_time" value="<?= htmlspecialchars($startTime) ?>" required>
                        </div>
                        <div class="col-md-4">
                           <label for="end_time" class="form-label">End Time <span class="text-danger">*</span></label>
                           <input type="time" class="form-control" id="end_time" name="end_time" value="<?= htmlspecialchars($endTime) ?>" required>
                        </div>
                     </div>

                     <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="5"><?= htmlspecialchars($description) ?></textarea>
                     </div>

                     <div class="mb-3">
                        <label for="event_image" class="form-label">Event Image (Optional)</label>
                        <input type="file" class="form-control" id="event_image" name="event_image" accept="image/*">
                        <div class="form-text">Upload an image for the event (max 2MB, JPG/PNG format)</div>
                     </div>

                     <div class="d-flex justify-content-end">
                        <button type="reset" class="btn btn-outline-secondary me-2">Reset</button>
                        <button type="submit" class="btn btn-primary">Create Event</button>
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

      // Validate end time is after start time
      document.querySelector('form').addEventListener('submit', function(e) {
         var startTime = document.getElementById('start_time').value;
         var endTime = document.getElementById('end_time').value;

         if (startTime && endTime && startTime >= endTime) {
            e.preventDefault();
            alert('End time must be after start time');
         }
      });
   </script>
</body>

</html>