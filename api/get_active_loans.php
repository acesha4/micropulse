<?php
// API endpoint to get active loans for payment modal
require_once '../config/database.php';
require_once '../config/config.php';
require_once '../config/auth_helper.php';

header('Content-Type: application/json');

// Start session for CSRF and authentication
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is authenticated
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Debug: Check total loans first
    $totalQuery = "SELECT COUNT(*) as total FROM loans";
    $totalStmt = $db->prepare($totalQuery);
    $totalStmt->execute();
    $totalLoans = $totalStmt->fetch()['total'];
    
    // Debug: Check active loans with balance
    $activeQuery = "SELECT COUNT(*) as active FROM loans WHERE status = 'active' AND balance_remaining > 0";
    $activeStmt = $db->prepare($activeQuery);
    $activeStmt->execute();
    $activeLoans = $activeStmt->fetch()['active'];
    
    // Get active loans with remaining balance
    $query = "SELECT l.id, l.loan_amount, l.monthly_payment, l.balance_remaining, 
                     l.status, l.due_date, c.first_name, c.last_name, c.email
              FROM loans l 
              JOIN clients c ON l.client_id = c.id 
              WHERE l.status = 'active' AND l.balance_remaining > 0
              ORDER BY l.due_date ASC";
    
    $stmt = $db->prepare($query);
    $stmt->execute();
    $loans = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'loans' => $loans,
        'debug' => [
            'total_loans' => $totalLoans,
            'active_loans_with_balance' => $activeLoans,
            'returned_loans' => count($loans)
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage(),
        'debug' => 'Exception occurred'
    ]);
}
?>
