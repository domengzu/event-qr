<!DOCTYPE html>
<html lang="en">

<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>EventQR - Student Events</title>
   <!-- Bootstrap CSS -->
   <link href="bootstrap/css/bootstrap.min.css" rel="stylesheet">
   <link rel="stylesheet" href="assets/main.css">
</head>

<body>
   <?php include 'includes/navigation.php' ?>

   <div class="hero-section">
      <div class="container">
         <div class="row">
            <div class="col-lg-6">
               <h1 class="display-4 mb-4">Discover Campus <span class="accent-text">Events</span></h1>
               <p class="lead mb-4">Find and register for exciting events happening around your campus. Scan QR codes for easy check-ins and stay updated with the latest activities.</p>
               <a href="events.php" class="btn btn-primary btn-lg shadow">Browse Events</a>
            </div>
            <div class="col-lg-6 d-flex justify-content-center">
               <img src="assets/images/events-illustrations.png" alt="Events Illustration" class="img-fluid">
            </div>
         </div>
      </div>
   </div>

   <div class="container py-5">
      <h2 class="section-title text-center mb-5">How It Works</h2>
      <div class="row text-center">
         <div class="col-md-4 mb-4">
            <div class="card h-100 shadow feature-card">
               <div class="card-body">
                  <div class="mb-3">
                     <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="#F3C623" class="bi bi-search" viewBox="0 0 16 16">
                        <path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001c.03.04.062.078.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1.007 1.007 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0z" />
                     </svg>
                  </div>
                  <h3 class="card-title">Find Events</h3>
                  <p class="card-text">Browse through a variety of campus events from workshops to social gatherings.</p>
               </div>
            </div>
         </div>
         <div class="col-md-4 mb-4">
            <div class="card h-100 shadow feature-card">
               <div class="card-body">
                  <div class="mb-3">
                     <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="#F3C623" class="bi bi-journal-check" viewBox="0 0 16 16">
                        <path fill-rule="evenodd" d="M10.854 6.146a.5.5 0 0 1 0 .708l-3 3a.5.5 0 0 1-.708 0l-1.5-1.5a.5.5 0 1 1 .708-.708L7.5 8.793l2.646-2.647a.5.5 0 0 1 .708 0z" />
                        <path d="M3 0h10a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2v-12a2 2 0 0 1 2-2zm0 1a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1H3z" />
                     </svg>
                  </div>
                  <h3 class="card-title">Register Online</h3>
                  <p class="card-text">Easily register for events and get your personal QR code for check-in.</p>
               </div>
            </div>
         </div>
         <div class="col-md-4 mb-4">
            <div class="card h-100 shadow feature-card">
               <div class="card-body">
                  <div class="mb-3">
                     <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="#F3C623" class="bi bi-people" viewBox="0 0 16 16">
                        <path d="M15 14s1 0 1-1-1-4-5-4-5 3-5 4 1 1 1 1h8Zm-7.978-1A.261.261 0 0 1 7 12.996c.001-.264.167-1.03.76-1.72C8.312 10.629 9.282 10 11 10c1.717 0 2.687.63 3.24 1.276.593.69.758 1.457.76 1.72l-.008.002a.274.274 0 0 1-.014.002H7.022ZM11 7a2 2 0 1 0 0-4 2 2 0 0 0 0 4Zm3-2a3 3 0 1 1-6 0 3 3 0 0 1 6 0ZM6.936 9.28a5.88 5.88 0 0 0-1.23-.247A7.35 7.35 0 0 0 5 9c-4 0-5 3-5 4 0 .667.333 1 1 1h4.216A2.238 2.238 0 0 1 5 13c0-1.01.377-2.042 1.09-2.904.243-.294.526-.569.846-.816ZM4.92 10A5.493 5.493 0 0 0 4 13H1c0-.26.164-1.03.76-1.724.545-.636 1.492-1.256 3.16-1.275ZM1.5 5.5a3 3 0 1 1 6 0 3 3 0 0 1-6 0Zm3-2a2 2 0 1 0 0 4 2 2 0 0 0 0-4Z" />
                     </svg>
                  </div>
                  <h3 class="card-title">Attend & Connect</h3>
                  <p class="card-text">Scan your QR code at events and connect with fellow students and faculty.</p>
               </div>
            </div>
         </div>
      </div>
   </div>

   <?php
   // Include database connection
   require_once 'config/database.php';
   $conn = getDBConnection();

   // Fetch upcoming events for the homepage
   $eventsSql = "SELECT * FROM events WHERE event_date >= CURDATE() ORDER BY event_date ASC LIMIT 3";
   $eventsResult = $conn->query($eventsSql);

   $events = [];
   if ($eventsResult && $eventsResult->num_rows > 0) {
      while ($row = $eventsResult->fetch_assoc()) {
         $events[] = $row;
      }
   }
   ?>

   <div class="container py-5">
      <h2 class="section-title text-center mb-5">Upcoming Events</h2>
      <div class="row">
         <?php if (empty($events)): ?>
            <div class="col-md-12">
               <div class="alert alert-info text-center">
                  <i class="bi bi-calendar-x mb-3" style="font-size: 2rem;"></i>
                  <h4>No upcoming events</h4>
                  <p>Check back soon for new events!</p>
               </div>
            </div>
         <?php else: ?>
            <?php foreach ($events as $event): ?>
               <div class="col-md-4 mb-4">
                  <div class="card event-card h-100 shadow-sm hover-lift">
                     <div class="card-img-container position-relative">
                        <?php if (!empty($event['event_image'])): ?>
                           <img src="<?= $event['event_image'] ?>" class="card-img-top event-image cover" alt="<?= htmlspecialchars($event['event_name']) ?>">
                        <?php else: ?>
                           <div class="card-img-top event-image bg-light d-flex align-items-center justify-content-center">
                              <i class="bi bi-calendar-event text-secondary" style="font-size: 3rem;"></i>
                           </div>
                        <?php endif; ?>
                        
                        <div class="event-date">
                           <span class="month"><?= date('M', strtotime($event['event_date'])) ?></span>
                           <span class="day"><?= date('d', strtotime($event['event_date'])) ?></span>
                        </div>

                        <?php
                        $isPastEvent = strtotime($event['event_date']) < strtotime('today');
                        $isTodayEvent = date('Y-m-d', strtotime($event['event_date'])) === date('Y-m-d');
                        ?>

                        <?php if ($isPastEvent): ?>
                           <div class="ribbon ribbon-top-right"><span>Past Event</span></div>
                        <?php elseif ($isTodayEvent): ?>
                           <div class="ribbon ribbon-top-right ribbon-today"><span>Today</span></div>
                        <?php endif; ?>
                     </div>

                     <div class="card-body d-flex flex-column">
                        <h5 class="card-title event-title"><?= htmlspecialchars($event['event_name']) ?></h5>
                        
                        <div class="event-details">
                           <div class="event-info">
                              <i class="bi bi-clock text-primary"></i>
                              <span><?= date('g:i A', strtotime($event['start_time'])) ?> - <?= date('g:i A', strtotime($event['end_time'])) ?></span>
                           </div>
                           <div class="event-info">
                              <i class="bi bi-geo-alt text-primary"></i>
                              <span><?= htmlspecialchars($event['location']) ?></span>
                           </div>
                        </div>
                        
                        <?php if (!empty($event['description'])): ?>
                           <p class="card-text text-muted event-description mt-2">
                              <?= htmlspecialchars(strlen($event['description']) > 100 ? 
                                 substr($event['description'], 0, 100) . '...' : 
                                 $event['description']) ?>
                           </p>
                        <?php endif; ?>
                        
                        <div class="mt-auto pt-3">
                           <a href="event-details.php?id=<?= $event['event_id'] ?>" class="btn btn-outline-primary btn-sm w-100">
                              <?= $isPastEvent ? 'View Details' : 'Register Now' ?>
                              <i class="bi <?= $isPastEvent ? 'bi-info-circle' : 'bi-arrow-right' ?> ms-1"></i>
                           </a>
                        </div>
                     </div>
                  </div>
               </div>
            <?php endforeach; ?>
            
            <div class="col-12 text-center mt-3">
               <a href="events.php" class="btn btn-primary btn-lg">
                  <i class="bi bi-calendar2-event me-2"></i>View All Events
               </a>
            </div>
         <?php endif; ?>
      </div>
   </div>

   <?php include 'includes/footer.php' ?>

   <!-- Bootstrap JS Bundle -->
   <script src="bootstrap/js/bootstrap.bundle.min.js"></script>

   <!-- Add ribbon CSS -->
   <style>
      .ribbon {
         width: 150px;
         height: 150px;
         overflow: hidden;
         position: absolute;
      }

      .ribbon span {
         position: absolute;
         display: block;
         width: 225px;
         padding: 8px 0;
         background-color: rgba(108, 117, 125, 0.8);
         box-shadow: 0 5px 10px rgba(0, 0, 0, .1);
         color: #fff;
         font: 700 12px/1 'Lato', sans-serif;
         text-shadow: 0 1px 1px rgba(0, 0, 0, .2);
         text-transform: uppercase;
         text-align: center;
      }

      .ribbon-top-right {
         top: 0;
         right: 0;
      }

      .ribbon-top-right span {
         left: -10px;
         top: 30px;
         transform: rotate(45deg);
      }

      .ribbon-today span {
         background-color: rgba(25, 135, 84, 0.8);
      }

      .event-card {
         border: none;
         border-radius: 12px;
         overflow: hidden;
         transition: all 0.3s ease;
      }
      
      .hover-lift:hover {
         transform: translateY(-8px);
         box-shadow: 0 10px 20px rgba(0,0,0,0.15) !important;
      }
      
      .event-image {
         height: 180px;
         object-fit: cover;
      }
      
      .event-title {
         font-weight: 600;
         font-size: 1.25rem;
         margin-bottom: 15px;
         color: #333;
         line-height: 1.4;
      }
      
      .event-date {
         position: absolute;
         left: 15px;
         top: 15px;
         background-color: rgba(255,255,255,0.9);
         color: #333;
         text-align: center;
         padding: 8px 15px;
         border-radius: 8px;
         box-shadow: 0 2px 10px rgba(0,0,0,0.1);
      }
      
      .event-date .month {
         display: block;
         font-size: 0.85rem;
         font-weight: 600;
         text-transform: uppercase;
         color: #0d6efd;
      }
      
      .event-date .day {
         display: block;
         font-size: 1.5rem;
         font-weight: 700;
         line-height: 1;
      }
      
      .event-info {
         display: flex;
         align-items: center;
         margin-bottom: 8px;
         color: #555;
      }
      
      .event-info i {
         margin-right: 8px;
         font-size: 1rem;
         width: 16px;
         text-align: center;
      }
      
      .event-details {
         margin-bottom: 15px;
      }
      
      .event-description {
         font-size: 0.9rem;
         display: -webkit-box;
         -webkit-line-clamp: 3;
         -webkit-box-orient: vertical;
         overflow: hidden;
      }
   </style>
</body>

</html>