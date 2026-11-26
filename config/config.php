<?php
/**
 * MicroPulse: Real-Time Loan Management Platform
 * Application Configuration
 */

// Include database configuration
require_once 'database.php';

// Application settings
define('APP_NAME', 'MicroPulse');
define('APP_VERSION', '1.0.0');
define('APP_URL', 'http://localhost/micropulse');

// Security settings
define('SESSION_LIFETIME', 3600); // 1 hour in seconds
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15 minutes in seconds

// Database settings
define('DB_HOST', 'localhost');
define('DB_NAME', 'micropulse');
define('DB_USER', 'root');
define('DB_PASS', '');

// Email settings (for future notifications)
define('SMTP_HOST', 'localhost');
define('SMTP_PORT', 587);
define('SMTP_USER', '');
define('SMTP_PASS', '');

// File upload settings
define('MAX_FILE_SIZE', 5242880); // 5MB in bytes
define('UPLOAD_PATH', 'uploads/');

// Date/Time settings
define('DATE_FORMAT', 'Y-m-d');
define('TIME_FORMAT', 'H:i:s');
define('DATETIME_FORMAT', 'Y-m-d H:i:s');

// Currency settings
define('CURRENCY', 'MXN');
define('CURRENCY_SYMBOL', '₱');

// Pagination settings
define('ITEMS_PER_PAGE', 20);

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start secure session
function startSecureSession() {
    // Only start session if not already active
    if (session_status() === PHP_SESSION_NONE) {
        $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $httponly = true;
        
        // Set session cookie parameters
        session_set_cookie_params(SESSION_LIFETIME, '/', '', $secure, $httponly);
        session_name('micropulse_session');
        session_start();
        
        // Regenerate session ID to prevent session fixation
        if (!isset($_SESSION['initiated'])) {
            session_regenerate_id(true);
            $_SESSION['initiated'] = true;
        }
        
        // Check if user agent is consistent (less strict validation)
        if (isset($_SESSION['user_agent'])) {
            // Only validate if HTTP_USER_AGENT is set (avoid command line issues)
            if (isset($_SERVER['HTTP_USER_AGENT']) && $_SESSION['user_agent'] !== $_SERVER['HTTP_USER_AGENT']) {
                // Don't destroy session automatically, just log the difference
                error_log("User agent changed: " . $_SESSION['user_agent'] . " -> " . $_SERVER['HTTP_USER_AGENT']);
                $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT']; // Update to new agent
            }
        } else {
            // Only set user agent if it exists
            if (isset($_SERVER['HTTP_USER_AGENT'])) {
                $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
            }
        }
    }
}

// CSRF token generation
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// CSRF token validation
function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Input sanitization
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

// Password hashing
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

// Password verification
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

// Format currency
function formatCurrency($amount) {
    return CURRENCY_SYMBOL . number_format($amount, 2);
}

// Calculate loan interest
function calculateLoanInterest($principal, $rate, $months) {
    $monthlyRate = $rate / 100 / 12;
    $totalPayable = $principal * (1 + ($monthlyRate * $months));
    $monthlyPayment = $totalPayable / $months;
    
    return [
        'total_payable' => round($totalPayable, 2),
        'monthly_payment' => round($monthlyPayment, 2)
    ];
}

// Log system activity
function logActivity($userId, $action, $tableName = null, $recordId = null, $oldValues = null, $newValues = null) {
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        $query = "INSERT INTO system_logs (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent) 
                  VALUES (:user_id, :action, :table_name, :record_id, :old_values, :new_values, :ip_address, :user_agent)";
        
        $stmt = $db->prepare($query);
        
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':action', $action);
        $stmt->bindParam(':table_name', $tableName);
        $stmt->bindParam(':record_id', $recordId);
        
        $oldValuesJson = $oldValues ? json_encode($oldValues) : null;
        $newValuesJson = $newValues ? json_encode($newValues) : null;
        
        $stmt->bindParam(':old_values', $oldValuesJson);
        $stmt->bindParam(':new_values', $newValuesJson);
        
        $ipAddress = $_SERVER['REMOTE_ADDR'];
        $userAgent = $_SERVER['HTTP_USER_AGENT'];
        
        $stmt->bindParam(':ip_address', $ipAddress);
        $stmt->bindParam(':user_agent', $userAgent);
        
        $stmt->execute();
    } catch (Exception $e) {
        error_log("Logging error: " . $e->getMessage());
    }
}

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

// Check if user has specific role
function hasRole($role) {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === $role;
}

// Redirect if not logged in
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit();
    }
}

// Redirect if not admin (allowing staff for settings access)
function requireAdmin() {
    requireLogin();
    if (!hasRole('admin') && !hasRole('staff')) {
        header('Location: dashboard.php');
        exit();
    }
}

// Auto-start secure session when config is loaded
startSecureSession();
?>
