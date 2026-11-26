<?php
$page_title = 'Repayment Tracking';
require_once 'includes/header.php';

require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$success_message = '';
$error_message = '';

// Get repayments for display
try {
    $search = sanitizeInput($_GET['search'] ?? '');
    $date_from = $_GET['date_from'] ?? '';
    $date_to = $_GET['date_to'] ?? '';
    $payment_method_filter = sanitizeInput($_GET['payment_method'] ?? '');
    $page = max(1, intval($_GET['page'] ?? 1));
    $offset = ($page - 1) * ITEMS_PER_PAGE;
    
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
    
    $query .= " ORDER BY r.payment_date DESC, r.created_at DESC LIMIT " . ITEMS_PER_PAGE . " OFFSET " . $offset;
    
    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $repayments = $stmt->fetchAll();
    
    // If search returns no results, try without search to see if there's data
    if (!empty($search) && empty($repayments)) {
        try {
            $fallback_query = "SELECT r.*, l.loan_amount, l.monthly_payment, l.balance_remaining as current_balance, 
                              c.first_name, c.last_name, c.email, u.full_name as recorded_by 
                              FROM repayments r 
                              JOIN loans l ON r.loan_id = l.id 
                              JOIN clients c ON l.client_id = c.id 
                              LEFT JOIN users u ON r.created_by = u.id 
                              ORDER BY r.payment_date DESC, r.created_at DESC LIMIT 5";
            
            $fallback_stmt = $db->prepare($fallback_query);
            $fallback_stmt->execute();
            $fallback_results = $fallback_stmt->fetchAll();
            
            if (!empty($fallback_results)) {
                $error_message = "Search found no results. There are " . count($fallback_results) . " recent repayments in the database.";
            } else {
                $error_message = "No repayments found in the database at all.";
            }
        } catch (Exception $e) {
            $error_message = "Error checking repayments: " . $e->getMessage();
        }
    }
    
    // Get total count for pagination
    $count_query = "SELECT COUNT(*) as total FROM repayments r 
                    JOIN loans l ON r.loan_id = l.id 
                    JOIN clients c ON l.client_id = c.id 
                    WHERE 1=1";
    $count_params = [];
    
    if (!empty($search)) {
        $count_query .= " AND (c.first_name LIKE :search_first OR c.last_name LIKE :search_last OR c.email LIKE :search_email)";
        $count_params[':search_first'] = "%$search%";
        $count_params[':search_last'] = "%$search%";
        $count_params[':search_email'] = "%$search%";
        if (is_numeric($search)) {
            $count_query .= " OR r.payment_amount = :search_amount";
            $count_params[':search_amount'] = $search;
        }
    }
    if (!empty($date_from)) {
        $count_query .= " AND r.payment_date >= :date_from";
        $count_params[':date_from'] = $date_from;
    }
    if (!empty($date_to)) {
        $count_query .= " AND r.payment_date <= :date_to";
        $count_params[':date_to'] = $date_to;
    }
    if (!empty($payment_method_filter)) {
        $count_query .= " AND r.payment_method = :payment_method";
        $count_params[':payment_method'] = $payment_method_filter;
    }
    
    $count_stmt = $db->prepare($count_query);
    foreach ($count_params as $key => $value) {
        $count_stmt->bindValue($key, $value);
    }
    $count_stmt->execute();
    $total_repayments = $count_stmt->fetch()['total'];
    $total_pages = ceil($total_repayments / ITEMS_PER_PAGE);
    
    // Get summary statistics
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
    
} catch (Exception $e) {
    $error_message = "Error loading repayments: " . $e->getMessage();
    $repayments = [];
    $total_pages = 0;
    $summary = [
        'total_payments' => 0,
        'total_amount' => 0,
        'avg_amount' => 0,
        'partial_payments' => 0,
        'full_payments' => 0
    ];
}
?>

<div class="row mb-4">
    <div class="col-md-8">
        <h1 class="h3 mb-0">Repayment Tracking</h1>
        <p class="text-muted">Monitor and manage loan repayments</p>
    </div>
    <div class="col-md-4 text-end">
        <button type="button" class="btn btn-primary" data-modal-target="makePaymentModal">
            <i class="fas fa-plus me-2"></i>Make New Payment
        </button>
    </div>
</div>

<!-- Summary Cards -->
<div class="dashboard-stats">
    <div class="dashboard-stat-col">
        <div class="stat-card primary">
            <div class="stat-icon">
                <i class="fas fa-receipt"></i>
            </div>
            <div class="stat-value"><?php echo $summary['total_payments']; ?></div>
            <div class="stat-label">Total Payments</div>
        </div>
    </div>
    
    <div class="dashboard-stat-col">
        <div class="stat-card success">
            <div class="stat-icon">
                <i class="fas fa-dollar-sign"></i>
            </div>
            <div class="stat-value"><?php echo formatCurrency($summary['total_amount'] ?? 0); ?></div>
            <div class="stat-label">Total Collected</div>
        </div>
    </div>
    
    <div class="dashboard-stat-col">
        <div class="stat-card warning">
            <div class="stat-icon">
                <i class="fas fa-chart-line"></i>
            </div>
            <div class="stat-value"><?php echo formatCurrency($summary['avg_amount'] ?? 0); ?></div>
            <div class="stat-label">Average Payment</div>
        </div>
    </div>
    
    <div class="dashboard-stat-col">
        <div class="stat-card info">
            <div class="stat-icon">
                <i class="fas fa-percentage"></i>
            </div>
            <div class="stat-value">
                <?php 
                $total = $summary['total_payments'];
                $full = $summary['full_payments'];
                $percentage = $total > 0 ? round(($full / $total) * 100, 1) : 0;
                echo $percentage . '%';
                ?>
            </div>
            <div class="stat-label">Full Payments</div>
        </div>
    </div>
</div>

<!-- Search and Filter -->
<div class="custom-card mb-4">
    <div class="custom-card-body">
        <form method="GET" action="">
            <div class="d-flex flex-wrap gap-2 align-items-end">
                <div style="min-width: 200px; flex: 1;">
                    <label class="form-label small text-muted">Search</label>
                    <input type="text" class="custom-form-control" name="search" placeholder="Search repayments..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div style="min-width: 130px;">
                    <label class="form-label small text-muted">From Date</label>
                    <input type="date" class="custom-form-control" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                </div>
                <div style="min-width: 130px;">
                    <label class="form-label small text-muted">To Date</label>
                    <input type="date" class="custom-form-control" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                </div>
                <div style="min-width: 140px;">
                    <label class="form-label small text-muted">Payment Method</label>
                    <select class="custom-form-select" name="payment_method">
                        <option value="">All Methods</option>
                        <option value="cash" <?php echo $payment_method_filter === 'cash' ? 'selected' : ''; ?>>Cash</option>
                        <option value="bank_transfer" <?php echo $payment_method_filter === 'bank_transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                        <option value="mobile_money" <?php echo $payment_method_filter === 'mobile_money' ? 'selected' : ''; ?>>Mobile Money</option>
                        <option value="check" <?php echo $payment_method_filter === 'check' ? 'selected' : ''; ?>>Check</option>
                    </select>
                </div>
                <div class="btn-group">
                    <button type="submit" class="btn btn-outline-primary btn-sm">
                        <i class="fas fa-search"></i> Search
                    </button>
                    <button type="button" class="btn btn-outline-black btn-sm" onclick="clearFilters()">
                        <i class="fas fa-times"></i> Clear
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Repayments Table -->
<div class="custom-card">
    <div class="custom-card-header d-flex justify-content-between align-items-center">
        <h5 class="custom-card-title mb-0">Payment Records</h5>
        <div>
            <button type="button" class="btn btn-outline-success btn-sm" onclick="exportRepayments()">
                <i class="fas fa-download me-1"></i>Export
            </button>
        </div>
    </div>
    <div class="custom-card-body">
        <?php if (count($repayments) > 0): ?>
            <div class="custom-table-responsive">
                <table class="custom-table custom-table-hover repayments-table">
                    <thead>
                        <tr>
                            <th>Client</th>
                            <th>Payment Date</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Reference</th>
                            <th>Type</th>
                            <th>Balance After</th>
                            <th>Recorded By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($repayments as $repayment): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($repayment['first_name'] . ' ' . $repayment['last_name']); ?></strong><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($repayment['email']); ?></small>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($repayment['payment_date'])); ?></td>
                                <td><strong><?php echo formatCurrency($repayment['payment_amount']); ?></strong></td>
                                <td>
                                    <span class="badge bg-secondary">
                                        <?php echo ucfirst(str_replace('_', ' ', $repayment['payment_method'])); ?>
                                    </span>
                                </td>
                                <td><?php echo $repayment['payment_reference'] ? htmlspecialchars($repayment['payment_reference']) : 'N/A'; ?></td>
                                <td>
                                    <?php if ($repayment['is_partial_payment']): ?>
                                        <span class="badge bg-warning">Partial</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">Full</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="<?php echo $repayment['balance_after_payment'] > 0 ? 'text-danger' : 'text-success'; ?>">
                                        <?php echo formatCurrency($repayment['balance_after_payment']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($repayment['recorded_by'] ?? 'System'); ?></td>
                                <td class="text-end sticky-actions">
                                    <div class="btn-group btn-group-sm">
                                        <button 
                                            type="button" 
                                            class="btn btn-outline-primary"
                                            title="View payment details"
                                            data-modal-target="paymentDetailsModal"
                                            data-payment-id="<?php echo $repayment['id']; ?>"
                                            data-client="<?php echo htmlspecialchars($repayment['first_name'] . ' ' . $repayment['last_name']); ?>"
                                            data-email="<?php echo htmlspecialchars($repayment['email']); ?>"
                                            data-date="<?php echo date('M d, Y', strtotime($repayment['payment_date'])); ?>"
                                            data-amount="<?php echo formatCurrency($repayment['payment_amount']); ?>"
                                            data-method="<?php echo ucfirst(str_replace('_', ' ', $repayment['payment_method'])); ?>"
                                            data-reference="<?php echo $repayment['payment_reference'] ? htmlspecialchars($repayment['payment_reference']) : 'N/A'; ?>"
                                            data-type="<?php echo $repayment['is_partial_payment'] ? 'Partial' : 'Full'; ?>"
                                            data-balance-after="<?php echo formatCurrency($repayment['balance_after_payment']); ?>"
                                            data-recorded-by="<?php echo htmlspecialchars($repayment['recorded_by'] ?? 'System'); ?>"
                                            data-notes="<?php echo htmlspecialchars($repayment['notes'] ?? ''); ?>"
                                            onclick="showPaymentDetails(this)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button 
                                            type="button" 
                                            class="btn btn-outline-info" 
                                            onclick="viewLoan(<?php echo $repayment['loan_id']; ?>)"
                                            title="View loan & make payment">
                                            <i class="fas fa-hand-holding-usd"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <nav aria-label="Page navigation">
                    <ul class="pagination justify-content-center">
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&payment_method=<?php echo urlencode($payment_method_filter); ?>">Previous</a>
                            </li>
                        <?php endif; ?>
                        
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&payment_method=<?php echo urlencode($payment_method_filter); ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&payment_method=<?php echo urlencode($payment_method_filter); ?>">Next</a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        <?php else: ?>
            <div class="text-center py-5">
                <i class="fas fa-money-bill-wave fa-3x text-muted mb-3"></i>
                <h5>No payment records found</h5>
                <p class="text-muted">Start recording payments to see them here.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Payment Details Modal -->
<div class="modal" id="paymentDetailsModal" tabindex="-1" aria-labelledby="paymentDetailsLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="paymentDetailsLabel">Payment Details</h5>
                <button type="button" class="modal-close" data-modal-close="paymentDetailsModal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <p><strong>Client:</strong> <span id="pd-client"></span></p>
                        <p><strong>Email:</strong> <span id="pd-email"></span></p>
                        <p><strong>Recorded By:</strong> <span id="pd-recorded-by"></span></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Payment Date:</strong> <span id="pd-date"></span></p>
                        <p><strong>Amount:</strong> <span id="pd-amount"></span></p>
                        <p><strong>Balance After:</strong> <span id="pd-balance-after"></span></p>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <p><strong>Method:</strong> <span id="pd-method"></span></p>
                        <p><strong>Reference:</strong> <span id="pd-reference"></span></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Payment Type:</strong> <span id="pd-type" class="badge"></span></p>
                    </div>
                </div>
                <div class="mb-3">
                    <p><strong>Notes:</strong></p>
                    <p id="pd-notes" class="text-muted mb-0"></p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="custom-btn custom-btn-outline-secondary" data-modal-close="paymentDetailsModal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Make Payment Modal -->
<div class="modal" id="makePaymentModal" tabindex="-1" aria-labelledby="makePaymentLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="makePaymentLabel">Make New Payment</h5>
                <button type="button" class="modal-close" data-modal-close="makePaymentModal" aria-label="Close"></button>
            </div>
            <form id="paymentForm" data-validate>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="custom-form-label">Select Loan *</label>
                        <select class="custom-form-select" name="loan_id" id="loanSelect" data-validate="required" required>
                            <option value="">Loading active loans...</option>
                        </select>
                        <div class="invalid-feedback"></div>
                    </div>
                    
                    <div id="loanInfo" class="custom-card mb-3" style="display: none;">
                        <div class="custom-card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <p><strong>Client:</strong> <span id="loan-client"></span></p>
                                    <p><strong>Loan Amount:</strong> <span id="loan-amount"></span></p>
                                    <p><strong>Monthly Payment:</strong> <span id="loan-monthly"></span></p>
                                </div>
                                <div class="col-md-6">
                                    <p><strong>Remaining Balance:</strong> <span id="loan-balance"></span></p>
                                    <p><strong>Due Date:</strong> <span id="loan-due"></span></p>
                                    <p><strong>Status:</strong> <span id="loan-status" class="badge"></span></p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="custom-form-label">Payment Amount *</label>
                                <div class="custom-input-group">
                                    <span class="custom-input-group-text">₱</span>
                                    <input type="number" class="custom-form-control" name="payment_amount" step="0.01" min="0.01" data-validate="required|min:0.01" required>
                                </div>
                                <small class="custom-form-text text-muted" id="maxAmountText">Maximum: </small>
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
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" onclick="setFullPayment()">
                        <i class="fas fa-calculator me-2"></i>Set Full Payment
                    </button>
                    <button type="button" class="btn btn-secondary" data-modal-close="makePaymentModal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-money-bill-wave me-2"></i>Record Payment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function clearFilters() {
    // Clear all form fields
    document.querySelector('input[name="search"]').value = '';
    document.querySelector('input[name="date_from"]').value = '';
    document.querySelector('input[name="date_to"]').value = '';
    document.querySelector('select[name="payment_method"]').value = '';
    
    // Submit the form to refresh the page with cleared filters
    document.querySelector('form').submit();
}

function showPaymentDetails(button) {
    const data = button.dataset;
    
    document.getElementById('pd-client').textContent = data.client || '';
    document.getElementById('pd-email').textContent = data.email || '';
    document.getElementById('pd-recorded-by').textContent = data.recordedBy || '';
    document.getElementById('pd-date').textContent = data.date || '';
    document.getElementById('pd-amount').textContent = data.amount || '';
    document.getElementById('pd-balance-after').textContent = data.balanceAfter || '';
    document.getElementById('pd-method').textContent = data.method || '';
    document.getElementById('pd-reference').textContent = data.reference || 'N/A';
    
    const typeBadge = document.getElementById('pd-type');
    typeBadge.textContent = data.type || '';
    typeBadge.className = 'badge ' + (data.type === 'Partial' ? 'bg-warning' : 'bg-success');
    
    document.getElementById('pd-notes').textContent = data.notes || 'No additional notes.';
    
    // Open the modal using the global modal system
    if (typeof openModal === 'function') {
        openModal('paymentDetailsModal');
    }
}

function viewLoan(loanId) {
    // Go to make payment page for this loan
    window.location.href = 'make_payment.php?loan_id=' + loanId;
}

function exportRepayments() {
    // Get current filters
    const search = '<?php echo htmlspecialchars($search); ?>';
    const dateFrom = '<?php echo htmlspecialchars($date_from); ?>';
    const dateTo = '<?php echo htmlspecialchars($date_to); ?>';
    const paymentMethod = '<?php echo htmlspecialchars($payment_method_filter); ?>';
    
    // Build export URL
    let exportUrl = 'api/export_repayments.php?';
    const params = [];
    
    if (search) params.push('search=' + encodeURIComponent(search));
    if (dateFrom) params.push('date_from=' + encodeURIComponent(dateFrom));
    if (dateTo) params.push('date_to=' + encodeURIComponent(dateTo));
    if (paymentMethod) params.push('payment_method=' + encodeURIComponent(paymentMethod));
    
    exportUrl += params.join('&');
    
    // Open export in new window
    window.open(exportUrl, '_blank');
}

// Payment Modal Functionality
let activeLoans = [];

// Load active loans when modal opens
document.addEventListener('DOMContentLoaded', function() {
    console.log('Repayments page DOM loaded');
    
    const makePaymentButton = document.querySelector('[data-modal-target="makePaymentModal"]');
    console.log('Make payment button found:', makePaymentButton);
    
    if (makePaymentButton) {
        makePaymentButton.addEventListener('click', function(e) {
            console.log('Make payment button clicked');
            e.preventDefault();
            
            // Open modal first
            if (typeof openModal === 'function') {
                openModal('makePaymentModal');
            }
            
            // Then load loans
            loadActiveLoans();
        });
    }
    
    // Setup loan selection change handler
    const loanSelect = document.getElementById('loanSelect');
    if (loanSelect) {
        loanSelect.addEventListener('change', handleLoanSelection);
    }
    
    // Setup payment form submission
    const paymentForm = document.getElementById('paymentForm');
    if (paymentForm) {
        paymentForm.addEventListener('submit', handlePaymentSubmission);
    }
});

function loadActiveLoans() {
    console.log('Loading active loans...');
    fetch('api/get_active_loans.php')
        .then(response => {
            console.log('API response status:', response.status);
            return response.json();
        })
        .then(data => {
            console.log('API response data:', data);
            if (data.success) {
                activeLoans = data.loans;
                populateLoanSelect();
                
                // Show debug info if available
                if (data.debug) {
                    const debugMsg = `Found ${data.debug.active_loans_with_balance} active loans out of ${data.debug.total_loans} total`;
                    showNotification(debugMsg, 'info');
                } else {
                    showNotification('Loaded ' + data.loans.length + ' active loans', 'success');
                }
            } else {
                showNotification('Error loading active loans: ' + data.message, 'danger');
            }
        })
        .catch(error => {
            console.error('Error loading loans:', error);
            showNotification('Error loading active loans', 'danger');
        });
}

function populateLoanSelect() {
    const loanSelect = document.getElementById('loanSelect');
    loanSelect.innerHTML = '<option value="">Select a loan...</option>';
    
    activeLoans.forEach(loan => {
        const option = document.createElement('option');
        option.value = loan.id;
        option.textContent = `${loan.first_name} ${loan.last_name} - ${formatCurrency(loan.balance_remaining)} remaining`;
        loanSelect.appendChild(option);
    });
}

function handleLoanSelection() {
    const loanId = parseInt(this.value);
    const loanInfo = document.getElementById('loanInfo');
    
    if (!loanId) {
        loanInfo.style.display = 'none';
        return;
    }
    
    const loan = activeLoans.find(l => l.id === loanId);
    if (loan) {
        // Populate loan information
        document.getElementById('loan-client').textContent = `${loan.first_name} ${loan.last_name}`;
        document.getElementById('loan-amount').textContent = formatCurrency(loan.loan_amount);
        document.getElementById('loan-monthly').textContent = formatCurrency(loan.monthly_payment);
        document.getElementById('loan-balance').textContent = formatCurrency(loan.balance_remaining);
        document.getElementById('loan-due').textContent = new Date(loan.due_date).toLocaleDateString();
        
        const statusBadge = document.getElementById('loan-status');
        statusBadge.textContent = loan.status.charAt(0).toUpperCase() + loan.status.slice(1);
        statusBadge.className = `badge bg-${getLoanStatusColor(loan.status)}`;
        
        // Update payment amount field
        const paymentAmountInput = document.querySelector('input[name="payment_amount"]');
        paymentAmountInput.max = loan.balance_remaining;
        document.getElementById('maxAmountText').textContent = `Maximum: ${formatCurrency(loan.balance_remaining)}`;
        
        loanInfo.style.display = 'block';
    }
}

function setFullPayment() {
    const loanSelect = document.getElementById('loanSelect');
    const selectedLoanId = parseInt(loanSelect.value);
    
    if (!selectedLoanId) {
        showNotification('Please select a loan first', 'warning');
        return;
    }
    
    const loan = activeLoans.find(l => l.id === selectedLoanId);
    if (loan) {
        document.querySelector('input[name="payment_amount"]').value = loan.balance_remaining.toFixed(2);
    }
}

function handlePaymentSubmission(e) {
    e.preventDefault();
    
    if (!validateForm(this)) {
        return;
    }
    
    const formData = new FormData(this);
    formData.append('csrf_token', '<?php echo generateCSRFToken(); ?>');
    
    // Show loading state
    const submitButton = this.querySelector('button[type="submit"]');
    const originalText = submitButton.innerHTML;
    submitButton.disabled = true;
    submitButton.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Processing...';
    
    fetch('api/process_payment.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Payment recorded successfully!', 'success');
            closeModal('makePaymentModal');
            this.reset();
            document.getElementById('loanInfo').style.display = 'none';
            
            // Refresh the page to show the new payment
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        } else {
            showNotification('Error: ' + data.message, 'danger');
        }
    })
    .catch(error => {
        console.error('Payment processing error:', error);
        showNotification('Error processing payment', 'danger');
    })
    .finally(() => {
        submitButton.disabled = false;
        submitButton.innerHTML = originalText;
    });
}

</script>

<?php require_once 'includes/footer.php'; ?>
