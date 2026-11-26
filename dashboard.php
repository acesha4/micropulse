<?php
$page_title = 'Dashboard';
require_once 'includes/header.php';

require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

// Get dashboard statistics
$stats = [
    'total_clients' => 0,
    'active_loans' => 0,
    'total_collected' => 0,
    'outstanding' => 0,
    'total_users' => 0,
    'active_users' => 0
];

try {
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
    
    // Recent loans
    $query = "SELECT l.*, c.first_name, c.last_name 
              FROM loans l 
              JOIN clients c ON l.client_id = c.id 
              ORDER BY l.created_at DESC 
              LIMIT 5";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $recent_loans = $stmt->fetchAll();
    
    // Recent repayments
    $query = "SELECT r.*, l.loan_amount, c.first_name, c.last_name 
              FROM repayments r 
              JOIN loans l ON r.loan_id = l.id 
              JOIN clients c ON l.client_id = c.id 
              ORDER BY r.created_at DESC 
              LIMIT 5";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $recent_repayments = $stmt->fetchAll();
    
    // Loan status distribution
    $query = "SELECT status, COUNT(*) as count FROM loans GROUP BY status";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $loan_status_data = $stmt->fetchAll();
    
} catch (Exception $e) {
    $error_message = "Error loading dashboard data: " . $e->getMessage();
}
?>

<div class="dashboard-header">
    <div class="dashboard-header-content">
        <h1 class="dashboard-title">Dashboard</h1>
        <p style="color: white;" class="dashboard-subtitle">Welcome back, <?php echo htmlspecialchars($_SESSION['full_name']); ?>!</p>
    </div>
</div>

<!-- Statistics Cards -->
<div class="dashboard-stats">
    <div class="dashboard-stat-col">
        <div class="stat-card primary">
            <div class="stat-icon">
                <i class="fas fa-users"></i>
            </div>
            <div class="stat-value" id="total-clients"><?php echo $stats['total_clients']; ?></div>
            <div class="stat-label">Total Clients</div>
        </div>
    </div>
    
    <div class="dashboard-stat-col">
        <div class="stat-card success">
            <div class="stat-icon">
                <i class="fas fa-hand-holding-usd"></i>
            </div>
            <div class="stat-value" id="active-loans"><?php echo $stats['active_loans']; ?></div>
            <div class="stat-label">Active Loans</div>
        </div>
    </div>
    
    <div class="dashboard-stat-col">
        <div class="stat-card warning">
            <div class="stat-icon">
                <i class="fas fa-money-bill-wave"></i>
            </div>
            <div class="stat-value" id="total-collected"><?php echo formatCurrency($stats['total_collected']); ?></div>
            <div class="stat-label">Total Collected</div>
        </div>
    </div>
    
    <div class="dashboard-stat-col">
        <div class="stat-card danger">
            <div class="stat-icon">
                <i class="fas fa-chart-line"></i>
            </div>
            <div class="stat-value" id="outstanding"><?php echo formatCurrency($stats['outstanding']); ?></div>
            <div class="stat-label">Outstanding Balance</div>
        </div>
    </div>
</div>

<!-- Charts Row -->
<div class="dashboard-charts">
    <div class="dashboard-chart-col">
        <div class="card">
            <div class="card-header">
                <h5 style="padding: 20px;
                    margin-bottom: 0;
                    background: var(--bg-secondary);
                    color: var(--text-primary);
                    border-radius: 10px 10px 0 0;
                    font-weight: 600;
                    border-bottom: 1px solid var(--border-color);"  class="card-title mb-0">Loan Status Distribution</h5>
            </div>
            <div class="card-body">
                <canvas id="loanStatusChart" style="height: 250px;"></canvas>
            </div>
        </div>
    </div>
    
    <div class="dashboard-chart-col">
        <div class="card">
            <div class="card-header">
                <h5 style="padding: 20px;
                    margin-bottom: 0;
                    background: var(--bg-secondary);
                    color: var(--text-primary);
                    border-radius: 10px 10px 0 0;
                    font-weight: 600;
                    border-bottom: 1px solid var(--border-color);" class="card-title mb-0">Monthly Collections</h5>
            </div>
            <div class="card-body">
                <canvas id="monthlyCollectionsChart" style="height: 250px;"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Recent Activities -->
<div class="dashboard-activities">
    <div class="dashboard-activity-col">
        <div class="card">
            <div class="card-header dashboard-card-header-flex">
                <h5 style="padding: 20px;
                    margin-bottom: 0;
                    background: var(--bg-secondary);
                    color: var(--text-primary);
                    border-radius: 10px 10px 0 0;
                    font-weight: 600;
                    border-bottom: 1px solid var(--border-color);"  class="card-title mb-0">Recent Loans</h5>
                <a href="loans.php" class="btn btn-sm btn-outline-light">View All</a>
            </div>
            <div class="card-body">
                <?php if (isset($recent_loans) && count($recent_loans) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Client</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_loans as $loan): ?>
                                    <tr>
                                        <td>
                                            <?php echo htmlspecialchars($loan['first_name'] . ' ' . $loan['last_name']); ?>
                                        </td>
                                        <td><?php echo formatCurrency($loan['loan_amount']); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo getStatusColor($loan['status']); ?>">
                                                <?php echo ucfirst($loan['status']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-gray-600 mb-0">No recent loans found.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="dashboard-activity-col">
        <div class="card">
            <div class="card-header dashboard-card-header-flex">
                <h5 style="padding: 20px;
                    margin-bottom: 0;
                    background: var(--bg-secondary);
                    color: var(--text-primary);
                    border-radius: 10px 10px 0 0;
                    font-weight: 600;
                    border-bottom: 1px solid var(--border-color);"  class="card-title mb-0">Recent Repayments</h5>
                <a href="repayments.php" class="btn btn-sm btn-outline-light">View All</a>
            </div>
            <div class="card-body">
                <?php if (isset($recent_repayments) && count($recent_repayments) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Client</th>
                                    <th>Amount</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_repayments as $repayment): ?>
                                    <tr>
                                        <td>
                                            <?php echo htmlspecialchars($repayment['first_name'] . ' ' . $repayment['last_name']); ?>
                                        </td>
                                        <td><?php echo formatCurrency($repayment['payment_amount']); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($repayment['payment_date'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-gray-600 mb-0">No recent repayments found.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
function getStatusColor($status) {
    $colors = [
        'active' => 'success',
        'pending' => 'warning',
        'overdue' => 'danger',
        'completed' => 'info',
        'defaulted' => 'dark'
    ];
    return $colors[$status] ?? 'secondary';
}
?>

<?php require_once 'includes/footer.php'; ?>
