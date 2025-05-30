<!-- Logout Confirmation Modal -->
<div class="modal fade" id="logoutModal" tabindex="-1" aria-labelledby="logoutModalLabel" aria-hidden="true">
   <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
         <div class="modal-header">
            <h5 class="modal-title" id="logoutModalLabel">Confirm Logout</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
         </div>
         <div class="modal-body">
            <p>Are you sure you want to log out of EventQR?</p>
            <p class="mb-0 text-muted small">Your session will end and you'll need to log in again to continue.</p>
         </div>
         <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <a href="../staff/logout.php" class="btn btn-danger">Yes, Logout</a>
         </div>
      </div>
   </div>
</div>

<script>
   // Function to show the logout confirmation modal
   function confirmLogout(event) {
      event.preventDefault();
      const logoutModal = new bootstrap.Modal(document.getElementById('logoutModal'));
      logoutModal.show();
   }

   // Add event listeners to logout links when the DOM is loaded
   document.addEventListener('DOMContentLoaded', function() {
      const logoutLinks = document.querySelectorAll('a[href="logout.php"]');
      logoutLinks.forEach(link => {
         link.addEventListener('click', confirmLogout);
      });
   });
</script>