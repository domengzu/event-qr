<?php
// Initialize the session
session_start();

// Log the logout action for debugging
error_log("Logout initiated for user: " . ($_SESSION['username'] ?? 'unknown'));

// Set a logout message in a temporary session variable
$_SESSION['logout_message'] = "You have been successfully logged out.";

// Unset all session values
$_SESSION = [];

// Delete the session cookie
if (ini_get("session.use_cookies")) {
   $params = session_get_cookie_params();
   setcookie(
      session_name(),
      '',
      time() - 42000,
      $params["path"],
      $params["domain"],
      $params["secure"],
      $params["httponly"]
   );
}

// Destroy the session
session_destroy();

// Clear any output buffering to ensure headers work
if (ob_get_level()) {
   ob_end_clean();
}

// Make sure we have clean headers
header_remove();

// Redirect to login page with an absolute URL
header("Location: login.php");
exit;
