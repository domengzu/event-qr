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

// Initialize variables
$errorMessage = '';
$successMessage = '';
$staffData = [];

// Fetch staff details
$staffSql = "SELECT * FROM staff WHERE staff_id = ?";
$stmt = $conn->prepare($staffSql);
$stmt->bind_param("i", $staffId);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
   $staffData = $result->fetch_assoc();
} else {
   $errorMessage = "Could not retrieve staff information.";
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
   // Get form data
   $newFullName = trim($_POST['full_name']);
   $newEmail = trim($_POST['email']);
   $newPhone = trim($_POST['phone']);
   $newDepartment = trim($_POST['department']);

   // Basic validation
   if (empty($newFullName)) {
      $errorMessage = "Full name cannot be empty.";
   } elseif (!empty($newEmail) && !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
      $errorMessage = "Please enter a valid email address.";
   } else {
      // Update profile information
      $updateSql = "UPDATE staff SET 
                     full_name = ?, 
                     email = ?, 
                     phone = ?, 
                     department = ?, 
                     updated_at = NOW() 
                     WHERE staff_id = ?";

      $stmt = $conn->prepare($updateSql);
      $stmt->bind_param("ssssi", $newFullName, $newEmail, $newPhone, $newDepartment, $staffId);

      if ($stmt->execute()) {
         // Update session data
         $_SESSION['full_name'] = $newFullName;
         $successMessage = "Profile updated successfully!";

         // Refresh staff data
         $stmt = $conn->prepare($staffSql);
         $stmt->bind_param("i", $staffId);
         $stmt->execute();
         $result = $stmt->get_result();
         if ($result) {
            $staffData = $result->fetch_assoc();
         }
      } else {
         $errorMessage = "Error updating profile: " . $conn->error;
      }
   }
}

// Process profile picture upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_picture'])) {
   if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
      $file = $_FILES['profile_picture'];

      // Check file type
      $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
      if (!in_array($file['type'], $allowedTypes)) {
         $errorMessage = "Only JPG, PNG, and GIF images are allowed.";
      } else if ($file['size'] > 5 * 1024 * 1024) {
         $errorMessage = "File size should be less than 5MB.";
      } else {
         // Create directory if it doesn't exist
         $uploadDir = "../uploads/staff_profiles/";
         if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
         }

         // Generate unique filename
         $fileExt = pathinfo($file['name'], PATHINFO_EXTENSION);
         $newFileName = $staffId . '_' . time() . '.' . $fileExt;
         $destination = $uploadDir . $newFileName;

         if (move_uploaded_file($file['tmp_name'], $destination)) {
            // Update database with new profile picture path
            $relativePath = "uploads/staff_profiles/" . $newFileName;

            $updatePicSql = "UPDATE staff SET profile_picture = ?, updated_at = NOW() WHERE staff_id = ?";
            $stmt = $conn->prepare($updatePicSql);
            $stmt->bind_param("si", $relativePath, $staffId);

            if ($stmt->execute()) {
               $successMessage = "Profile picture updated successfully!";

               // Refresh staff data
               $stmt = $conn->prepare($staffSql);
               $stmt->bind_param("i", $staffId);
               $stmt->execute();
               $result = $stmt->get_result();
               if ($result) {
                  $staffData = $result->fetch_assoc();
               }
            } else {
               $errorMessage = "Error updating profile picture: " . $conn->error;
            }
         } else {
            $errorMessage = "Error uploading file.";
         }
      }
   } else if ($_FILES['profile_picture']['error'] !== UPLOAD_ERR_NO_FILE) {
      $errorMessage = "Error uploading file. Error code: " . $_FILES['profile_picture']['error'];
   }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Profile - EventQR</title>
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

      /* Profile styles */
      .profile-header {
         background-color: #fff;
         border-radius: 10px;
         box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
         padding: 30px;
         position: relative;
      }

      .profile-picture {
         width: 150px;
         height: 150px;
         border-radius: 50%;
         object-fit: cover;
         border: 5px solid #fff;
         box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
      }

      .profile-picture-edit {
         position: absolute;
         bottom: 0;
         right: 0;
         background-color: #F3C623;
         width: 40px;
         height: 40px;
         border-radius: 50%;
         display: flex;
         align-items: center;
         justify-content: center;
         box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
         cursor: pointer;
      }

      .profile-tabs .nav-link {
         color: #222831;
         font-weight: 500;
         border: none;
         padding: 15px 20px;
      }

      .profile-tabs .nav-link.active {
         color: #F3C623;
         background-color: transparent;
         border-bottom: 2px solid #F3C623;
      }

      .profile-card {
         background-color: #fff;
         border-radius: 10px;
         box-shadow: 0 0 10px rgba(0, 0, 0, 0.05);
         padding: 20px;
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

         <!-- Profile Content -->
         <div class="container-fluid dashboard-container">
            <div class="row mb-4">
               <div class="col">
                  <h1 class="mb-0">Staff Profile</h1>
               </div>
            </div>

            <?php if (!empty($errorMessage)): ?>
               <div class="alert alert-danger alert-dismissible fade show" role="alert">
                  <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= $errorMessage ?>
                  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
               </div>
            <?php endif; ?>

            <?php if (!empty($successMessage)): ?>
               <div class="alert alert-success alert-dismissible fade show" role="alert">
                  <i class="bi bi-check-circle-fill me-2"></i> <?= $successMessage ?>
                  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
               </div>
            <?php endif; ?>

            <div class="row">
               <div class="col-md-12">
                  <div class="profile-header mb-4">
                     <div class="row align-items-center">
                        <div class="col-md-4 text-center">
                           <div class="position-relative d-inline-block">
                              <?php
                              $profilePic = (!empty($staffData['profile_picture']))
                                 ? "../" . $staffData['profile_picture']
                                 : "../assets/images/default-avatar.png";
                              ?>
                              <img src="<?= $profilePic ?>" alt="Profile Picture" class="profile-picture">
                              <div class="profile-picture-edit" data-bs-toggle="modal" data-bs-target="#changeProfilePicModal">
                                 <i class="bi bi-camera"></i>
                              </div>
                           </div>
                        </div>
                        <div class="col-md-8">
                           <h2 class="mb-1"><?= htmlspecialchars($staffData['full_name'] ?? 'N/A') ?></h2>
                           <p class="text-muted mb-3"><i class="bi bi-person-badge me-2"></i><?= htmlspecialchars($username) ?></p>
                           <div class="d-flex flex-wrap">
                              <?php if (!empty($staffData['department'])): ?>
                                 <span class="badge bg-primary me-2 mb-2"><?= htmlspecialchars($staffData['department']) ?></span>
                              <?php endif; ?>
                              <span class="badge bg-success me-2 mb-2">Staff</span>
                           </div>
                        </div>
                     </div>
                  </div>
               </div>
            </div>

            <div class="row">
               <div class="col-md-12">
                  <div class="profile-card">
                     <h4 class="mb-4">Profile Information</h4>
                     <form method="POST" action="">
                        <div class="row">
                           <div class="col-md-6 mb-3">
                              <label for="fullName" class="form-label">Full Name</label>
                              <input type="text" class="form-control" id="fullName" name="full_name" value="<?= htmlspecialchars($staffData['full_name'] ?? '') ?>" required>
                           </div>
                           <div class="col-md-6 mb-3">
                              <label for="username" class="form-label">Username</label>
                              <input type="text" class="form-control" id="username" value="<?= htmlspecialchars($staffData['username'] ?? '') ?>" disabled>
                              <div class="form-text text-muted">Username cannot be changed</div>
                           </div>
                        </div>
                        <div class="row">
                           <div class="col-md-6 mb-3">
                              <label for="email" class="form-label">Email Address</label>
                              <input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($staffData['email'] ?? '') ?>">
                           </div>
                           <div class="col-md-6 mb-3">
                              <label for="phone" class="form-label">Phone Number</label>
                              <input type="tel" class="form-control" id="phone" name="phone" value="<?= htmlspecialchars($staffData['phone'] ?? '') ?>">
                           </div>
                        </div>
                        <div class="row">
                           <div class="col-md-6 mb-3">
                              <label for="department" class="form-label">Department</label>
                              <input type="text" class="form-control" id="department" name="department" value="<?= htmlspecialchars($staffData['department'] ?? '') ?>">
                           </div>
                           <div class="col-md-6 mb-3">
                              <label for="lastLogin" class="form-label">Last Login</label>
                              <input type="text" class="form-control" id="lastLogin" value="<?= isset($staffData['last_login']) ? date('F d, Y h:i A', strtotime($staffData['last_login'])) : 'N/A' ?>" disabled>
                           </div>
                        </div>
                        <div class="row">
                           <div class="col-12 mt-3">
                              <button type="submit" name="update_profile" class="btn btn-primary">
                                 <i class="bi bi-check2 me-2"></i>Save Changes
                              </button>
                              <a href="change-password.php" class="btn btn-outline-dark ms-2">
                                 <i class="bi bi-key me-2"></i>Change Password
                              </a>
                           </div>
                        </div>
                     </form>
                  </div>
               </div>
            </div>
         </div>
      </div>
   </div>

   <!-- Profile Picture Modal -->
   <div class="modal fade" id="changeProfilePicModal" tabindex="-1" aria-labelledby="changeProfilePicModalLabel" aria-hidden="true">
      <div class="modal-dialog">
         <div class="modal-content">
            <div class="modal-header">
               <h5 class="modal-title" id="changeProfilePicModalLabel">Change Profile Picture</h5>
               <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
               <form method="POST" enctype="multipart/form-data">
                  <div class="mb-3">
                     <label for="profilePicture" class="form-label">Select a new profile picture</label>
                     <input type="file" class="form-control" id="profilePicture" name="profile_picture" accept="image/jpeg,image/png,image/gif" required>
                     <div class="form-text">Max file size: 5MB. Allowed formats: JPG, PNG, GIF.</div>
                  </div>
                  <div class="d-grid">
                     <button type="submit" name="update_picture" class="btn btn-primary">Upload Picture</button>
                  </div>
               </form>
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