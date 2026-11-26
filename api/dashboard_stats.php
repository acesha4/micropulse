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
    
    $stats = [];
    
    // Total clients
    $query = "SELECT COUNT(*) as count FROM clients WHERE is_active = 1";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $result = $stmt->fetch();
    $stats['total_clients'] = $result['count'];
    
    // Active loans
    $query = "SELECT COUNT(*) as count FROM loans WHERE status = 'active'";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $result = $stmt->fetch();
    $stats['active_loans'] = $result['count'];
    
    // Total collected
    $query = "SELECT SUM(payment_amount) as total FROM repayments";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $result = $stmt->fetch();
    $stats['total_collected'] = $result['total'] ? $result['total'] : 0;
    
    // Outstanding balance
    $query = "SELECT SUM(balance_remaining) as total FROM loans WHERE status IN ('active', 'overdue')";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $result = $stmt->fetch();
    $stats['outstanding'] = $result['total'] ? $result['total'] : 0;
    
    echo json_encode(['success' => true, 'data' => $stats]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
