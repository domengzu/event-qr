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
$successMessage = '';
$errorMessage = '';
$settings = [];

// Fetch staff profile picture for header
$staffSql = "SELECT profile_picture FROM staff WHERE staff_id = ?";
$stmt = $conn->prepare($staffSql);
$stmt->bind_param("i", $staffId);
$stmt->execute();
$result = $stmt->get_result();
$staffData = [];
if ($result && $result->num_rows > 0) {
   $staffData = $result->fetch_assoc();
}

// Fetch current settings
$settingsSql = "SELECT * FROM staff_settings WHERE staff_id = ?";
$stmt = $conn->prepare($settingsSql);
$stmt->bind_param("i", $staffId);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
   $settings = $result->fetch_assoc();
} else {
   // Create default settings if none exist
   $defaultSettings = [
      'email_notifications' => 1,
      'attendance_alert_sound' => 1,
      'default_attendance_type' => 'present',
      'scanner_auto_submit' => 1,
      'theme' => 'light',
      'dashboard_widgets' => 'events,students,attendance,reports',
      'table_rows_per_page' => 10
   ];

   $insertSql = "INSERT INTO staff_settings 
                 (staff_id, email_notifications, attendance_alert_sound, default_attendance_type, 
                  scanner_auto_submit, theme, dashboard_widgets, table_rows_per_page) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

   $stmt = $conn->prepare($insertSql);
   $stmt->bind_param(
      "iiisissi",
      $staffId,
      $defaultSettings['email_notifications'],
      $defaultSettings['attendance_alert_sound'],
      $defaultSettings['default_attendance_type'],
      $defaultSettings['scanner_auto_submit'],
      $defaultSettings['theme'],
      $defaultSettings['dashboard_widgets'],
      $defaultSettings['table_rows_per_page']
   );

   $stmt->execute();
   $settings = $defaultSettings;
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
   // Get form data
   $emailNotifications = isset($_POST['email_notifications']) ? 1 : 0;
   $attendanceSound = isset($_POST['attendance_alert_sound']) ? 1 : 0;
   $defaultAttendanceType = $_POST['default_attendance_type'];
   $scannerAutoSubmit = isset($_POST['scanner_auto_submit']) ? 1 : 0;
   $theme = $_POST['theme'];
   $tableRowsPerPage = (int)$_POST['table_rows_per_page'];

   // Validate and collect selected widgets
   $availableWidgets = ['events', 'students', 'attendance', 'reports'];
   $selectedWidgets = [];
   foreach ($availableWidgets as $widget) {
      if (isset($_POST['widget_' . $widget])) {
         $selectedWidgets[] = $widget;
      }
   }
   $dashboardWidgets = implode(',', $selectedWidgets);

   // Update settings
   $updateSql = "UPDATE staff_settings SET 
                 email_notifications = ?,
                 attendance_alert_sound = ?,
                 default_attendance_type = ?,
                 scanner_auto_submit = ?,
                 theme = ?,
                 dashboard_widgets = ?,
                 table_rows_per_page = ?,
                 updated_at = NOW()
                 WHERE staff_id = ?";

   $stmt = $conn->prepare($updateSql);
   $stmt->bind_param(
      "iisissii",
      $emailNotifications,
      $attendanceSound,
      $defaultAttendanceType,
      $scannerAutoSubmit,
      $theme,
      $dashboardWidgets,
      $tableRowsPerPage,
      $staffId
   );

   if ($stmt->execute()) {
      $successMessage = "Settings updated successfully!";

      // Update session variables if needed
      $_SESSION['user_theme'] = $theme;

      // Fetch updated settings
      $stmt = $conn->prepare($settingsSql);
      $stmt->bind_param("i", $staffId);
      $stmt->execute();
      $result = $stmt->get_result();
      if ($result) {
         $settings = $result->fetch_assoc();
      }
   } else {
      $errorMessage = "Error updating settings: " . $conn->error;
   }
}

// Handle system settings (admin only)
$isAdmin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
$systemSettings = [];

if ($isAdmin) {
   // Fetch system settings
   $systemSettingsSql = "SELECT * FROM system_settings LIMIT 1";
   $result = $conn->query($systemSettingsSql);

   if ($result && $result->num_rows > 0) {
      $systemSettings = $result->fetch_assoc();
   }

   // Process system settings form
   if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_system_settings'])) {
      $siteName = trim($_POST['site_name']);
      $siteEmail = trim($_POST['site_email']);
      $registrationOpen = isset($_POST['registration_open']) ? 1 : 0;
      $maintenanceMode = isset($_POST['maintenance_mode']) ? 1 : 0;

      // Update system settings
      $updateSql = "UPDATE system_settings SET 
                     site_name = ?,
                     site_email = ?,
                     registration_open = ?,
                     maintenance_mode = ?,
                     updated_at = NOW()";

      $stmt = $conn->prepare($updateSql);
      $stmt->bind_param("ssii", $siteName, $siteEmail, $registrationOpen, $maintenanceMode);

      if ($stmt->execute()) {
         $successMessage = "System settings updated successfully!";

         // Fetch updated system settings
         $result = $conn->query($systemSettingsSql);
         if ($result) {
            $systemSettings = $result->fetch_assoc();
         }
      } else {
         $errorMessage = "Error updating system settings: " . $conn->error;
      }
   }
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= $settings['theme'] ?? 'light' ?>">

<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Settings - EventQR</title>
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

      /* Settings styles */
      .settings-card {
         background-color: #fff;
         border-radius: 10px;
         box-shadow: 0 0 10px rgba(0, 0, 0, 0.05);
         padding: 20px;
         margin-bottom: 20px;
      }

      .form-check-input:checked {
         background-color: #F3C623;
         border-color: #F3C623;
      }

      .widget-card {
         border: 2px solid #e9ecef;
         border-radius: 10px;
         padding: 15px;
         cursor: pointer;
         transition: all 0.2s;
         height: 100%;
      }

      .widget-card:hover {
         border-color: #F3C623;
      }

      .widget-card.selected {
         border-color: #F3C623;
         background-color: rgba(243, 198, 35, 0.1);
      }

      .widget-card .widget-icon {
         font-size: 24px;
         margin-bottom: 10px;
         color: #F3C623;
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

      /* Dark mode adjustments */
      [data-bs-theme="dark"] .settings-card,
      [data-bs-theme="dark"] .dashboard-header {
         background-color: #2c3038;
         color: #f8f9fa;
      }

      [data-bs-theme="dark"] .widget-card {
         border-color: #495057;
         background-color: #343a40;
      }

      [data-bs-theme="dark"] .widget-card:hover {
         border-color: #F3C623;
      }

      [data-bs-theme="dark"] .widget-card.selected {
         border-color: #F3C623;
         background-color: rgba(243, 198, 35, 0.2);
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
               <a href="settings.php" class="active">
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

         <!-- Settings Content -->
         <div class="container-fluid dashboard-container">
            <div class="d-flex justify-content-between align-items-center mb-4">
               <h1 class="mb-0">Settings</h1>
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

            <!-- Settings Nav Tabs -->
            <ul class="nav nav-tabs mb-4" id="settingsTabs" role="tablist">
               <li class="nav-item" role="presentation">
                  <button class="nav-link active" id="preferences-tab" data-bs-toggle="tab" data-bs-target="#preferences" type="button" role="tab" aria-controls="preferences" aria-selected="true">
                     <i class="bi bi-sliders me-2"></i>Preferences
                  </button>
               </li>
               <li class="nav-item" role="presentation">
                  <button class="nav-link" id="notifications-tab" data-bs-toggle="tab" data-bs-target="#notifications" type="button" role="tab" aria-controls="notifications" aria-selected="false">
                     <i class="bi bi-bell me-2"></i>Notifications
                  </button>
               </li>
               <li class="nav-item" role="presentation">
                  <button class="nav-link" id="dashboard-tab" data-bs-toggle="tab" data-bs-target="#dashboard" type="button" role="tab" aria-controls="dashboard" aria-selected="false">
                     <i class="bi bi-grid me-2"></i>Dashboard
                  </button>
               </li>
               <?php if ($isAdmin): ?>
                  <li class="nav-item" role="presentation">
                     <button class="nav-link" id="system-tab" data-bs-toggle="tab" data-bs-target="#system" type="button" role="tab" aria-controls="system" aria-selected="false">
                        <i class="bi bi-gear-wide-connected me-2"></i>System
                     </button>
                  </li>
               <?php endif; ?>
            </ul>

            <form method="POST" action="">
               <div class="tab-content" id="settingsTabsContent">
                  <!-- Preferences Tab -->
                  <div class="tab-pane fade show active" id="preferences" role="tabpanel" aria-labelledby="preferences-tab">
                     <div class="settings-card">
                        <h4 class="mb-4">General Preferences</h4>

                        <div class="row mb-4">
                           <div class="col-lg-6">
                              <div class="mb-3">
                                 <label for="theme" class="form-label">Theme</label>
                                 <select class="form-select" id="theme" name="theme">
                                    <option value="light" <?= ($settings['theme'] ?? 'light') == 'light' ? 'selected' : '' ?>>Light</option>
                                    <option value="dark" <?= ($settings['theme'] ?? 'light') == 'dark' ? 'selected' : '' ?>>Dark</option>
                                 </select>
                                 <div class="form-text">Choose your preferred visual theme.</div>
                              </div>
                           </div>
                           <div class="col-lg-6">
                              <div class="mb-3">
                                 <label for="tableRowsPerPage" class="form-label">Rows Per Page</label>
                                 <select class="form-select" id="tableRowsPerPage" name="table_rows_per_page">
                                    <option value="10" <?= ($settings['table_rows_per_page'] ?? 10) == 10 ? 'selected' : '' ?>>10</option>
                                    <option value="25" <?= ($settings['table_rows_per_page'] ?? 10) == 25 ? 'selected' : '' ?>>25</option>
                                    <option value="50" <?= ($settings['table_rows_per_page'] ?? 10) == 50 ? 'selected' : '' ?>>50</option>
                                    <option value="100" <?= ($settings['table_rows_per_page'] ?? 10) == 100 ? 'selected' : '' ?>>100</option>
                                 </select>
                                 <div class="form-text">Number of rows to display in tables.</div>
                              </div>
                           </div>
                        </div>

                        <h5 class="mb-3">Scanner Settings</h5>
                        <div class="row">
                           <div class="col-lg-6">
                              <div class="mb-3">
                                 <label for="defaultAttendanceType" class="form-label">Default Attendance Type</label>
                                 <select class="form-select" id="defaultAttendanceType" name="default_attendance_type">
                                    <option value="present" <?= ($settings['default_attendance_type'] ?? 'present') == 'present' ? 'selected' : '' ?>>Present</option>
                                    <option value="late" <?= ($settings['default_attendance_type'] ?? 'present') == 'late' ? 'selected' : '' ?>>Late</option>
                                    <option value="left early" <?= ($settings['default_attendance_type'] ?? 'present') == 'left early' ? 'selected' : '' ?>>Left Early</option>
                                 </select>
                                 <div class="form-text">Default attendance status when scanning QR codes.</div>
                              </div>
                           </div>
                           <div class="col-lg-6">
                              <div class="mb-3">
                                 <div class="form-check form-switch mt-4">
                                    <input class="form-check-input" type="checkbox" id="autoSubmit" name="scanner_auto_submit" <?= isset($settings['scanner_auto_submit']) && $settings['scanner_auto_submit'] ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="autoSubmit">
                                       Automatically mark attendance on successful scan
                                    </label>
                                 </div>
                              </div>
                           </div>
                        </div>
                     </div>
                  </div>

                  <!-- Notifications Tab -->
                  <div class="tab-pane fade" id="notifications" role="tabpanel" aria-labelledby="notifications-tab">
                     <div class="settings-card">
                        <h4 class="mb-4">Notification Settings</h4>

                        <div class="form-check form-switch mb-3">
                           <input class="form-check-input" type="checkbox" id="emailNotifications" name="email_notifications" <?= isset($settings['email_notifications']) && $settings['email_notifications'] ? 'checked' : '' ?>>
                           <label class="form-check-label" for="emailNotifications">
                              Email Notifications
                           </label>
                           <div class="form-text">Receive email notifications for important events.</div>
                        </div>

                        <div class="form-check form-switch mb-3">
                           <input class="form-check-input" type="checkbox" id="soundAlerts" name="attendance_alert_sound" <?= isset($settings['attendance_alert_sound']) && $settings['attendance_alert_sound'] ? 'checked' : '' ?>>
                           <label class="form-check-label" for="soundAlerts">
                              Attendance Sound Alerts
                           </label>
                           <div class="form-text">Play sound when scanning QR codes.</div>
                        </div>

                        <h5 class="mt-4 mb-3">Events to be notified about:</h5>
                        <div class="row">
                           <div class="col-lg-6">
                              <div class="form-check mb-2">
                                 <input class="form-check-input" type="checkbox" id="notifyNewRegistration" name="notify_new_registration" checked>
                                 <label class="form-check-label" for="notifyNewRegistration">
                                    New Event Registration
                                 </label>
                              </div>
                              <div class="form-check mb-2">
                                 <input class="form-check-input" type="checkbox" id="notifyEventReminder" name="notify_event_reminder" checked>
                                 <label class="form-check-label" for="notifyEventReminder">
                                    Event Reminders
                                 </label>
                              </div>
                           </div>
                           <div class="col-lg-6">
                              <div class="form-check mb-2">
                                 <input class="form-check-input" type="checkbox" id="notifyAttendanceReport" name="notify_attendance_report" checked>
                                 <label class="form-check-label" for="notifyAttendanceReport">
                                    Attendance Reports
                                 </label>
                              </div>
                              <div class="form-check mb-2">
                                 <input class="form-check-input" type="checkbox" id="notifySystemUpdates" name="notify_system_updates" checked>
                                 <label class="form-check-label" for="notifySystemUpdates">
                                    System Updates
                                 </label>
                              </div>
                           </div>
                        </div>
                     </div>
                  </div>

                  <!-- Dashboard Tab -->
                  <div class="tab-pane fade" id="dashboard" role="tabpanel" aria-labelledby="dashboard-tab">
                     <div class="settings-card">
                        <h4 class="mb-4">Dashboard Layout</h4>
                        <p class="text-muted mb-4">Select which widgets to display on your dashboard.</p>

                        <?php
                        $dashboardWidgets = explode(',', $settings['dashboard_widgets'] ?? 'events,students,attendance,reports');
                        ?>

                        <div class="row">
                           <div class="col-lg-3 col-md-6 mb-4">
                              <div class="widget-card <?= in_array('events', $dashboardWidgets) ? 'selected' : '' ?>" id="widgetEvents">
                                 <div class="widget-icon text-center">
                                    <i class="bi bi-calendar-event"></i>
                                 </div>
                                 <h5 class="text-center">Events</h5>
                                 <p class="text-muted small text-center mb-0">Recent and upcoming events</p>
                                 <input type="checkbox" name="widget_events" id="widgetEventsCheck" class="d-none" <?= in_array('events', $dashboardWidgets) ? 'checked' : '' ?>>
                              </div>
                           </div>

                           <div class="col-lg-3 col-md-6 mb-4">
                              <div class="widget-card <?= in_array('students', $dashboardWidgets) ? 'selected' : '' ?>" id="widgetStudents">
                                 <div class="widget-icon text-center">
                                    <i class="bi bi-people"></i>
                                 </div>
                                 <h5 class="text-center">Students</h5>
                                 <p class="text-muted small text-center mb-0">Student statistics and info</p>
                                 <input type="checkbox" name="widget_students" id="widgetStudentsCheck" class="d-none" <?= in_array('students', $dashboardWidgets) ? 'checked' : '' ?>>
                              </div>
                           </div>

                           <div class="col-lg-3 col-md-6 mb-4">
                              <div class="widget-card <?= in_array('attendance', $dashboardWidgets) ? 'selected' : '' ?>" id="widgetAttendance">
                                 <div class="widget-icon text-center">
                                    <i class="bi bi-check2-square"></i>
                                 </div>
                                 <h5 class="text-center">Attendance</h5>
                                 <p class="text-muted small text-center mb-0">Recent attendance records</p>
                                 <input type="checkbox" name="widget_attendance" id="widgetAttendanceCheck" class="d-none" <?= in_array('attendance', $dashboardWidgets) ? 'checked' : '' ?>>
                              </div>
                           </div>

                           <div class="col-lg-3 col-md-6 mb-4">
                              <div class="widget-card <?= in_array('reports', $dashboardWidgets) ? 'selected' : '' ?>" id="widgetReports">
                                 <div class="widget-icon text-center">
                                    <i class="bi bi-graph-up"></i>
                                 </div>
                                 <h5 class="text-center">Reports</h5>
                                 <p class="text-muted small text-center mb-0">Quick insights and charts</p>
                                 <input type="checkbox" name="widget_reports" id="widgetReportsCheck" class="d-none" <?= in_array('reports', $dashboardWidgets) ? 'checked' : '' ?>>
                              </div>
                           </div>
                        </div>
                     </div>
                  </div>

                  <?php if ($isAdmin): ?>
                     <!-- System Tab (Admin Only) -->
                     <div class="tab-pane fade" id="system" role="tabpanel" aria-labelledby="system-tab">
                        <div class="settings-card mb-4">
                           <div class="d-flex justify-content-between align-items-center mb-4">
                              <h4 class="mb-0">System Settings</h4>
                              <span class="badge bg-primary">Admin Only</span>
                           </div>

                           <div class="row">
                              <div class="col-md-6">
                                 <div class="mb-3">
                                    <label for="siteName" class="form-label">Site Name</label>
                                    <input type="text" class="form-control" id="siteName" name="site_name" value="<?= htmlspecialchars($systemSettings['site_name'] ?? 'EventQR') ?>">
                                 </div>
                              </div>
                              <div class="col-md-6">
                                 <div class="mb-3">
                                    <label for="siteEmail" class="form-label">Site Email</label>
                                    <input type="email" class="form-control" id="siteEmail" name="site_email" value="<?= htmlspecialchars($systemSettings['site_email'] ?? '') ?>">
                                 </div>
                              </div>
                           </div>

                           <div class="row mt-3">
                              <div class="col-md-6">
                                 <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" id="registrationOpen" name="registration_open" <?= isset($systemSettings['registration_open']) && $systemSettings['registration_open'] ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="registrationOpen">
                                       Allow Student Registration
                                    </label>
                                 </div>
                              </div>
                              <div class="col-md-6">
                                 <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" id="maintenanceMode" name="maintenance_mode" <?= isset($systemSettings['maintenance_mode']) && $systemSettings['maintenance_mode'] ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="maintenanceMode">
                                       Maintenance Mode
                                    </label>
                                 </div>
                              </div>
                           </div>

                           <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                              <button type="submit" name="save_system_settings" class="btn btn-primary">
                                 <i class="bi bi-save me-2"></i>Save System Settings
                              </button>
                           </div>
                        </div>

                        <div class="settings-card">
                           <h4 class="mb-4">System Maintenance</h4>

                           <div class="d-grid gap-2 d-md-flex justify-content-md-start">
                              <a href="maintenance.php?action=clear_cache" class="btn btn-outline-secondary">
                                 <i class="bi bi-trash me-2"></i>Clear Cache
                              </a>
                              <a href="maintenance.php?action=backup" class="btn btn-outline-primary">
                                 <i class="bi bi-download me-2"></i>Backup Database
                              </a>
                              <a href="logs.php" class="btn btn-outline-info">
                                 <i class="bi bi-file-text me-2"></i>View System Logs
                              </a>
                           </div>
                        </div>
                     </div>
                  <?php endif; ?>
               </div>

               <!-- Save Button -->
               <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                  <button type="reset" class="btn btn-outline-secondary me-2">
                     <i class="bi bi-arrow-counterclockwise me-2"></i>Reset
                  </button>
                  <button type="submit" name="save_settings" class="btn btn-primary">
                     <i class="bi bi-save me-2"></i>Save Settings
                  </button>
               </div>
            </form>
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

      // Theme changer
      document.getElementById('theme').addEventListener('change', function() {
         document.documentElement.setAttribute('data-bs-theme', this.value);
      });

      // Widget selection
      const widgetCards = document.querySelectorAll('.widget-card');
      widgetCards.forEach(card => {
         card.addEventListener('click', function() {
            const checkbox = this.querySelector('input[type="checkbox"]');
            checkbox.checked = !checkbox.checked;

            if (checkbox.checked) {
               this.classList.add('selected');
            } else {
               this.classList.remove('selected');
            }
         });
      });
   </script>
</body>

</html>