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
    
    // Get all active loans with current status
    $query = "SELECT id, status FROM loans WHERE status IN ('active', 'overdue', 'pending')";
    $stmt = $db->prepare($query);
    $stmt->execute();
    
    $loans = $stmt->fetchAll();
    
    // Update overdue loans
    $update_query = "UPDATE loans SET status = 'overdue', updated_at = NOW() 
                     WHERE status = 'active' AND due_date < CURDATE()";
    $update_stmt = $db->prepare($update_query);
    $update_stmt->execute();
    
    echo json_encode(['success' => true, 'loans' => $loans]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
