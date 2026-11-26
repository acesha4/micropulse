<?php
require_once '../config/config.php';
require_once '../config/database.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $notifications = [];
    
    // Check for overdue loans
    $query = "SELECT COUNT(*) as count FROM loans WHERE status = 'active' AND due_date < CURDATE() AND DATE(due_date) = CURDATE()";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $overdue_count = $stmt->fetch()['count'];
    
    if ($overdue_count > 0) {
        $notifications[] = [
            'message' => $overdue_count . ' loan(s) became overdue today',
            'type' => 'warning'
        ];
    }
    
    // Check for loans due soon (within 7 days)
    $query = "SELECT COUNT(*) as count FROM loans 
              WHERE status = 'active' 
              AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
              AND DATE(due_date) = CURDATE()";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $due_soon_count = $stmt->fetch()['count'];
    
    if ($due_soon_count > 0) {
        $notifications[] = [
            'message' => $due_soon_count . ' loan(s) due within the next 7 days',
            'type' => 'info'
        ];
    }
    
    // Check for large repayments today
    $query = "SELECT COUNT(*) as count, SUM(payment_amount) as total 
              FROM repayments 
              WHERE payment_date = CURDATE()";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $today_payments = $stmt->fetch();
    
    if ($today_payments['total'] > 10000) {
        $notifications[] = [
            'message' => 'Great day! ' . formatCurrency($today_payments['total']) . ' collected today',
            'type' => 'success'
        ];
    }
    
    echo json_encode(['success' => true, 'notifications' => $notifications]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
