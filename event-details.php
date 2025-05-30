<?php
// Include database connection and mailer
require_once 'config/database.php';
require_once 'services/PhpMailerService.php';

// Get database connection
$conn = getDBConnection();

// Get event ID from URL
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
   header("Location: events.php");
   exit;
}

$eventId = (int)$_GET['id'];

// Fetch event details
$sql = "SELECT e.*, s.username as organizer 
        FROM events e 
        LEFT JOIN staff s ON e.created_by = s.staff_id
        WHERE e.event_id = ?";
$event = executeQuery($sql, "i", [$eventId]);

// If event not found, redirect to events page
if (empty($event)) {
   header("Location: events.php");
   exit;
}

// Get the event data
$event = $event[0];

// Count registered participants
$countSql = "SELECT COUNT(*) as count FROM event_registrations WHERE event_id = ?";
$countResult = executeQuery($countSql, "i", [$eventId]);
$participantCount = $countResult[0]['count'] ?? 0;

// Process registration form
$registrationSuccess = false;
$registrationError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
   // Validate form data
   $studentId = trim($_POST['student_id'] ?? '');
   $firstName = trim($_POST['first_name'] ?? '');
   $lastName = trim($_POST['last_name'] ?? '');
   $evsuEmail = trim($_POST['evsu_email'] ?? '');
   $course = trim($_POST['course'] ?? '');
   $yearLevel = (int)($_POST['year_level'] ?? 0);
   $guardianContact = trim($_POST['guardian_contact'] ?? '');

   if (empty($studentId) || empty($firstName) || empty($lastName) || empty($evsuEmail) || empty($course)) {
      $registrationError = 'Please fill in all required fields';
   } else {
      // Start transaction
      $conn->begin_transaction();

      try {
         // Check if student already registered for this event
         $checkRegSql = "SELECT registration_id FROM event_registrations 
                         WHERE event_id = ? AND student_id = ?";
         $existingReg = executeQuery($checkRegSql, "is", [$eventId, $studentId]);

         if (!empty($existingReg)) {
            throw new Exception('You are already registered for this event');
         }

         // Check if student exists in database
         $checkStudentSql = "SELECT student_id FROM students WHERE student_id = ?";
         $existingStudent = executeQuery($checkStudentSql, "s", [$studentId]);

         if (empty($existingStudent)) {
            // Student doesn't exist, create new student record
            $qrCode = 'QR_' . $studentId . '_' . uniqid();
            $insertStudentSql = "INSERT INTO students 
                                (student_id, qr_code, first_name, last_name, evsu_email, course, year_level, guardian_contact) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmtStudent = $conn->prepare($insertStudentSql);
            $stmtStudent->bind_param("sssssssi", $studentId, $qrCode, $firstName, $lastName, $evsuEmail, $course, $yearLevel, $guardianContact);

            if (!$stmtStudent->execute()) {
               throw new Exception('Failed to create student record');
            }
         } else {
            // Update existing student record information
            $updateStudentSql = "UPDATE students SET first_name = ?, last_name = ?, evsu_email = ?, course = ?, year_level = ?, guardian_contact = ? WHERE student_id = ?";
            $stmtUpdate = $conn->prepare($updateStudentSql);
            $stmtUpdate->bind_param("sssssis", $firstName, $lastName, $evsuEmail, $course, $yearLevel, $guardianContact, $studentId);
            $stmtUpdate->execute();
         }

         // Register student for the event
         $insertRegSql = "INSERT INTO event_registrations (event_id, student_id) VALUES (?, ?)";
         $stmtReg = $conn->prepare($insertRegSql);
         $stmtReg->bind_param("is", $eventId, $studentId);

         if (!$stmtReg->execute()) {
            throw new Exception('Registration failed');
         }

         // Commit the transaction
         $conn->commit();
         $registrationSuccess = true;

         // Generate QR code for check-in
         $qrCode = 'QR_' . $studentId . '_' . $eventId . '_' . uniqid();
         $qrCodeUrl = "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . urlencode($qrCode);

         // Send confirmation email using PHPMailer
         $mailer = new PhpMailerService();

         // Prepare email data
         $emailData = [
            'studentName' => $firstName . ' ' . $lastName,
            'eventName' => $event['event_name'],
            'eventDate' => date('F j, Y', strtotime($event['event_date'])),
            'eventTime' => date('g:i A', strtotime($event['start_time'])) . ' - ' .
               date('g:i A', strtotime($event['end_time'])),
            'location' => $event['location'],
            'studentId' => $studentId,
            'qrCodeUrl' => $qrCodeUrl
         ];

         // Generate and send the email
         $emailBody = $mailer->generateRegistrationEmail($emailData);
         $emailSubject = "Registration Confirmation: " . $event['event_name'];

         $emailSent = $mailer->sendEmail(
            $evsuEmail,
            $emailSubject,
            $emailBody
         );

         // Log email status to database
         try {
            $logEmailSql = "INSERT INTO email_logs (student_id, event_id, email_address, subject, status, sent_at) 
                           VALUES (?, ?, ?, ?, ?, NOW())";
            $stmtEmail = $conn->prepare($logEmailSql);
            $emailStatus = $emailSent ? 'sent' : 'failed';
            $stmtEmail->bind_param("sisss", $studentId, $eventId, $evsuEmail, $emailSubject, $emailStatus);
            $stmtEmail->execute();
         } catch (Exception $logError) {
            // Just log the error, don't interrupt the flow
            error_log("Error logging email: " . $logError->getMessage());
         }

         // Redirect to confirmation page
         header("Location: registration-confirmation.php?event_id=$eventId&student_id=$studentId&email_status=" . ($emailSent ? '1' : '0'));
         exit;
      } catch (Exception $e) {
         // Roll back the transaction on error
         $conn->rollback();
         $registrationError = $e->getMessage();
      }
   }
}

// Get attendance count for this event
$attendanceSql = "SELECT COUNT(*) as count FROM attendance WHERE event_id = ? AND status = 'present'";
$attendanceResult = executeQuery($attendanceSql, "i", [$eventId]);
$attendanceCount = $attendanceResult[0]['count'] ?? 0;

// Get registrations count
$regCountSql = "SELECT COUNT(*) as count FROM event_registrations WHERE event_id = ?";
$stmt = $conn->prepare($regCountSql);
$stmt->bind_param("i", $eventId);
$stmt->execute();
$regResult = $stmt->get_result();
$registrationsCount = 0; // Initialize with default value
if ($regRow = $regResult->fetch_assoc()) {
   $registrationsCount = $regRow['count'];
}

// Format dates for display
$eventDate = date('F d, Y', strtotime($event['event_date']));
$eventDay = date('l', strtotime($event['event_date']));
$startTime = date('g:i A', strtotime($event['start_time']));
$endTime = date('g:i A', strtotime($event['end_time']));
?>

<!DOCTYPE html>
<html lang="en">

<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title><?= htmlspecialchars($event['event_name']) ?> - EventQR</title>
   <!-- Bootstrap CSS -->
   <link href="bootstrap/css/bootstrap.min.css" rel="stylesheet">
   <link rel="stylesheet" href="assets/main.css">
   <!-- Bootstrap Icons -->
   <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
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
                  <a class="nav-link fw-bolder active" href="events.php">EVENTS</a>
               </li>
            </ul>
         </div>
      </div>
   </nav>

   <!-- Event Details Section -->
   <div class="container py-5">
      <div class="row">
         <div class="col-md-8">
            <!-- Event Banner with Image or Default with enhanced styling -->
            <div class="event-banner mb-4 position-relative">
               <div style="background-image: url('<?= !empty($event['event_image']) ? $event['event_image'] : 'assets/images/event-placeholder.jpg' ?>'); height: 350px; background-size: cover; background-position: center; border-radius: 10px; position: relative;">
                  <div class="overlay"></div>
               </div>

               <div class="banner-content">
                  <div class="container">
                     <div class="row">
                        <div class="col-lg-8">
                           <h1 class="display-4 fw-bold text-white mb-3"><?= htmlspecialchars($event['event_name']) ?></h1>
                           <div class="mb-4">
                              <span class="badge bg-primary me-2 px-3 py-2"><?= $eventDay ?></span>
                              <span class="badge bg-success px-3 py-2"><?= $registrationsCount ?> Registered</span>
                           </div>

                           <div class="event-details-card">
                              <div class="row g-0">
                                 <div class="col-auto pe-4">
                                    <div class="calendar-icon">
                                       <div class="calendar-month"><?= date('M', strtotime($event['event_date'])) ?></div>
                                       <div class="calendar-day"><?= date('d', strtotime($event['event_date'])) ?></div>
                                    </div>
                                 </div>
                                 <div class="col">
                                    <ul class="event-info-list">
                                       <li>
                                          <i class="bi bi-clock"></i>
                                          <span><?= $startTime ?> - <?= $endTime ?></span>
                                       </li>
                                       <li>
                                          <i class="bi bi-geo-alt"></i>
                                          <span><?= htmlspecialchars($event['location']) ?></span>
                                       </li>
                                       <?php if (strtotime($event['event_date']) >= strtotime('today')): ?>
                                          <li>
                                             <i class="bi bi-people"></i>
                                             <span>Registration open</span>
                                          </li>
                                       <?php endif; ?>
                                    </ul>
                                 </div>
                              </div>
                           </div>
                        </div>
                     </div>
                  </div>
               </div>
            </div>

            <!-- Event Title and Details -->
            <h1 class="mb-3"><?= htmlspecialchars($event['event_name']) ?></h1>

            <div class="event-meta mb-4">
               <div class="badge bg-primary me-2">
                  <i class="bi bi-calendar3"></i> <?= date('F d, Y', strtotime($event['event_date'])) ?>
               </div>
               <div class="badge bg-secondary me-2">
                  <i class="bi bi-clock"></i>
                  <?= date('g:i A', strtotime($event['start_time'])) ?> -
                  <?= date('g:i A', strtotime($event['end_time'])) ?>
               </div>
               <div class="badge bg-info me-2">
                  <i class="bi bi-geo-alt"></i> <?= htmlspecialchars($event['location']) ?>
               </div>
            </div>

            <!-- Event Description -->
            <div class="card mb-4 enhanced-card about-event-card">
               <div class="card-body">
                  <h3 class="card-title">
                     <i class="bi bi-info-circle-fill text-primary me-2"></i>
                     About This Event
                  </h3>
                  <hr class="card-divider">
                  <div class="event-description">
                     <?php if (!empty($event['description'])): ?>
                        <?= nl2br(htmlspecialchars($event['description'])) ?>
                     <?php else: ?>
                        <p class="text-muted fst-italic">No detailed description available for this event.</p>
                     <?php endif; ?>
                  </div>

                  <!-- Event highlights section -->
                  <div class="event-highlights mt-4">
                     <div class="row">
                        <div class="col-md-4 mb-3">
                           <div class="highlight-item">
                              <div class="highlight-icon">
                                 <i class="bi bi-calendar-date"></i>
                              </div>
                              <div class="highlight-content">
                                 <h5>Date</h5>
                                 <p><?= $eventDate ?></p>
                              </div>
                           </div>
                        </div>
                        <div class="col-md-4 mb-3">
                           <div class="highlight-item">
                              <div class="highlight-icon">
                                 <i class="bi bi-clock"></i>
                              </div>
                              <div class="highlight-content">
                                 <h5>Time</h5>
                                 <p><?= $startTime ?> - <?= $endTime ?></p>
                              </div>
                           </div>
                        </div>
                        <div class="col-md-4 mb-3">
                           <div class="highlight-item">
                              <div class="highlight-icon">
                                 <i class="bi bi-geo-alt"></i>
                              </div>
                              <div class="highlight-content">
                                 <h5>Location</h5>
                                 <p><?= htmlspecialchars($event['location']) ?></p>
                              </div>
                           </div>
                        </div>
                     </div>
                  </div>
               </div>
            </div>

            <!-- Organizer Information -->
            <?php if (!empty($event['organizer'])): ?>
               <div class="card mb-4 enhanced-card organizer-card">
                  <div class="card-body">
                     <h3 class="card-title">
                        <i class="bi bi-person-badge-fill text-primary me-2"></i>
                        Organizer
                     </h3>
                     <hr class="card-divider">

                     <div class="organizer-info">
                        <div class="row align-items-center">
                           <div class="col-md-2 col-sm-3 text-center mb-3 mb-md-0">
                              <div class="organizer-avatar">
                                 <i class="bi bi-person-circle"></i>
                              </div>
                           </div>
                           <div class="col-md-10 col-sm-9">
                              <h4 class="organizer-name"><?= htmlspecialchars($event['organizer']) ?></h4>
                              <p class="organizer-role text-muted">Event Coordinator</p>
                              <p class="mb-0">From the <?= htmlspecialchars($event['department'] ?? 'Event Management Department') ?></p>

                              <div class="mt-3">
                                 <span class="badge bg-light text-dark me-2">
                                    <i class="bi bi-calendar-check me-1"></i> Event Organizer
                                 </span>
                                 <span class="badge bg-light text-dark">
                                    <i class="bi bi-telephone me-1"></i> EVSU Staff
                                 </span>
                              </div>
                           </div>
                        </div>

                        <div class="organizer-message mt-4 p-3 bg-light rounded">
                           <i class="bi bi-quote me-2 text-primary"></i>
                           <span class="fst-italic">For inquiries about this event, please contact the event organizer through the University's official channels.</span>
                        </div>
                     </div>
                  </div>
               </div>
            <?php endif; ?>

            <!-- Event Info Cards - Moved from sidebar to main content -->
            <div class="row info-cards mb-4">
               <!-- Event Statistics Card -->
               <div class="col-md-4 mb-3">
                  <div class="card sidebar-card h-100">
                     <div class="card-body">
                        <h3 class="card-title"><i class="bi bi-bar-chart-line me-2"></i>Event Statistics</h3>
                        <div class="d-flex justify-content-between align-items-center mb-3">
                           <div>Registered:</div>
                           <div><strong><?= $participantCount ?></strong></div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                           <div>Attended:</div>
                           <div><strong><?= $attendanceCount ?></strong></div>
                        </div>
                     </div>
                  </div>
               </div>

               <!-- Event Details Card -->
               <div class="col-md-4 mb-3">
                  <div class="card sidebar-card h-100">
                     <div class="card-body">
                        <h3 class="card-title"><i class="bi bi-info-circle me-2"></i>Event Details</h3>
                        <ul class="list-unstyled event-details-list">
                           <li>
                              <i class="bi bi-calendar3"></i>
                              <span><?= date('F d, Y', strtotime($event['event_date'])) ?></span>
                           </li>
                           <li>
                              <i class="bi bi-clock"></i>
                              <span><?= date('g:i A', strtotime($event['start_time'])) ?> - <?= date('g:i A', strtotime($event['end_time'])) ?></span>
                           </li>
                           <li>
                              <i class="bi bi-geo-alt"></i>
                              <span><?= htmlspecialchars($event['location']) ?></span>
                           </li>
                        </ul>
                     </div>
                  </div>
               </div>

               <!-- Share Event -->
               <div class="col-md-4 mb-3">
                  <div class="card sidebar-card h-100">
                     <div class="card-body">
                        <h3 class="card-title"><i class="bi bi-share me-2"></i>Share This Event</h3>
                        <div class="d-flex justify-content-center">
                           <a href="javascript:void(0)" onclick="shareEvent('facebook')" class="btn btn-outline-primary mx-1" title="Share on Facebook">
                              <i class="bi bi-facebook"></i>
                           </a>
                           <a href="javascript:void(0)" onclick="shareEvent('twitter')" class="btn btn-outline-info mx-1" title="Share on Twitter">
                              <i class="bi bi-twitter"></i>
                           </a>
                           <a href="javascript:void(0)" onclick="shareEvent('whatsapp')" class="btn btn-outline-success mx-1" title="Share on WhatsApp">
                              <i class="bi bi-whatsapp"></i>
                           </a>
                           <a href="javascript:void(0)" onclick="copyEventLink()" class="btn btn-outline-secondary mx-1" title="Copy Link" id="copyLinkBtn">
                              <i class="bi bi-link-45deg"></i>
                           </a>
                        </div>
                     </div>
                  </div>
               </div>
            </div>
         </div>

         <div class="col-md-4">
            <!-- Registration Card -->
            <div class="card shadow-sm sticky-sidebar">
               <div class="card-header bg-primary">
                  <h3 class="card-title m-0 text-white">Register for this Event</h3>
               </div>
               <div class="card-body">
                  <?php if ($registrationError): ?>
                     <div class="alert alert-danger">
                        <?= $registrationError ?>
                     </div>
                  <?php endif; ?>

                  <form method="post" action="event-details.php?id=<?= $eventId ?>">
                     <div class="mb-3">
                        <label for="student_id" class="form-label">Student ID *</label>
                        <input type="text" class="form-control" id="student_id" name="student_id" required>
                     </div>
                     <div class="mb-3">
                        <label for="first_name" class="form-label">First Name *</label>
                        <input type="text" class="form-control" id="first_name" name="first_name" required>
                     </div>
                     <div class="mb-3">
                        <label for="last_name" class="form-label">Last Name *</label>
                        <input type="text" class="form-control" id="last_name" name="last_name" required>
                     </div>
                     <div class="mb-3">
                        <label for="evsu_email" class="form-label">EVSU Email *</label>
                        <input type="email" class="form-control" id="evsu_email" name="evsu_email" required>
                     </div>
                     <div class="mb-3">
                        <label for="course" class="form-label">Course *</label>
                        <select class="form-select" id="course" name="course" required>
                           <option value="">Select Course</option>
                           <optgroup label="College of Engineering">
                              <option value="BSCE">Bachelor of Science in Civil Engineering</option>
                              <option value="BSME">Bachelor of Science in Mechanical Engineering</option>
                              <option value="BSEE">Bachelor of Science in Electrical Engineering</option>
                              <option value="BSCpE">Bachelor of Science in Computer Engineering</option>
                              <option value="BSECE">Bachelor of Science in Electronics and Communications Engineering</option>
                           </optgroup>
                           <optgroup label="College of Technology">
                              <option value="BIT-CT">Bachelor of Industrial Technology major in Civil Technology</option>
                              <option value="BIT-AT">Bachelor of Industrial Technology major in Automotive Technology</option>
                              <option value="BIT-ET">Bachelor of Industrial Technology major in Electrical Technology</option>
                              <option value="BIT-EXT">Bachelor of Industrial Technology major in Electronics Technology</option>
                           </optgroup>
                           <optgroup label="College of Education">
                              <option value="BEED">Bachelor of Elementary Education</option>
                              <option value="BSED-English">Bachelor of Secondary Education major in English</option>
                              <option value="BSED-Math">Bachelor of Secondary Education major in Mathematics</option>
                              <option value="BSED-Science">Bachelor of Secondary Education major in Science</option>
                              <option value="BSED-Filipino">Bachelor of Secondary Education major in Filipino</option>
                              <option value="BSED-SS">Bachelor of Secondary Education major in Social Studies</option>
                           </optgroup>
                           <optgroup label="College of Arts and Sciences">
                              <option value="BSIT">Bachelor of Science in Information Technology</option>
                              <option value="BSCS">Bachelor of Science in Computer Science</option>
                              <option value="BS Math">Bachelor of Science in Mathematics</option>
                              <option value="BS Physics">Bachelor of Science in Physics</option>
                           </optgroup>
                           <optgroup label="College of Business and Entrepreneurship">
                              <option value="BSBA-FM">Bachelor of Science in Business Administration major in Financial Management</option>
                              <option value="BSBA-MM">Bachelor of Science in Business Administration major in Marketing Management</option>
                              <option value="BSBA-HRM">Bachelor of Science in Business Administration major in Human Resource Management</option>
                              <option value="BSOA">Bachelor of Science in Office Administration</option>
                              <option value="BSA">Bachelor of Science in Accountancy</option>
                           </optgroup>
                           <optgroup label="College of Hospitality and Tourism Management">
                              <option value="BSHM">Bachelor of Science in Hospitality Management</option>
                              <option value="BSTM">Bachelor of Science in Tourism Management</option>
                              <option value="BS Nutrition">Bachelor of Science in Nutrition and Dietetics</option>
                           </optgroup>
                           <optgroup label="College of Architecture and Fine Arts">
                              <option value="BS Architecture">Bachelor of Science in Architecture</option>
                              <option value="BFA">Bachelor of Fine Arts</option>
                           </optgroup>
                           <optgroup label="Graduate Studies">
                              <option value="MA">Master of Arts</option>
                              <option value="MBA">Master of Business Administration</option>
                              <option value="MPA">Master of Public Administration</option>
                              <option value="MSc">Master of Science</option>
                           </optgroup>
                           <option value="Other">Other / Not Listed</option>
                        </select>
                     </div>
                     <div class="mb-3">
                        <label for="year_level" class="form-label">Year Level *</label>
                        <select class="form-select" id="year_level" name="year_level" required>
                           <option value="">Select Year Level</option>
                           <option value="1">1st Year</option>
                           <option value="2">2nd Year</option>
                           <option value="3">3rd Year</option>
                           <option value="4">4th Year</option>
                           <option value="5">5th Year</option>
                        </select>
                     </div>
                     <div class="mb-3">
                        <label for="guardian_contact" class="form-label">Guardian/Parent Contact Number *</label>
                        <div class="input-group">
                           <span class="input-group-text">+63</span>
                           <input type="text" class="form-control" id="guardian_contact" name="guardian_contact"
                              placeholder="9XX XXX XXXX" pattern="[0-9]{10}" maxlength="10" required>
                        </div>
                        <div class="form-text">Enter 10 digits without the leading '0' (e.g., 9171234567 for 09171234567)</div>
                     </div>
                     <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="consentCheck" required>
                        <label class="form-check-label" for="consentCheck">
                           I consent to receive email notifications about this event
                        </label>
                     </div>
                     <div class="d-grid">
                        <button type="submit" class="btn btn-primary">Register Now</button>
                     </div>
                  </form>

                  <div class="mt-3 text-center">
                     <p class="mb-0 text-muted">
                        <small>
                           <strong><?= $participantCount ?></strong> students registered
                        </small>
                     </p>
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
   <!-- Add phone number validation -->
   <script>
      document.addEventListener('DOMContentLoaded', function() {
         // Add phone number validation
         const guardianContactInput = document.getElementById('guardian_contact');
         if (guardianContactInput) {
            guardianContactInput.addEventListener('input', function(e) {
               // Remove any non-digit characters
               let value = this.value.replace(/\D/g, '');

               // Ensure the value doesn't start with 0
               if (value.startsWith('0')) {
                  value = value.substring(1);
               }

               // Keep only the first 10 digits
               value = value.substring(0, 10);

               // Update the input value
               this.value = value;
            });
         }

         // Restore selected course if form was submitted with errors
         <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($registrationError)): ?>
            const courseSelect = document.getElementById('course');
            const savedCourse = "<?= htmlspecialchars($_POST['course'] ?? '') ?>";

            if (courseSelect && savedCourse) {
               // Find and select the option
               for (let i = 0; i < courseSelect.options.length; i++) {
                  if (courseSelect.options[i].value === savedCourse) {
                     courseSelect.options[i].selected = true;
                     break;
                  }
               }
            }
         <?php endif; ?>
      });

      // Share event functionality
      function shareEvent(platform) {
         const eventTitle = "<?= htmlspecialchars($event['event_name']) ?>";
         const eventUrl = window.location.href;
         const eventDate = "<?= $eventDate ?>";
         const eventLocation = "<?= htmlspecialchars($event['location']) ?>";

         let shareUrl = '';

         switch (platform) {
            case 'facebook':
               shareUrl = `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(eventUrl)}`;
               break;
            case 'twitter':
               shareUrl = `https://twitter.com/intent/tweet?text=${encodeURIComponent(`Join me at ${eventTitle} on ${eventDate} at ${eventLocation}`)}&url=${encodeURIComponent(eventUrl)}`;
               break;
            case 'whatsapp':
               shareUrl = `https://api.whatsapp.com/send?text=${encodeURIComponent(`Join me at ${eventTitle} on ${eventDate} at ${eventLocation}: ${eventUrl}`)}`;
               break;
         }

         if (shareUrl) {
            window.open(shareUrl, '_blank');
         }
      }

      // Copy event link functionality
      function copyEventLink() {
         const eventUrl = window.location.href;
         navigator.clipboard.writeText(eventUrl).then(() => {
            const copyBtn = document.getElementById('copyLinkBtn');
            copyBtn.innerHTML = '<i class="bi bi-check2"></i>';
            copyBtn.classList.remove('btn-outline-secondary');
            copyBtn.classList.add('btn-success');

            setTimeout(() => {
               copyBtn.innerHTML = '<i class="bi bi-link-45deg"></i>';
               copyBtn.classList.remove('btn-success');
               copyBtn.classList.add('btn-outline-secondary');
            }, 2000);
         });
      }
   </script>
</body>

</html>

<!-- Add custom styles -->
<style>
   .event-banner {
      overflow: hidden;
      margin-top: -20px;
   }

   .overlay {
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: linear-gradient(rgba(0, 0, 0, 0.3), rgba(0, 0, 0, 0.7));
   }

   .banner-content {
      position: absolute;
      bottom: 0;
      left: 0;
      right: 0;
      padding: 30px 0;
   }

   .event-details-card {
      background-color: rgba(255, 255, 255, 0.9);
      border-radius: 10px;
      padding: 15px;
      box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
   }

   .calendar-icon {
      background-color: white;
      border-radius: 8px;
      overflow: hidden;
      width: 70px;
      text-align: center;
      box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
   }

   .calendar-month {
      background-color: #f84444;
      color: white;
      font-weight: bold;
      padding: 4px 0;
      font-size: 12px;
      text-transform: uppercase;
   }

   .calendar-day {
      font-size: 24px;
      font-weight: bold;
      padding: 5px 0;
   }

   .event-info-list {
      list-style: none;
      padding: 0;
      margin: 0;
   }

   .event-info-list li {
      display: flex;
      align-items: center;
      margin-bottom: 8px;
   }

   .event-info-list li i {
      color: #0d6efd;
      margin-right: 10px;
      font-size: 18px;
      width: 20px;
   }

   /* Registration card animation */
   .event-info-card {
      transition: transform 0.3s, box-shadow 0.3s;
   }

   .event-info-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 8px 15px rgba(0, 0, 0, 0.1);
   }

   /* Enhanced styles for sticky sidebar */
   .sticky-sidebar {
      position: -webkit-sticky;
      position: sticky;
      top: 20px;
      z-index: 100;
   }

   /* Container for the secondary sidebar cards */
   .sidebar-info-cards {
      position: -webkit-sticky;
      position: sticky;
      top: 480px;
      /* Height of registration form + padding */
   }

   /* Styling for sidebar cards */
   .sidebar-card {
      box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
      border-radius: 8px;
      border: none;
      transition: transform 0.3s ease, box-shadow 0.3s ease;
   }

   .sidebar-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 8px 15px rgba(0, 0, 0, 0.15);
   }

   .sidebar-card .card-title {
      font-size: 1.2rem;
      font-weight: 600;
      margin-bottom: 15px;
      color: #333;
      border-bottom: 1px solid #eee;
      padding-bottom: 10px;
   }

   /* Styles for the info cards in row format */
   .info-cards .card {
      box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
      border-radius: 8px;
      border: none;
      transition: transform 0.3s ease, box-shadow 0.3s ease;
      height: 100%;
   }

   .info-cards .card:hover {
      transform: translateY(-5px);
      box-shadow: 0 8px 15px rgba(0, 0, 0, 0.15);
   }

   .info-cards .card-title {
      font-size: 1.2rem;
      font-weight: 600;
      margin-bottom: 15px;
      color: #333;
      border-bottom: 1px solid #eee;
      padding-bottom: 10px;
   }

   /* Enhanced event details list for card format */
   .event-details-list li {
      display: flex;
      align-items: center;
      margin-bottom: 12px;
      font-size: 1rem;
   }

   .event-details-list li i {
      color: #0d6efd;
      margin-right: 12px;
      font-size: 1.1rem;
      width: 24px;
   }

   /* Ensure buttons look consistent across cards */
   .btn-outline-primary:hover,
   .btn-outline-info:hover,
   .btn-outline-success:hover,
   .btn-outline-secondary:hover {
      transform: scale(1.1);
   }

   /* Make cards consistent height on mobile */
   @media (max-width: 767px) {
      .info-cards .card {
         margin-bottom: 1rem;
      }
   }

   /* Custom styles for enhanced cards */
   .enhanced-card {
      border: none;
      border-radius: 12px;
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
      transition: transform 0.3s, box-shadow 0.3s;
      overflow: hidden;
   }

   .enhanced-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
   }

   /* Custom styles for enhanced cards */
   .enhanced-card {
      border: none;
      border-radius: 12px;
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
      transition: transform 0.3s, box-shadow 0.3s;
      overflow: hidden;
   }

   .enhanced-card .card-title {
      display: flex;
      align-items: center;
      font-size: 1.4rem;
      font-weight: 600;
      color: #333;
   }

   .enhanced-card .card-divider {
      margin: 0.8rem 0 1.2rem;
      opacity: 0.15;
      height: 2px;
      background: linear-gradient(to right, #007bff, #6610f2);
      margin: 0 -15px 15px;
   }

   /* Organizer avatar and message styles */
   .organizer-avatar {
      width: 60px;
      height: 60px;
      border-radius: 50%;
      background-color: #f0f0f0;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 28px;
      color: #007bff;
      margin: 0 auto 10px;
   }

   .organizer-message {
      background-color: #f9f9f9;
      border-left: 4px solid #007bff;
      padding: 10px 15px;
      border-radius: 8px;
      position: relative;
      margin-top: 10px;
   }

   .organizer-message:before {
      content: '';
      position: absolute;
      top: 10px;
      left: 15px;
      width: 8px;
      height: 8px;
      background-color: #007bff;
      border-radius: 50%;
   }

   /* About event card specific styling */
   .about-event-card .event-description {
      font-size: 1.05rem;
      line-height: 1.6;
      color: #555;
   }

   .event-highlights {
      margin-top: 1.5rem;
      padding-top: 1.5rem;
      border-top: 1px dashed rgba(0, 0, 0, 0.1);
   }

   .highlight-item {
      display: flex;
      align-items: flex-start;
   }

   .highlight-icon {
      width: 40px;
      height: 40px;
      background: rgba(13, 110, 253, 0.1);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-right: 10px;
      color: #0d6efd;
      font-size: 1.2rem;
   }

   .highlight-content h5 {
      margin: 0;
      font-size: 0.9rem;
      font-weight: 600;
      color: #666;
   }

   .highlight-content p {
      margin: 0;
      font-weight: 500;
      color: #333;
   }

   /* Organizer card specific styling */
   .organizer-card .organizer-avatar {
      width: 70px;
      height: 70px;
      background: rgba(13, 110, 253, 0.1);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto;
      color: #0d6efd;
   }

   .organizer-card .organizer-avatar i {
      font-size: 2.5rem;
   }

   .organizer-name {
      font-size: 1.2rem;
      font-weight: 600;
      margin-bottom: 0.2rem;
   }

   .organizer-role {
      font-size: 0.95rem;
      margin-bottom: 0.5rem;
   }

   .organizer-message {
      font-size: 0.95rem;
      border-left: 3px solid #0d6efd;
   }

   /* Responsive adjustments */
   @media (max-width: 576px) {
      .highlight-item {
         margin-bottom: 1rem;
      }

      .organizer-card .organizer-avatar {
         width: 50px;
         height: 50px;
      }

      .organizer-card .organizer-avatar i {
         font-size: 1.8rem;
      }
   }
</style>