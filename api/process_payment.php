<?php
// API endpoint to process payment from modal
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

// Validate CSRF token
$csrf_token = $_POST['csrf_token'] ?? '';
if (!validateCSRFToken($csrf_token)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request. Please try again.']);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Get and validate input
    $loan_id = intval($_POST['loan_id'] ?? 0);
    $payment_amount = floatval($_POST['payment_amount'] ?? 0);
    $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
    $payment_method = trim($_POST['payment_method'] ?? 'cash');
    $payment_reference = trim($_POST['payment_reference'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    
    // Validate required fields
    if ($loan_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Please select a loan.']);
        exit;
    }
    
    if ($payment_amount <= 0) {
        echo json_encode(['success' => false, 'message' => 'Payment amount must be greater than 0.']);
        exit;
    }
    
    // Get loan details
    $query = "SELECT l.*, c.first_name, c.last_name 
              FROM loans l 
              JOIN clients c ON l.client_id = c.id 
              WHERE l.id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $loan_id);
    $stmt->execute();
    $loan = $stmt->fetch();
    
    if (!$loan) {
        echo json_encode(['success' => false, 'message' => 'Loan not found.']);
        exit;
    }
    
    if ($loan['status'] !== 'active') {
        echo json_encode(['success' => false, 'message' => 'Payments can only be made for active loans.']);
        exit;
    }
    
    if ($payment_amount > $loan['balance_remaining']) {
        echo json_encode(['success' => false, 'message' => 'Payment amount cannot exceed the remaining balance.']);
        exit;
    }
    
    // Begin transaction
    $db->beginTransaction();
    
    // Record payment
    $balance_before = $loan['balance_remaining'];
    $balance_after = $balance_before - $payment_amount;
    $is_partial_payment = $payment_amount < $loan['monthly_payment'];
    
    $query = "INSERT INTO repayments (loan_id, payment_amount, payment_date, payment_method, 
                      payment_reference, is_partial_payment, balance_before_payment, 
                      balance_after_payment, notes, created_by) 
              VALUES (:loan_id, :payment_amount, :payment_date, :payment_method, 
                      :payment_reference, :is_partial_payment, :balance_before_payment, 
                      :balance_after_payment, :notes, :created_by)";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':loan_id', $loan_id);
    $stmt->bindParam(':payment_amount', $payment_amount);
    $stmt->bindParam(':payment_date', $payment_date);
    $stmt->bindParam(':payment_method', $payment_method);
    $stmt->bindParam(':payment_reference', $payment_reference);
    $stmt->bindParam(':is_partial_payment', $is_partial_payment);
    $stmt->bindParam(':balance_before_payment', $balance_before);
    $stmt->bindParam(':balance_after_payment', $balance_after);
    $stmt->bindParam(':notes', $notes);
    $stmt->bindParam(':created_by', $_SESSION['user_id']);
    
    if ($stmt->execute()) {
        $repayment_id = $db->lastInsertId();
        
        // Update loan balance
        $update_query = "UPDATE loans SET balance_remaining = :balance_after, updated_at = NOW() WHERE id = :loan_id";
        $update_stmt = $db->prepare($update_query);
        $update_stmt->bindParam(':balance_after', $balance_after);
        $update_stmt->bindParam(':loan_id', $loan_id);
        
        if ($update_stmt->execute()) {
            // Check if loan is fully paid
            if ($balance_after <= 0) {
                $complete_query = "UPDATE loans SET status = 'completed', completed_date = CURDATE(), 
                                   balance_remaining = 0, updated_at = NOW() WHERE id = :loan_id";
                $complete_stmt = $db->prepare($complete_query);
                $complete_stmt->bindParam(':loan_id', $loan_id);
                $complete_stmt->execute();
            }
            
            // Commit transaction
            $db->commit();
            
            // Log activity
            logActivity($_SESSION['user_id'], 'payment', 'repayments', $repayment_id, null, $_POST);
            logActivity($_SESSION['user_id'], 'update', 'loans', $loan_id, 
                       ['balance_remaining' => $balance_before], ['balance_remaining' => $balance_after]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Payment recorded successfully!',
                'payment_id' => $repayment_id,
                'new_balance' => $balance_after
            ]);
            
        } else {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => 'Failed to update loan balance.']);
        }
    } else {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'Failed to record payment.']);
    }
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error processing payment: ' . $e->getMessage()
    ]);
}
?>
