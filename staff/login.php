<?php
// Initialize session
session_start();

// Redirect if already logged in
if (isset($_SESSION['staff_id'])) {
   header("Location: dashboard.php");
   exit;
}

// Include required files
require_once '../config/database.php';
require_once '../controller/StaffController.php';

// Initialize variables
$error = '';
$username = '';
$lockoutMessage = '';

// Get database connection
$conn = getDBConnection();

// Create staff controller
$staffController = new StaffController($conn);

// Process login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
   // Get user inputs
   $username = trim($_POST['username'] ?? '');
   $password = $_POST['password'] ?? '';

   // Get IP address
   $ipAddress = $_SERVER['REMOTE_ADDR'];

   // Check for account lockout
   if ($staffController->isAccountLocked($username)) {
      $lockoutMessage = "This account has been temporarily locked due to multiple failed login attempts. Please try again later.";
   } else {
      // Authenticate user
      $staff = $staffController->authenticate($username, $password);

      // Check if authentication was successful
      if ($staff && isset($staff['staff_id'])) {
         // Set session variables
         $_SESSION['staff_id'] = $staff['staff_id'];
         $_SESSION['username'] = $staff['username'];
         $_SESSION['full_name'] = $staff['full_name'] ?? $username;
         $_SESSION['login_time'] = time();

         // Log successful login
         $staffController->logLoginAttempt($staff['staff_id'], $username, true, $ipAddress);

         // Update last login timestamp in the database
         $updateLoginSql = "UPDATE staff SET last_login = NOW() WHERE staff_id = ?";
         $stmt = $conn->prepare($updateLoginSql);
         $stmt->bind_param("i", $staff['staff_id']);
         $stmt->execute();

         // Record login in security log
         $logSql = "INSERT INTO security_logs (staff_id, action, ip_address, user_agent) 
                    VALUES (?, 'login', ?, ?)";
         $stmt = $conn->prepare($logSql);
         $ipAddress = $_SERVER['REMOTE_ADDR'];
         $userAgent = $_SERVER['HTTP_USER_AGENT'];
         $stmt->bind_param("iss", $staff['staff_id'], $ipAddress, $userAgent);
         $stmt->execute();

         // Redirect to dashboard
         header("Location: dashboard.php");
         exit;
      } else {
         // Log failed login attempt
         $staffId = 0; // Unknown staff ID
         $staffController->logLoginAttempt($staffId, $username, false, $ipAddress);

         // Set error message
         $error = "Invalid username or password";
      }
   }
}

// Check if there's a logout message
$logoutMessage = isset($_SESSION['logout_message']) ? $_SESSION['logout_message'] : '';
unset($_SESSION['logout_message']);  // Clear the message after retrieving it
session_write_close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Staff Login - EventQR</title>
   <!-- Bootstrap CSS -->
   <link href="../bootstrap/css/bootstrap.min.css" rel="stylesheet">
   <link rel="stylesheet" href="../assets/main.css">
   <!-- Bootstrap Icons -->
   <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
   <style>
      body {
         background-color: #f8f9fa;
      }

      .login-container {
         max-width: 400px;
         margin: 80px auto;
      }

      .login-header {
         text-align: center;
         margin-bottom: 30px;
      }

      .login-card {
         border-radius: 10px;
         box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
      }

      .login-card .card-header {
         border-radius: 10px 10px 0 0;
         background-color: #222831;
         color: white;
      }

      .login-logo {
         font-size: 24px;
         font-weight: bold;
      }

      .accent-text {
         color: #F3C623;
      }
   </style>
</head>

<body>
   <div class="container login-container">
      <div class="login-header">
         <div class="login-logo">EVSU-EVENT<span class="accent-text">QR</span></div>
         <h2>Staff Portal</h2>
      </div>

      <div class="card login-card">
         <div class="card-header">
            <h4 class="m-0 text-center">Login</h4>
         </div>
         <div class="card-body p-4">
            <?php if (!empty($error)): ?>
               <div class="alert alert-danger"><?= $error ?></div>
            <?php endif; ?>

            <?php if (!empty($lockoutMessage)): ?>
               <div class="alert alert-warning"><?= $lockoutMessage ?></div>
            <?php else: ?>
               <?php if (!empty($logoutMessage)): ?>
                  <div class="alert alert-success alert-dismissible fade show" role="alert">
                     <i class="bi bi-check-circle me-2"></i><?= $logoutMessage ?>
                     <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                  </div>
               <?php endif; ?>
               <form method="post" action="login.php">
                  <div class="mb-3">
                     <label for="username" class="form-label">Username</label>
                     <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person"></i></span>
                        <input type="text" class="form-control" id="username" name="username" value="<?= htmlspecialchars($username) ?>" required autofocus>
                     </div>
                  </div>
                  <div class="mb-3">
                     <label for="password" class="form-label">Password</label>
                     <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" class="form-control" id="password" name="password" required>
                        <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                           <i class="bi bi-eye"></i>
                        </button>
                     </div>
                  </div>
                  <div class="mb-3 form-check">
                     <input type="checkbox" class="form-check-input" id="rememberMe" name="remember_me">
                     <label class="form-check-label" for="rememberMe">Remember me</label>
                  </div>
                  <div class="d-grid gap-2">
                     <button type="submit" class="btn btn-primary">Login</button>
                  </div>
               </form>
               <div class="text-center mt-3">
                  <a href="forgot-password.php" class="text-decoration-none">Forgot Password?</a>
               </div>
            <?php endif; ?>
         </div>
         <div class="card-footer text-center text-muted">
            <a href="../index.php" class="text-decoration-none"><i class="bi bi-arrow-left me-1"></i> Back to Homepage</a>
         </div>
      </div>
   </div>

   <!-- Bootstrap JS Bundle -->
   <script src="../bootstrap/js/bootstrap.bundle.min.js"></script>
   <script>
      // Toggle password visibility
      document.getElementById('togglePassword').addEventListener('click', function() {
         const passwordInput = document.getElementById('password');
         const icon = this.querySelector('i');

         if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            icon.classList.remove('bi-eye');
            icon.classList.add('bi-eye-slash');
         } else {
            passwordInput.type = 'password';
            icon.classList.remove('bi-eye-slash');
            icon.classList.add('bi-eye');
         }
      });
   </script>
</body>

</html>