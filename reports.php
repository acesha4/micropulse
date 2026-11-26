<?php
$page_title = 'Reports';
require_once 'includes/header.php';

require_once 'config/database.php';
require_once 'config/config.php';

$database = new Database();
$db = $database->getConnection();

$success_message = '';
$error_message = '';

// Get report parameters
$report_type = sanitizeInput($_GET['report_type'] ?? 'daily');
$date_from = sanitizeInput($_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days')));
$date_to = sanitizeInput($_GET['date_to'] ?? date('Y-m-d'));

// Validate report type
$valid_report_types = ['daily', 'weekly', 'monthly', 'loan_performance', 'client_summary'];
if (!in_array($report_type, $valid_report_types)) {
    $report_type = 'daily';
    $error_message = 'Invalid report type. Showing daily report instead.';
}

// Validate dates
if ($report_type !== 'loan_performance' && $report_type !== 'client_summary') {
    if (!strtotime($date_from) || !strtotime($date_to)) {
        $date_from = date('Y-m-d', strtotime('-30 days'));
        $date_to = date('Y-m-d');
        $error_message = 'Invalid date format. Using default dates.';
    }
    
    if ($date_from > $date_to) {
        $temp = $date_from;
        $date_from = $date_to;
        $date_to = $temp;
        $error_message = 'Date from was greater than date to. Dates have been swapped.';
    }
}

// Generate report data
try {
    $report_data = [];
    
    switch ($report_type) {
        case 'daily':
            $report_data = generateDailyReport($db, $date_from, $date_to);
            break;
        case 'weekly':
            $report_data = generateWeeklyReport($db, $date_from, $date_to);
            break;
        case 'monthly':
            $report_data = generateMonthlyReport($db, $date_from, $date_to);
            break;
        case 'loan_performance':
            $report_data = generateLoanPerformanceReport($db);
            break;
        case 'client_summary':
            $report_data = generateClientSummaryReport($db);
            break;
    }
} catch (Exception $e) {
    $error_message = 'Error generating report: ' . $e->getMessage();
    $report_data = [];
}

function generateDailyReport($db, $date_from, $date_to) {
    $data = [];
    
    // Daily collections
    $query = "SELECT DATE(payment_date) as date, 
                     COUNT(*) as payment_count,
                     SUM(payment_amount) as total_amount,
                     AVG(payment_amount) as avg_amount
              FROM repayments 
              WHERE payment_date BETWEEN :date_from AND :date_to
              GROUP BY DATE(payment_date)
              ORDER BY date DESC";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':date_from', $date_from);
    $stmt->bindParam(':date_to', $date_to);
    $stmt->execute();
    $data['daily_collections'] = $stmt->fetchAll();
    
    // Daily new loans
    $query = "SELECT DATE(created_at) as date, 
                     COUNT(*) as loan_count,
                     SUM(loan_amount) as total_amount,
                     AVG(loan_amount) as avg_amount
              FROM loans 
              WHERE DATE(created_at) BETWEEN :date_from AND :date_to
              GROUP BY DATE(created_at)
              ORDER BY date DESC";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':date_from', $date_from);
    $stmt->bindParam(':date_to', $date_to);
    $stmt->execute();
    $data['daily_loans'] = $stmt->fetchAll();
    
    // Summary
    $query = "SELECT COUNT(*) as total_payments,
                     SUM(payment_amount) as total_collected,
                     COUNT(DISTINCT loan_id) as unique_loans
              FROM repayments 
              WHERE payment_date BETWEEN :date_from AND :date_to";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':date_from', $date_from);
    $stmt->bindParam(':date_to', $date_to);
    $stmt->execute();
    $data['summary'] = $stmt->fetch();
    
    return $data;
}

function generateWeeklyReport($db, $date_from, $date_to) {
    $data = [];
    
    // Weekly collections
    $query = "SELECT YEARWEEK(payment_date) as week, 
                     MIN(DATE(payment_date)) as week_start,
                     MAX(DATE(payment_date)) as week_end,
                     COUNT(*) as payment_count,
                     SUM(payment_amount) as total_amount,
                     AVG(payment_amount) as avg_amount
              FROM repayments 
              WHERE payment_date BETWEEN :date_from AND :date_to
              GROUP BY YEARWEEK(payment_date)
              ORDER BY week DESC";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':date_from', $date_from);
    $stmt->bindParam(':date_to', $date_to);
    $stmt->execute();
    $data['weekly_collections'] = $stmt->fetchAll();
    
    // Weekly new loans
    $query = "SELECT YEARWEEK(DATE(created_at)) as week,
                     MIN(DATE(created_at)) as week_start,
                     MAX(DATE(created_at)) as week_end,
                     COUNT(*) as loan_count,
                     SUM(loan_amount) as total_amount,
                     AVG(loan_amount) as avg_amount
              FROM loans 
              WHERE DATE(created_at) BETWEEN :date_from AND :date_to
              GROUP BY YEARWEEK(DATE(created_at))
              ORDER BY week DESC";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':date_from', $date_from);
    $stmt->bindParam(':date_to', $date_to);
    $stmt->execute();
    $data['weekly_loans'] = $stmt->fetchAll();
    
    return $data;
}

function generateMonthlyReport($db, $date_from, $date_to) {
    $data = [];
    
    // Monthly collections
    $query = "SELECT DATE_FORMAT(payment_date, '%Y-%m') as month,
                     DATE_FORMAT(payment_date, '%M %Y') as month_name,
                     COUNT(*) as payment_count,
                     SUM(payment_amount) as total_amount,
                     AVG(payment_amount) as avg_amount
              FROM repayments 
              WHERE payment_date BETWEEN :date_from AND :date_to
              GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
              ORDER BY month DESC";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':date_from', $date_from);
    $stmt->bindParam(':date_to', $date_to);
    $stmt->execute();
    $data['monthly_collections'] = $stmt->fetchAll();
    
    // Monthly new loans
    $query = "SELECT DATE_FORMAT(DATE(created_at), '%Y-%m') as month,
                     DATE_FORMAT(DATE(created_at), '%M %Y') as month_name,
                     COUNT(*) as loan_count,
                     SUM(loan_amount) as total_amount,
                     AVG(loan_amount) as avg_amount
              FROM loans 
              WHERE DATE(created_at) BETWEEN :date_from AND :date_to
              GROUP BY DATE_FORMAT(DATE(created_at), '%Y-%m')
              ORDER BY month DESC";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':date_from', $date_from);
    $stmt->bindParam(':date_to', $date_to);
    $stmt->execute();
    $data['monthly_loans'] = $stmt->fetchAll();
    
    // Loan status by month
    $query = "SELECT DATE_FORMAT(DATE(created_at), '%Y-%m') as month,
                     DATE_FORMAT(DATE(created_at), '%M %Y') as month_name,
                     status,
                     COUNT(*) as count
              FROM loans 
              WHERE DATE(created_at) BETWEEN :date_from AND :date_to
              GROUP BY DATE_FORMAT(DATE(created_at), '%Y-%m'), status
              ORDER BY month DESC, status";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':date_from', $date_from);
    $stmt->bindParam(':date_to', $date_to);
    $stmt->execute();
    $data['loan_status_by_month'] = $stmt->fetchAll();
    
    return $data;
}

function generateLoanPerformanceReport($db) {
    $data = [];
    
    // Loan performance metrics
    $query = "SELECT 
                COUNT(*) as total_loans,
                SUM(loan_amount) as total_loan_amount,
                AVG(loan_amount) as avg_loan_amount,
                SUM(total_payable) as total_payable,
                SUM(balance_remaining) as total_outstanding,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_loans,
                COUNT(CASE WHEN status = 'active' THEN 1 END) as active_loans,
                COUNT(CASE WHEN status = 'overdue' THEN 1 END) as overdue_loans,
                COUNT(CASE WHEN status = 'defaulted' THEN 1 END) as defaulted_loans
              FROM loans";
    
    $stmt = $db->prepare($query);
    $stmt->execute();
    $data['performance_metrics'] = $stmt->fetch();
    
    // Top performing clients
    $query = "SELECT c.first_name, c.last_name, c.email,
                     COUNT(l.id) as loan_count,
                     SUM(l.loan_amount) as total_borrowed,
                     SUM(l.total_payable) as total_payable,
                     SUM(l.balance_remaining) as outstanding
              FROM clients c
              JOIN loans l ON c.id = l.client_id
              WHERE c.is_active = 1
              GROUP BY c.id
              ORDER BY loan_count DESC, total_borrowed DESC
              LIMIT 10";
    
    $stmt = $db->prepare($query);
    $stmt->execute();
    $data['top_clients'] = $stmt->fetchAll();
    
    // Loan distribution by amount
    $query = "SELECT 
                CASE 
                    WHEN loan_amount < 1000 THEN 'Under $1,000'
                    WHEN loan_amount < 5000 THEN '$1,000 - $4,999'
                    WHEN loan_amount < 10000 THEN '$5,000 - $9,999'
                    ELSE '$10,000+'
                END as amount_range,
                COUNT(*) as count,
                SUM(loan_amount) as total_amount
              FROM loans
              GROUP BY 
                CASE 
                    WHEN loan_amount < 1000 THEN 'Under $1,000'
                    WHEN loan_amount < 5000 THEN '$1,000 - $4,999'
                    WHEN loan_amount < 10000 THEN '$5,000 - $9,999'
                    ELSE '$10,000+'
                END
              ORDER BY MIN(loan_amount)";
    
    $stmt = $db->prepare($query);
    $stmt->execute();
    $data['loan_distribution'] = $stmt->fetchAll();
    
    return $data;
}

function generateClientSummaryReport($db) {
    $data = [];
    
    // Client statistics
    $query = "SELECT 
                COUNT(*) as total_clients,
                COUNT(CASE WHEN employment_status = 'Employed' THEN 1 END) as employed,
                COUNT(CASE WHEN employment_status = 'Self-Employed' THEN 1 END) as self_employed,
                COUNT(CASE WHEN employment_status = 'Unemployed' THEN 1 END) as unemployed,
                COUNT(CASE WHEN employment_status = 'Student' THEN 1 END) as student,
                COUNT(CASE WHEN employment_status = 'Retired' THEN 1 END) as retired,
                COALESCE(AVG(monthly_income), 0) as avg_income,
                COALESCE(MIN(monthly_income), 0) as min_income,
                COALESCE(MAX(monthly_income), 0) as max_income
              FROM clients 
              WHERE is_active = 1";
    
    $stmt = $db->prepare($query);
    $stmt->execute();
    $data['client_stats'] = $stmt->fetch();
    
    // Client loan activity
    $query = "SELECT c.id, c.first_name, c.last_name, c.email,
                     COALESCE(COUNT(l.id), 0) as loan_count,
                     COALESCE(SUM(l.loan_amount), 0) as total_borrowed,
                     COALESCE(SUM(l.total_payable), 0) as total_payable,
                     COALESCE(SUM(l.balance_remaining), 0) as outstanding,
                     MAX(l.created_at) as last_loan_date
              FROM clients c
              LEFT JOIN loans l ON c.id = l.client_id
              WHERE c.is_active = 1
              GROUP BY c.id
              ORDER BY loan_count DESC, total_borrowed DESC";
    
    $stmt = $db->prepare($query);
    $stmt->execute();
    $data['client_activity'] = $stmt->fetchAll();
    
    return $data;
}

function getStatusBadgeClass($status) {
    switch ($status) {
        case 'active':
            return 'success';
        case 'completed':
            return 'primary';
        case 'overdue':
            return 'warning';
        case 'defaulted':
            return 'danger';
        default:
            return 'secondary';
    }
}
?>

<div class="row mb-4">
    <div class="col-md-8">
        <h1 class="h3 mb-0">Reports</h1>
        <p class="text-muted">Generate and view financial reports</p>
    </div>
    <div class="col-md-4 text-end">
        <div class="custom-btn-group">
            <button type="button" class="custom-btn custom-btn-outline-secondary" onclick="exportReport()">
                <i class="fas fa-download me-2"></i>Export
            </button>
            <button type="button" class="custom-btn custom-btn-outline-primary" onclick="printReport()">
                <i class="fas fa-print me-2"></i>Print
            </button>
        </div>
    </div>
</div>

<!-- Report Controls -->
<div class="custom-card mb-4">
    <div class="custom-card-body">
        <form method="GET" action="">
            <div class="d-flex flex-wrap gap-2 align-items-end">
                <div style="min-width: 200px; flex: 1;">
                    <label class="form-label small text-muted">Report Type</label>
                    <select class="custom-form-select" name="report_type" onchange="this.form.submit()">
                        <option value="daily" <?php echo $report_type === 'daily' ? 'selected' : ''; ?>>Daily Report</option>
                        <option value="weekly" <?php echo $report_type === 'weekly' ? 'selected' : ''; ?>>Weekly Report</option>
                        <option value="monthly" <?php echo $report_type === 'monthly' ? 'selected' : ''; ?>>Monthly Report</option>
                        <option value="loan_performance" <?php echo $report_type === 'loan_performance' ? 'selected' : ''; ?>>Loan Performance</option>
                        <option value="client_summary" <?php echo $report_type === 'client_summary' ? 'selected' : ''; ?>>Client Summary</option>
                    </select>
                </div>
                
                <?php if (in_array($report_type, ['daily', 'weekly', 'monthly'])): ?>
                    <div style="min-width: 130px;">
                        <label class="form-label small text-muted">From Date</label>
                        <input type="date" class="custom-form-control" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>
                    <div style="min-width: 130px;">
                        <label class="form-label small text-muted">To Date</label>
                        <input type="date" class="custom-form-control" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>
                    <div class="btn-group">
                        <button type="submit" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-sync"></i> Generate
                        </button>
                        <button type="button" class="btn btn-outline-black btn-sm" onclick="clearFilters()">
                            <i class="fas fa-times"></i> Clear
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Report Content -->
<div id="reportContent">
    <?php if ($error_message): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <?php echo htmlspecialchars($error_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if ($report_type === 'daily'): ?>
        <!-- Daily Report -->
        <div class="row mb-4">
            <div class="col-md-12">
                <h4>Daily Report: <?php echo date('M d, Y', strtotime($date_from)); ?> - <?php echo date('M d, Y', strtotime($date_to)); ?></h4>
            </div>
        </div>
        
        <div class="dashboard-stats">
            <div class="dashboard-stat-col">
                <div class="stat-card primary">
                    <div class="stat-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="stat-value"><?php echo $report_data['summary']['total_payments'] ?? 0; ?></div>
                    <div class="stat-label">Total Payments</div>
                </div>
            </div>
            
            <div class="dashboard-stat-col">
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-dollar-sign"></i>
                    </div>
                    <div class="stat-value"><?php echo formatCurrency($report_data['summary']['total_collected'] ?? 0); ?></div>
                    <div class="stat-label">Total Collected</div>
                </div>
            </div>
            
            <div class="dashboard-stat-col">
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-hand-holding-usd"></i>
                    </div>
                    <div class="stat-value"><?php echo $report_data['summary']['unique_loans'] ?? 0; ?></div>
                    <div class="stat-label">Unique Loans</div>
                </div>
            </div>
            
            <div class="dashboard-stat-col">
                <div class="stat-card info">
                    <div class="stat-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-value"><?php echo formatCurrency(($report_data['summary']['total_collected'] ?? 0) / max(1, $report_data['summary']['total_payments'] ?? 1)); ?></div>
                    <div class="stat-label">Avg Payment</div>
                </div>
            </div>
        </div>
        
        <div class="custom-card mb-4">
            <div class="custom-card-header">
                <h5>Daily Collections</h5>
            </div>
            <div class="custom-card-body">
                <div class="custom-table-responsive">
                    <table class="custom-table custom-table-sm custom-table-hover">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Payments</th>
                                <th>Total Amount</th>
                                <th>Average Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($report_data['daily_collections'])): ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted">No collection data found for the selected period.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($report_data['daily_collections'] ?? [] as $day): ?>
                                    <tr>
                                        <td><?php echo date('M d, Y', strtotime($day['date'])); ?></td>
                                        <td><?php echo $day['payment_count']; ?></td>
                                        <td><?php echo formatCurrency($day['total_amount']); ?></td>
                                        <td><?php echo formatCurrency($day['avg_amount']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <div class="custom-card mb-4">
            <div class="custom-card-header">
                <h5>Daily New Loans</h5>
            </div>
            <div class="custom-card-body">
                <div class="custom-table-responsive">
                    <table class="custom-table custom-table-sm custom-table-hover">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Loans</th>
                                <th>Total Amount</th>
                                <th>Average Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($report_data['daily_loans'])): ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted">No loan data found for the selected period.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($report_data['daily_loans'] ?? [] as $day): ?>
                                    <tr>
                                        <td><?php echo date('M d, Y', strtotime($day['date'])); ?></td>
                                        <td><?php echo $day['loan_count']; ?></td>
                                        <td><?php echo formatCurrency($day['total_amount']); ?></td>
                                        <td><?php echo formatCurrency($day['avg_amount']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
    <?php elseif ($report_type === 'weekly'): ?>
        <!-- Weekly Report -->
        <div class="row mb-4">
            <div class="col-md-12">
                <h4>Weekly Report: <?php echo date('M d, Y', strtotime($date_from)); ?> - <?php echo date('M d, Y', strtotime($date_to)); ?></h4>
            </div>
        </div>
        
        <div class="custom-card mb-4">
            <div class="custom-card-header">
                <h5>Weekly Collections</h5>
            </div>
            <div class="custom-card-body">
                <div class="custom-table-responsive">
                    <table class="custom-table custom-table-sm custom-table-hover">
                        <thead>
                            <tr>
                                <th>Week</th>
                                <th>Week Start</th>
                                <th>Week End</th>
                                <th>Payments</th>
                                <th>Total Amount</th>
                                <th>Average Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($report_data['weekly_collections'])): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted">No collection data found for the selected period.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($report_data['weekly_collections'] ?? [] as $week): ?>
                                    <tr>
                                        <td><?php echo $week['week']; ?></td>
                                        <td><?php echo date('M d, Y', strtotime($week['week_start'])); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($week['week_end'])); ?></td>
                                        <td><?php echo $week['payment_count']; ?></td>
                                        <td><?php echo formatCurrency($week['total_amount']); ?></td>
                                        <td><?php echo formatCurrency($week['avg_amount']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <div class="custom-card mb-4">
            <div class="custom-card-header">
                <h5>Weekly New Loans</h5>
            </div>
            <div class="custom-card-body">
                <div class="custom-table-responsive">
                    <table class="custom-table custom-table-sm custom-table-hover">
                        <thead>
                            <tr>
                                <th>Week</th>
                                <th>Week Start</th>
                                <th>Week End</th>
                                <th>Loans</th>
                                <th>Total Amount</th>
                                <th>Average Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($report_data['weekly_loans'])): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted">No loan data found for the selected period.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($report_data['weekly_loans'] ?? [] as $week): ?>
                                    <tr>
                                        <td><?php echo $week['week']; ?></td>
                                        <td><?php echo date('M d, Y', strtotime($week['week_start'])); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($week['week_end'])); ?></td>
                                        <td><?php echo $week['loan_count']; ?></td>
                                        <td><?php echo formatCurrency($week['total_amount']); ?></td>
                                        <td><?php echo formatCurrency($week['avg_amount']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
    <?php elseif ($report_type === 'monthly'): ?>
        <!-- Monthly Report -->
        <div class="row mb-4">
            <div class="col-md-12">
                <h4>Monthly Report: <?php echo date('M d, Y', strtotime($date_from)); ?> - <?php echo date('M d, Y', strtotime($date_to)); ?></h4>
            </div>
        </div>
        
        <div class="custom-card mb-4">
            <div class="custom-card-header">
                <h5>Monthly Collections</h5>
            </div>
            <div class="custom-card-body">
                <div class="custom-table-responsive">
                    <table class="custom-table custom-table-sm custom-table-hover">
                        <thead>
                            <tr>
                                <th>Month</th>
                                <th>Payments</th>
                                <th>Total Amount</th>
                                <th>Average Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($report_data['monthly_collections'])): ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted">No collection data found for the selected period.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($report_data['monthly_collections'] ?? [] as $month): ?>
                                    <tr>
                                        <td><?php echo $month['month_name']; ?></td>
                                        <td><?php echo $month['payment_count']; ?></td>
                                        <td><?php echo formatCurrency($month['total_amount']); ?></td>
                                        <td><?php echo formatCurrency($month['avg_amount']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <div class="custom-card mb-4">
            <div class="custom-card-header">
                <h5>Monthly New Loans</h5>
            </div>
            <div class="custom-card-body">
                <div class="custom-table-responsive">
                    <table class="custom-table custom-table-sm custom-table-hover">
                        <thead>
                            <tr>
                                <th>Month</th>
                                <th>Loans</th>
                                <th>Total Amount</th>
                                <th>Average Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($report_data['monthly_loans'])): ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted">No loan data found for the selected period.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($report_data['monthly_loans'] ?? [] as $month): ?>
                                    <tr>
                                        <td><?php echo $month['month_name']; ?></td>
                                        <td><?php echo $month['loan_count']; ?></td>
                                        <td><?php echo formatCurrency($month['total_amount']); ?></td>
                                        <td><?php echo formatCurrency($month['avg_amount']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <div class="custom-card mb-4">
            <div class="custom-card-header">
                <h5>Loan Status by Month</h5>
            </div>
            <div class="custom-card-body">
                <div class="custom-table-responsive">
                    <table class="custom-table custom-table-sm custom-table-hover">
                        <thead>
                            <tr>
                                <th>Month</th>
                                <th>Status</th>
                                <th>Count</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($report_data['loan_status_by_month'])): ?>
                                <tr>
                                    <td colspan="3" class="text-center text-muted">No loan status data found for the selected period.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($report_data['loan_status_by_month'] ?? [] as $status): ?>
                                    <tr>
                                        <td><?php echo $status['month_name']; ?></td>
                                        <td><span class="badge bg-<?php echo getStatusBadgeClass($status['status']); ?>"><?php echo ucfirst($status['status']); ?></span></td>
                                        <td><?php echo $status['count']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
    <?php elseif ($report_type === 'loan_performance'): ?>
        <!-- Loan Performance Report -->
        <div class="row mb-4">
            <div class="col-md-12">
                <h4>Loan Performance Report</h4>
            </div>
        </div>
        
        <div class="custom-card mb-4">
            <div class="custom-card-header">
                <h5>Performance Metrics</h5>
            </div>
            <div class="custom-card-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="custom-table-responsive">
                            <table class="custom-table custom-table-sm">
                            <tr>
                                <td><strong>Total Loans:</strong></td>
                                <td style="text-align: end;"><?php echo $report_data['performance_metrics']['total_loans'] ?? 0; ?></td>
                            </tr>
                            <tr>
                                <td><strong>Total Loan Amount:</strong></td>
                                <td style="text-align: end;"><?php echo formatCurrency($report_data['performance_metrics']['total_loan_amount'] ?? 0); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Average Loan Amount:</strong></td>
                                <td style="text-align: end;"><?php echo formatCurrency($report_data['performance_metrics']['avg_loan_amount'] ?? 0); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Total Outstanding:</strong></td>
                                <td style="text-align: end;"><?php echo formatCurrency($report_data['performance_metrics']['total_outstanding'] ?? 0); ?></td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <div class="custom-table-responsive">
                            <table class="custom-table custom-table-sm">
                            <tr>
                                <td><strong>Completed Loans:</strong></td>
                                <td style="text-align: end;"><?php echo $report_data['performance_metrics']['completed_loans'] ?? 0; ?></td>
                            </tr>
                            <tr>
                                <td><strong>Active Loans:</strong></td>
                                <td style="text-align: end;"><?php echo $report_data['performance_metrics']['active_loans'] ?? 0; ?></td>
                            </tr>
                            <tr>
                                <td><strong>Overdue Loans:</strong></td>
                                <td style="text-align: end;"><?php echo $report_data['performance_metrics']['overdue_loans'] ?? 0; ?></td>
                            </tr>
                            <tr>
                                <td><strong>Defaulted Loans:</strong></td>
                                <td style="text-align: end;"><?php echo $report_data['performance_metrics']['defaulted_loans'] ?? 0; ?></td>
                            </tr>
                        </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="custom-card mb-4">
            <div class="custom-card-header">
                <h5>Top Clients</h5>
            </div>
            <div class="custom-card-body">
                <div class="custom-table-responsive">
                    <table class="custom-table custom-table-sm custom-table-hover">
                        <thead>
                            <tr>
                                <th>Client</th>
                                <th>Loans</th>
                                <th>Total Borrowed</th>
                                <th>Outstanding</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($report_data['top_clients'])): ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted">No client data found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($report_data['top_clients'] ?? [] as $client): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($client['first_name'] . ' ' . $client['last_name']); ?></td>
                                        <td><?php echo $client['loan_count']; ?></td>
                                        <td><?php echo formatCurrency($client['total_borrowed']); ?></td>
                                        <td><?php echo formatCurrency($client['outstanding']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
    <?php elseif ($report_type === 'client_summary'): ?>
        <!-- Client Summary Report -->
        <div class="row mb-4">
            <div class="col-md-12">
                <h4>Client Summary Report</h4>
            </div>
        </div>
        
        <div class="custom-card mb-4">
            <div class="custom-card-header">
                <h5>Client Statistics</h5>
            </div>
            <div class="custom-card-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="custom-table-responsive">
                            <table class="custom-table custom-table-sm">
                            <tr>
                                <td><strong>Total Clients:</strong></td>
                                <td style="text-align: end;"><?php echo $report_data['client_stats']['total_clients'] ?: 0; ?></td>
                            </tr>
                            <tr>
                                <td><strong>Employed:</strong></td>
                                <td style="text-align: end;"><?php echo $report_data['client_stats']['employed'] ?: 0; ?></td>
                            </tr>
                            <tr>
                                <td><strong>Self-Employed:</strong></td>
                                <td style="text-align: end;"><?php echo $report_data['client_stats']['self_employed'] ?: 0; ?></td>
                            </tr>
                            <tr>
                                <td><strong>Unemployed:</strong></td>
                                <td style="text-align: end;"><?php echo $report_data['client_stats']['unemployed'] ?: 0; ?></td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <div class="custom-table-responsive">
                            <table class="custom-table custom-table-sm">
                            <tr>
                                <td><strong>Students:</strong></td>
                                <td style="text-align: end;"><?php echo $report_data['client_stats']['student'] ?: 0; ?></td>
                            </tr>
                            <tr>
                                <td><strong>Retired:</strong></td>
                                <td style="text-align: end;"><?php echo $report_data['client_stats']['retired'] ?: 0; ?></td>
                            </tr>
                            <tr>
                                <td><strong>Average Income:</strong></td>
                                <td style="text-align: end;"><?php echo formatCurrency($report_data['client_stats']['avg_income'] ?: 0); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Income Range:</strong></td>
                                <td style="text-align: end;"><?php echo formatCurrency($report_data['client_stats']['min_income'] ?: 0) . ' - ' . formatCurrency($report_data['client_stats']['max_income'] ?: 0); ?></td>
                            </tr>
                        </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="custom-card mb-4">
            <div class="custom-card-header">
                <h5>Client Activity</h5>
            </div>
            <div class="custom-card-body">
                <div class="custom-table-responsive">
                    <table class="custom-table custom-table-sm custom-table-hover">
                        <thead>
                            <tr>
                                <th>Client</th>
                                <th>Email</th>
                                <th>Loans</th>
                                <th>Total Borrowed</th>
                                <th>Outstanding</th>
                                <th>Last Loan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($report_data['client_activity'])): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted">No client activity data found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($report_data['client_activity'] ?? [] as $client): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($client['first_name'] . ' ' . $client['last_name']); ?></td>
                                        <td><?php echo htmlspecialchars($client['email']); ?></td>
                                        <td><?php echo $client['loan_count'] ?: 0; ?></td>
                                        <td><?php echo formatCurrency($client['total_borrowed'] ?: 0); ?></td>
                                        <td><?php echo formatCurrency($client['outstanding'] ?: 0); ?></td>
                                        <td><?php echo $client['last_loan_date'] ? date('M d, Y', strtotime($client['last_loan_date'])) : 'No loans'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
    <?php endif; ?>
</div>

<script>
function clearFilters() {
    // Clear all form fields if they exist
    const dateFromField = document.querySelector('input[name="date_from"]');
    const dateToField = document.querySelector('input[name="date_to"]');
    
    if (dateFromField) dateFromField.value = '';
    if (dateToField) dateToField.value = '';
    
    // Submit the form to refresh the page with cleared filters
    document.querySelector('form').submit();
}

function exportReport() {
    const reportType = '<?php echo $report_type; ?>';
    const dateFrom = '<?php echo $date_from; ?>';
    const dateTo = '<?php echo $date_to; ?>';
    
    let exportUrl = 'api/export_report.php?report_type=' + encodeURIComponent(reportType);
    
    if (dateFrom) exportUrl += '&date_from=' + encodeURIComponent(dateFrom);
    if (dateTo) exportUrl += '&date_to=' + encodeURIComponent(dateTo);
    
    window.open(exportUrl, '_blank');
}

function printReport() {
    window.print();
}
</script>

<style>
@media print {
    .top-nav, .sidebar, .btn-group, .card-header .btn {
        display: none !important;
    }
    
    .main-content {
        margin-left: 0 !important;
    }
    
    .card {
        box-shadow: none;
        border: 1px solid #ddd;
    }
}
</style>

<?php require_once 'includes/footer.php'; ?>
