<?php
// Export reports as CSV based on current filters

require_once '../config/config.php';
require_once '../config/database.php';

header('Content-Type: text/csv; charset=utf-8');

$report_type = $_GET['report_type'] ?? 'daily';
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');

// Build filename
$filename_parts = ['micropulse', $report_type, $date_from, $date_to];
$filename = implode('_', array_filter($filename_parts)) . '.csv';
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

// Helper to write header and rows
function writeCsv($handle, array $headers, array $rows)
{
    fputcsv($handle, $headers);
    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }
}

try {
    $database = new Database();
    $db = $database->getConnection();

    switch ($report_type) {
        case 'weekly':
            // Weekly collections
            $query = "SELECT YEARWEEK(payment_date) as week, 
                             MIN(DATE(payment_date)) as week_start,
                             MAX(DATE(payment_date)) as week_end,
                             COUNT(*) as payment_count,
                             SUM(payment_amount) as total_amount,
                             AVG(payment_amount) as avg_amount
                      FROM repayments 
                      WHERE payment_date BETWEEN :date_from AND :date_to
                      GROUP BY YEARWEEK(payment_date)
                      ORDER BY week DESC";

            $stmt = $db->prepare($query);
            $stmt->bindParam(':date_from', $date_from);
            $stmt->bindParam(':date_to', $date_to);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            writeCsv($output, ['Week', 'Week Start', 'Week End', 'Payments', 'Total Amount', 'Average Amount'], array_map(function ($row) {
                return [
                    $row['week'],
                    $row['week_start'],
                    $row['week_end'],
                    $row['payment_count'],
                    $row['total_amount'],
                    $row['avg_amount'],
                ];
            }, $rows));
            break;

        case 'monthly':
            // Monthly collections
            $query = "SELECT DATE_FORMAT(payment_date, '%Y-%m') as month,
                             DATE_FORMAT(payment_date, '%M %Y') as month_name,
                             COUNT(*) as payment_count,
                             SUM(payment_amount) as total_amount,
                             AVG(payment_amount) as avg_amount
                      FROM repayments 
                      WHERE payment_date BETWEEN :date_from AND :date_to
                      GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
                      ORDER BY month DESC";

            $stmt = $db->prepare($query);
            $stmt->bindParam(':date_from', $date_from);
            $stmt->bindParam(':date_to', $date_to);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            writeCsv($output, ['Month', 'Payments', 'Total Amount', 'Average Amount'], array_map(function ($row) {
                return [
                    $row['month_name'],
                    $row['payment_count'],
                    $row['total_amount'],
                    $row['avg_amount'],
                ];
            }, $rows));
            break;

        case 'loan_performance':
            // Loan performance summary
            $query = "SELECT 
                        COUNT(*) as total_loans,
                        SUM(loan_amount) as total_loan_amount,
                        AVG(loan_amount) as avg_loan_amount,
                        SUM(total_payable) as total_payable,
                        SUM(balance_remaining) as total_outstanding,
                        COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_loans,
                        COUNT(CASE WHEN status = 'active' THEN 1 END) as active_loans,
                        COUNT(CASE WHEN status = 'overdue' THEN 1 END) as overdue_loans,
                        COUNT(CASE WHEN status = 'defaulted' THEN 1 END) as defaulted_loans
                      FROM loans";

            $stmt = $db->prepare($query);
            $stmt->execute();
            $metrics = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            writeCsv($output, ['Metric', 'Value'], [
                ['Total Loans', $metrics['total_loans'] ?? 0],
                ['Total Loan Amount', $metrics['total_loan_amount'] ?? 0],
                ['Average Loan Amount', $metrics['avg_loan_amount'] ?? 0],
                ['Total Payable', $metrics['total_payable'] ?? 0],
                ['Total Outstanding', $metrics['total_outstanding'] ?? 0],
                ['Completed Loans', $metrics['completed_loans'] ?? 0],
                ['Active Loans', $metrics['active_loans'] ?? 0],
                ['Overdue Loans', $metrics['overdue_loans'] ?? 0],
                ['Defaulted Loans', $metrics['defaulted_loans'] ?? 0],
            ]);
            break;

        case 'client_summary':
            // Client activity summary
            $query = "SELECT c.id, c.first_name, c.last_name, c.email,
                             COUNT(l.id) as loan_count,
                             SUM(l.loan_amount) as total_borrowed,
                             SUM(l.total_payable) as total_payable,
                             SUM(l.balance_remaining) as outstanding
                      FROM clients c
                      LEFT JOIN loans l ON c.id = l.client_id
                      WHERE c.is_active = 1
                      GROUP BY c.id
                      ORDER BY loan_count DESC, total_borrowed DESC";

            $stmt = $db->prepare($query);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            writeCsv($output, ['Client', 'Email', 'Loans', 'Total Borrowed', 'Total Payable', 'Outstanding'], array_map(function ($row) {
                return [
                    $row['first_name'] . ' ' . $row['last_name'],
                    $row['email'],
                    $row['loan_count'],
                    $row['total_borrowed'],
                    $row['total_payable'],
                    $row['outstanding'],
                ];
            }, $rows));
            break;

        case 'daily':
        default:
            // Daily collections
            $query = "SELECT DATE(payment_date) as date, 
                             COUNT(*) as payment_count,
                             SUM(payment_amount) as total_amount,
                             AVG(payment_amount) as avg_amount
                      FROM repayments 
                      WHERE payment_date BETWEEN :date_from AND :date_to
                      GROUP BY DATE(payment_date)
                      ORDER BY date DESC";

            $stmt = $db->prepare($query);
            $stmt->bindParam(':date_from', $date_from);
            $stmt->bindParam(':date_to', $date_to);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            writeCsv($output, ['Date', 'Payments', 'Total Amount', 'Average Amount'], array_map(function ($row) {
                return [
                    $row['date'],
                    $row['payment_count'],
                    $row['total_amount'],
                    $row['avg_amount'],
                ];
            }, $rows));
            break;
    }
} catch (Exception $e) {
    // On error, output a simple CSV with the error message
    writeCsv($output, ['Error'], [[ $e->getMessage() ]]);
}

fclose($output);
exit;
