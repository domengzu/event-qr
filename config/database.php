<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'eventqr');

function connectDB()
{
   $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

   if ($conn->connect_error) {
      die("Connection failed: " . $conn->connect_error);
   }

   return $conn;
}

function getDBConnection()
{
   static $conn;

   if ($conn === null) {
      $conn = connectDB();
   }

   return $conn;
}

/**
 * Execute a parameterized query and return results as an array
 * 
 * @param string $sql The SQL query with placeholders
 * @param string $types Parameter types (i: integer, d: double, s: string, b: blob)
 * @param array $params The parameters to bind to the query
 * @return array The result set as an associative array
 */
function executeQuery($sql, $types, $params)
{
   $conn = getDBConnection();
   $result = [];

   try {
      // Ensure proper timezone for accurate date/time comparisons
      $conn->query("SET time_zone = '+08:00'");  // Philippine Time (PHT)

      $stmt = $conn->prepare($sql);
      if ($stmt === false) {
         throw new Exception("Failed to prepare statement: " . $conn->error);
      }

      if (!empty($params)) {
         $stmt->bind_param($types, ...$params);
      }

      $stmt->execute();
      $queryResult = $stmt->get_result();

      if ($queryResult) {
         while ($row = $queryResult->fetch_assoc()) {
            $result[] = $row;
         }
      }

      $stmt->close();
   } catch (Exception $e) {
      error_log("Database query error: " . $e->getMessage());
      // Could return false to indicate error
   }

   return $result;
}
