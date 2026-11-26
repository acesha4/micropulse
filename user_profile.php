<?php
require_once 'config/config.php';
require_once 'config/auth_helper.php';

// Check login
requireLogin();

// Initialize variables
$success = '';
$error = '';
$user = null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!validateCSRFToken($csrf_token)) {
        $error = 'Invalid request. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        
        try {
            $database = new Database();
            $db = $database->getConnection();
            
            switch ($action) {
                case 'update_profile':
                    $full_name = sanitizeInput($_POST['full_name'] ?? '');
                    $email = sanitizeInput($_POST['email'] ?? '');
                    $current_password = $_POST['current_password'] ?? '';
                    
                    if (empty($full_name) || empty($email)) {
                        $error = 'Full name and email are required.';
                    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $error = 'Invalid email format.';
                    } else {
                        // Verify current password
                        $query = "SELECT password_hash FROM users WHERE id = ?";
                        $stmt = $db->prepare($query);
                        $stmt->execute([$_SESSION['user_id']]);
                        $user_data = $stmt->fetch();
                        
                        if (!verifyPassword($current_password, $user_data['password_hash'])) {
                            $error = 'Current password is incorrect.';
                        } else {
                            // Check if email already exists for other users
                            $checkQuery = "SELECT id FROM users WHERE email = ? AND id != ?";
                            $checkStmt = $db->prepare($checkQuery);
                            $checkStmt->execute([$email, $_SESSION['user_id']]);
                            
                            if ($checkStmt->fetch()) {
                                $error = 'Email already exists.';
                            } else {
                                // Handle profile image upload if provided
                                if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] !== UPLOAD_ERR_NO_FILE) {
                                    $file = $_FILES['profile_image'];
                                    
                                    if ($file['error'] === UPLOAD_ERR_OK) {
                                        if ($file['size'] > MAX_FILE_SIZE) {
                                            $error = 'Profile picture is too large.';
                                            break;
                                        }
                                        
                                        $allowedExt = ['jpg', 'jpeg', 'png'];
                                        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                                        if (!in_array($ext, $allowedExt, true)) {
                                            $error = 'Invalid profile picture format. Only JPG and PNG are allowed.';
                                            break;
                                        }
                                        
                                        $profileDir = rtrim(UPLOAD_PATH, '/\\') . '/profiles/';
                                        if (!is_dir($profileDir)) {
                                            @mkdir($profileDir, 0755, true);
                                        }
                                        
                                        $destination = $profileDir . 'user_' . $_SESSION['user_id'] . '.' . $ext;
                                        
                                        // Remove old files with different extensions
                                        foreach (['jpg', 'jpeg', 'png'] as $oldExt) {
                                            $oldFile = $profileDir . 'user_' . $_SESSION['user_id'] . '.' . $oldExt;
                                            if ($oldExt !== $ext && file_exists($oldFile)) {
                                                @unlink($oldFile);
                                            }
                                        }
                                        
                                        if (!move_uploaded_file($file['tmp_name'], $destination)) {
                                            $error = 'Failed to upload profile picture.';
                                            break;
                                        }
                                    } else {
                                        $error = 'Error uploading profile picture.';
                                        break;
                                    }
                                }
                                
                                if (empty($error)) {
                                    // Update profile
                                    $updateQuery = "UPDATE users SET full_name = ?, email = ? WHERE id = ?";
                                    $updateStmt = $db->prepare($updateQuery);
                                    $updateStmt->execute([$full_name, $email, $_SESSION['user_id']]);
                                    
                                    // Update session
                                    $_SESSION['full_name'] = $full_name;
                                    $_SESSION['email'] = $email;
                                    
                                    logUserActivity('profile_updated', ['fields_updated' => ['full_name', 'email', 'profile_image']]);
                                    $success = 'Profile updated successfully.';
                                }
                            }
                        }
                    }
                    break;
                    
                case 'change_password':
                    $current_password = $_POST['current_password'] ?? '';
                    $new_password = $_POST['new_password'] ?? '';
                    $confirm_password = $_POST['confirm_password'] ?? '';
                    
                    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
                        $error = 'All password fields are required.';
                    } elseif ($new_password !== $confirm_password) {
                        $error = 'New password and confirmation do not match.';
                    } else {
                        $password_errors = validatePasswordStrength($new_password);
                        if (!empty($password_errors)) {
                            $error = implode(' ', $password_errors);
                        } else {
                            // Verify current password
                            $query = "SELECT password_hash FROM users WHERE id = ?";
                            $stmt = $db->prepare($query);
                            $stmt->execute([$_SESSION['user_id']]);
                            $user_data = $stmt->fetch();
                            
                            if (!verifyPassword($current_password, $user_data['password_hash'])) {
                                $error = 'Current password is incorrect.';
                            } else {
                                // Update password
                                $password_hash = hashPassword($new_password);
                                $updateQuery = "UPDATE users SET password_hash = ? WHERE id = ?";
                                $updateStmt = $db->prepare($updateQuery);
                                $updateStmt->execute([$password_hash, $_SESSION['user_id']]);
                                
                                logUserActivity('password_changed');
                                $success = 'Password changed successfully.';
                            }
                        }
                    }
                    break;
            }
        } catch (Exception $e) {
            $error = 'Operation failed: ' . $e->getMessage();
            error_log("Profile update error: " . $e->getMessage());
        }
    }
}

// Fetch current user data
try {
    $database = new Database();
    $db = $database->getConnection();
    
    $query = "SELECT id, username, email, full_name, role, is_active, created_at, last_login 
              FROM users WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    // Determine profile image path (file-based, no DB change)
    $profile_image = null;
    if ($user && isset($user['id'])) {
        $profileDir = rtrim(UPLOAD_PATH, '/\\') . '/profiles/';
        $webProfileDir = $profileDir; // same relative path for web
        $extensions = ['jpg', 'jpeg', 'png'];
        foreach ($extensions as $ext) {
            $candidate = $profileDir . 'user_' . $user['id'] . '.' . $ext;
            if (file_exists($candidate)) {
                $profile_image = $webProfileDir . 'user_' . $user['id'] . '.' . $ext;
                break;
            }
        }
    }
} catch (Exception $e) {
    error_log("Error fetching user profile: " . $e->getMessage());
}
?>

<?php
$page_title = 'User Profile';
require_once 'includes/header.php';
?>
    <div class="profile-container">
        <div class="d-flex flex-wrap">
            <div class="w-full">
                <div class="profile-header">
                    <div class="profile-avatar">
                        <?php if (!empty($profile_image)): ?>
                            <img src="<?php echo htmlspecialchars($profile_image); ?>" alt="Profile Picture" class="img-fluid rounded-circle" style="width: 100%; height: 100%; object-fit: cover;">
                        <?php else: ?>
                            <i class="fas fa-user"></i>
                        <?php endif; ?>
                    </div>
                    <h2><?php echo htmlspecialchars($user['full_name']); ?></h2>
                    <p class="mb-1"><?php echo htmlspecialchars($user['email']); ?></p>
                    <span class="custom-badge bg-<?php echo $user['role'] === 'admin' ? 'danger' : 'primary'; ?> fs-6">
                        <?php echo ucfirst($user['role']); ?>
                    </span>
                </div>
            </div>
        </div>
        
        <div class="d-flex flex-wrap" style="margin: 0 -8px;">
            <div class="profile-sidebar">
                <div class="profile-card">
                    <div class="profile-card-header">
                        <ul class="profile-tabs" id="profileTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="profile-tab-button active" id="profile-tab" data-tab="profile-content" type="button" role="tab">
                                    <i class="fas fa-user mr-2"></i>Profile Information
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="profile-tab-button inactive" id="password-tab" data-tab="password-content" type="button" role="tab">
                                    <i class="fas fa-key mr-2"></i>Change Password
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="activity-tab" data-bs-toggle="tab" data-bs-target="#activity-content" type="button" role="tab">
                                    <i class="fas fa-history me-2"></i>Recent Activity
                                </button>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <div class="profile-content">
                <div id="profileTabsContent">
                    <!-- Profile Information Tab -->
                    <div class="tab-pane" id="profile-content" role="tabpanel">
                        <div class="bg-white rounded-lg shadow-md">
                            <div class="bg-gradient-to-r from-indigo-600 to-purple-600 text-white p-4 rounded-t-lg">
                                <h5 class="mb-0 text-lg font-semibold">
                                    <i class="fas fa-user-edit mr-2"></i>Edit Profile Information
                                </h5>
                            </div>
                            <div class="p-4">
                                <?php if ($error): ?>
                                    <div class="profile-alert profile-alert-danger" role="alert">
                                        <?php echo $error; ?>
                                        <button type="button" class="profile-alert-close" onclick="this.parentElement.remove()">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($success): ?>
                                    <div class="profile-alert profile-alert-success" role="alert">
                                        <?php echo $success; ?>
                                        <button type="button" class="profile-alert-close" onclick="this.parentElement.remove()">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                <?php endif; ?>
                                
                                <form method="POST" action="" enctype="multipart/form-data">
                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                    <input type="hidden" name="action" value="update_profile">
                                    
                                    <div class="profile-form-row">
                                        <div class="profile-form-col">
                                            <div class="profile-form-group">
                                                <label for="username" class="profile-form-label">Username</label>
                                                <input type="text" class="profile-form-input" id="username" value="<?php echo htmlspecialchars($user['username']); ?>" readonly>
                                                <p class="profile-form-help">Username cannot be changed</p>
                                            </div>
                                        </div>
                                        <div class="profile-form-col">
                                            <div class="profile-form-group">
                                                <label for="email" class="profile-form-label">Email Address</label>
                                                <input type="email" class="profile-form-input" id="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="profile-form-group">
                                        <label class="profile-form-label">Profile Picture</label>
                                        <div class="profile-image-group">
                                            <div class="profile-image-preview">
                                                <div class="profile-avatar" style="width: 70px; height: 70px;">
                                                    <?php if (!empty($profile_image)): ?>
                                                        <img src="<?php echo htmlspecialchars($profile_image); ?>" alt="Profile Picture" class="img-fluid rounded-circle" style="width: 100%; height: 100%; object-fit: cover;">
                                                    <?php else: ?>
                                                        <i class="fas fa-user"></i>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="profile-image-input">
                                                <input type="file" class="profile-form-input" id="profile_image" name="profile_image" accept=".jpg,.jpeg,.png">
                                                <p class="profile-form-help">
                                                    Allowed types: JPG, JPEG, PNG. Max size: <?php echo round(MAX_FILE_SIZE / 1024 / 1024, 1); ?>MB.
                                                </p>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="profile-form-group">
                                        <label for="full_name" class="profile-form-label">Full Name</label>
                                        <input type="text" class="profile-form-input" id="full_name" name="full_name" value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                                    </div>
                                    
                                    <div class="profile-form-group">
                                        <label for="current_password" class="profile-form-label">Current Password</label>
                                        <input type="password" class="profile-form-input" id="current_password" name="current_password" required>
                                        <p class="profile-form-help">Enter your current password to confirm changes</p>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-2"></i>Update Profile
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Change Password Tab -->
                    <div class="tab-pane hidden" id="password-content" role="tabpanel">
                        <div class="profile-card">
                            <div class="profile-form-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-key me-2"></i>Change Password
                                </h5>
                            </div>
                            <div class="profile-form-body">
                                <form method="POST" action="">
                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                    <input type="hidden" name="action" value="change_password">
                                    
                                    <div class="profile-form-group">
                                        <label for="current_password_pass" class="profile-form-label">Current Password</label>
                                        <input type="password" class="profile-form-input" id="current_password_pass" name="current_password" required>
                                    </div>
                                    
                                    <div class="profile-form-group">
                                        <label for="new_password" class="profile-form-label">New Password</label>
                                        <input type="password" class="profile-form-input" id="new_password" name="new_password" required>
                                        <p class="profile-form-help">
                                            Password must be at least 8 characters long and contain uppercase, lowercase, number, and special character.
                                        </p>
                                    </div>
                                    
                                    <div class="profile-form-group">
                                        <label for="confirm_password" class="profile-form-label">Confirm New Password</label>
                                        <input type="password" class="profile-form-input" id="confirm_password" name="confirm_password" required>
                                        <div id="password-error" class="invalid-feedback mt-1 hidden"></div>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-key me-2"></i>Change Password
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Recent Activity Tab -->
                    <div class="tab-pane fade" id="activity-content" role="tabpanel">
                        <div class="card">
                            <div class="custom-card-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-history me-2"></i>Recent Activity
                                </h5>
                            </div>
                            <div class="custom-card-body">
                                <?php
                                try {
                                    $database = new Database();
                                    $db = $database->getConnection();
                                    
                                    $query = "SELECT action, created_at, ip_address, user_agent 
                                              FROM system_logs 
                                              WHERE user_id = ? 
                                              ORDER BY created_at DESC 
                                              LIMIT 20";
                                    $stmt = $db->prepare($query);
                                    $stmt->execute([$_SESSION['user_id']]);
                                    $activities = $stmt->fetchAll();
                                    
                                    if (empty($activities)) {
                                        echo '<p class="text-muted">No recent activity found.</p>';
                                    } else {
                                        echo '<div class="custom-table-responsive">';
                                        echo '<table class="custom-table custom-table-sm">';
                                        echo '<thead><tr><th>Action</th><th>Date/Time</th><th>IP Address</th></tr></thead>';
                                        echo '<tbody>';
                                        
                                        foreach ($activities as $activity) {
                                            echo '<tr>';
                                            echo '<td>' . htmlspecialchars(ucfirst(str_replace('_', ' ', $activity['action']))) . '</td>';
                                            echo '<td>' . date('M j, Y H:i:s', strtotime($activity['created_at'])) . '</td>';
                                            echo '<td>' . htmlspecialchars($activity['ip_address']) . '</td>';
                                            echo '</tr>';
                                        }
                                        
                                        echo '</tbody></table></div>';
                                    }
                                } catch (Exception $e) {
                                    echo '<p class="text-danger">Error loading activity log.</p>';
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Tab switching functionality
        document.querySelectorAll('[data-tab]').forEach(button => {
            button.addEventListener('click', function() {
                const targetTab = this.getAttribute('data-tab');
                
                // Hide all tab panes
                document.querySelectorAll('.tab-pane').forEach(pane => {
                    pane.classList.add('hidden');
                });
                
                // Remove active state from all buttons
                document.querySelectorAll('[data-tab]').forEach(btn => {
                    btn.classList.remove('bg-indigo-600', 'text-white', 'hover:bg-indigo-700');
                    btn.classList.add('bg-gray-200', 'text-gray-700', 'hover:bg-gray-300');
                });
                
                // Show target tab pane
                document.getElementById(targetTab).classList.remove('hidden');
                
                // Add active state to clicked button
                this.classList.remove('bg-gray-200', 'text-gray-700', 'hover:bg-gray-300');
                this.classList.add('bg-indigo-600', 'text-white', 'hover:bg-indigo-700');
            });
        });
        
        // Password confirmation validation
        document.getElementById('confirm_password').addEventListener('input', function() {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = this.value;
            const errorDiv = document.getElementById('password-error');
            
            if (newPassword !== confirmPassword) {
                errorDiv.textContent = 'Passwords do not match';
                errorDiv.classList.remove('hidden');
                this.classList.add('border-red-500');
            } else {
                errorDiv.classList.add('hidden');
                this.classList.remove('border-red-500');
            }
        });
    </script>    
        // Password strength indicator
        document.getElementById('new_password').addEventListener('input', function() {
            const password = this.value;
            const strength = checkPasswordStrength(password);
            
            // Update strength indicator if exists
            const strengthIndicator = document.getElementById('password-strength');
            if (strengthIndicator) {
                strengthIndicator.textContent = strength.text;
                strengthIndicator.className = 'custom-form-text ' + strength.class;
            }
        });
        
        function checkPasswordStrength(password) {
            let score = 0;
            let feedback = [];
            
            if (password.length >= 8) score++;
            else feedback.push('at least 8 characters');
            
            if (/[A-Z]/.test(password)) score++;
            else feedback.push('uppercase letter');
            
            if (/[a-z]/.test(password)) score++;
            else feedback.push('lowercase letter');
            
            if (/[0-9]/.test(password)) score++;
            else feedback.push('number');
            
            if (/[^A-Za-z0-9]/.test(password)) score++;
            else feedback.push('special character');
            
            if (score < 3) {
                return { text: 'Weak: Add ' + feedback.join(', '), class: 'text-danger' };
            } else if (score < 4) {
                return { text: 'Fair: Add ' + feedback.join(', '), class: 'text-warning' };
            } else {
                return { text: 'Strong password', class: 'text-success' };
            }
        }
    </script>
</body>
</html>
