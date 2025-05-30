<?php
$currentPage = basename($_SERVER['PHP_SELF']);
?>

<nav class="navbar navbar-expand-lg navbar-dark">
   <div class="container">
      <a class="navbar-brand" href="index.php">EVSU-EVENT<span class="accent-text">QR</span></a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
         <span class="navbar-toggler-icon"></span>
      </button>
      <div class="collapse navbar-collapse" id="navbarNav">
         <ul class="navbar-nav ms-auto">
            <li class="nav-item">
               <a class="nav-link fw-bolder <?= $currentPage === 'index.php' ? 'active' : '' ?>" href="index.php">
                  HOME
                  <?php if($currentPage === 'index.php'): ?>
                     <span class="nav-indicator"></span>
                  <?php endif; ?>
               </a>
            </li>
            <li class="nav-item">
               <a class="nav-link fw-bolder <?= ($currentPage === 'events.php' || $currentPage === 'event-details.php') ? 'active' : '' ?>" href="events.php">
                  EVENTS
                  <?php if($currentPage === 'events.php' || $currentPage === 'event-details.php'): ?>
                     <span class="nav-indicator"></span>
                  <?php endif; ?>
               </a>
            </li>
         </ul>
      </div>
   </div>
</nav>

<style>
   .nav-link {
      position: relative;
      padding: 0.5rem 1rem;
      transition: color 0.3s;
   }
   
   .nav-link.active {
      color: var(--accent) !important;
   }
   
   .nav-indicator {
      position: absolute;
      bottom: -3px;
      left: 1rem;
      right: 1rem;
      height: 3px;
      background-color: var(--accent);
      border-radius: 3px 3px 0 0;
      display: block;
   }
   
   .navbar-nav .nav-item {
      margin: 0 5px;
   }
   
   @media (max-width: 991px) {
      .nav-indicator {
         left: 0;
         width: 4px;
         top: 0;
         bottom: 0;
         height: auto;
         border-radius: 0 3px 3px 0;
      }
   }
</style>