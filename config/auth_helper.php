<?php
/**
 * MicroPulse: Authentication Helper Functions
 * Provides role-based access control and user management utilities
 */

// Enhanced role checking with hierarchy
function hasPermission($permission) {
    if (!isLoggedIn()) {
        return false;
    }
    
    $userRole = $_SESSION['user_role'] ?? 'staff';
    
    // Define permission hierarchy
    $permissions = [
        'admin' => [
            'view_dashboard', 'manage_clients', 'manage_loans', 'manage_repayments',
            'view_reports', 'manage_users', 'manage_settings', 'delete_records',
            'export_data', 'import_data', 'view_audit_logs', 'system_backup'
        ],
        'staff' => [
            'view_dashboard', 'manage_clients', 'manage_loans', 'manage_repayments',
            'view_reports'
        ]
    ];
    
    return in_array($permission, $permissions[$userRole] ?? []);
}

// Check if user can access specific module
function canAccessModule($module) {
    $modulePermissions = [
        'dashboard' => 'view_dashboard',
        'clients' => 'manage_clients',
        'loans' => 'manage_loans',
        'repayments' => 'manage_repayments',
        'reports' => 'view_reports',
        'users' => 'manage_users',
        'settings' => 'manage_settings',
        'backup' => 'system_backup'
    ];
    
    $permission = $modulePermissions[$module] ?? null;
    return $permission ? hasPermission($permission) : false;
}

// Require specific permission
function requirePermission($permission) {
    if (!hasPermission($permission)) {
        $_SESSION['error_message'] = 'You do not have permission to access this resource.';
        header('Location: dashboard.php');
        exit();
    }
}

// Require module access
function requireModuleAccess($module) {
    if (!canAccessModule($module)) {
        $_SESSION['error_message'] = 'You do not have permission to access this module.';
        header('Location: dashboard.php');
        exit();
    }
}

// Check if user can perform action on specific record
function canPerformAction($action, $recordType, $recordId = null) {
    if (!isLoggedIn()) {
        return false;
    }
    
    $userId = $_SESSION['user_id'];
    $userRole = $_SESSION['user_role'];
    
    // Admins can do everything
    if ($userRole === 'admin') {
        return true;
    }
    
    // Staff limitations
    switch ($action) {
        case 'create':
            return hasPermission("manage_{$recordType}");
            
        case 'edit':
            return hasPermission("manage_{$recordType}");
            
        case 'delete':
            // Staff cannot delete records
            return false;
            
        case 'view':
            return hasPermission("manage_{$recordType}");
            
        default:
            return false;
    }
}

// Get user statistics for dashboard
function getUserStatistics() {
    if (!isLoggedIn()) {
        return null;
    }
    
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        $userId = $_SESSION['user_id'];
        $userRole = $_SESSION['user_role'];
        
        $stats = [];
        
        if ($userRole === 'admin') {
            // Admin can see all statistics
            $queries = [
                'total_clients' => "SELECT COUNT(*) FROM clients",
                'total_loans' => "SELECT COUNT(*) FROM loans",
                'active_loans' => "SELECT COUNT(*) FROM loans WHERE status = 'active'",
                'total_repayments' => "SELECT COUNT(*) FROM repayments",
                'total_users' => "SELECT COUNT(*) FROM users",
                'active_users' => "SELECT COUNT(*) FROM users WHERE is_active = 1"
            ];
        } else {
            // Staff can only see their own statistics
            $queries = [
                'total_clients' => "SELECT COUNT(*) FROM clients WHERE created_by = ?",
                'total_loans' => "SELECT COUNT(*) FROM loans WHERE created_by = ?",
                'active_loans' => "SELECT COUNT(*) FROM loans WHERE created_by = ? AND status = 'active'",
                'total_repayments' => "SELECT COUNT(*) FROM repayments WHERE created_by = ?"
            ];
        }
        
        foreach ($queries as $key => $query) {
            $stmt = $db->prepare($query);
            if ($userRole !== 'admin') {
                $stmt->execute([$userId]);
            } else {
                $stmt->execute();
            }
            $stats[$key] = $stmt->fetchColumn();
        }
        
        return $stats;
        
    } catch (Exception $e) {
        error_log("Error getting user statistics: " . $e->getMessage());
        return null;
    }
}

// Check if user account is locked due to failed login attempts
function isUserLocked($username) {
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        $query = "SELECT login_attempts, last_attempt_time FROM users WHERE username = ? OR email = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();
        
        if (!$user) {
            return false;
        }
        
        if ($user['login_attempts'] >= MAX_LOGIN_ATTEMPTS) {
            $lockTime = strtotime($user['last_attempt_time']) + LOGIN_LOCKOUT_TIME;
            if (time() < $lockTime) {
                return true;
            }
        }
        
        return false;
        
    } catch (Exception $e) {
        error_log("Error checking user lock status: " . $e->getMessage());
        return false;
    }
}

// Reset login attempts after successful login
function resetLoginAttempts($userId) {
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        $query = "UPDATE users SET login_attempts = 0, last_attempt_time = NULL WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$userId]);
        
    } catch (Exception $e) {
        error_log("Error resetting login attempts: " . $e->getMessage());
    }
}

// Increment login attempts after failed login
function incrementLoginAttempts($username) {
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        $query = "UPDATE users SET login_attempts = login_attempts + 1, last_attempt_time = NOW() 
                  WHERE username = ? OR email = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$username, $username]);
        
    } catch (Exception $e) {
        error_log("Error incrementing login attempts: " . $e->getMessage());
    }
}

// Validate password strength
function validatePasswordStrength($password) {
    $errors = [];
    
    if (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long";
    }
    
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter";
    }
    
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = "Password must contain at least one lowercase letter";
    }
    
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one number";
    }
    
    if (!preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password)) {
        $errors[] = "Password must contain at least one special character";
    }
    
    return $errors;
}

// Generate secure random password
function generateSecurePassword($length = 12) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
    return substr(str_shuffle($chars), 0, $length);
}

// Check if user session is still valid
function isSessionValid() {
    if (!isLoggedIn()) {
        return false;
    }
    
    // Check session lifetime
    $loginTime = $_SESSION['login_time'] ?? 0;
    if (time() - $loginTime > SESSION_LIFETIME) {
        session_destroy();
        return false;
    }
    
    return true;
}

// Extend session lifetime
function extendSession() {
    if (isLoggedIn()) {
        $_SESSION['login_time'] = time();
    }
}

// Get user's full permissions list
function getUserPermissions() {
    if (!isLoggedIn()) {
        return [];
    }
    
    $userRole = $_SESSION['user_role'] ?? 'staff';
    
    $permissions = [
        'admin' => [
            'view_dashboard', 'manage_clients', 'manage_loans', 'manage_repayments',
            'view_reports', 'manage_users', 'manage_settings', 'delete_records',
            'export_data', 'import_data', 'view_audit_logs', 'system_backup'
        ],
        'staff' => [
            'view_dashboard', 'manage_clients', 'manage_loans', 'manage_repayments',
            'view_reports'
        ]
    ];
    
    return $permissions[$userRole] ?? [];
}

// Log user activity with more details
function logUserActivity($action, $details = []) {
    if (!isLoggedIn()) {
        return;
    }
    
    $userId = $_SESSION['user_id'];
    $defaultDetails = [
        'ip_address' => $_SERVER['REMOTE_ADDR'],
        'user_agent' => $_SERVER['HTTP_USER_AGENT'],
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    $logData = array_merge($defaultDetails, $details);
    
    logActivity($userId, $action, null, null, null, json_encode($logData));
}
?>
