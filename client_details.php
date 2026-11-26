<?php
$page_title = 'Client Details';
require_once 'includes/header.php';
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$client_id = intval($_GET['id'] ?? 0);
$error_message = '';

// Fetch client info
try {
    $query = "SELECT * FROM clients WHERE id = :id AND is_active = 1";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $client_id);
    $stmt->execute();
    $client = $stmt->fetch();

    if (!$client) {
        $error_message = 'Client not found or inactive.';
    } else {
        // Fetch loans for this client
        $loan_query = "SELECT * FROM loans WHERE client_id = :id ORDER BY created_at DESC";
        $loan_stmt = $db->prepare($loan_query);
        $loan_stmt->bindParam(':id', $client_id);
        $loan_stmt->execute();
        $loans = $loan_stmt->fetchAll();

        // Fetch recent repayments for this client (via loans)
        $repayments = [];
        if (!empty($loans)) {
            $loanIds = array_column($loans, 'id');
            $placeholders = implode(',', array_fill(0, count($loanIds), '?'));
            $rep_query = "SELECT r.*, l.id as loan_id 
                          FROM repayments r 
                          JOIN loans l ON r.loan_id = l.id 
                          WHERE r.loan_id IN ($placeholders)
                          ORDER BY r.payment_date DESC, r.created_at DESC
                          LIMIT 20";
            $rep_stmt = $db->prepare($rep_query);
            $rep_stmt->execute($loanIds);
            $repayments = $rep_stmt->fetchAll();
        }
    }
} catch (Exception $e) {
    $error_message = 'Error loading client details: ' . $e->getMessage();
    $client = null;
    $loans = [];
    $repayments = [];
}
?>

<div class="row mb-4">
    <div class="col-md-8">
        <h1 class="h3 mb-0">Client Details</h1>
        <p class="text-muted">View full profile, loans, and repayments</p>
    </div>
    <div class="col-md-4 text-end">
        <a href="clients.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-2"></i>Back to Clients
        </a>
    </div>
</div>

<?php if ($error_message || !$client): ?>
    <div class="alert alert-danger">
        <?php echo htmlspecialchars($error_message ?: 'Client not found.'); ?>
    </div>
<?php else: ?>
    <!-- Client Summary -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="fas fa-user me-2"></i>
                <?php echo htmlspecialchars($client['first_name'] . ' ' . $client['last_name']); ?>
            </h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <p><strong>Email:</strong> <?php echo htmlspecialchars($client['email'] ?? 'N/A'); ?></p>
                    <p><strong>Phone:</strong> <?php echo htmlspecialchars($client['phone']); ?></p>
                    <p><strong>ID Number:</strong> <?php echo htmlspecialchars($client['id_number'] ?? 'N/A'); ?></p>
                    <p><strong>Date of Birth:</strong>
                        <?php echo $client['date_of_birth'] ? date('M d, Y', strtotime($client['date_of_birth'])) : 'N/A'; ?>
                    </p>
                </div>
                <div class="col-md-6">
                    <p><strong>Employment Status:</strong> <?php echo htmlspecialchars($client['employment_status'] ?? 'N/A'); ?></p>
                    <p><strong>Monthly Income:</strong> <?php echo formatCurrency($client['monthly_income'] ?? 0); ?></p>
                    <p><strong>Address:</strong><br><?php echo nl2br(htmlspecialchars($client['address'])); ?></p>
                    <p><strong>Joined:</strong> <?php echo date('M d, Y', strtotime($client['created_at'])); ?></p>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Loans -->
        <div class="col-md-6 mb-4">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-hand-holding-usd me-2"></i>Loans</h5>
                    <a href="loans.php?search=<?php echo urlencode($client['first_name'] . ' ' . $client['last_name']); ?>" class="btn btn-sm btn-outline-light">
                        View in Loans
                    </a>
                </div>
                <div class="card-body">
                    <?php if (empty($loans)): ?>
                        <p class="text-muted mb-0">No loans found for this client.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Balance</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($loans as $loan): ?>
                                        <tr>
                                            <td><?php echo formatCurrency($loan['loan_amount']); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo getLoanStatusColor($loan['status']); ?>">
                                                    <?php echo ucfirst($loan['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="<?php echo $loan['balance_remaining'] > 0 ? 'text-danger' : 'text-success'; ?>">
                                                    <?php echo formatCurrency($loan['balance_remaining']); ?>
                                                </span>
                                            </td>
                                            <td class="text-end">
                                                <a href="make_payment.php?loan_id=<?php echo $loan['id']; ?>" class="btn btn-sm btn-outline-success">
                                                    <i class="fas fa-money-bill-wave"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Recent Repayments -->
        <div class="col-md-6 mb-4">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-receipt me-2"></i>Recent Repayments</h5>
                    <a href="repayments.php?search=<?php echo urlencode($client['first_name'] . ' ' . $client['last_name']); ?>" class="btn btn-sm btn-outline-light">
                        View in Repayments
                    </a>
                </div>
                <div class="card-body">
                    <?php if (empty($repayments)): ?>
                        <p class="text-muted mb-0">No repayments found for this client.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($repayments as $repayment): ?>
                                        <tr>
                                            <td><?php echo date('M d, Y', strtotime($repayment['payment_date'])); ?></td>
                                            <td><?php echo formatCurrency($repayment['payment_amount']); ?></td>
                                            <td><?php echo ucfirst(str_replace('_', ' ', $repayment['payment_method'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>

