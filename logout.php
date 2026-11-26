<?php
require_once 'config/config.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    startSecureSession();
}

try {
    // Log the logout activity before destroying session
    if (isLoggedIn()) {
        $userId = $_SESSION['user_id'];
        logActivity($userId, 'logout', 'users', $userId);
    }

    // Destroy all session data
    session_destroy();

    // Delete session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }

    // Clear all session variables
    $_SESSION = array();

    // Check if this is an AJAX request
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        // Return JSON response for AJAX requests
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'redirect' => 'login.php']);
        exit();
    }

    // Redirect to login page for regular requests
    header('Location: login.php');
    exit();
    
} catch (Exception $e) {
    // Log error and redirect anyway
    error_log("Logout error: " . $e->getMessage());
    
    // Even if logging fails, still destroy session and redirect
    session_destroy();
    $_SESSION = array();
    
    // Check if this is an AJAX request
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'redirect' => 'login.php']);
        exit();
    }
    
    header('Location: login.php');
    exit();
}
?>
