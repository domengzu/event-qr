<?php

/**
 * Cron job to automatically mark absent students
 * Recommended to run this script daily after midnight
 * Example crontab entry:
 * 0 1 * * * php /path/to/eventQR/cron-jobs/auto-mark-absent.php >> /path/to/logs/cron.log 2>&1
 */

// Set execution time limit to 5 minutes
set_time_limit(300);

// Define the root path manually for CLI execution
$rootPath = dirname(dirname(__FILE__));
require_once $rootPath . '/config/database.php';

// Print start time for logs
echo "Starting Auto-Mark Absent process at " . date('Y-m-d H:i:s') . "\n";

// Include and run the mark-absent script
require_once $rootPath . '/staff/mark-absent.php';

// Script execution completed in mark-absent.php
echo "Process completed at " . date('Y-m-d H:i:s') . "\n";
echo "---------------------------------------------\n";
