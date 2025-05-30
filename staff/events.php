<?php
date_default_timezone_set('Asia/Manila');

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

// Process filter parameters
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10; // Events per page
$offset = ($page - 1) * $limit;

$searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';
$statusFilter = isset($_GET['status']) ? $_GET['status'] : 'all';
$dateFilter = isset($_GET['date_filter']) ? $_GET['date_filter'] : '';

// Build the SQL query with filters - optimize by using accurate registration counts from database
$sql = "SELECT e.*, 
        (SELECT COUNT(*) FROM event_registrations WHERE event_id = e.event_id) AS registrations,
        (SELECT COUNT(*) FROM attendance WHERE event_id = e.event_id AND status = 'present') as attendance
        FROM events e
        WHERE 1=1";

$countSql = "SELECT COUNT(*) as total FROM events WHERE 1=1";
$params = [];
$types = '';

// Apply search filter if provided
if (!empty($searchTerm)) {
   $searchPattern = "%$searchTerm%";
   $sql .= " AND (e.event_name LIKE ? OR e.location LIKE ? OR e.description LIKE ?)";
   $countSql .= " AND (event_name LIKE ? OR location LIKE ? OR description LIKE ?)";
   $params = array_merge($params, [$searchPattern, $searchPattern, $searchPattern]);
   $types .= 'sss';
}

// Apply status filter
$currentDate = date('Y-m-d');
if ($statusFilter === 'upcoming') {
   $sql .= " AND e.event_date >= ?";
   $countSql .= " AND event_date >= ?";
   $params[] = $currentDate;
   $types .= 's';
} elseif ($statusFilter === 'past') {
   $sql .= " AND e.event_date < ?";
   $countSql .= " AND event_date < ?";
   $params[] = $currentDate;
   $types .= 's';
}

// Apply date filter if provided
if (!empty($dateFilter)) {
   $sql .= " AND e.event_date = ?";
   $countSql .= " AND event_date = ?";
   $params[] = $dateFilter;
   $types .= 's';
}

// Execute the count query for pagination
$totalEvents = 0;
$stmt = $conn->prepare($countSql);
if (!empty($params)) {
   $stmt->bind_param($types, ...$params);
   $stmt->execute();
   $result = $stmt->get_result();
   if ($row = $result->fetch_assoc()) {
      $totalEvents = $row['total'];
   }
} else {
   $result = $conn->query($countSql);
   if ($row = $result->fetch_assoc()) {
      $totalEvents = $row['total'];
   }
}

// Calculate total pages for pagination
$totalPages = ceil($totalEvents / $limit);

// Adjust current page if it's out of range
if ($page < 1) $page = 1;
if ($page > $totalPages && $totalPages > 0) $page = $totalPages;

// Add ordering and pagination to the main query
$sql .= " ORDER BY e.event_date DESC, e.start_time ASC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= 'ii';

// Execute the main query
$events = [];
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
   $events[] = $row;
}

// Check for success message
$successMessage = isset($_GET['success']) ? $_GET['success'] : '';
$deleteSuccess = isset($_GET['deleted']) && $_GET['deleted'] == '1';
?>

<!DOCTYPE html>
<html lang="en">

<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Events Management - Staff Portal</title>
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

      /* Events table styles */
      .events-table {
         margin-bottom: 0;
      }

      .events-table td {
         vertical-align: middle;
      }

      .event-description {
         max-width: 250px;
         white-space: nowrap;
         overflow: hidden;
         text-overflow: ellipsis;
      }

      .event-actions {
         white-space: nowrap;
      }

      .event-badge {
         width: 100%;
         display: inline-block;
         text-align: center;
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

         <!-- Events Management Content -->
         <div class="container-fluid dashboard-container">
            <div class="d-flex justify-content-between align-items-center mb-4">
               <h1>Events Management</h1>
               <a href="create-event.php" class="btn btn-primary">
                  <i class="bi bi-plus-circle me-2"></i>Create Event
               </a>
            </div>

            <?php if ($successMessage === 'created'): ?>
               <div class="alert alert-success alert-dismissible fade show" role="alert">
                  Event created successfully.
                  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
               </div>
            <?php endif; ?>

            <?php if ($successMessage === 'updated'): ?>
               <div class="alert alert-success alert-dismissible fade show" role="alert">
                  Event updated successfully.
                  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
               </div>
            <?php endif; ?>

            <?php if ($deleteSuccess): ?>
               <div class="alert alert-success alert-dismissible fade show" role="alert">
                  Event deleted successfully.
                  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
               </div>
            <?php endif; ?>

            <!-- Search and Filter -->
            <div class="card mb-4 shadow-sm">
               <div class="card-body">
                  <form action="events.php" method="get" class="row g-3">
                     <div class="col-md-5">
                        <div class="input-group">
                           <input type="text" class="form-control" placeholder="Search events..." name="search" value="<?= htmlspecialchars($searchTerm) ?>">
                           <button class="btn btn-primary" type="submit">
                              <i class="bi bi-search me-1"></i> Search
                           </button>
                        </div>
                     </div>
                     <div class="col-md-3">
                        <select class="form-select" name="status" onchange="this.form.submit()">
                           <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Events</option>
                           <option value="upcoming" <?= $statusFilter === 'upcoming' ? 'selected' : '' ?>>Upcoming Events</option>
                           <option value="past" <?= $statusFilter === 'past' ? 'selected' : '' ?>>Past Events</option>
                        </select>
                     </div>
                     <div class="col-md-3">
                        <input type="date" class="form-control" name="date_filter" value="<?= htmlspecialchars($dateFilter) ?>" onchange="this.form.submit()">
                     </div>
                     <div class="col-md-1">
                        <a href="events.php" class="btn btn-outline-secondary d-block">Reset</a>
                     </div>
                  </form>
               </div>
            </div>

            <!-- Events Table -->
            <div class="card shadow-sm">
               <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                  <h5 class="mb-0"><i class="bi bi-calendar-event me-2"></i>Events List</h5>
                  <a href="create-event.php" class="btn btn-sm btn-success">
                     <i class="bi bi-plus-circle me-1"></i>Add New Event
                  </a>
               </div>
               <div class="card-body">
                  <?php if (empty($events)): ?>
                     <div class="alert alert-info d-flex align-items-center">
                        <i class="bi bi-info-circle-fill me-2 fs-4"></i>
                        <div>
                           <?php if (!empty($searchTerm) || $statusFilter !== 'all' || !empty($dateFilter)): ?>
                              No events found matching your search criteria. <a href="events.php" class="alert-link">Clear filters</a>
                           <?php else: ?>
                              No events found. <a href="create-event.php" class="alert-link">Create your first event</a>
                           <?php endif; ?>
                        </div>
                     </div>
                  <?php else: ?>
                     <div class="table-responsive">
                        <table class="table table-hover events-table align-middle">
                           <thead class="table-light">
                              <tr>
                                 <th width="50">#</th>
                                 <th>Event Details</th>
                                 <th>Date</th>
                                 <th>Time</th>
                                 <th>Location</th>
                                 <th>Reg.</th>
                                 <th>Att.</th>
                                 <th>Status</th>
                                 <th>Actions</th>
                              </tr>
                           </thead>
                           <tbody>
                              <?php foreach ($events as $index => $event): ?>
                                 <?php
                                 // Enhanced event status determination
                                 $eventDate = $event['event_date'];
                                 $startTime = $event['start_time'];
                                 $endTime = $event['end_time'];
                                 $startDateTime = "$eventDate $startTime";
                                 $endDateTime = "$eventDate $endTime";
                                 $currentDateTime = date('Y-m-d H:i:s');

                                 if ($currentDateTime < $startDateTime) {
                                    $status = 'Upcoming';
                                    $statusClass = 'primary';

                                    // Calculate time until event starts
                                    $seconds = strtotime($startDateTime) - time();
                                    $days = floor($seconds / 86400);
                                    $hours = floor(($seconds % 86400) / 3600);

                                    if ($days > 0) {
                                       $timeInfo = "Starts in $days day" . ($days > 1 ? 's' : '');
                                    } else {
                                       $timeInfo = "Starts in $hours hr" . ($hours != 1 ? 's' : '');
                                    }
                                 } elseif ($currentDateTime >= $startDateTime && $currentDateTime <= $endDateTime) {
                                    $status = 'In Progress';
                                    $statusClass = 'success';

                                    // Calculate time until event ends
                                    $seconds = strtotime($endDateTime) - time();
                                    $hours = floor($seconds / 3600);
                                    $minutes = floor(($seconds % 3600) / 60);
                                    $timeInfo = "Ends in $hours hr" . ($hours != 1 ? 's' : '') . ", $minutes min";
                                 } else {
                                    $status = 'Past';
                                    $statusClass = 'secondary';

                                    // Calculate time since event ended
                                    $seconds = time() - strtotime($endDateTime);
                                    $days = floor($seconds / 86400);

                                    if ($days > 7) {
                                       $timeInfo = date('M d, Y', strtotime($eventDate));
                                    } elseif ($days > 0) {
                                       $timeInfo = "$days day" . ($days > 1 ? 's' : '') . " ago";
                                    } else {
                                       $hours = floor($seconds / 3600);
                                       $timeInfo = "$hours hour" . ($hours != 1 ? 's' : '') . " ago";
                                    }
                                 }
                                 ?>
                                 <tr>
                                    <td><?= ($page - 1) * $limit + $index + 1 ?></td>
                                    <td>
                                       <?php if (!empty($event['event_image'])): ?>
                                          <div class="d-flex align-items-center">
                                             <img src="<?= '../' . htmlspecialchars($event['event_image']) ?>"
                                                alt="<?= htmlspecialchars($event['event_name']) ?>"
                                                class="me-3 rounded" style="width: 60px; height: 60px; object-fit: cover;">
                                             <div>
                                                <div class="fw-bold"><?= htmlspecialchars($event['event_name']) ?></div>
                                                <div class="event-description small text-muted">
                                                   <?= !empty($event['description']) ? htmlspecialchars(substr($event['description'], 0, 80)) . (strlen($event['description']) > 80 ? '...' : '') : '<em>No description provided</em>' ?>
                                                </div>
                                             </div>
                                          </div>
                                       <?php else: ?>
                                          <div class="fw-bold"><?= htmlspecialchars($event['event_name']) ?></div>
                                          <div class="event-description small text-muted">
                                             <?= !empty($event['description']) ? htmlspecialchars(substr($event['description'], 0, 80)) . (strlen($event['description']) > 80 ? '...' : '') : '<em>No description provided</em>' ?>
                                          </div>
                                       <?php endif; ?>
                                    </td>
                                    <td><?= date('M d, Y', strtotime($event['event_date'])) ?></td>
                                    <td><?= date('g:i A', strtotime($event['start_time'])) ?> - <?= date('g:i A', strtotime($event['end_time'])) ?></td>
                                    <td><?= !empty($event['location']) ? htmlspecialchars($event['location']) : '<em>No location set</em>' ?></td>
                                    <td><span class="badge bg-info rounded-pill"><?= intval($event['registrations']) ?></span></td>
                                    <td><span class="badge bg-success rounded-pill"><?= intval($event['attendance']) ?></span></td>
                                    <td>
                                       <span class="badge bg-<?= $statusClass ?> event-badge"><?= $status ?></span>
                                       <div class="small text-muted mt-1"><?= $timeInfo ?></div>
                                    </td>
                                    <td class="event-actions">
                                       <div class="dropdown">
                                          <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                             Actions
                                          </button>
                                          <ul class="dropdown-menu shadow">
                                             <li>
                                                <a class="dropdown-item" href="view-event.php?id=<?= $event['event_id'] ?>">
                                                   <i class="bi bi-eye me-2"></i> View Details
                                                </a>
                                             </li>
                                             <li>
                                                <a class="dropdown-item" href="edit-event.php?id=<?= $event['event_id'] ?>">
                                                   <i class="bi bi-pencil me-2"></i> Edit Event
                                                </a>
                                             </li>
                                             <li>
                                                <a class="dropdown-item" href="manage-attendees.php?event_id=<?= $event['event_id'] ?>">
                                                   <i class="bi bi-people me-2"></i> Manage Attendees
                                                </a>
                                             </li>
                                             <?php if ($status == 'In Progress'): ?>
                                                <li>
                                                   <a class="dropdown-item text-success" href="qr-scanner.php?event_id=<?= $event['event_id'] ?>">
                                                      <i class="bi bi-qr-code-scan me-2"></i> Scan QR Codes
                                                   </a>
                                                </li>
                                             <?php endif; ?>
                                             <li>
                                                <hr class="dropdown-divider">
                                             </li>
                                             <li>
                                                <button class="dropdown-item text-danger" onclick="showDeleteModal(<?= $event['event_id'] ?>, '<?= addslashes($event['event_name']) ?>')">
                                                   <i class="bi bi-trash me-2"></i> Delete Event
                                                </button>
                                             </li>
                                          </ul>
                                       </div>
                                    </td>
                                 </tr>
                              <?php endforeach; ?>
                           </tbody>
                        </table>
                     </div>

                     <!-- Pagination -->
                     <?php if ($totalPages > 1): ?>
                        <nav aria-label="Page navigation" class="mt-4">
                           <ul class="pagination justify-content-center">
                              <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                                 <a class="page-link" href="events.php?page=<?= $page - 1 ?>&search=<?= urlencode($searchTerm) ?>&status=<?= $statusFilter ?>&date_filter=<?= $dateFilter ?>" aria-label="Previous">
                                    <span aria-hidden="true">&laquo;</span>
                                 </a>
                              </li>

                              <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                 <li class="page-item <?= ($page == $i) ? 'active' : '' ?>">
                                    <a class="page-link" href="events.php?page=<?= $i ?>&search=<?= urlencode($searchTerm) ?>&status=<?= $statusFilter ?>&date_filter=<?= $dateFilter ?>">
                                       <?= $i ?>
                                    </a>
                                 </li>
                              <?php endfor; ?>

                              <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
                                 <a class="page-link" href="events.php?page=<?= $page + 1 ?>&search=<?= urlencode($searchTerm) ?>&status=<?= $statusFilter ?>&date_filter=<?= $dateFilter ?>" aria-label="Next">
                                    <span aria-hidden="true">&raquo;</span>
                                 </a>
                              </li>
                           </ul>
                        </nav>
                     <?php endif; ?>
                  <?php endif; ?>
               </div>
            </div>
         </div>
      </div>
   </div>

   <!-- Delete Event Modal -->
   <div class="modal fade" id="deleteEventModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog">
         <div class="modal-content">
            <div class="modal-header">
               <h5 class="modal-title">Confirm Delete</h5>
               <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
               <p>Are you sure you want to delete the event <strong id="eventNameToDelete"></strong>?</p>
               <p class="text-danger">This action cannot be undone. All registrations and attendance records for this event will also be deleted.</p>
            </div>
            <div class="modal-footer">
               <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
               <a href="#" id="confirmDeleteBtn" class="btn btn-danger">Delete Event</a>
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

      // Set up event deletion confirmation modal
      function showDeleteModal(eventId, eventName) {
         document.getElementById('eventNameToDelete').textContent = eventName;
         document.getElementById('confirmDeleteBtn').href = `delete-event.php?id=${eventId}`;

         // Initialize and show the modal
         const deleteModal = new bootstrap.Modal(document.getElementById('deleteEventModal'));
         deleteModal.show();
      }
   </script>
</body>

</html>