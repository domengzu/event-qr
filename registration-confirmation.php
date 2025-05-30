<?php
// Include database connection
require_once 'config/database.php';

// Check required parameters
if (!isset($_GET['event_id']) || !isset($_GET['student_id'])) {
   header("Location: events.php");
   exit;
}

$eventId = (int)$_GET['event_id'];
$studentId = $_GET['student_id'];

// Get event details
$eventSql = "SELECT * FROM events WHERE event_id = ?";
$event = executeQuery($eventSql, "i", [$eventId]);

// Get student details
$studentSql = "SELECT * FROM students WHERE student_id = ?";
$student = executeQuery($studentSql, "s", [$studentId]);

// Check if both student and event exist
if (empty($event) || empty($student)) {
   header("Location: events.php");
   exit;
}

$event = $event[0];
$student = $student[0];

// Check if registration exists
$regSql = "SELECT * FROM event_registrations WHERE event_id = ? AND student_id = ?";
$registration = executeQuery($regSql, "is", [$eventId, $studentId]);

if (empty($registration)) {
   header("Location: events.php");
   exit;
}

$registration = $registration[0];

// Get email status if available
$emailStatus = isset($_GET['email_status']) ? (bool)$_GET['email_status'] : null;
?>

<!DOCTYPE html>
<html lang="en">

<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Registration Confirmed - EventQR</title>
   <!-- Bootstrap CSS -->
   <link href="bootstrap/css/bootstrap.min.css" rel="stylesheet">
   <link rel="stylesheet" href="assets/main.css">
   <!-- Bootstrap Icons -->
   <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
   
   <!-- Print Styles for Student ID Size -->
   <style>
      @media print {
         /* Hide elements not needed for print */
         nav, footer, .no-print, .alert, button {
            display: none !important;
         }
         
         body {
            margin: 0;
            padding: 0;
            background: white;
         }
         
         .container, .row, .col-md-8, .card {
            margin: 0 !important;
            padding: 0 !important;
            width: 100% !important;
            max-width: none !important;
            border: none !important;
            box-shadow: none !important;
         }
         
         /* Student ID card size (54mm x 86mm) */
         .print-card {
            display: block !important;
            width: 54mm !important;
            height: 86mm !important;
            margin: 0 auto !important;
            padding: 3mm !important;
            border: 1px dashed #ccc !important;
            page-break-inside: avoid;
            background-color: white;
            box-sizing: border-box;
            overflow: hidden;
         }
         
         .print-card .logo {
            text-align: center;
            margin-bottom: 2mm;
            font-weight: bold;
            font-size: 12pt;
         }
         
         .print-card .event-name {
            text-align: center;
            font-weight: bold;
            font-size: 11pt;
            margin-bottom: 3mm;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 48mm;
         }
         
         .print-card .student-details {
            font-size: 8pt;
            margin-bottom: 3mm;
         }
         
         .print-card .student-details div {
            margin-bottom: 1.5mm;
         }
         
         .print-card .qr-container {
            text-align: center;
            margin: 0 auto;
         }
         
         .print-card .qr-container img {
            display: block;
            width: 20mm !important;
            height: 20mm !important;
            margin: 0 auto 5mm auto;
            object-fit: contain;
         }
         
         .print-card .instructions {
            font-size: 7pt;
            text-align: center;
            font-style: italic;
            margin-top: 2mm;
         }
         
         /* Ensure vertical layout for portrait ID card */
         @page {
            size: portrait;
            margin: 0;
         }
         
         /* Hide the regular content when printing */
         .card-body > *:not(.print-card) {
            display: none !important;
         }
      }
      
      /* Hide the print version when viewing normally */
      .print-card {
         display: none;
      }
   </style>
</head>

<body>
   <!-- Navigation Bar -->
   <nav class="navbar navbar-expand-lg navbar-dark">
      <div class="container">
         <a class="navbar-brand" href="index.php">EVSU-EVENT<span class="accent-text">QR</span></a>
         <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
         </button>
         <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
               <li class="nav-item">
                  <a class="nav-link fw-bolder" href="index.php">HOME</a>
               </li>
               <li class="nav-item">
                  <a class="nav-link fw-bolder" href="events.php">EVENTS</a>
               </li>
            </ul>
         </div>
      </div>
   </nav>

   <!-- Confirmation Content -->
   <div class="container py-5">
      <div class="row justify-content-center">
         <div class="col-md-8">
            <div class="card">
               <div class="card-header bg-success text-white">
                  <h3 class="m-0">Registration Successful!</h3>
               </div>
               <div class="card-body">
                  <!-- Print-optimized version -->
                  <div class="print-card">
                     <div class="logo">EVSU-EVENT<span style="color: #F3C623;">QR</span></div>
                     <div class="event-name"><?= htmlspecialchars($event['event_name']) ?></div>
                     <div class="student-details">
                        <div><strong>Name:</strong> <?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></div>
                        <div><strong>ID:</strong> <?= htmlspecialchars($student['student_id']) ?></div>
                        <div><strong>Date:</strong> <?= date('m/d/Y', strtotime($event['event_date'])) ?></div>
                        <div><strong>Time:</strong> <?= date('g:i A', strtotime($event['start_time'])) ?></div>
                     </div>
                     <div class="qr-container">
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=<?= urlencode($student['qr_code']) ?>" alt="QR Code">
                     </div>
                     <div class="instructions">
                        Present this QR code at the entrance for check-in
                     </div>
                  </div>
                  
                  <!-- Regular content (only visible on screen) -->
                  <div class="text-center mb-4">
                     <i class="bi bi-check-circle-fill text-success" style="font-size: 64px;"></i>
                     <h4 class="mt-3">You're registered for this event</h4>
                  </div>

                  <div class="row mb-4">
                     <div class="col-md-6">
                        <h5>Event Details</h5>
                        <ul class="list-unstyled">
                           <li><strong>Event:</strong> <?= htmlspecialchars($event['event_name']) ?></li>
                           <li><strong>Date:</strong> <?= date('F d, Y', strtotime($event['event_date'])) ?></li>
                           <li><strong>Time:</strong> <?= date('g:i A', strtotime($event['start_time'])) ?> - <?= date('g:i A', strtotime($event['end_time'])) ?></li>
                           <li><strong>Location:</strong> <?= htmlspecialchars($event['location']) ?></li>
                        </ul>
                     </div>
                     <div class="col-md-6">
                        <h5>Your Information</h5>
                        <ul class="list-unstyled">
                           <li><strong>Name:</strong> <?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></li>
                           <li><strong>Student ID:</strong> <?= htmlspecialchars($student['student_id']) ?></li>
                           <li><strong>Email:</strong> <?= htmlspecialchars($student['evsu_email']) ?></li>
                           <li><strong>Course:</strong> <?= htmlspecialchars($student['course']) ?></li>
                        </ul>
                     </div>
                  </div>

                  <div class="text-center mb-4">
                     <h5>Your QR Code for Check-in</h5>
                     <div class="qr-code-container p-3 bg-light rounded">
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?= urlencode($student['qr_code']) ?>"
                           alt="QR Code" class="img-fluid">
                     </div>
                     <p class="text-muted mt-2">Present this QR code at the venue for check-in</p>
                  </div>

                  <div class="alert alert-info">
                     <h5 class="alert-heading"><i class="bi bi-info-circle me-2"></i>Important Instructions</h5>
                     <ul>
                        <li>Take a screenshot of your QR code or bookmark this page.</li>
                        <li>Arrive 15-30 minutes before the event starts.</li>
                        <li>Don't forget to bring your student ID.</li>
                        <li>Show your QR code to the staff at the entrance for quick check-in.</li>
                     </ul>
                  </div>

                  <!-- Email Notification Status -->
                  <?php if ($emailStatus !== null): ?>
                     <?php if ($emailStatus): ?>
                        <div class="alert alert-success mt-3">
                           <h5 class="alert-heading"><i class="bi bi-envelope-check me-2"></i>Confirmation Email Sent!</h5>
                           <p>A confirmation email with your check-in QR code has been sent to your email address: <strong><?= htmlspecialchars($student['evsu_email']) ?></strong></p>
                           <p>Please check your inbox (or spam folder) for more details.</p>
                        </div>
                     <?php else: ?>
                        <div class="alert alert-warning mt-3">
                           <h5 class="alert-heading"><i class="bi bi-envelope-x me-2"></i>Email Delivery Issue</h5>
                           <p>We couldn't send a confirmation email to your address. Please make sure your email is correct.</p>
                           <p><strong>Don't worry</strong> - your registration is still complete!</p>
                        </div>
                     <?php endif; ?>
                  <?php endif; ?>

                  <div class="text-center mt-4">
                     <a href="events.php" class="btn btn-primary me-2">Browse More Events</a>
                     <button onclick="window.print()" class="btn btn-outline-dark">
                        <i class="bi bi-printer me-2"></i>Print QR Pass
                     </button>
                  </div>
               </div>
            </div>
         </div>
      </div>
   </div>

   <!-- Footer -->
   <?php include 'includes/footer.php'; ?>

   <!-- Bootstrap JS Bundle -->
   <script src="bootstrap/js/bootstrap.bundle.min.js"></script>
   <script>
      // Force print preview to use the correct media
      document.querySelector('button[onclick="window.print()"]').addEventListener('click', function() {
         // Add a short timeout to ensure styles are applied and images are loaded
         setTimeout(function() {
            window.print();
         }, 500); // Increased timeout to ensure QR code is fully loaded
      });
      
      // Ensure QR code is loaded before printing
      window.addEventListener('load', function() {
         // Preload the QR code for printing
         const qrImg = new Image();
         qrImg.src = document.querySelector('.print-card .qr-container img').src;
      });
   </script>
</body>

</html>