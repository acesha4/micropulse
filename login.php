<?php
require_once 'config/config.php';
require_once 'config/database.php';

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitizeInput($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    // Validate CSRF token
    if (!validateCSRFToken($csrf_token)) {
        $error = 'Invalid request. Please try again.';
    } elseif (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        try {
            $database = new Database();
            $db = $database->getConnection();
            
            if (!$db) {
                $error = 'Database connection failed. Please try again.';
                error_log("Database connection failed during login");
            } else {
            
            $query = "SELECT id, username, email, password_hash, full_name, role, is_active, last_login 
                      FROM users WHERE username = ? OR email = ? LIMIT 1";
            
            $stmt = $db->prepare($query);
            $stmt->execute([$username, $username]);
            
            $user = $stmt->fetch();
            
            if ($user && verifyPassword($password, $user['password_hash'])) {
                if (!$user['is_active']) {
                    $error = 'Your account has been deactivated. Please contact administrator.';
                } else {
                    // Login successful - set session
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['user_role'] = $user['role'];
                    $_SESSION['last_login'] = $user['last_login'];
                    $_SESSION['login_time'] = time();
                    
                    // Update last login
                    $updateQuery = "UPDATE users SET last_login = NOW() WHERE id = :id";
                    $updateStmt = $db->prepare($updateQuery);
                    $updateStmt->bindParam(':id', $user['id']);
                    $updateStmt->execute();
                    
                    // Log activity
                    logActivity($user['id'], 'login', 'users', $user['id']);
                    
                    // Redirect to dashboard
                    header('Location: dashboard.php');
                    exit();
                }
            } else {
                $error = 'Invalid username or password.';
                error_log("Login failed for username: $username");
            }
            }
        } catch (Exception $e) {
            $error = 'Login failed. Please try again.';
            error_log("Login error: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo APP_NAME; ?></title>
    <link href="assets/css/style.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="login-bg">
    <div class="login-container">
        <div class="login-card">
            <div class="login-content login-content-md">
                <div class="login-left login-left-md">
                    <div class="login-brand login-brand-md">
                        <div class="login-header">
                            <i class="fas fa-chart-line login-brand-icon login-brand-icon-md"></i>
                            <h2 class="login-brand-title login-brand-title-md">MicroPulse</h2>
                            <p class="login-brand-subtitle">Real-Time Loan Management Platform</p>
                        </div>
                    </div>
                </div>
                <div class="login-right login-right-md">
                    <div class="login-form-container login-form-container-md">
                        <div class="login-header">
                            <h3 style="color: #FFFFFF;" class="login-title">Welcome Back</h3>
                            <p style="color: #FFFFFF;" class="login-subtitle">Sign in to your account</p>
                        </div>
                        
                        <?php if ($error): ?>
                            <div class="login-alert login-alert-danger">
                                <?php echo $error; ?>
                                <button type="button" class="login-alert-close" onclick="this.parentElement.remove()">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($success): ?>
                            <div class="login-alert login-alert-success">
                                <?php echo $success; ?>
                                <button type="button" class="login-alert-close" onclick="this.parentElement.remove()">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            
                            <div class="login-form-group">
                                <label style="color: #FFFFFF;" for="username" class="login-label">Username or Email</label>
                                <div class="login-input-group">
                                    <div class="login-input-icon">
                                        <i class="fas fa-user"></i>
                                    </div>
                                    <input type="text" class="login-input" id="username" name="username" 
                                           value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>" 
                                           required>
                                </div>
                            </div>
                            
                            <div class="login-form-group">
                                <label style="color: #FFFFFF;" for="password" class="login-label">Password</label>
                                <div class="login-input-group">
                                    <div class="login-input-icon">
                                        <i class="fas fa-lock"></i>
                                    </div>
                                    <input type="password" class="login-input" id="password" name="password" required>
                                </div>
                            </div>
                            
                            <div class="login-checkbox-group">
                                <input type="checkbox" class="login-checkbox" id="remember">
                                <label style="color: #FFFFFF;" for="remember" class="login-checkbox-label">
                                    Remember me
                                </label>
                            </div>
                            
                            <div>
                                <button type="submit" class="login-button">
                                    <i style="margin-right: 10px;" class="fas fa-sign-in-alt"></i>Sign In
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
