<?php
// This file is a utility to generate QR codes for testing

// Include database connection
require_once 'config/database.php';

// Get database connection
$conn = getDBConnection();

$students = [];
$message = '';
$selected_student = null;

// Get available students
$sql = "SELECT student_id, first_name, last_name FROM students ORDER BY last_name, first_name";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
   while ($row = $result->fetch_assoc()) {
      $students[] = $row;
   }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['student_id'])) {
   $student_id = $_POST['student_id'];

   // Get student details
   $sql = "SELECT * FROM students WHERE student_id = ?";
   $stmt = $conn->prepare($sql);
   $stmt->bind_param("s", $student_id);
   $stmt->execute();
   $result = $stmt->get_result();

   if ($result->num_rows > 0) {
      $selected_student = $result->fetch_assoc();
      $message = 'QR code generated successfully.';
   } else {
      $message = 'Student not found.';
   }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Student QR Code Generator</title>
   <!-- Bootstrap CSS -->
   <link href="bootstrap/css/bootstrap.min.css" rel="stylesheet">
   <!-- Bootstrap Icons -->
   <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
   <!-- QRCode.js library -->
   <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
   <style>
      body {
         padding: 20px;
         background-color: #f8f9fa;
      }

      .qr-container {
         text-align: center;
         padding: 20px;
         background-color: white;
         border-radius: 8px;
         box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
      }

      #qrcode {
         margin: 20px auto;
      }

      #qrcode img {
         margin: 0 auto;
      }
   </style>
</head>

<body>
   <div class="container">
      <div class="row justify-content-center">
         <div class="col-md-8">
            <h1 class="mb-4">Student QR Code Generator</h1>

            <?php if (!empty($message)): ?>
               <div class="alert alert-info alert-dismissible fade show" role="alert">
                  <?= htmlspecialchars($message) ?>
                  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
               </div>
            <?php endif; ?>

            <div class="card mb-4">
               <div class="card-header bg-primary text-white">
                  <h5 class="mb-0">Generate QR Code</h5>
               </div>
               <div class="card-body">
                  <form method="post" action="student-qr-generator.php">
                     <div class="mb-3">
                        <label for="student_id" class="form-label">Select Student</label>
                        <select name="student_id" id="student_id" class="form-select" required>
                           <option value="">Select a student...</option>
                           <?php foreach ($students as $student): ?>
                              <option value="<?= htmlspecialchars($student['student_id']) ?>">
                                 <?= htmlspecialchars($student['last_name'] . ', ' . $student['first_name']) ?>
                                 (<?= htmlspecialchars($student['student_id']) ?>)
                              </option>
                           <?php endforeach; ?>
                        </select>
                     </div>

                     <button type="submit" class="btn btn-primary">Generate QR Code</button>
                  </form>
               </div>
            </div>

            <?php if ($selected_student): ?>
               <div class="card">
                  <div class="card-header bg-success text-white">
                     <h5 class="mb-0">Student QR Code</h5>
                  </div>
                  <div class="card-body">
                     <div class="row">
                        <div class="col-md-6">
                           <h5><?= htmlspecialchars($selected_student['first_name'] . ' ' . $selected_student['last_name']) ?></h5>
                           <p>Student ID: <strong><?= htmlspecialchars($selected_student['student_id']) ?></strong></p>
                           <p>Course: <?= htmlspecialchars($selected_student['course']) ?></p>
                           <p>Year Level: <?= htmlspecialchars($selected_student['year_level']) ?></p>

                           <div class="mt-3">
                              <div class="mb-2">QR Code Format Options:</div>
                              <select id="qr-format" class="form-select mb-3" onchange="updateQRCode()">
                                 <option value="plain">Plain Student ID</option>
                                 <option value="json">JSON Format</option>
                                 <option value="url">URL Format</option>
                              </select>

                              <div class="mb-2">QR Code Content:</div>
                              <textarea id="qr-data" class="form-control mb-3" rows="3"
                                 readonly><?= htmlspecialchars($selected_student['student_id']) ?></textarea>
                           </div>

                           <button class="btn btn-primary" onclick="downloadQR()">
                              <i class="bi bi-download me-1"></i> Download QR Code
                           </button>
                        </div>
                        <div class="col-md-6 qr-container">
                           <div id="qrcode"></div>
                           <p class="small text-muted">Scan this code with the EventQR Scanner</p>
                        </div>
                     </div>
                  </div>
               </div>
            <?php endif; ?>
         </div>
      </div>
   </div>

   <!-- Bootstrap JS -->
   <script src="bootstrap/js/bootstrap.bundle.min.js"></script>

   <?php if ($selected_student): ?>
      <script>
         let qrcode;
         const studentData = {
            student_id: "<?= addslashes($selected_student['student_id']) ?>",
            first_name: "<?= addslashes($selected_student['first_name']) ?>",
            last_name: "<?= addslashes($selected_student['last_name']) ?>",
         };

         function initQRCode(data) {
            if (qrcode) {
               qrcode.clear();
               qrcode.makeCode(data);
            } else {
               qrcode = new QRCode(document.getElementById("qrcode"), {
                  text: data,
                  width: 200,
                  height: 200,
                  colorDark: "#000000",
                  colorLight: "#ffffff",
                  correctLevel: QRCode.CorrectLevel.H
               });
            }
         }

         function updateQRCode() {
            const format = document.getElementById('qr-format').value;
            let data;

            switch (format) {
               case 'json':
                  data = JSON.stringify(studentData);
                  break;
               case 'url':
                  data = `https://eventqr.example.com/student/${studentData.student_id}`;
                  break;
               default: // plain
                  data = studentData.student_id;
            }

            document.getElementById('qr-data').value = data;
            initQRCode(data);
         }

         function downloadQR() {
            const canvas = document.querySelector("#qrcode canvas");
            const link = document.createElement('a');
            link.download = `student_qr_${studentData.student_id}.png`;
            link.href = canvas.toDataURL('image/png');
            link.click();
         }

         // Initialize QR code when page loads
         document.addEventListener('DOMContentLoaded', function() {
            updateQRCode();
         });
      </script>
   <?php endif; ?>
</body>

</html>