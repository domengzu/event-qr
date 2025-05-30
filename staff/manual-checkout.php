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

// Get database connection
$conn = getDBConnection();

// Get list of events with attendance
$sql = "SELECT DISTINCT e.event_id, e.event_name, e.event_date, e.location
        FROM events e
        JOIN attendance a ON e.event_id = a.event_id
        WHERE a.check_out_time IS NULL
        ORDER BY e.event_date DESC";
$result = $conn->query($sql);
$events = [];
if ($result) {
   while ($row = $result->fetch_assoc()) {
      $events[] = $row;
   }
}

// Process form submission
$message = '';
$messageType = '';
$selectedEvent = '';
$students = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
   if (isset($_POST['student_id']) && isset($_POST['event_id'])) {
      $studentId = $_POST['student_id'];
      $eventId = $_POST['event_id'];

      // Get event end time to check if student is leaving early
      $eventSql = "SELECT event_date, end_time FROM events WHERE event_id = ?";
      $stmt = $conn->prepare($eventSql);
      $stmt->bind_param("i", $eventId);
      $stmt->execute();
      $eventResult = $stmt->get_result();
      $event = $eventResult->fetch_assoc();

      // Calculate if student is leaving early
      $eventEndDateTime = $event['event_date'] . ' ' . $event['end_time'];
      $currentTime = date('Y-m-d H:i:s');
      $leavingEarly = ($currentTime < $eventEndDateTime);

      // Get current status from attendance
      $statusSql = "SELECT status FROM attendance 
                    WHERE student_id = ? AND event_id = ? AND check_out_time IS NULL";
      $stmt = $conn->prepare($statusSql);
      $stmt->bind_param("si", $studentId, $eventId);
      $stmt->execute();
      $statusResult = $stmt->get_result();
      $currentAttendance = $statusResult->fetch_assoc();

      // If student is leaving early, update status, otherwise keep current status
      $newStatus = $leavingEarly ? 'left early' : $currentAttendance['status'];

      // Update the attendance record with check-out time and possibly new status
      $sql = "UPDATE attendance 
              SET check_out_time = NOW(), status = ? 
              WHERE student_id = ? AND event_id = ? AND check_out_time IS NULL";
      $stmt = $conn->prepare($sql);
      $stmt->bind_param("ssi", $newStatus, $studentId, $eventId);

      if ($stmt->execute() && $stmt->affected_rows > 0) {
         $messageType = "success";

         // Get student name for confirmation
         $sql = "SELECT first_name, last_name FROM students WHERE student_id = ?";
         $stmt = $conn->prepare($sql);
         $stmt->bind_param("s", $studentId);
         $stmt->execute();
         $result = $stmt->get_result();
         if ($row = $result->fetch_assoc()) {
            $message = "{$row['first_name']} {$row['last_name']} has been checked out successfully";
            if ($leavingEarly) {
               $message .= " (Left Early)";
            }
            $message .= ".";
         }
      } else {
         $message = "Failed to check out student. They might have already checked out.";
         $messageType = "danger";
      }
   }

   // In any case, if event_id is submitted, load students for that event
   if (isset($_POST['event_id']) && !empty($_POST['event_id'])) {
      $selectedEvent = $_POST['event_id'];

      // Get students who checked in but haven't checked out for this event
      $sql = "SELECT s.student_id, s.first_name, s.last_name, a.check_in_time
              FROM students s
              JOIN attendance a ON s.student_id = a.student_id
              WHERE a.event_id = ? AND a.check_out_time IS NULL
              ORDER BY s.last_name, s.first_name";
      $stmt = $conn->prepare($sql);
      $stmt->bind_param("i", $selectedEvent);
      $stmt->execute();
      $result = $stmt->get_result();

      while ($row = $result->fetch_assoc()) {
         $students[] = $row;
      }
   }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Manual Check-Out - EventQR</title>
   <!-- Bootstrap CSS -->
   <link href="../bootstrap/css/bootstrap.min.css" rel="stylesheet">
   <link rel="stylesheet" href="../assets/main.css">
   <!-- Bootstrap Icons -->
   <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
   <!-- Include your common CSS styles -->
</head>

<body>
   <!-- Include your common header/sidebar here -->

   <div class="container mt-4">
      <div class="row">
         <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
               <h1>Manual Check-Out</h1>
               <div>
                  <a href="qr-scanner.php" class="btn btn-primary">
                     <i class="bi bi-qr-code-scan me-1"></i> QR Scanner
                  </a>
                  <a href="attendance.php" class="btn btn-outline-secondary">
                     <i class="bi bi-list-check me-1"></i> View All Attendance
                  </a>
               </div>
            </div>
         </div>
      </div>

      <?php if (!empty($message)) : ?>
         <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
            <?= $message ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
         </div>
      <?php endif; ?>

      <div class="card shadow-sm">
         <div class="card-header bg-white">
            <h5 class="mb-0">Check-Out Students</h5>
         </div>
         <div class="card-body">
            <form method="post" action="manual-checkout.php" id="eventSelectForm">
               <div class="mb-3">
                  <label for="event_id" class="form-label">Select Event</label>
                  <select class="form-select" name="event_id" id="event_id" onchange="this.form.submit()" required>
                     <option value="">-- Select Event --</option>
                     <?php foreach ($events as $event) : ?>
                        <option value="<?= $event['event_id'] ?>" <?= ($selectedEvent == $event['event_id']) ? 'selected' : '' ?>>
                           <?= htmlspecialchars($event['event_name']) ?>
                           (<?= date('M d, Y', strtotime($event['event_date'])) ?>)
                        </option>
                     <?php endforeach; ?>
                  </select>
               </div>
            </form>

            <?php if (empty($students) && !empty($selectedEvent)) : ?>
               <div class="alert alert-info">
                  <i class="bi bi-info-circle me-2"></i> No students are checked in without checking out for this event.
               </div>
            <?php elseif (!empty($students)) : ?>
               <h5>Students to Check Out:</h5>
               <div class="table-responsive">
                  <table class="table table-hover">
                     <thead>
                        <tr>
                           <th>Student ID</th>
                           <th>Name</th>
                           <th>Check-in Time</th>
                           <th>Action</th>
                        </tr>
                     </thead>
                     <tbody>
                        <?php foreach ($students as $student) : ?>
                           <tr>
                              <td><?= htmlspecialchars($student['student_id']) ?></td>
                              <td><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></td>
                              <td><?= date('g:i A', strtotime($student['check_in_time'])) ?></td>
                              <td>
                                 <form method="post" action="manual-checkout.php" class="d-inline">
                                    <input type="hidden" name="event_id" value="<?= $selectedEvent ?>">
                                    <input type="hidden" name="student_id" value="<?= $student['student_id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-primary" onclick="return confirm('Check out <?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?>?')">
                                       <i class="bi bi-box-arrow-right me-1"></i> Check Out
                                    </button>
                                 </form>
                              </td>
                           </tr>
                        <?php endforeach; ?>
                     </tbody>
                  </table>
               </div>

               <!-- Bulk checkout option -->
               <div class="mt-4">
                  <form method="post" action="bulk-checkout.php" id="bulkCheckoutForm">
                     <input type="hidden" name="event_id" value="<?= $selectedEvent ?>">
                     <button type="submit" class="btn btn-warning" onclick="return confirm('Check out ALL remaining students for this event?')">
                        <i class="bi bi-people me-1"></i> Check Out All Students
                     </button>
                  </form>
               </div>
            <?php endif; ?>
         </div>
      </div>
   </div>

   <!-- Bootstrap JS -->
   <script src="../bootstrap/js/bootstrap.bundle.min.js"></script>
</body>

</html>