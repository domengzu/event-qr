<?php

/**
 * Helper functions for report generation and formatting
 */

/**
 * Get icon class for report type
 */
function getReportTypeIcon($type)
{
   $icons = [
      'event_attendance' => 'bi-calendar-check',
      'student_participation' => 'bi-person-check',
      'event_summary' => 'bi-bar-chart-line',
      'attendance_trends' => 'bi-graph-up-arrow'
   ];

   return $icons[$type] ?? 'bi-file-earmark-bar-graph';
}

/**
 * Get description for report type
 */
function getReportTypeDescription($type)
{
   $descriptions = [
      'event_attendance' => 'Check who attended a specific event',
      'student_participation' => 'View student engagement across events',
      'event_summary' => 'Get overview statistics for all events',
      'attendance_trends' => 'Analyze attendance patterns over time'
   ];

   return $descriptions[$type] ?? '';
}

/**
 * Get color for status badges
 */
function getStatusColor($status)
{
   $statusColors = [
      'present' => 'success',
      'late' => 'warning',
      'absent' => 'danger',
      'left early' => 'info'
   ];

   return $statusColors[strtolower($status)] ?? 'secondary';
}

/**
 * Get color for rate values
 */
function getRateColor($rate)
{
   if ($rate >= 90) return 'success';
   if ($rate >= 75) return 'info';
   if ($rate >= 50) return 'warning';
   return 'danger';
}

/**
 * Export students data to Excel file
 */
function exportToExcel($reportData, $filename)
{
   // Implement Excel export functionality
   // This will be called via AJAX
   header('Content-Type: application/vnd.ms-excel');
   header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
   header('Pragma: no-cache');
   header('Expires: 0');

   $output = '<table border="1">';

   // Add headers
   $output .= '<tr>';
   foreach (array_keys($reportData[0]) as $header) {
      $displayHeader = ucwords(str_replace('_', ' ', $header));
      $output .= '<th>' . $displayHeader . '</th>';
   }
   $output .= '</tr>';

   // Add data rows
   foreach ($reportData as $row) {
      $output .= '<tr>';
      foreach ($row as $key => $value) {
         if ($key == 'check_in_time' && !empty($value)) {
            $output .= '<td>' . date('g:i A', strtotime($value)) . '</td>';
         } elseif (($key == 'event_date' || $key == 'registration_date') && !empty($value)) {
            $output .= '<td>' . date('M d, Y', strtotime($value)) . '</td>';
         } else {
            $output .= '<td>' . ($value ?: 'N/A') . '</td>';
         }
      }
      $output .= '</tr>';
   }

   $output .= '</table>';

   echo $output;
   exit;
}

/**
 * Export students data to PDF file
 */
function exportToPDF($reportData, $reportTitle, $filename)
{
   // This would need a PDF library like TCPDF or FPDF
   // For this implementation, we'll use client-side PDF generation via html2pdf.js
   // The actual implementation will be handled in JavaScript
}
