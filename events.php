<?php
// Include database connection
require_once 'config/database.php';

// Get database connection
$conn = getDBConnection();

// Initialize search condition
$searchCondition = "";
$searchParams = [];
$paramTypes = "";

// Handle search functionality
if (isset($_GET['search']) && !empty($_GET['search'])) {
   $searchTerm = '%' . $_GET['search'] . '%';
   $searchCondition = " AND (event_name LIKE ? OR location LIKE ? OR description LIKE ?)";
   $searchParams = [$searchTerm, $searchTerm, $searchTerm];
   $paramTypes = "sss";
}

// Handle location filtering
$locationCondition = "";
if (isset($_GET['location']) && !empty($_GET['location'])) {
   $location = $_GET['location'];
   $locationCondition = " AND location = ?";
   $searchParams[] = $location;
   $paramTypes .= "s";
}

// Handle event timing filter (replacing type filter)
$timingCondition = "";
$currentDate = date('Y-m-d');
$currentTime = date('H:i:s');

if (isset($_GET['timing']) && !empty($_GET['timing'])) {
   switch ($_GET['timing']) {
      case 'today':
         $timingCondition = " AND event_date = ?";
         $searchParams[] = $currentDate;
         $paramTypes .= "s";
         break;

      case 'ongoing':
         $timingCondition = " AND event_date = ? AND start_time <= ? AND end_time >= ?";
         $searchParams[] = $currentDate;
         $searchParams[] = $currentTime;
         $searchParams[] = $currentTime;
         $paramTypes .= "sss";
         break;

      case 'upcoming':
         $timingCondition = " AND (event_date > ? OR (event_date = ? AND start_time > ?))";
         $searchParams[] = $currentDate;
         $searchParams[] = $currentDate;
         $searchParams[] = $currentTime;
         $paramTypes .= "sss";
         break;

      case 'past':
         $timingCondition = " AND (event_date < ? OR (event_date = ? AND end_time < ?))";
         $searchParams[] = $currentDate;
         $searchParams[] = $currentDate;
         $searchParams[] = $currentTime;
         $paramTypes .= "sss";
         break;
   }
}

// Get unique locations for the dropdown
$locationsSql = "SELECT DISTINCT location FROM events ORDER BY location ASC";
$locationsResult = $conn->query($locationsSql);
$locations = [];

if ($locationsResult && $locationsResult->num_rows > 0) {
   while ($row = $locationsResult->fetch_assoc()) {
      $locations[] = $row['location'];
   }
}

// Fetch all events with search and filters
$sql = "SELECT *, 
        CONCAT(event_date, ' ', end_time) as end_datetime,
        CASE WHEN CONCAT(event_date, ' ', end_time) < NOW() THEN 1 ELSE 0 END as is_completed
        FROM events WHERE 1=1" . $searchCondition . $locationCondition . $timingCondition .
   " ORDER BY is_completed ASC, event_date ASC";

// Initialize events array
$events = [];
$completedEvents = [];

if (!empty($searchParams)) {
   // Use prepared statement for search
   $allEvents = executeQuery($sql, $paramTypes, $searchParams);
   foreach ($allEvents as $event) {
      if ($event['is_completed'] == 1) {
         $completedEvents[] = $event;
      } else {
         $events[] = $event;
      }
   }
} else {
   // No search parameters, use simple query
   $result = $conn->query($sql);

   if ($result && $result->num_rows > 0) {
      while ($row = $result->fetch_assoc()) {
         if ($row['is_completed'] == 1) {
            $completedEvents[] = $row;
         } else {
            $events[] = $row;
         }
      }
   }
}

// Event types - hardcoded since we don't have a dedicated column
// $eventTypes = ['Workshop', 'Seminar', 'Conference', 'Social', 'Cultural', 'Academic', 'Sports'];
?>

<!DOCTYPE html>
<html lang="en">

<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Browse Events - EventQR</title>
   <!-- Bootstrap CSS -->
   <link href="bootstrap/css/bootstrap.min.css" rel="stylesheet">
   <link rel="stylesheet" href="assets/main.css">
   <!-- Bootstrap Icons -->
   <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
   <style>
      /* Enhanced event cards with Bootstrap */
      .event-card {
         border: none;
         border-radius: 12px;
         overflow: hidden;
         box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
         transition: transform 0.3s ease, box-shadow 0.3s ease;
         height: 100%;
         margin-bottom: 20px;
      }

      .event-card:hover {
         transform: translateY(-8px);
         box-shadow: 0 15px 30px rgba(0, 0, 0, 0.15);
      }

      .event-image-container {
         position: relative;
         height: 200px;
         overflow: hidden;
      }

      .event-image {
         width: 100%;
         height: 100%;
         object-fit: cover;
         transition: transform 0.5s ease;
      }

      .event-card:hover .event-image {
         transform: scale(1.05);
      }

      .event-date-badge {
         position: absolute;
         top: 15px;
         right: 15px;
         background: rgba(0, 0, 0, 0.7);
         color: white;
         padding: 8px 15px;
         border-radius: 50px;
         font-weight: 600;
         font-size: 0.9rem;
         backdrop-filter: blur(2px);
      }

      .event-content {
         padding: 1.5rem;
      }

      .event-title {
         font-size: 1.25rem;
         font-weight: 700;
         color: #333;
         margin-bottom: 0.8rem;
         line-height: 1.4;
      }

      .event-meta {
         display: flex;
         align-items: center;
         margin-bottom: 0.5rem;
         color: #555;
      }

      .event-meta i {
         color: #0d6efd;
         margin-right: 10px;
         width: 18px;
      }

      .event-description {
         color: #666;
         margin: 1rem 0;
         max-height: 72px;
         overflow: hidden;
         display: -webkit-box;
         -webkit-line-clamp: 3;
         -webkit-box-orient: vertical;
      }

      /* Banner section */
      .events-banner {
         background: linear-gradient(135deg, #0d6efd, #0099ff);
         padding: 3rem 0;
         margin-bottom: 3rem;
         color: white;
         border-radius: 0 0 30px 30px;
         box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
      }

      .banner-content h1 {
         font-weight: 700;
         margin-bottom: 1rem;
      }

      .banner-content p {
         font-size: 1.1rem;
         max-width: 600px;
         margin-bottom: 1.5rem;
         opacity: 0.9;
      }

      /* Search and filter section */
      .search-filter-section {
         background-color: white;
         border-radius: 12px;
         padding: 1.5rem;
         margin-bottom: 2rem;
         box-shadow: 0 3px 10px rgba(0, 0, 0, 0.05);
      }

      .search-input {
         border-radius: 50px;
         padding-left: 1rem;
         border: 1px solid #ddd;
      }

      .search-btn {
         border-radius: 0 50px 50px 0;
         padding-left: 1.5rem;
         padding-right: 1.5rem;
      }

      /* Pagination */
      .custom-pagination .page-item:first-child .page-link {
         border-radius: 50px 0 0 50px;
      }

      .custom-pagination .page-item:last-child .page-link {
         border-radius: 0 50px 50px 0;
      }

      .custom-pagination .page-link {
         color: #0d6efd;
         border: none;
         padding: 0.5rem 1rem;
         margin: 0 2px;
      }

      .custom-pagination .page-item.active .page-link {
         background-color: #0d6efd;
         color: white;
      }

      /* Empty state */
      .empty-state {
         text-align: center;
         padding: 3rem;
         background: white;
         border-radius: 12px;
         box-shadow: 0 3px 10px rgba(0, 0, 0, 0.05);
      }

      .empty-state i {
         font-size: 3rem;
         color: #6c757d;
         margin-bottom: 1.5rem;
      }

      .event-status-badge {
         position: absolute;
         top: 15px;
         left: 15px;
         z-index: 2;
      }

      .btn-secondary {
         background-color: #6c757d;
         border-color: #6c757d;
      }

      .btn-secondary:hover {
         background-color: #5a6268;
         border-color: #545b62;
      }
   </style>
</head>

<body>
   <!-- Navigation Bar -->
   <?php include 'includes/navigation.php'; ?>

   <!-- Banner Section -->
   <div class="events-banner">
      <div class="container">
         <div class="row align-items-center">
            <div class="col-md-7 banner-content">
               <h1>Upcoming Events</h1>
               <p>Discover and register for exciting events happening at Eastern Visayas State University. Stay connected and engage with the community!</p>
               <a href="#events-list" class="btn btn-light btn-lg">Browse Events</a>
            </div>
            <div class="col-md-5 text-center d-none d-md-block">
               <img src="assets/images/events-illustrations.png" alt="Events Illustration" class="img-fluid" style="max-height: 250px;">
            </div>
         </div>
      </div>
   </div>

   <!-- Events List Section -->
   <div class="container py-4" id="events-list">
      <!-- Search and Filter -->
      <div class="search-filter-section">
         <div class="row g-3">
            <div class="col-lg-7 col-md-6">
               <form method="get" action="events.php" class="d-flex">
                  <div class="input-group">
                     <input type="text" name="search" class="form-control search-input" placeholder="Search events by name, location..."
                        value="<?= isset($_GET['search']) ? htmlspecialchars($_GET['search']) : '' ?>">
                     <button type="submit" class="btn btn-primary search-btn">
                        <i class="bi bi-search me-1"></i> Search
                     </button>
                  </div>
               </form>
            </div>
            <div class="col-lg-5 col-md-6">
               <div class="row g-2">
                  <div class="col">
                     <select class="form-select" id="locationFilter" onchange="applyFilters()">
                        <option value="">All Locations</option>
                        <?php if (isset($locations) && is_array($locations)): ?>
                           <?php foreach ($locations as $loc): ?>
                              <option value="<?= htmlspecialchars($loc) ?>" <?= (isset($_GET['location']) && $_GET['location'] === $loc) ? 'selected' : '' ?>>
                                 <?= htmlspecialchars($loc) ?>
                              </option>
                           <?php endforeach; ?>
                        <?php endif; ?>
                     </select>
                  </div>
                  <div class="col">
                     <select class="form-select" id="dateFilter" onchange="applyFilters()">
                        <option value="">All Dates</option>
                        <option value="today" <?= (isset($_GET['date']) && $_GET['date'] === 'today') ? 'selected' : '' ?>>Today</option>
                        <option value="week" <?= (isset($_GET['date']) && $_GET['date'] === 'week') ? 'selected' : '' ?>>This Week</option>
                        <option value="month" <?= (isset($_GET['date']) && $_GET['date'] === 'month') ? 'selected' : '' ?>>This Month</option>
                     </select>
                  </div>
               </div>
            </div>
         </div>
      </div>

      <!-- Events Display -->
      <?php if (empty($events) && empty($completedEvents)): ?>
         <div class="empty-state">
            <i class="bi bi-calendar-x"></i>
            <h3>No events found</h3>
            <p class="text-muted mb-4">We couldn't find any events matching your search criteria.</p>
            <a href="events.php" class="btn btn-primary">View All Events</a>
         </div>
      <?php else: ?>
         <?php if (!empty($events)): ?>
            <h4 class="mb-3 text-primary"><i class="bi bi-calendar2-event me-2"></i>Upcoming & Current Events</h4>
            <div class="row">
               <?php foreach ($events as $event): ?>
                  <div class="col-lg-4 col-md-6 mb-4">
                     <!-- Event card for available events -->
                     <div class="card event-card">
                        <div class="event-image-container">
                           <?php if (!empty($event['event_image'])): ?>
                              <img src="<?= $event['event_image'] ?>" class="event-image" alt="<?= htmlspecialchars($event['event_name']) ?>">
                           <?php else: ?>
                              <div class="event-image d-flex align-items-center justify-content-center bg-light">
                                 <i class="bi bi-calendar-event text-secondary" style="font-size: 3rem;"></i>
                              </div>
                           <?php endif; ?>
                           <div class="event-date-badge">
                              <i class="bi bi-calendar2-event me-2"></i><?= date('M d', strtotime($event['event_date'])) ?>
                           </div>

                           <?php
                           // Ensure proper timezone is set for Philippines
                           date_default_timezone_set('Asia/Manila');

                           // Debug info - hidden in HTML comment for staff to check
                           $eventDateTime = $event['event_date'] . ' ' . $event['end_time'];
                           $currentDateTime = date('Y-m-d H:i:s');
                           $eventTimestamp = strtotime($eventDateTime);
                           $currentTimestamp = strtotime($currentDateTime);

                           // More reliable timestamp comparison to determine if event has ended
                           $eventEnded = ($currentTimestamp > $eventTimestamp);

                           // Add debug information in HTML comment that staff can view with inspect
                           echo "<!-- 
                              Debug info for event ID {$event['event_id']}:
                              Event date/time: $eventDateTime ($eventTimestamp)
                              Current date/time: $currentDateTime ($currentTimestamp)
                              Event has ended: " . ($eventEnded ? 'Yes' : 'No') . "
                           -->";

                           if ($eventEnded): ?>
                              <div class="event-status-badge">
                                 <span class="badge bg-secondary">Event Completed</span>
                              </div>
                           <?php endif; ?>
                        </div>
                        <div class="event-content">
                           <h3 class="event-title"><?= htmlspecialchars($event['event_name']) ?></h3>

                           <div class="event-meta">
                              <i class="bi bi-clock"></i>
                              <span><?= date('g:i A', strtotime($event['start_time'])) ?> - <?= date('g:i A', strtotime($event['end_time'])) ?></span>
                           </div>

                           <div class="event-meta">
                              <i class="bi bi-geo-alt"></i>
                              <span><?= htmlspecialchars($event['location']) ?></span>
                           </div>

                           <?php if (!empty($event['description'])): ?>
                              <div class="event-description">
                                 <?= htmlspecialchars($event['description']) ?>
                              </div>
                           <?php endif; ?>

                           <?php if ($eventEnded): ?>
                              <!-- For completed events, show disabled button -->
                              <button disabled class="btn btn-secondary w-100" title="Registration closed - event completed">
                                 <i class="bi bi-calendar-x me-2"></i>Event Completed
                              </button>
                           <?php else: ?>
                              <!-- For upcoming events, show registration option -->
                              <a href="event-details.php?id=<?= $event['event_id'] ?>" class="btn btn-primary w-100">
                                 <i class="bi bi-calendar-check me-2"></i>Register Now
                              </a>
                           <?php endif; ?>
                        </div>
                     </div>
                  </div>
               <?php endforeach; ?>
            </div>
         <?php endif; ?>

         <?php if (!empty($completedEvents)): ?>
            <div class="mt-5 mb-3">
               <h4 class="text-secondary"><i class="bi bi-calendar-check me-2"></i>Completed Events</h4>
            </div>
            <div class="row">
               <?php foreach ($completedEvents as $event): ?>
                  <div class="col-lg-4 col-md-6 mb-4">
                     <!-- Event card for completed events -->
                     <div class="card event-card" style="opacity: 0.85;">
                        <div class="event-image-container">
                           <?php if (!empty($event['event_image'])): ?>
                              <img src="<?= $event['event_image'] ?>" class="event-image" alt="<?= htmlspecialchars($event['event_name']) ?>">
                           <?php else: ?>
                              <div class="event-image d-flex align-items-center justify-content-center bg-light">
                                 <i class="bi bi-calendar-event text-secondary" style="font-size: 3rem;"></i>
                              </div>
                           <?php endif; ?>
                           <div class="event-date-badge">
                              <i class="bi bi-calendar2-event me-2"></i><?= date('M d', strtotime($event['event_date'])) ?>
                           </div>

                           <?php
                           // Ensure proper timezone is set for Philippines
                           date_default_timezone_set('Asia/Manila');

                           // Debug info - hidden in HTML comment for staff to check
                           $eventDateTime = $event['event_date'] . ' ' . $event['end_time'];
                           $currentDateTime = date('Y-m-d H:i:s');
                           $eventTimestamp = strtotime($eventDateTime);
                           $currentTimestamp = strtotime($currentDateTime);

                           // More reliable timestamp comparison to determine if event has ended
                           $eventEnded = ($currentTimestamp > $eventTimestamp);

                           // Add debug information in HTML comment that staff can view with inspect
                           echo "<!-- 
                              Debug info for event ID {$event['event_id']}:
                              Event date/time: $eventDateTime ($eventTimestamp)
                              Current date/time: $currentDateTime ($currentTimestamp)
                              Event has ended: " . ($eventEnded ? 'Yes' : 'No') . "
                           -->";

                           if ($eventEnded): ?>
                              <div class="event-status-badge">
                                 <span class="badge bg-secondary">Event Completed</span>
                              </div>
                           <?php endif; ?>
                        </div>
                        <div class="event-content">
                           <h3 class="event-title"><?= htmlspecialchars($event['event_name']) ?></h3>

                           <div class="event-meta">
                              <i class="bi bi-clock"></i>
                              <span><?= date('g:i A', strtotime($event['start_time'])) ?> - <?= date('g:i A', strtotime($event['end_time'])) ?></span>
                           </div>

                           <div class="event-meta">
                              <i class="bi bi-geo-alt"></i>
                              <span><?= htmlspecialchars($event['location']) ?></span>
                           </div>

                           <?php if (!empty($event['description'])): ?>
                              <div class="event-description">
                                 <?= htmlspecialchars($event['description']) ?>
                              </div>
                           <?php endif; ?>

                           <?php if ($eventEnded): ?>
                              <!-- For completed events, show disabled button -->
                              <button disabled class="btn btn-secondary w-100" title="Registration closed - event completed">
                                 <i class="bi bi-calendar-x me-2"></i>Event Completed
                              </button>
                           <?php else: ?>
                              <!-- For upcoming events, show registration option -->
                              <a href="event-details.php?id=<?= $event['event_id'] ?>" class="btn btn-primary w-100">
                                 <i class="bi bi-calendar-check me-2"></i>Register Now
                              </a>
                           <?php endif; ?>
                        </div>
                     </div>
                  </div>
               <?php endforeach; ?>
            </div>
         <?php endif; ?>

         <!-- Pagination -->
         <?php if (isset($totalPages) && $totalPages > 1): ?>
            <nav class="mt-5" aria-label="Event pagination">
               <ul class="pagination justify-content-center custom-pagination">
                  <?php
                  $currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
                  $prevPage = max(1, $currentPage - 1);
                  $nextPage = min($totalPages, $currentPage + 1);

                  $queryParams = $_GET;
                  ?>

                  <!-- Previous page -->
                  <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                     <?php
                     $queryParams['page'] = $prevPage;
                     $queryString = http_build_query($queryParams);
                     ?>
                     <a class="page-link" href="?<?= $queryString ?>" aria-label="Previous">
                        <span aria-hidden="true"><i class="bi bi-chevron-left"></i></span>
                     </a>
                  </li>

                  <?php
                  // Display limited page numbers with ellipsis
                  $startPage = max(1, $currentPage - 2);
                  $endPage = min($totalPages, $currentPage + 2);

                  // Always show first page
                  if ($startPage > 1) {
                     $queryParams['page'] = 1;
                     echo '<li class="page-item"><a class="page-link" href="?' . http_build_query($queryParams) . '">1</a></li>';
                     if ($startPage > 2) {
                        echo '<li class="page-item disabled"><a class="page-link">...</a></li>';
                     }
                  }

                  // Show page numbers
                  for ($i = $startPage; $i <= $endPage; $i++) {
                     $queryParams['page'] = $i;
                     $queryString = http_build_query($queryParams);
                     $activeClass = $i === $currentPage ? 'active' : '';
                     echo "<li class='page-item $activeClass'><a class='page-link' href='?$queryString'>$i</a></li>";
                  }

                  // Always show last page
                  if ($endPage < $totalPages) {
                     if ($endPage < $totalPages - 1) {
                        echo '<li class="page-item disabled"><a class="page-link">...</a></li>';
                     }
                     $queryParams['page'] = $totalPages;
                     echo '<li class="page-item"><a class="page-link" href="?' . http_build_query($queryParams) . '">' . $totalPages . '</a></li>';
                  }
                  ?>

                  <!-- Next page -->
                  <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                     <?php
                     $queryParams['page'] = $nextPage;
                     $queryString = http_build_query($queryParams);
                     ?>
                     <a class="page-link" href="?<?= $queryString ?>" aria-label="Next">
                        <span aria-hidden="true"><i class="bi bi-chevron-right"></i></span>
                     </a>
                  </li>
               </ul>
            </nav>
         <?php endif; ?>
      <?php endif; ?>
   </div>

   <!-- Footer -->
   <?php include 'includes/footer.php'; ?>

   <!-- Bootstrap JS -->
   <script src="bootstrap/js/bootstrap.bundle.min.js"></script>
   <script>
      // Filter functionality
      function applyFilters() {
         const locationFilter = document.getElementById('locationFilter').value;
         const dateFilter = document.getElementById('dateFilter').value;

         // Get current search parameter if exists
         const urlParams = new URLSearchParams(window.location.search);
         const searchParam = urlParams.get('search');

         // Build new URL with filters
         let queryParams = [];

         if (searchParam) {
            queryParams.push(`search=${encodeURIComponent(searchParam)}`);
         }

         if (locationFilter) {
            queryParams.push(`location=${encodeURIComponent(locationFilter)}`);
         }

         if (dateFilter) {
            queryParams.push(`date=${encodeURIComponent(dateFilter)}`);
         }

         // Redirect with new filters
         window.location.href = 'events.php' + (queryParams.length ? '?' + queryParams.join('&') : '');
      }

      // Smooth scroll to events section
      document.addEventListener('DOMContentLoaded', function() {
         const eventsBtn = document.querySelector('.banner-content .btn');
         eventsBtn.addEventListener('click', function(e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            window.scrollTo({
               top: target.offsetTop - 20,
               behavior: 'smooth'
            });
         });
      });
   </script>
</body>

</html>