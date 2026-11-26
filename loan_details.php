<?php
$page_title = 'Loan Details';
require_once 'includes/header.php';
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$loan_id = intval($_GET['id'] ?? 0);
$error_message = '';

try {
    // Fetch loan with client info
    $query = "SELECT l.*, c.first_name, c.last_name, c.email, c.phone, c.address
              FROM loans l
              JOIN clients c ON l.client_id = c.id
              WHERE l.id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $loan_id);
    $stmt->execute();
    $loan = $stmt->fetch();

    if (!$loan) {
        $error_message = 'Loan not found.';
    } else {
        // Fetch repayments for this loan
        $rep_query = "SELECT r.*, u.full_name as recorded_by
                      FROM repayments r
                      LEFT JOIN users u ON r.created_by = u.id
                      WHERE r.loan_id = :loan_id
                      ORDER BY r.payment_date DESC, r.created_at DESC";
        $rep_stmt = $db->prepare($rep_query);
        $rep_stmt->bindParam(':loan_id', $loan_id);
        $rep_stmt->execute();
        $repayments = $rep_stmt->fetchAll();
    }
} catch (Exception $e) {
    $error_message = 'Error loading loan details: ' . $e->getMessage();
    $loan = null;
    $repayments = [];
}
?>

<div class="row mb-4">
    <div class="col-md-8">
        <h1 class="h3 mb-0">Loan Details</h1>
        <p class="text-muted">View loan information and payment history</p>
    </div>
    <div class="col-md-4 text-end">
        <a href="loans.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-2"></i>Back to Loans
        </a>
        <?php if (!empty($loan)): ?>
            <a href="make_payment.php?loan_id=<?php echo $loan['id']; ?>" class="btn btn-primary">
                <i class="fas fa-money-bill-wave me-2"></i>Make Payment
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($error_message || !$loan): ?>
    <div class="alert alert-danger">
        <?php echo htmlspecialchars($error_message ?: 'Loan not found.'); ?>
    </div>
<?php else: ?>
    <!-- Loan Summary -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">
                Loan #<?php echo $loan['id']; ?> - 
                <?php echo htmlspecialchars($loan['first_name'] . ' ' . $loan['last_name']); ?>
            </h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <p><strong>Client Email:</strong> <?php echo htmlspecialchars($loan['email']); ?></p>
                    <p><strong>Phone:</strong> <?php echo htmlspecialchars($loan['phone']); ?></p>
                    <p><strong>Address:</strong><br><?php echo nl2br(htmlspecialchars($loan['address'])); ?></p>
                </div>
                <div class="col-md-6">
                    <p><strong>Loan Amount:</strong> <?php echo formatCurrency($loan['loan_amount']); ?></p>
                    <p><strong>Interest Rate:</strong> <?php echo $loan['interest_rate']; ?>%</p>
                    <p><strong>Term:</strong> <?php echo $loan['loan_term_months']; ?> months</p>
                    <p><strong>Monthly Payment:</strong> <?php echo formatCurrency($loan['monthly_payment']); ?></p>
                    <p>
                        <strong>Remaining Balance:</strong>
                        <span class="<?php echo $loan['balance_remaining'] > 0 ? 'text-danger' : 'text-success'; ?>">
                            <?php echo formatCurrency($loan['balance_remaining']); ?>
                        </span>
                    </p>
                    <p>
                        <strong>Status:</strong>
                        <span class="badge bg-<?php echo getLoanStatusColor($loan['status']); ?>">
                            <?php echo ucfirst($loan['status']); ?>
                        </span>
                    </p>
                    <p><strong>Disbursement Date:</strong> <?php echo $loan['disbursement_date'] ? date('M d, Y', strtotime($loan['disbursement_date'])) : 'N/A'; ?></p>
                    <p><strong>Due Date:</strong> <?php echo $loan['due_date'] ? date('M d, Y', strtotime($loan['due_date'])) : 'N/A'; ?></p>
                    <?php if ($loan['completed_date']): ?>
                        <p><strong>Completed Date:</strong> <?php echo date('M d, Y', strtotime($loan['completed_date'])); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Repayment History -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Repayment History</h5>
            <a href="repayments.php?search=<?php echo urlencode($loan['first_name'] . ' ' . $loan['last_name']); ?>" class="btn btn-sm btn-outline-light">
                View in Repayments
            </a>
        </div>
        <div class="card-body">
            <?php if (empty($repayments)): ?>
                <p class="text-muted mb-0">No repayments have been recorded for this loan yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Reference</th>
                                <th>Type</th>
                                <th>Balance Before</th>
                                <th>Balance After</th>
                                <th>Recorded By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($repayments as $repayment): ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($repayment['payment_date'])); ?></td>
                                    <td><?php echo formatCurrency($repayment['payment_amount']); ?></td>
                                    <td><?php echo ucfirst(str_replace('_', ' ', $repayment['payment_method'])); ?></td>
                                    <td><?php echo $repayment['payment_reference'] ? htmlspecialchars($repayment['payment_reference']) : 'N/A'; ?></td>
                                    <td>
                                        <?php if ($repayment['is_partial_payment']): ?>
                                            <span class="badge bg-warning">Partial</span>
                                        <?php else: ?>
                                            <span class="badge bg-success">Full</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo formatCurrency($repayment['balance_before_payment']); ?></td>
                                    <td><?php echo formatCurrency($repayment['balance_after_payment']); ?></td>
                                    <td><?php echo htmlspecialchars($repayment['recorded_by'] ?? 'System'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>

