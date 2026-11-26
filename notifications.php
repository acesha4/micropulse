<?php
$page_title = 'Notifications';
require_once 'includes/header.php';

require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    die("Database connection failed. Please check your configuration.");
}

$success_message = null;
$error_message = null;

// Helper function to format date
function formatDate($dateString) {
    $date = new DateTime($dateString);
    $now = new DateTime();
    $diff = $now->getTimestamp() - $date->getTimestamp();
    
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' minutes ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
    return $date->format('M j, Y');
}

// Handle marking notifications as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'mark_all_read') {
        try {
            // Mark all payment notifications as completed
            $_SESSION['notifications_marked_read'] = true;
            $_SESSION['marked_read_time'] = date('Y-m-d H:i:s');
            $success_message = "All notifications marked as read successfully!";
        } catch (Exception $e) {
            $error_message = "Error marking notifications as read: " . $e->getMessage();
        }
    } elseif ($_POST['action'] === 'mark_notification_read' && isset($_POST['notification_id'])) {
        try {
            $notification_id = $_POST['notification_id'];
            
            // Use session to track individual notification completion
            if (!isset($_SESSION['completed_notifications'])) {
                $_SESSION['completed_notifications'] = [];
            }
            $_SESSION['completed_notifications'][$notification_id] = date('Y-m-d H:i:s');
            
            $success_message = "Notification marked as complete successfully!";
        } catch (Exception $e) {
            $error_message = "Error marking notification as complete: " . $e->getMessage();
        }
    }
}

// Get notifications for display
$notifications = [];
$total_count = 0;

// Get completed notifications from session
$completed_notifications = $_SESSION['completed_notifications'] ?? [];
$all_marked_read = $_SESSION['notifications_marked_read'] ?? false;
$marked_read_time = $_SESSION['marked_read_time'] ?? null;

try {
    // Check for new loan requests (pending loans)
    $query = "SELECT COUNT(*) as count FROM loans WHERE status = 'pending'";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $pending_loans = $stmt->fetch()['count'];
    
    if ($pending_loans > 0 && !isset($completed_notifications['loan_requests'])) {
        $notifications[] = [
            'id' => 'loan_requests',
            'message' => $pending_loans . ' loan request(s) pending approval',
            'type' => 'primary',
            'icon' => 'fa-hand-holding-usd',
            'action_url' => 'loans.php?status=pending',
            'timestamp' => date('Y-m-d H:i:s')
        ];
        $total_count += $pending_loans;
    }
    
    // Check for recent payment transactions (last hour)
    if (!isset($completed_notifications['recent_payments']) && !$all_marked_read) {
        $query = "SELECT COUNT(*) as count, SUM(payment_amount) as total, 
                  GROUP_CONCAT(DISTINCT CONCAT(c.first_name, ' ', c.last_name) SEPARATOR ', ') as clients
                  FROM repayments r 
                  JOIN loans l ON r.loan_id = l.id 
                  JOIN clients c ON l.client_id = c.id 
                  WHERE r.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $recent_payments = $stmt->fetch();
        
        if ($recent_payments && $recent_payments['count'] > 0) {
            $client_names = $recent_payments['clients'] ? ' from ' . $recent_payments['clients'] : '';
            $notifications[] = [
                'id' => 'recent_payments',
                'message' => $recent_payments['count'] . ' payment(s) received' . $client_names . ' (' . formatCurrency($recent_payments['total']) . ')',
                'type' => 'success',
                'icon' => 'fa-money-bill-wave',
                'action_url' => 'repayments.php',
                'timestamp' => date('Y-m-d H:i:s')
            ];
            $total_count += $recent_payments['count'];
        }
    }
    
    // Check for payments today
    if (!isset($completed_notifications['today_payments']) && !$all_marked_read) {
        $query = "SELECT COUNT(*) as count, SUM(payment_amount) as total 
                  FROM repayments 
                  WHERE payment_date = CURDATE()";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $today_payments = $stmt->fetch();
        
        if ($today_payments && $today_payments['count'] > 0) {
            $notifications[] = [
                'id' => 'today_payments',
                'message' => $today_payments['count'] . ' payment(s) collected today (' . formatCurrency($today_payments['total']) . ')',
                'type' => 'info',
                'icon' => 'fa-calendar-check',
                'action_url' => 'repayments.php',
                'timestamp' => date('Y-m-d H:i:s')
            ];
            $total_count += $today_payments['count'];
        }
    }
    
} catch (Exception $e) {
    $error_message = "Error loading notifications: " . $e->getMessage();
    error_log("Notifications query error: " . $e->getMessage());
}
?>

<!-- Notifications Header -->
<div class="page-header">
    <div class="page-header-content">
        <h1 class="page-title">Notifications</h1>
        <p class="page-subtitle">View all system notifications and alerts</p>
    </div>
</div>

<!-- Notifications Content -->
<div class="page-content">
    <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($error_message); ?>
            <button type="button" class="btn-close" onclick="this.parentElement.remove()"></button>
        </div>
    <?php endif; ?>
    
    <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($success_message); ?>
            <button type="button" class="btn-close" onclick="this.parentElement.remove()"></button>
        </div>
    <?php endif; ?>
    
    <!-- Notifications Summary -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-bell me-2"></i>
                        Notifications Summary
                        <?php if ($total_count > 0): ?>
                            <span class="badge bg-danger ms-2"><?php echo $total_count; ?></span>
                        <?php endif; ?>
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (empty($notifications)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-bell-slash fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">No notifications</h5>
                            <p class="text-muted">You're all caught up! No new notifications at this time.</p>
                        </div>
                    <?php else: ?>
                        <div class="row">
                            <div class="col-md-3">
                                <div class="text-center">
                                    <h3 class="text-primary"><?php echo $total_count; ?></h3>
                                    <p class="text-muted mb-0">Total Notifications</p>
                                </div>
                            </div>
                            <div class="col-md-9">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <span class="badge bg-primary me-2">Loan Requests: <?php echo (!isset($completed_notifications['loan_requests']) && ($pending_loans ?? 0) > 0) ? $pending_loans : 0; ?></span>
                                        <span class="badge bg-success">Payments: <?php echo ((!isset($completed_notifications['recent_payments']) && !$all_marked_read ? ($recent_payments['count'] ?? 0) : 0) + (!isset($completed_notifications['today_payments']) && !$all_marked_read ? ($today_payments['count'] ?? 0) : 0)); ?></span>
                                    </div>
                                    <div class="btn-group">
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="mark_all_read">
                                            <button type="submit" class="btn btn-outline-success btn-sm" onclick="return confirm('Mark all notifications as read?')">
                                                <i class="fas fa-check-double me-1"></i>Mark All as Read
                                            </button>
                                        </form>
                                        <button class="btn btn-outline-primary btn-sm" onclick="location.reload()">
                                            <i class="fas fa-sync-alt me-1"></i>Refresh
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Notifications List -->
    <?php if (!empty($notifications)): ?>
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-list me-2"></i>
                        All Notifications
                    </h5>
                </div>
                <div class="card-body">
                    <div class="notifications-list">
                        <?php foreach ($notifications as $notification): ?>
                            <div class="notification-item">
                                <div class="notification-icon">
                                    <i class="fas <?php echo htmlspecialchars($notification['icon']); ?> text-<?php echo htmlspecialchars($notification['type']); ?>"></i>
                                    <?php if (isset($notification['symbol'])): ?>
                                        <span class="notification-symbol-badge"><?php echo htmlspecialchars($notification['symbol']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="notification-content">
                                    <div class="notification-message">
                                        <?php echo htmlspecialchars($notification['message']); ?>
                                    </div>
                                    <div class="notification-time">
                                        <i class="fas fa-clock me-1"></i>
                                        <?php echo formatDate($notification['timestamp']); ?>
                                    </div>
                                </div>
                                <div class="notification-actions">
                                    <?php if (in_array($notification['id'], ['recent_payments', 'today_payments'])): ?>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Mark this notification as complete?')">
                                            <input type="hidden" name="action" value="mark_notification_read">
                                            <input type="hidden" name="notification_id" value="<?php echo htmlspecialchars($notification['id']); ?>">
                                            <button type="submit" class="btn btn-outline-success btn-sm me-2" title="Mark as Complete">
                                                <i class="fas fa-check"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <a href="<?php echo htmlspecialchars($notification['action_url']); ?>" class="btn btn-outline-primary btn-sm" title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Quick Actions -->
    <div class="row mt-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-bolt me-2"></i>
                        Quick Actions
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <a href="loans.php?status=pending" class="btn btn-outline-primary w-100">
                                <i class="fas fa-hand-holding-usd me-2"></i>
                                View Loan Requests
                            </a>
                        </div>
                        <div class="col-md-6 mb-3">
                            <a href="repayments.php" class="btn btn-outline-success w-100">
                                <i class="fas fa-money-bill-wave me-2"></i>
                                View Repayments
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.notification-item {
    display: flex;
    align-items: center;
    padding: 15px;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    margin-bottom: 10px;
    cursor: pointer;
    transition: all 0.3s ease;
    background: white;
}

.notification-item:hover {
    background: #f8f9fa;
    border-color: #dee2e6;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.notification-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f8f9fa;
    margin-right: 15px;
    flex-shrink: 0;
}

.notification-icon i {
    font-size: 18px;
}

.notification-icon .fa-hand-holding-usd {
    color: #007bff;
}

.notification-icon .fa-money-bill-wave {
    color: #28a745;
}

.notification-icon .fa-exclamation-triangle {
    color: #ffc107;
}

.notification-icon .fa-exclamation-circle {
    color: #dc3545;
}

.notification-icon .fa-info-circle {
    color: #17a2b8;
}

.notification-content {
    flex: 1;
    min-width: 0;
}

.notification-message {
    font-weight: 500;
    color: #212529;
    margin-bottom: 5px;
}

.notification-time {
    font-size: 12px;
    color: #6c757d;
}

.notification-action {
    margin-left: 15px;
    color: #6c757d;
    transition: color 0.3s ease;
}

.notification-item:hover .notification-action {
    color: #007bff;
}

.notification-actions {
    margin-left: 15px;
    display: flex;
    gap: 8px;
    align-items: center;
}

.notification-actions .btn {
    padding: 4px 8px;
    font-size: 12px;
    border-radius: 4px;
}

.notifications-list {
    max-height: 500px;
    overflow-y: auto;
}
</style>

<?php require_once 'includes/footer.php'; ?>
