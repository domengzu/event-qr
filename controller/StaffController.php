<?php

/**
 * Staff Controller
 * Handles all staff-related operations including authentication
 */
class StaffController
{
   private $conn;

   /**
    * Constructor - initialize database connection
    */
   public function __construct($conn)
   {
      $this->conn = $conn;
   }

   /**
    * Authenticate staff member
    * @param string $username Username
    * @param string $password Password
    * @return array|bool Staff data array if authenticated, false otherwise
    */
   public function authenticate($username, $password)
   {
      // Sanitize inputs
      $username = $this->conn->real_escape_string($username);

      // Get staff by username
      $sql = "SELECT staff_id, username, password, full_name FROM staff WHERE username = ?";
      $stmt = $this->conn->prepare($sql);
      $stmt->bind_param("s", $username);
      $stmt->execute();
      $result = $stmt->get_result();

      if ($result->num_rows > 0) {
         $staff = $result->fetch_assoc();

         // Verify the password
         if (password_verify($password, $staff['password'])) {
            return $staff;
         }
      }

      // Authentication failed
      return false;
   }

   /**
    * Log staff login attempt
    * @param int $staffId Staff ID
    * @param string $username Username used
    * @param bool $success Whether login was successful
    * @param string $ipAddress IP address of login attempt
    */
   public function logLoginAttempt($staffId, $username, $success, $ipAddress)
   {
      // Create staff_login_logs table if it doesn't exist
      $this->ensureLoginLogsTableExists();

      $stmt = $this->conn->prepare("INSERT INTO staff_login_logs 
                                     (staff_id, username, success, ip_address, attempt_time) 
                                     VALUES (?, ?, ?, ?, NOW())");
      $successInt = $success ? 1 : 0;
      $stmt->bind_param("issi", $staffId, $username, $successInt, $ipAddress);
      $stmt->execute();
   }

   /**
    * Check if account is locked due to too many failed attempts
    * @param string $username Username to check
    * @return bool True if account is locked, false otherwise
    */
   public function isAccountLocked($username)
   {
      // Create staff_login_logs table if it doesn't exist
      $this->ensureLoginLogsTableExists();

      // Get the last 5 login attempts
      $stmt = $this->conn->prepare("SELECT * FROM staff_login_logs 
                                     WHERE username = ? 
                                     ORDER BY attempt_time DESC LIMIT 5");
      $stmt->bind_param("s", $username);
      $stmt->execute();
      $result = $stmt->get_result();

      if ($result->num_rows < 5) {
         return false; // Not enough attempts to lock
      }

      // Check if all 5 were failures and within the last 30 seconds
      $failedCount = 0;
      $oldestTime = null;

      while ($row = $result->fetch_assoc()) {
         if ($row['success'] == 0) {
            $failedCount++;
            if ($oldestTime === null) {
               $oldestTime = strtotime($row['attempt_time']);
            }
         }
      }

      if ($failedCount >= 5) {
         // Check if the oldest attempt was within the last 30 seconds
         $thirtySecondsAgo = time() - 30; // 30 seconds ago

         if ($oldestTime >= $thirtySecondsAgo) {
            return true; // Account is locked
         }
      }

      return false; // Account is not locked
   }

   /**
    * Ensure the login logs table exists
    */
   private function ensureLoginLogsTableExists()
   {
      $this->conn->query("CREATE TABLE IF NOT EXISTS `staff_login_logs` (
            `log_id` int(11) NOT NULL AUTO_INCREMENT,
            `staff_id` int(11) NOT NULL,
            `username` varchar(50) NOT NULL,
            `success` tinyint(1) NOT NULL DEFAULT 0,
            `ip_address` varchar(45) NOT NULL,
            `attempt_time` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`log_id`),
            KEY `idx_username` (`username`),
            KEY `idx_attempt_time` (`attempt_time`)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
   }
}
