<footer class="footer bg-dark text-white py-4 mt-auto">
   <div class="container">
      <div class="row">
         <div class="col-md-6">
            <p>&copy; <?= date('Y') ?> EVSU-EventQR. All rights reserved.</p>
         </div>
         <div class="col-md-6 text-md-end">
            <a href="#" class="text-white me-3">Privacy Policy</a>
            <a href="#" class="text-white me-3">Terms of Service</a>
            <a href="staff/login.php" class="text-white">Staff Portal</a>
         </div>
      </div>
   </div>
</footer>

<style>
   html, body {
      height: 100%;
   }
   
   body {
      display: flex;
      flex-direction: column;
      min-height: 100vh;
   }
   
   .content {
      flex: 1 0 auto;
   }
   
   .footer {
      flex-shrink: 0;
   }
   
   @media (max-height: 800px) {
      .footer {
         position: relative;
      }
   }
</style>

<script>
   // Ensure the page structure has a content wrapper
   document.addEventListener('DOMContentLoaded', function() {
      if (!document.querySelector('.content')) {
         // If no content wrapper exists, wrap all content between nav and footer
         const nav = document.querySelector('nav');
         const footer = document.querySelector('footer');
         
         if (nav && footer) {
            let contentWrapper = document.createElement('div');
            contentWrapper.className = 'content';
            
            // Get all elements between nav and footer
            let currentElement = nav.nextElementSibling;
            let elementsToMove = [];
            
            while (currentElement && currentElement !== footer) {
               elementsToMove.push(currentElement);
               currentElement = currentElement.nextElementSibling;
            }
            
            // Move elements to the content wrapper
            elementsToMove.forEach(el => contentWrapper.appendChild(el));
            
            // Insert content wrapper after nav
            nav.parentNode.insertBefore(contentWrapper, footer);
         }
      }
   });
</script>