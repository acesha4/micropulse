<?php
// Export detailed repayments as CSV based on current filters

require_once '../config/config.php';
require_once '../config/database.php';

header('Content-Type: text/csv; charset=utf-8');

// Get filter parameters
$search = sanitizeInput($_GET['search'] ?? '');
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$payment_method_filter = sanitizeInput($_GET['payment_method'] ?? '');

// Build filename
$filename_parts = ['micropulse', 'repayments'];
if ($date_from) $filename_parts[] = $date_from;
if ($date_to) $filename_parts[] = $date_to;
$filename = implode('_', array_filter($filename_parts)) . '.csv';
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

try {
    $database = new Database();
    $db = $database->getConnection();

    // Build the same query as in repayments.php but without LIMIT for export
    $query = "SELECT r.*, l.loan_amount, l.monthly_payment, l.balance_remaining as current_balance, 
              c.first_name, c.last_name, c.email, u.full_name as recorded_by 
              FROM repayments r 
              JOIN loans l ON r.loan_id = l.id 
              JOIN clients c ON l.client_id = c.id 
              LEFT JOIN users u ON r.created_by = u.id 
              WHERE 1=1";
    $params = [];
    
    if (!empty($search)) {
        $query .= " AND (c.first_name LIKE :search_first OR c.last_name LIKE :search_last OR c.email LIKE :search_email)";
        $params[':search_first'] = "%$search%";
        $params[':search_last'] = "%$search%";
        $params[':search_email'] = "%$search%";
        
        // Also check if search is a numeric value to search payment_amount
        if (is_numeric($search)) {
            $query .= " OR r.payment_amount = :search_amount";
            $params[':search_amount'] = $search;
        }
    }
    
    if (!empty($date_from)) {
        $query .= " AND r.payment_date >= :date_from";
        $params[':date_from'] = $date_from;
    }
    
    if (!empty($date_to)) {
        $query .= " AND r.payment_date <= :date_to";
        $params[':date_to'] = $date_to;
    }
    
    if (!empty($payment_method_filter)) {
        $query .= " AND r.payment_method = :payment_method";
        $params[':payment_method'] = $payment_method_filter;
    }
    
    $query .= " ORDER BY r.payment_date DESC, r.created_at DESC";
    
    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $repayments = $stmt->fetchAll();

    // CSV headers
    $headers = [
        'Payment ID',
        'Client Name',
        'Client Email',
        'Loan Amount',
        'Monthly Payment',
        'Payment Date',
        'Payment Amount',
        'Payment Method',
        'Reference Number',
        'Payment Type',
        'Balance Before Payment',
        'Balance After Payment',
        'Current Loan Balance',
        'Recorded By',
        'Created At',
        'Notes'
    ];
    
    fputcsv($output, $headers);
    
    // CSV data rows
    foreach ($repayments as $repayment) {
        $row = [
            $repayment['id'],
            $repayment['first_name'] . ' ' . $repayment['last_name'],
            $repayment['email'],
            number_format($repayment['loan_amount'], 2),
            number_format($repayment['monthly_payment'], 2),
            date('Y-m-d', strtotime($repayment['payment_date'])),
            number_format($repayment['payment_amount'], 2),
            ucfirst(str_replace('_', ' ', $repayment['payment_method'])),
            $repayment['payment_reference'] ?? 'N/A',
            $repayment['is_partial_payment'] ? 'Partial' : 'Full',
            number_format($repayment['balance_before_payment'], 2),
            number_format($repayment['balance_after_payment'], 2),
            number_format($repayment['current_balance'], 2),
            $repayment['recorded_by'] ?? 'System',
            date('Y-m-d H:i:s', strtotime($repayment['created_at'])),
            $repayment['notes'] ?? ''
        ];
        
        fputcsv($output, $row);
    }
    
    // Add summary at the end
    $summary_query = "SELECT 
                        COUNT(*) as total_payments,
                        SUM(payment_amount) as total_amount,
                        AVG(payment_amount) as avg_amount,
                        COUNT(CASE WHEN is_partial_payment = 1 THEN 1 END) as partial_payments,
                        COUNT(CASE WHEN is_partial_payment = 0 THEN 1 END) as full_payments
                       FROM repayments r 
                       JOIN loans l ON r.loan_id = l.id 
                       JOIN clients c ON l.client_id = c.id 
                       WHERE 1=1";
    
    $summary_params = [];
    if (!empty($search)) {
        $summary_query .= " AND (c.first_name LIKE :search_first OR c.last_name LIKE :search_last OR c.email LIKE :search_email)";
        $summary_params[':search_first'] = "%$search%";
        $summary_params[':search_last'] = "%$search%";
        $summary_params[':search_email'] = "%$search%";
        if (is_numeric($search)) {
            $summary_query .= " OR r.payment_amount = :search_amount";
            $summary_params[':search_amount'] = $search;
        }
    }
    if (!empty($date_from)) {
        $summary_query .= " AND r.payment_date >= :date_from";
        $summary_params[':date_from'] = $date_from;
    }
    if (!empty($date_to)) {
        $summary_query .= " AND r.payment_date <= :date_to";
        $summary_params[':date_to'] = $date_to;
    }
    if (!empty($payment_method_filter)) {
        $summary_query .= " AND r.payment_method = :payment_method";
        $summary_params[':payment_method'] = $payment_method_filter;
    }
    
    $summary_stmt = $db->prepare($summary_query);
    foreach ($summary_params as $key => $value) {
        $summary_stmt->bindValue($key, $value);
    }
    $summary_stmt->execute();
    $summary = $summary_stmt->fetch();
    
    // Add empty row and summary
    fputcsv($output, []);
    fputcsv($output, ['SUMMARY']);
    fputcsv($output, ['Total Payments', $summary['total_payments']]);
    fputcsv($output, ['Total Amount Collected', number_format($summary['total_amount'], 2)]);
    fputcsv($output, ['Average Payment Amount', number_format($summary['avg_amount'], 2)]);
    fputcsv($output, ['Full Payments', $summary['full_payments']]);
    fputcsv($output, ['Partial Payments', $summary['partial_payments']]);
    
} catch (Exception $e) {
    // On error, output a simple CSV with error message
    fputcsv($output, ['Error']);
    fputcsv($output, [$e->getMessage()]);
}

fclose($output);
exit;
