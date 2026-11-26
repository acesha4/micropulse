<?php
require_once 'config/config.php';
requireLogin();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title . ' - ' : ''; ?><?php echo APP_NAME; ?></title>
    
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
    <!-- Sidebar Navigation -->
    <nav class="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <i class="fas fa-chart-line"></i>
                <span>MicroPulse</span>
            </div>
        </div>
        
        <ul class="sidebar-menu">
            <li class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">
                <a href="dashboard.php">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            
            <li class="<?php echo basename($_SERVER['PHP_SELF']) == 'clients.php' ? 'active' : ''; ?>">
                <a href="clients.php">
                    <i class="fas fa-users"></i>
                    <span>Clients</span>
                </a>
            </li>
            
            <li class="<?php echo basename($_SERVER['PHP_SELF']) == 'loans.php' ? 'active' : ''; ?>">
                <a href="loans.php">
                    <i class="fas fa-hand-holding-usd"></i>
                    <span>Loans</span>
                </a>
            </li>
            
            <li class="<?php echo basename($_SERVER['PHP_SELF']) == 'repayments.php' ? 'active' : ''; ?>">
                <a href="repayments.php">
                    <i class="fas fa-money-bill-wave"></i>
                    <span>Repayments</span>
                </a>
            </li>
            
            <li class="<?php echo basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'active' : ''; ?>">
                <a href="reports.php">
                    <i class="fas fa-chart-bar"></i>
                    <span>Reports</span>
                </a>
            </li>
            
            <?php if (hasRole('admin')): ?>
            <li class="<?php echo basename($_SERVER['PHP_SELF']) == 'users.php' ? 'active' : ''; ?>">
                <a href="users.php">
                    <i class="fas fa-user-shield"></i>
                    <span>User Management</span>
                </a>
            </li>
            <?php endif; ?>
        </ul>
    </nav>
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Navigation -->
        <nav class="top-nav">
            <div class="nav-left">
                <button class="sidebar-toggle" id="sidebarToggle">
                    <i class="fas fa-bars"></i>
                </button>
            </div>
            
            <div class="nav-right">
                <div class="relative">
                    <button class="custom-btn custom-btn-outline dropdown-toggle" type="button" id="userDropdown" onclick="toggleDropdown()">
                        <i class="fas fa-user-circle mr-2"></i>
                        <span style="color: white;"><?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                        <span class="custom-badge bg-<?php echo $_SESSION['user_role'] == 'admin' ? 'danger' : 'primary'; ?> ms-2">
                            <?php echo ucfirst($_SESSION['user_role']); ?>
                        </span>
                        <i class="fa-solid fa-angle-down"></i>
                    </button>
                    <ul id="dropdownMenu" class="custom-dropdown-menu custom-dropdown-menu-end hidden absolute right-0 mt-2 w-48 bg-black rounded-lg shadow-lg z-50">
                        <li><a class="custom-dropdown-item block px-4 py-2 text-gray-800 hover:bg-gray-100" href="user_profile.php"><i class="fas fa-user me-2"></i>Profile</a></li>
                        <li><hr class="custom-dropdown-divider border-t border-gray-200"></li>
                        <li><a class="custom-dropdown-item block px-4 py-2 text-white-800 hover:bg-gray-100" href="logout.php" onclick="return confirm('Are you sure you want to logout?');"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </nav>
        
        <!-- Page Content -->
        <div class="page-content">
            <?php if (isset($success_message)): ?>
                <div class="custom-alert custom-alert-success custom-alert-dismissible fade show" role="alert">
                    <?php echo $success_message; ?>
                    <button type="button" class="custom-btn-close" onclick="this.parentElement.remove()"></button>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                <div class="custom-alert custom-alert-danger custom-alert-dismissible fade show" role="alert">
                    <?php echo $error_message; ?>
                    <button type="button" class="custom-btn-close" onclick="this.parentElement.remove()"></button>
                </div>
            <?php endif; ?>
