<?php
$page_title = 'Loan Management';
require_once 'includes/header.php';

require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$success_message = '';
$error_message = '';
$totalLoansInDb = 0;
$totalClientsInDb = 0;

// Check for success messages from redirects
if (isset($_GET['created'])) {
    $success_message = $_SESSION['success_message'] ?? 'Loan created successfully!';
    unset($_SESSION['success_message']);
} elseif (isset($_GET['disbursed'])) {
    $success_message = $_SESSION['success_message'] ?? 'Loan disbursed successfully!';
    unset($_SESSION['success_message']);
} elseif (isset($_GET['completed'])) {
    $success_message = $_SESSION['success_message'] ?? 'Loan marked as completed!';
    unset($_SESSION['success_message']);
}

// Test database connection and check if loans table exists
try {
    $testQuery = "SHOW TABLES LIKE 'loans'";
    $testStmt = $db->prepare($testQuery);
    $testStmt->execute();
    $tableExists = $testStmt->fetch();
    
    if (!$tableExists) {
        $error_message = "Loans table does not exist in database. Please run setup_database.php";
    } else {
        // Check if there are any loans at all
        $countQuery = "SELECT COUNT(*) as total FROM loans";
        $countStmt = $db->prepare($countQuery);
        $countStmt->execute();
        $totalLoansInDb = $countStmt->fetch()['total'];
        error_log("Total loans in database: " . $totalLoansInDb);
        
        // Also check clients table
        $clientCountQuery = "SELECT COUNT(*) as total FROM clients";
        $clientCountStmt = $db->prepare($clientCountQuery);
        $clientCountStmt->execute();
        $totalClientsInDb = $clientCountStmt->fetch()['total'];
        error_log("Total clients in database: " . $totalClientsInDb);
    }
} catch (Exception $e) {
    $error_message = "Database connection error: " . $e->getMessage();
}

// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!validateCSRFToken($csrf_token)) {
        $error_message = 'Invalid request. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'add':
                $client_id = intval($_POST['client_id'] ?? 0);
                $loan_amount = floatval($_POST['loan_amount'] ?? 0);
                $interest_rate = floatval($_POST['interest_rate'] ?? 0);
                $loan_term_months = intval($_POST['loan_term_months'] ?? 0);
                $disbursement_date = $_POST['disbursement_date'] ?? '';
                
                try {
                    // Calculate loan details
                    $interest_calculation = calculateLoanInterest($loan_amount, $interest_rate, $loan_term_months);
                    $total_payable = $interest_calculation['total_payable'];
                    $monthly_payment = $interest_calculation['monthly_payment'];
                    $due_date = date('Y-m-d', strtotime($disbursement_date . ' + ' . $loan_term_months . ' months'));
                    
                    $query = "INSERT INTO loans (client_id, loan_amount, interest_rate, loan_term_months, total_payable, monthly_payment, status, disbursement_date, due_date, balance_remaining, created_by) 
                              VALUES (:client_id, :loan_amount, :interest_rate, :loan_term_months, :total_payable, :monthly_payment, 'pending', :disbursement_date, :due_date, :balance_remaining, :created_by)";
                    
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':client_id', $client_id);
                    $stmt->bindParam(':loan_amount', $loan_amount);
                    $stmt->bindParam(':interest_rate', $interest_rate);
                    $stmt->bindParam(':loan_term_months', $loan_term_months);
                    $stmt->bindParam(':total_payable', $total_payable);
                    $stmt->bindParam(':monthly_payment', $monthly_payment);
                    $stmt->bindParam(':disbursement_date', $disbursement_date);
                    $stmt->bindParam(':due_date', $due_date);
                    $stmt->bindParam(':balance_remaining', $total_payable);
                    $stmt->bindParam(':created_by', $_SESSION['user_id']);
                    
                    if ($stmt->execute()) {
                        $loan_id = $db->lastInsertId();
                        logActivity($_SESSION['user_id'], 'create', 'loans', $loan_id, null, $_POST);
                        $_SESSION['success_message'] = 'Loan created successfully!';
                        header('Location: loans.php?created=1');
                        exit();
                    } else {
                        $error_message = 'Failed to create loan.';
                    }
                } catch (Exception $e) {
                    $error_message = 'Error: ' . $e->getMessage();
                }
                break;
                
            case 'disburse':
                $loan_id = intval($_POST['loan_id'] ?? 0);
                
                try {
                    $query = "UPDATE loans SET status = 'active', disbursement_date = CURDATE(), updated_at = NOW() WHERE id = :id AND status = 'pending'";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':id', $loan_id);
                    
                    if ($stmt->execute()) {
                        logActivity($_SESSION['user_id'], 'disburse', 'loans', $loan_id);
                        $_SESSION['success_message'] = 'Loan disbursed successfully!';
                        header('Location: loans.php?disbursed=1');
                        exit();
                    } else {
                        $error_message = 'Failed to disburse loan.';
                    }
                } catch (Exception $e) {
                    $error_message = 'Error: ' . $e->getMessage();
                }
                break;
                
            case 'complete':
                $loan_id = intval($_POST['loan_id'] ?? 0);
                
                try {
                    $query = "UPDATE loans SET status = 'completed', completed_date = CURDATE(), balance_remaining = 0, updated_at = NOW() WHERE id = :id AND status = 'active'";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':id', $loan_id);
                    
                    if ($stmt->execute()) {
                        logActivity($_SESSION['user_id'], 'complete', 'loans', $loan_id);
                        $_SESSION['success_message'] = 'Loan marked as completed!';
                        header('Location: loans.php?completed=1');
                        exit();
                    } else {
                        $error_message = 'Failed to complete loan.';
                    }
                } catch (Exception $e) {
                    $error_message = 'Error: ' . $e->getMessage();
                }
                break;
        }
    }
}

// Get loans for display
try {
    $search = sanitizeInput($_GET['search'] ?? '');
    $status_filter = sanitizeInput($_GET['status'] ?? '');
    $page = max(1, intval($_GET['page'] ?? 1));
    $offset = ($page - 1) * ITEMS_PER_PAGE;
    
    $query = "SELECT l.*, c.first_name, c.last_name, c.email, c.phone 
              FROM loans l 
              JOIN clients c ON l.client_id = c.id 
              WHERE 1=1";
    $params = [];
    
    if (!empty($search)) {
        // Check if search is numeric for loan amount or loan ID search
        if (is_numeric($search)) {
            $query .= " AND (c.first_name LIKE :search_name OR c.last_name LIKE :search_last OR c.email LIKE :search_email OR c.phone LIKE :search_phone OR l.loan_amount = :search_numeric OR l.id = :search_id)";
            $search_param = "%$search%";
            $params[':search_name'] = $search_param;
            $params[':search_last'] = $search_param;
            $params[':search_email'] = $search_param;
            $params[':search_phone'] = $search_param;
            $params[':search_numeric'] = floatval($search);
            $params[':search_id'] = intval($search);
        } else {
            $query .= " AND (c.first_name LIKE :search_name OR c.last_name LIKE :search_last OR c.email LIKE :search_email OR c.phone LIKE :search_phone OR l.status LIKE :search_status)";
            $search_param = "%$search%";
            $params[':search_name'] = $search_param;
            $params[':search_last'] = $search_param;
            $params[':search_email'] = $search_param;
            $params[':search_phone'] = $search_param;
            $params[':search_status'] = $search_param;
        }
    }
    
    if (!empty($status_filter)) {
        $query .= " AND l.status = :status";
        $params[':status'] = $status_filter;
    }
    
    $query .= " ORDER BY l.created_at DESC LIMIT " . ITEMS_PER_PAGE . " OFFSET " . $offset;
    
    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $loans = $stmt->fetchAll();
    
    // Get total count for pagination
    $count_query = "SELECT COUNT(*) as total FROM loans l JOIN clients c ON l.client_id = c.id WHERE 1=1";
    $count_params = [];
    
    if (!empty($search)) {
        // Check if search is numeric for loan amount or loan ID search
        if (is_numeric($search)) {
            $count_query .= " AND (c.first_name LIKE :search_name OR c.last_name LIKE :search_last OR c.email LIKE :search_email OR c.phone LIKE :search_phone OR l.loan_amount = :search_numeric OR l.id = :search_id)";
            $search_param = "%$search%";
            $count_params[':search_name'] = $search_param;
            $count_params[':search_last'] = $search_param;
            $count_params[':search_email'] = $search_param;
            $count_params[':search_phone'] = $search_param;
            $count_params[':search_numeric'] = floatval($search);
            $count_params[':search_id'] = intval($search);
        } else {
            $count_query .= " AND (c.first_name LIKE :search_name OR c.last_name LIKE :search_last OR c.email LIKE :search_email OR c.phone LIKE :search_phone OR l.status LIKE :search_status)";
            $search_param = "%$search%";
            $count_params[':search_name'] = $search_param;
            $count_params[':search_last'] = $search_param;
            $count_params[':search_email'] = $search_param;
            $count_params[':search_phone'] = $search_param;
            $count_params[':search_status'] = $search_param;
        }
    }
    if (!empty($status_filter)) {
        $count_query .= " AND l.status = :status";
        $count_params[':status'] = $status_filter;
    }
    
    $count_stmt = $db->prepare($count_query);
    foreach ($count_params as $key => $value) {
        $count_stmt->bindValue($key, $value);
    }
    $count_stmt->execute();
    $total_loans = $count_stmt->fetch()['total'];
    $total_pages = ceil($total_loans / ITEMS_PER_PAGE);
    
} catch (Exception $e) {
    $error_message = "Error loading loans: " . $e->getMessage();
    error_log("Loan loading error: " . $e->getMessage());
    $loans = [];
    $total_pages = 0;
}

// Get clients for loan creation dropdown
try {
    $client_query = "SELECT id, first_name, last_name, email, phone FROM clients WHERE is_active = 1 ORDER BY first_name, last_name";
    $client_stmt = $db->prepare($client_query);
    $client_stmt->execute();
    $clients = $client_stmt->fetchAll();
} catch (Exception $e) {
    $clients = [];
}
?>

<div class="row mb-4">
    <div class="col-md-8">
        <h1 class="h3 mb-0">Loan Management</h1>
        <p class="text-muted">Create and manage loan accounts</p>
    </div>
    <div class="col-md-4 text-end">
        <button type="button" class="btn btn-primary" onclick="openModal('addLoanModal')">
            <i class="fas fa-plus me-2"></i>Create New Loan
        </button>
    </div>
</div>

<!-- Search and Filter -->
<div class="row mb-4">
    <div class="col-md-6">
        <form method="GET" action="">
            <div class="input-group">
                <input type="text" class="form-control" name="search" placeholder="Search by name, email, phone, loan ID, amount, or status..." value="<?php echo htmlspecialchars($search); ?>">
                <select class="form-select" name="status" style="max-width: 150px;">
                    <option value="">All Status</option>
                    <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                    <option value="overdue" <?php echo $status_filter === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                </select>
                <button type="submit" class="btn btn-outline-primary">
                    <i class="fas fa-search"></i>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Success Messages -->
<?php if ($success_message): ?>
    <div class="alert alert-success">
        <?php echo htmlspecialchars($success_message); ?>
    </div>
<?php endif; ?>

<!-- Error Messages -->
<?php if ($error_message): ?>
    <div class="alert alert-danger">
        <?php echo htmlspecialchars($error_message); ?>
    </div>
<?php endif; ?>


<!-- Loans Table -->
<div class="card">
    <div class="card-header">
        <h5 style="padding: 20px;
                    margin-bottom: 0;
                    background: var(--bg-secondary);
                    color: var(--text-primary);
                    border-radius: 10px 10px 0 0;
                    font-weight: 600;
                    border-bottom: 1px solid var(--border-color);"  class="card-title mb-0">Loans List</h5>
    </div>
    <div class="card-body">
        <?php if (count($loans) > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Client</th>
                            <th>Loan Amount</th>
                            <th>Interest Rate</th>
                            <th>Term</th>
                            <th>Monthly Payment</th>
                            <th>Balance</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($loans as $loan): ?>
                            <tr data-loan-id="<?php echo $loan['id']; ?>">
                                <td>
                                    <strong><?php echo htmlspecialchars($loan['first_name'] . ' ' . $loan['last_name']); ?></strong><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($loan['email']); ?></small>
                                </td>
                                <td><?php echo formatCurrency($loan['loan_amount']); ?></td>
                                <td><?php echo $loan['interest_rate']; ?>%</td>
                                <td><?php echo $loan['loan_term_months']; ?> months</td>
                                <td><?php echo formatCurrency($loan['monthly_payment']); ?></td>
                                <td>
                                    <span class="<?php echo $loan['balance_remaining'] > 0 ? 'text-danger' : 'text-success'; ?>">
                                        <?php echo formatCurrency($loan['balance_remaining']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo getLoanStatusColor($loan['status']); ?> loan-status">
                                        <?php echo ucfirst($loan['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-outline-primary" onclick="viewLoan(<?php echo $loan['id']; ?>)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <?php if ($loan['status'] === 'pending'): ?>
                                            <button type="button" class="btn btn-outline-success" onclick="disburseLoan(<?php echo $loan['id']; ?>)">
                                                <i class="fas fa-hand-holding-usd"></i>
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($loan['status'] === 'active' && $loan['balance_remaining'] == 0): ?>
                                            <button type="button" class="btn btn-outline-info" onclick="completeLoan(<?php echo $loan['id']; ?>)">
                                                <i class="fas fa-check"></i>
                                            </button>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-outline-success" onclick="makePayment(<?php echo $loan['id']; ?>)">
                                            <i class="fas fa-money-bill-wave"></i>
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
                                <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>">Previous</a>
                            </li>
                        <?php endif; ?>
                        
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>">Next</a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        <?php else: ?>
            <div class="text-center py-5">
                <i class="fas fa-hand-holding-usd fa-3x text-muted mb-3"></i>
                <h5>No loans found</h5>
                <p class="text-muted">Start by creating your first loan.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add Loan Modal -->
<div class="modal fade" id="addLoanModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Create New Loan</h5>
                <button type="button" class="modal-close" onclick="closeModal('addLoanModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" action="" id="loanForm" onsubmit="return validateLoanForm()">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Client *</label>
                                <select class="form-select" name="client_id" data-validate="required" required onchange="updateClientInfo()">
                                    <option value="">Select Client...</option>
                                    <?php foreach ($clients as $client): ?>
                                        <option value="<?php echo $client['id']; ?>" data-email="<?php echo htmlspecialchars($client['email']); ?>" data-phone="<?php echo htmlspecialchars($client['phone']); ?>">
                                            <?php echo htmlspecialchars($client['first_name'] . ' ' . $client['last_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="invalid-feedback"></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Loan Amount *</label>
                                <input type="number" class="form-control" name="loan_amount" step="0.01" min="0" data-validate="required|min:1" required onchange="calculateLoan()">
                                <div class="invalid-feedback"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Interest Rate (%) *</label>
                                <input type="number" class="form-control" name="interest_rate" step="0.01" min="0" max="100" value="10" data-validate="required|min:0|max:100" required onchange="calculateLoan()">
                                <div class="invalid-feedback"></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Loan Term (months) *</label>
                                <input type="number" class="form-control" name="loan_term_months" min="1" max="60" value="12" data-validate="required|min:1|max:60" required onchange="calculateLoan()">
                                <div class="invalid-feedback"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Disbursement Date *</label>
                                <input type="date" class="form-control" name="disbursement_date" data-validate="required" required value="<?php echo date('Y-m-d'); ?>">
                                <div class="invalid-feedback"></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Due Date</label>
                                <input type="date" class="form-control" id="dueDate" readonly>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Loan Calculation Preview -->
                    <div class="alert alert-info">
                        <h6>Loan Calculation</h6>
                        <div class="row">
                            <div class="col-md-4">
                                <strong>Total Payable:</strong> <span id="totalPayable">$0.00</span>
                            </div>
                            <div class="col-md-4">
                                <strong>Monthly Payment:</strong> <span id="monthlyPayment">$0.00</span>
                            </div>
                            <div class="col-md-4">
                                <strong>Total Interest:</strong> <span id="totalInterest">$0.00</span>
                            </div>
                        </div>
                    </div>
                    
                    <div id="clientInfo" class="alert alert-secondary" style="display: none;">
                        <h6>Selected Client Information</h6>
                        <div id="clientDetails"></div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('addLoanModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Loan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function validateLoanForm() {
    const form = document.getElementById('loanForm');
    const clientId = form.client_id.value;
    const loanAmount = parseFloat(form.loan_amount.value);
    const interestRate = parseFloat(form.interest_rate.value);
    const loanTerm = parseInt(form.loan_term_months.value);
    const disbursementDate = form.disbursement_date.value;
    
    // Clear previous error messages
    const errorElements = form.querySelectorAll('.is-invalid');
    errorElements.forEach(element => element.classList.remove('is-invalid'));
    
    let isValid = true;
    
    // Validate client selection
    if (!clientId) {
        showFieldError(form.client_id, 'Please select a client');
        isValid = false;
    }
    
    // Validate loan amount
    if (!loanAmount || loanAmount <= 0) {
        showFieldError(form.loan_amount, 'Please enter a valid loan amount');
        isValid = false;
    }
    
    // Validate interest rate
    if (isNaN(interestRate) || interestRate < 0 || interestRate > 100) {
        showFieldError(form.interest_rate, 'Please enter a valid interest rate (0-100)');
        isValid = false;
    }
    
    // Validate loan term
    if (!loanTerm || loanTerm < 1 || loanTerm > 60) {
        showFieldError(form.loan_term_months, 'Please enter a valid loan term (1-60 months)');
        isValid = false;
    }
    
    // Validate disbursement date
    if (!disbursementDate) {
        showFieldError(form.disbursement_date, 'Please select a disbursement date');
        isValid = false;
    }
    
    if (isValid) {
        // Disable submit button to prevent double submission
        const submitBtn = form.querySelector('button[type="submit"]');
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Creating Loan...';
        
        // Submit the form
        return true;
    }
    
    return false;
}

function showFieldError(field, message) {
    field.classList.add('is-invalid');
    let feedback = field.parentNode.querySelector('.invalid-feedback');
    if (!feedback) {
        feedback = document.createElement('div');
        feedback.className = 'invalid-feedback';
        field.parentNode.appendChild(feedback);
    }
    feedback.textContent = message;
}

function calculateLoan() {
    const amount = parseFloat(document.querySelector('[name="loan_amount"]').value) || 0;
    const rate = parseFloat(document.querySelector('[name="interest_rate"]').value) || 0;
    const term = parseInt(document.querySelector('[name="loan_term_months"]').value) || 0;
    const disbursementDate = document.querySelector('[name="disbursement_date"]').value;
    
    if (amount > 0 && rate >= 0 && term > 0) {
        const monthlyRate = rate / 100 / 12;
        const totalPayable = amount * (1 + (monthlyRate * term));
        const monthlyPayment = totalPayable / term;
        const totalInterest = totalPayable - amount;
        
        document.getElementById('totalPayable').textContent = '₱' + totalPayable.toFixed(2);
        document.getElementById('monthlyPayment').textContent = '₱' + monthlyPayment.toFixed(2);
        document.getElementById('totalInterest').textContent = '₱' + totalInterest.toFixed(2);
        
        if (disbursementDate) {
            const dueDate = new Date(disbursementDate);
            dueDate.setMonth(dueDate.getMonth() + term);
            // Format as YYYY-MM-DD for the input field
            const year = dueDate.getFullYear();
            const month = String(dueDate.getMonth() + 1).padStart(2, '0');
            const day = String(dueDate.getDate()).padStart(2, '0');
            document.getElementById('dueDate').value = `${year}-${month}-${day}`;
        }
    } else {
        document.getElementById('totalPayable').textContent = '₱0.00';
        document.getElementById('monthlyPayment').textContent = '₱0.00';
        document.getElementById('totalInterest').textContent = '₱0.00';
        document.getElementById('dueDate').value = '';
    }
}

function updateClientInfo() {
    const select = document.querySelector('[name="client_id"]');
    const selectedOption = select.options[select.selectedIndex];
    const infoDiv = document.getElementById('clientInfo');
    const detailsDiv = document.getElementById('clientDetails');
    
    if (select.value) {
        const email = selectedOption.dataset.email || 'N/A';
        const phone = selectedOption.dataset.phone || 'N/A';
        
        detailsDiv.innerHTML = `
            <strong>Email:</strong> ${email}<br>
            <strong>Phone:</strong> ${phone}
        `;
        infoDiv.style.display = 'block';
    } else {
        infoDiv.style.display = 'none';
    }
}

function disburseLoan(loanId) {
    if (confirm('Are you sure you want to disburse this loan?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="disburse">
            <input type="hidden" name="loan_id" value="${loanId}">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function completeLoan(loanId) {
    if (confirm('Are you sure you want to mark this loan as completed?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="complete">
            <input type="hidden" name="loan_id" value="${loanId}">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function viewLoan(loanId) {
    window.location.href = 'loan_details.php?id=' + loanId;
}

function makePayment(loanId) {
    window.location.href = 'make_payment.php?loan_id=' + loanId;
}

// Initialize calculation on page load
document.addEventListener('DOMContentLoaded', function() {
    calculateLoan();
    
    // Ensure modal functions are available globally
    window.openModal = function(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.add('show');
            modal.style.display = 'block';
            document.body.style.overflow = 'hidden';
        }
    };
    
    window.closeModal = function(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.remove('show');
            modal.style.display = 'none';
            document.body.style.overflow = '';
            
            // Reset form if it's the add loan modal
            if (modalId === 'addLoanModal') {
                const form = document.getElementById('loanForm');
                if (form) {
                    form.reset();
                    // Clear any validation errors
                    const errorElements = form.querySelectorAll('.is-invalid');
                    errorElements.forEach(element => element.classList.remove('is-invalid'));
                    
                    // Reset calculation display
                    document.getElementById('totalPayable').textContent = '₱0.00';
                    document.getElementById('monthlyPayment').textContent = '₱0.00';
                    document.getElementById('totalInterest').textContent = '₱0.00';
                    document.getElementById('dueDate').value = '';
                    
                    // Hide client info
                    document.getElementById('clientInfo').style.display = 'none';
                    
                    // Reset submit button
                    const submitBtn = form.querySelector('button[type="submit"]');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = 'Create Loan';
                }
            }
        }
    };
});
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
