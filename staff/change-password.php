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

// Fetch staff details for profile picture
$staffSql = "SELECT profile_picture, last_login FROM staff WHERE staff_id = ?";
$stmt = $conn->prepare($staffSql);
$stmt->bind_param("i", $staffId);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
   $staffData = $result->fetch_assoc();
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
   // Get form data
   $currentPassword = $_POST['current_password'];
   $newPassword = $_POST['new_password'];
   $confirmPassword = $_POST['confirm_password'];

   // Validate input
   if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
      $errorMessage = "All fields are required.";
   } elseif ($newPassword !== $confirmPassword) {
      $errorMessage = "New password and confirmation do not match.";
   } elseif (strlen($newPassword) < 8) {
      $errorMessage = "Password must be at least 8 characters long.";
   } else {
      // Verify current password
      $passwordSql = "SELECT password FROM staff WHERE staff_id = ?";
      $stmt = $conn->prepare($passwordSql);
      $stmt->bind_param("i", $staffId);
      $stmt->execute();
      $result = $stmt->get_result();

      if ($result && $result->num_rows > 0) {
         $userData = $result->fetch_assoc();
         if (password_verify($currentPassword, $userData['password'])) {
            // Hash new password
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

            // Update password
            $updateSql = "UPDATE staff SET password = ?, updated_at = NOW() WHERE staff_id = ?";
            $stmt = $conn->prepare($updateSql);
            $stmt->bind_param("si", $hashedPassword, $staffId);

            if ($stmt->execute()) {
               $successMessage = "Password changed successfully!";

               // Record password change in security log
               $logSql = "INSERT INTO security_logs (staff_id, action, ip_address, user_agent) 
                               VALUES (?, 'password_change', ?, ?)";
               $stmt = $conn->prepare($logSql);
               $ipAddress = $_SERVER['REMOTE_ADDR'];
               $userAgent = $_SERVER['HTTP_USER_AGENT'];
               $stmt->bind_param("iss", $staffId, $ipAddress, $userAgent);
               $stmt->execute();
            } else {
               $errorMessage = "Error updating password: " . $conn->error;
            }
         } else {
            $errorMessage = "Current password is incorrect.";
         }
      } else {
         $errorMessage = "Could not verify current password.";
      }
   }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Change Password - EventQR</title>
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

      /* Password card */
      .password-card {
         background-color: #fff;
         border-radius: 10px;
         box-shadow: 0 0 10px rgba(0, 0, 0, 0.05);
         padding: 30px;
      }

      .password-strength-meter {
         height: 5px;
         width: 100%;
         background-color: #e9ecef;
         margin-top: 5px;
         border-radius: 3px;
         overflow: hidden;
      }

      .password-strength-value {
         height: 100%;
         width: 0%;
         transition: width 0.3s ease;
      }

      .strength-weak {
         width: 25%;
         background-color: #dc3545;
      }

      .strength-medium {
         width: 50%;
         background-color: #ffc107;
      }

      .strength-strong {
         width: 75%;
         background-color: #28a745;
      }

      .strength-very-strong {
         width: 100%;
         background-color: #20c997;
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

         <!-- Change Password Content -->
         <div class="container-fluid dashboard-container">
            <div class="row mb-4">
               <div class="col">
                  <h1 class="mb-0">Change Password</h1>
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
               <div class="col-lg-6 mx-auto">
                  <div class="password-card">
                     <div class="mb-4">
                        <h4>Update Your Password</h4>
                        <p class="text-muted">For your security, please use a strong password that you don't use elsewhere.</p>
                     </div>

                     <form method="POST" action="" id="changePasswordForm">
                        <div class="mb-3">
                           <label for="currentPassword" class="form-label">Current Password</label>
                           <div class="input-group">
                              <input type="password" class="form-control" id="currentPassword" name="current_password" required>
                              <span class="input-group-text toggle-password" data-target="currentPassword">
                                 <i class="bi bi-eye"></i>
                              </span>
                           </div>
                        </div>

                        <div class="mb-3">
                           <label for="newPassword" class="form-label">New Password</label>
                           <div class="input-group">
                              <input type="password" class="form-control" id="newPassword" name="new_password"
                                 required minlength="8" onkeyup="checkPasswordStrength()">
                              <span class="input-group-text toggle-password" data-target="newPassword">
                                 <i class="bi bi-eye"></i>
                              </span>
                           </div>
                           <div class="password-strength-meter mt-2">
                              <div class="password-strength-value" id="passwordStrength"></div>
                           </div>
                           <small class="form-text text-muted" id="passwordFeedback">
                              Password must be at least 8 characters long
                           </small>
                        </div>

                        <div class="mb-4">
                           <label for="confirmPassword" class="form-label">Confirm New Password</label>
                           <div class="input-group">
                              <input type="password" class="form-control" id="confirmPassword" name="confirm_password"
                                 required minlength="8" onkeyup="checkPasswordMatch()">
                              <span class="input-group-text toggle-password" data-target="confirmPassword">
                                 <i class="bi bi-eye"></i>
                              </span>
                           </div>
                           <small class="form-text text-danger d-none" id="passwordMatchError">
                              Passwords do not match
                           </small>
                        </div>

                        <div class="mb-3">
                           <button type="submit" name="change_password" class="btn btn-primary" id="submitBtn">
                              <i class="bi bi-check2 me-2"></i>Change Password
                           </button>
                           <a href="profile.php" class="btn btn-outline-secondary ms-2">
                              <i class="bi bi-arrow-left me-2"></i>Back to Profile
                           </a>
                        </div>
                     </form>

                     <div class="mt-4">
                        <h5>Password Requirements</h5>
                        <ul class="text-muted small">
                           <li>Minimum 8 characters in length</li>
                           <li>Include at least one uppercase letter</li>
                           <li>Include at least one number</li>
                           <li>Include at least one special character</li>
                           <li>Avoid common passwords and personal information</li>
                        </ul>
                     </div>
                  </div>
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

      // Toggle password visibility
      document.querySelectorAll('.toggle-password').forEach(function(button) {
         button.addEventListener('click', function() {
            const targetId = this.getAttribute('data-target');
            const input = document.getElementById(targetId);
            const icon = this.querySelector('i');

            if (input.type === 'password') {
               input.type = 'text';
               icon.classList.remove('bi-eye');
               icon.classList.add('bi-eye-slash');
            } else {
               input.type = 'password';
               icon.classList.remove('bi-eye-slash');
               icon.classList.add('bi-eye');
            }
         });
      });

      // Check password strength
      function checkPasswordStrength() {
         const password = document.getElementById('newPassword').value;
         const strengthMeter = document.getElementById('passwordStrength');
         const feedback = document.getElementById('passwordFeedback');

         // Reset the strength meter
         strengthMeter.className = 'password-strength-value';

         if (password.length === 0) {
            feedback.textContent = 'Password must be at least 8 characters long';
            return;
         }

         // Check password strength
         let strength = 0;
         const patterns = [
            /[a-z]+/, // lowercase letters
            /[A-Z]+/, // uppercase letters
            /[0-9]+/, // numbers
            /[$@#&!]+/ // special characters
         ];

         // Add points for each pattern matched
         patterns.forEach(pattern => {
            if (pattern.test(password)) {
               strength++;
            }
         });

         // Add points for length
         if (password.length >= 8) strength++;
         if (password.length >= 12) strength++;

         // Update the strength meter
         if (password.length < 8) {
            feedback.textContent = 'Password is too short';
            feedback.className = 'form-text text-danger';
         } else if (strength <= 2) {
            strengthMeter.classList.add('strength-weak');
            feedback.textContent = 'Password is weak';
            feedback.className = 'form-text text-danger';
         } else if (strength <= 3) {
            strengthMeter.classList.add('strength-medium');
            feedback.textContent = 'Password is medium strength';
            feedback.className = 'form-text text-warning';
         } else if (strength <= 4) {
            strengthMeter.classList.add('strength-strong');
            feedback.textContent = 'Password is strong';
            feedback.className = 'form-text text-success';
         } else {
            strengthMeter.classList.add('strength-very-strong');
            feedback.textContent = 'Password is very strong';
            feedback.className = 'form-text text-success';
         }
      }

      // Check if passwords match
      function checkPasswordMatch() {
         const password = document.getElementById('newPassword').value;
         const confirmPassword = document.getElementById('confirmPassword').value;
         const errorMsg = document.getElementById('passwordMatchError');

         if (password !== confirmPassword) {
            errorMsg.classList.remove('d-none');
         } else {
            errorMsg.classList.add('d-none');
         }
      }

      // Form validation
      document.getElementById('changePasswordForm').addEventListener('submit', function(e) {
         const newPassword = document.getElementById('newPassword').value;
         const confirmPassword = document.getElementById('confirmPassword').value;

         if (newPassword !== confirmPassword) {
            e.preventDefault();
            document.getElementById('passwordMatchError').classList.remove('d-none');
            return false;
         }

         if (newPassword.length < 8) {
            e.preventDefault();
            alert('Password must be at least 8 characters long.');
            return false;
         }

         return true;
      });
   </script>
</body>

</html>