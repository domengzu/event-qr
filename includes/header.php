<nav class="dashboard-header rounded">
   <button type="button" id="sidebarCollapse" class="btn btn-outline-dark">
      <i class="bi bi-list"></i>
   </button>

   <div class="d-flex align-items-end w-100 justify-content-end">
      <div class="dropdown user-dropdown">
         <button class="btn dropdown-toggle d-flex align-items-center" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
            <div class="me-2 text-end d-none d-md-block">
               <div class="fw-bold"><?= htmlspecialchars($fullName) ?></div>
               <div class="small text-muted"><?= htmlspecialchars($username) ?></div>
            </div>
            <?php
            $profilePic = (!empty($staffData['profile_picture']))
               ? "../" . $staffData['profile_picture']
               : "../assets/images/default-avatar.png";
            ?>
            <img src="<?= $profilePic ?>" alt="User Avatar">
         </button>
         <ul class="dropdown-menu shadow" aria-labelledby="userDropdown">
            <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i>Profile</a></li>
            <li><a class="dropdown-item" href="change-password.php"><i class="bi bi-key me-2"></i>Change Password</a></li>
            <li>
               <hr class="dropdown-divider">
            </li>
            <li><a class="dropdown-item" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
         </ul>
      </div>
   </div>
</nav>