<?php
$page_title = 'Make Payment';
require_once 'includes/header.php';

require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$loan_id = intval($_GET['loan_id'] ?? 0);
$success_message = '';
$error_message = '';

// Get loan details
try {
    $query = "SELECT l.*, c.first_name, c.last_name, c.email, c.phone 
              FROM loans l 
              JOIN clients c ON l.client_id = c.id 
              WHERE l.id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $loan_id);
    $stmt->execute();
    $loan = $stmt->fetch();
    
    if (!$loan) {
        $error_message = 'Loan not found.';
        $loan = null;
    } elseif ($loan['status'] !== 'active') {
        $error_message = 'Payments can only be made for active loans.';
    }
} catch (Exception $e) {
    $error_message = 'Error loading loan details: ' . $e->getMessage();
    $loan = null;
}

// Handle payment processing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $loan) {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!validateCSRFToken($csrf_token)) {
        $error_message = 'Invalid request. Please try again.';
    } else {
        $payment_amount = floatval($_POST['payment_amount'] ?? 0);
        $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
        $payment_method = sanitizeInput($_POST['payment_method'] ?? 'cash');
        $payment_reference = sanitizeInput($_POST['payment_reference'] ?? '');
        $notes = sanitizeInput($_POST['notes'] ?? '');
        
        try {
            // Validate payment amount
            if ($payment_amount <= 0) {
                $error_message = 'Payment amount must be greater than 0.';
            } elseif ($payment_amount > $loan['balance_remaining']) {
                $error_message = 'Payment amount cannot exceed the remaining balance.';
            } else {
                // Begin transaction
                $db->beginTransaction();
                
                // Record payment
                $balance_before = $loan['balance_remaining'];
                $balance_after = $balance_before - $payment_amount;
                $is_partial_payment = $payment_amount < $loan['monthly_payment'];
                
                $query = "INSERT INTO repayments (loan_id, payment_amount, payment_date, payment_method, payment_reference, is_partial_payment, balance_before_payment, balance_after_payment, notes, created_by) 
                          VALUES (:loan_id, :payment_amount, :payment_date, :payment_method, :payment_reference, :is_partial_payment, :balance_before_payment, :balance_after_payment, :notes, :created_by)";
                
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
                            $complete_query = "UPDATE loans SET status = 'completed', completed_date = CURDATE(), balance_remaining = 0, updated_at = NOW() WHERE id = :loan_id";
                            $complete_stmt = $db->prepare($complete_query);
                            $complete_stmt->bindParam(':loan_id', $loan_id);
                            $complete_stmt->execute();
                        }
                        
                        // Commit transaction
                        $db->commit();
                        
                        // Log activity
                        logActivity($_SESSION['user_id'], 'payment', 'repayments', $repayment_id, null, $_POST);
                        logActivity($_SESSION['user_id'], 'update', 'loans', $loan_id, ['balance_remaining' => $balance_before], ['balance_remaining' => $balance_after]);
                        
                        $success_message = 'Payment recorded successfully!';
                        
                        // Refresh loan data
                        $query = "SELECT l.*, c.first_name, c.last_name, c.email, c.phone 
                                  FROM loans l 
                                  JOIN clients c ON l.client_id = c.id 
                                  WHERE l.id = :id";
                        $stmt = $db->prepare($query);
                        $stmt->bindParam(':id', $loan_id);
                        $stmt->execute();
                        $loan = $stmt->fetch();
                    } else {
                        $db->rollBack();
                        $error_message = 'Failed to update loan balance.';
                    }
                } else {
                    $db->rollBack();
                    $error_message = 'Failed to record payment.';
                }
            }
        } catch (Exception $e) {
            $db->rollBack();
            $error_message = 'Error processing payment: ' . $e->getMessage();
        }
    }
}

// Get payment history for this loan
if ($loan) {
    try {
        $history_query = "SELECT r.*, u.full_name as recorded_by 
                          FROM repayments r 
                          LEFT JOIN users u ON r.created_by = u.id 
                          WHERE r.loan_id = :loan_id 
                          ORDER BY r.payment_date DESC, r.created_at DESC";
        $history_stmt = $db->prepare($history_query);
        $history_stmt->bindParam(':loan_id', $loan_id);
        $history_stmt->execute();
        $payment_history = $history_stmt->fetchAll();
    } catch (Exception $e) {
        $payment_history = [];
    }
}
?>

<div class="row mb-4">
    <div class="col-md-8">
        <h1 class="h3 mb-0">Make Payment</h1>
        <p class="text-muted">Record loan repayment</p>
    </div>
    <div class="col-md-4 text-end">
        <a href="loans.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-2"></i>Back to Loans
        </a>
    </div>
</div>

<?php if ($loan): ?>
    <!-- Loan Information Card -->
    <div class="custom-card mb-4">
        <div class="custom-card-header">
            <h5 class="custom-card-title mb-0">Loan Information</h5>
        </div>
        <div class="custom-card-body">
            <div class="row">
                <div class="col-md-6">
                    <p><strong>Client:</strong> <?php echo htmlspecialchars($loan['first_name'] . ' ' . $loan['last_name']); ?></p>
                    <p><strong>Loan Amount:</strong> <?php echo formatCurrency($loan['loan_amount']); ?></p>
                    <p><strong>Interest Rate:</strong> <?php echo $loan['interest_rate']; ?>%</p>
                    <p><strong>Monthly Payment:</strong> <?php echo formatCurrency($loan['monthly_payment']); ?></p>
                </div>
                <div class="col-md-6">
                    <p><strong>Total Payable:</strong> <?php echo formatCurrency($loan['total_payable']); ?></p>
                    <p><strong>Remaining Balance:</strong> <span class="<?php echo $loan['balance_remaining'] > 0 ? 'text-danger' : 'text-success'; ?>"><?php echo formatCurrency($loan['balance_remaining']); ?></span></p>
                    <p><strong>Status:</strong> <span class="badge bg-<?php echo getLoanStatusColor($loan['status']); ?>"><?php echo ucfirst($loan['status']); ?></span></p>
                    <p><strong>Due Date:</strong> <?php echo date('M d, Y', strtotime($loan['due_date'])); ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Payment Form -->
    <?php if ($loan['status'] === 'active' && $loan['balance_remaining'] > 0): ?>
        <div class="custom-card mb-4">
            <div class="custom-card-header">
                <h5 class="custom-card-title mb-0">Record Payment</h5>
            </div>
            <div class="custom-card-body">
                <form method="POST" action="" data-validate>
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="custom-form-label">Payment Amount *</label>
                                <div class="custom-input-group">
                                    <span class="custom-input-group-text">₱</span>
                                    <input type="number" class="custom-form-control" name="payment_amount" step="0.01" min="0.01" max="<?php echo $loan['balance_remaining']; ?>" data-validate="required|min:0.01" required>
                                </div>
                                <small class="custom-form-text text-muted">Maximum: <?php echo formatCurrency($loan['balance_remaining']); ?></small>
                                <div class="invalid-feedback"></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="custom-form-label">Payment Date *</label>
                                <input type="date" class="custom-form-control" name="payment_date" value="<?php echo date('Y-m-d'); ?>" data-validate="required" required>
                                <div class="invalid-feedback"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="custom-form-label">Payment Method *</label>
                                <select class="custom-form-select" name="payment_method" data-validate="required" required>
                                    <option value="cash">Cash</option>
                                    <option value="bank_transfer">Bank Transfer</option>
                                    <option value="mobile_money">Mobile Money</option>
                                    <option value="check">Check</option>
                                </select>
                                <div class="invalid-feedback"></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="custom-form-label">Reference Number</label>
                                <input type="text" class="custom-form-control" name="payment_reference" placeholder="Transaction reference (optional)">
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="custom-form-label">Notes</label>
                        <textarea class="custom-form-control" name="notes" rows="3" placeholder="Additional notes about this payment (optional)"></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <button type="button" class="btn btn-outline-primary w-100" onclick="setFullPayment()">
                                <i class="fas fa-calculator me-2"></i>Set Full Payment
                            </button>
                        </div>
                        <div class="col-md-6">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-money-bill-wave me-2"></i>Record Payment
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    <?php else: ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>
            <?php if ($loan['balance_remaining'] <= 0): ?>
                This loan has been fully paid.
            <?php else: ?>
                Payments can only be made for active loans.
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Payment History -->
    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0">Payment History</h5>
        </div>
        <div class="card-body">
            <?php if (isset($payment_history) && count($payment_history) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Reference</th>
                                <th>Type</th>
                                <th>Balance After</th>
                                <th>Recorded By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payment_history as $payment): ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></td>
                                    <td><?php echo formatCurrency($payment['payment_amount']); ?></td>
                                    <td>
                                        <span class="badge bg-secondary">
                                            <?php echo ucfirst(str_replace('_', ' ', $payment['payment_method'])); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $payment['payment_reference'] ? htmlspecialchars($payment['payment_reference']) : 'N/A'; ?></td>
                                    <td>
                                        <?php if ($payment['is_partial_payment']): ?>
                                            <span class="badge bg-warning">Partial</span>
                                        <?php else: ?>
                                            <span class="badge bg-success">Full</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo formatCurrency($payment['balance_after_payment']); ?></td>
                                    <td><?php echo htmlspecialchars($payment['recorded_by'] ?? 'System'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="text-muted mb-0">No payment history found for this loan.</p>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <div class="alert alert-danger">
        <i class="fas fa-exclamation-triangle me-2"></i>
        <?php echo $error_message; ?>
    </div>
<?php endif; ?>

<script>
function setFullPayment() {
    const remainingBalance = <?php echo $loan ? $loan['balance_remaining'] : 0; ?>;
    document.querySelector('[name="payment_amount"]').value = remainingBalance.toFixed(2);
}

function getLoanStatusColor(status) {
    const colors = {
        'active': 'success',
        'pending': 'warning',
        'overdue': 'danger',
        'completed': 'info',
        'defaulted': 'dark'
    };
    return colors[status] || 'secondary';
}
</script>

<?php 
function getLoanStatusColor($status) {
    $colors = [
        'active' => 'success',
        'pending' => 'warning',
        'overdue' => 'danger',
        'completed' => 'info',
        'defaulted' => 'dark'
    ];
    return $colors[$status] ?? 'secondary';
}

require_once 'includes/footer.php'; 
?>
