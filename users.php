<?php
require_once 'config/config.php';
require_once 'config/database.php';

// Check admin access
requireAdmin();

// Initialize variables
$success = '';
$error = '';
$users = [];
$user = null;
$editing = false;

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
                case 'add':
                    $username = sanitizeInput($_POST['username'] ?? '');
                    $email = sanitizeInput($_POST['email'] ?? '');
                    $password = $_POST['password'] ?? '';
                    $full_name = sanitizeInput($_POST['full_name'] ?? '');
                    $role = sanitizeInput($_POST['role'] ?? 'staff');
                    $is_active = isset($_POST['is_active']) ? 1 : 0;
                    
                    // Validation
                    if (empty($username) || empty($email) || empty($password) || empty($full_name)) {
                        $error = 'All fields are required.';
                    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $error = 'Invalid email format.';
                    } elseif (strlen($password) < 8) {
                        $error = 'Password must be at least 8 characters long.';
                    } else {
                        // Check if username or email already exists
                        $checkQuery = "SELECT id FROM users WHERE username = ? OR email = ?";
                        $checkStmt = $db->prepare($checkQuery);
                        $checkStmt->execute([$username, $email]);
                        
                        if ($checkStmt->fetch()) {
                            $error = 'Username or email already exists.';
                        } else {
                            // Insert new user
                            $password_hash = hashPassword($password);
                            $insertQuery = "INSERT INTO users (username, email, password_hash, full_name, role, is_active) 
                                          VALUES (?, ?, ?, ?, ?, ?)";
                            $insertStmt = $db->prepare($insertQuery);
                            $insertStmt->execute([$username, $email, $password_hash, $full_name, $role, $is_active]);
                            
                            $userId = $db->lastInsertId();
                            logActivity($_SESSION['user_id'], 'create', 'users', $userId);
                            $success = 'User created successfully.';
                        }
                    }
                    break;
                    
                case 'edit':
                    $userId = (int)($_POST['user_id'] ?? 0);
                    $username = sanitizeInput($_POST['username'] ?? '');
                    $email = sanitizeInput($_POST['email'] ?? '');
                    $full_name = sanitizeInput($_POST['full_name'] ?? '');
                    $role = sanitizeInput($_POST['role'] ?? 'staff');
                    $is_active = isset($_POST['is_active']) ? 1 : 0;
                    $password = $_POST['password'] ?? '';
                    
                    if ($userId <= 0) {
                        $error = 'Invalid user ID.';
                    } elseif (empty($username) || empty($email) || empty($full_name)) {
                        $error = 'Username, email, and full name are required.';
                    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $error = 'Invalid email format.';
                    } elseif (!empty($password) && strlen($password) < 8) {
                        $error = 'Password must be at least 8 characters long.';
                    } else {
                        // Check if username or email already exists for other users
                        $checkQuery = "SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?";
                        $checkStmt = $db->prepare($checkQuery);
                        $checkStmt->execute([$username, $email, $userId]);
                        
                        if ($checkStmt->fetch()) {
                            $error = 'Username or email already exists.';
                        } else {
                            // Get old values for logging
                            $oldQuery = "SELECT * FROM users WHERE id = ?";
                            $oldStmt = $db->prepare($oldQuery);
                            $oldStmt->execute([$userId]);
                            $oldValues = $oldStmt->fetch();
                            
                            // Update user
                            if (!empty($password)) {
                                $password_hash = hashPassword($password);
                                $updateQuery = "UPDATE users SET username = ?, email = ?, password_hash = ?, 
                                              full_name = ?, role = ?, is_active = ? WHERE id = ?";
                                $updateStmt = $db->prepare($updateQuery);
                                $updateStmt->execute([$username, $email, $password_hash, $full_name, $role, $is_active, $userId]);
                            } else {
                                $updateQuery = "UPDATE users SET username = ?, email = ?, full_name = ?, 
                                              role = ?, is_active = ? WHERE id = ?";
                                $updateStmt = $db->prepare($updateQuery);
                                $updateStmt->execute([$username, $email, $full_name, $role, $is_active, $userId]);
                            }
                            
                            // Get new values for logging
                            $newQuery = "SELECT * FROM users WHERE id = ?";
                            $newStmt = $db->prepare($newQuery);
                            $newStmt->execute([$userId]);
                            $newValues = $newStmt->fetch();
                            
                            logActivity($_SESSION['user_id'], 'update', 'users', $userId, $oldValues, $newValues);
                            $success = 'User updated successfully.';
                        }
                    }
                    break;
                    
                case 'delete':
                    $userId = (int)($_POST['user_id'] ?? 0);
                    
                    if ($userId <= 0) {
                        $error = 'Invalid user ID.';
                    } elseif ($userId === $_SESSION['user_id']) {
                        $error = 'You cannot delete your own account.';
                    } else {
                        // Check for associated records
                        $checkQuery = "SELECT 
                                         (SELECT COUNT(*) FROM clients WHERE created_by = ?) as client_count,
                                         (SELECT COUNT(*) FROM loans WHERE created_by = ?) as loan_count,
                                         (SELECT COUNT(*) FROM repayments WHERE created_by = ?) as repayment_count";
                        $checkStmt = $db->prepare($checkQuery);
                        $checkStmt->execute([$userId, $userId, $userId]);
                        $counts = $checkStmt->fetch();
                        
                        $totalRecords = $counts['client_count'] + $counts['loan_count'] + $counts['repayment_count'];
                        
                        if ($totalRecords > 0) {
                            $error = "Cannot delete user - they have associated records ({$counts['client_count']} clients, {$counts['loan_count']} loans, {$counts['repayment_count']} repayments). Consider deactivating the account instead.";
                        } else {
                            // Get user info for logging
                            $oldQuery = "SELECT * FROM users WHERE id = ?";
                            $oldStmt = $db->prepare($oldQuery);
                            $oldStmt->execute([$userId]);
                            $oldValues = $oldStmt->fetch();
                            
                            // Delete user
                            $deleteQuery = "DELETE FROM users WHERE id = ?";
                            $deleteStmt = $db->prepare($deleteQuery);
                            $deleteStmt->execute([$userId]);
                            
                            logActivity($_SESSION['user_id'], 'delete', 'users', $userId, $oldValues);
                            $success = 'User deleted successfully.';
                        }
                    }
                    break;
                    
                case 'toggle_status':
                    $userId = (int)($_POST['user_id'] ?? 0);
                    
                    if ($userId <= 0) {
                        $error = 'Invalid user ID.';
                    } elseif ($userId === $_SESSION['user_id']) {
                        $error = 'You cannot change your own status.';
                    } else {
                        // Get current status
                        $statusQuery = "SELECT is_active FROM users WHERE id = ?";
                        $statusStmt = $db->prepare($statusQuery);
                        $statusStmt->execute([$userId]);
                        $currentStatus = $statusStmt->fetchColumn();
                        
                        // Toggle status
                        $newStatus = $currentStatus ? 0 : 1;
                        $toggleQuery = "UPDATE users SET is_active = ? WHERE id = ?";
                        $toggleStmt = $db->prepare($toggleQuery);
                        $toggleStmt->execute([$newStatus, $userId]);
                        
                        $actionText = $newStatus ? 'activated' : 'deactivated';
                        logActivity($_SESSION['user_id'], $actionText, 'users', $userId);
                        $success = "User {$actionText} successfully.";
                    }
                    break;
            }
        } catch (Exception $e) {
            $error = 'Operation failed: ' . $e->getMessage();
            error_log("User management error: " . $e->getMessage());
        }
    }
}

// Handle GET requests for editing
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $edit_id = (int)($_GET['edit'] ?? 0);
    if ($edit_id > 0) {
        try {
            $database = new Database();
            $db = $database->getConnection();
            
            $query = "SELECT * FROM users WHERE id = ?";
            $stmt = $db->prepare($query);
            $stmt->execute([$edit_id]);
            $user = $stmt->fetch();
            
            if ($user) {
                $editing = true;
            }
        } catch (Exception $e) {
            error_log("Error fetching user: " . $e->getMessage());
        }
    }
}

// Fetch all users with associated record counts
try {
    $database = new Database();
    $db = $database->getConnection();
    
    $query = "SELECT u.id, u.username, u.email, u.full_name, u.role, u.is_active, u.created_at, u.last_login,
                     (SELECT COUNT(*) FROM clients WHERE created_by = u.id) as client_count,
                     (SELECT COUNT(*) FROM loans WHERE created_by = u.id) as loan_count,
                     (SELECT COUNT(*) FROM repayments WHERE created_by = u.id) as repayment_count
              FROM users u 
              ORDER BY u.created_at DESC";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $users = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Error fetching users: " . $e->getMessage());
}
?>

<?php
$page_title = 'User Management';
require_once 'includes/header.php';
?>
    
    <div class="users-container">
        <div class="users-header">
            <div class="users-header-content">
                <div class="users-title">
                    <i class="fas fa-users"></i>User Management
                </div>
                <button type="button" class="btn btn-primary" onclick="openModal()">
                    <i class="fas fa-plus me-2"></i>Add New User
                </button>
            </div>
                
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
                
                <div class="card">
                    <div class="card-header">
                        <h5 style="padding: 20px;
                    margin-bottom: 0;
                    background: var(--bg-secondary);
                    color: var(--text-primary);
                    border-radius: 10px 10px 0 0;
                    font-weight: 600;
                    border-bottom: 1px solid var(--border-color);"  class="card-title mb-0">System Users</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Username</th>
                                        <th>Email</th>
                                        <th>Full Name</th>
                                        <th>Role</th>
                                        <th>Status</th>
                                        <th>Records</th>
                                        <th>Created</th>
                                        <th>Last Login</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $u): 
                                        $totalRecords = $u['client_count'] + $u['loan_count'] + $u['repayment_count'];
                                        $hasRecords = $totalRecords > 0;
                                    ?>
                                        <tr>
                                            <td><?php echo $u['id']; ?></td>
                                            <td><?php echo htmlspecialchars($u['username']); ?></td>
                                            <td><?php echo htmlspecialchars($u['email']); ?></td>
                                            <td><?php echo htmlspecialchars($u['full_name']); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo $u['role'] === 'admin' ? 'primary' : 'secondary'; ?>">
                                                    <?php echo ucfirst($u['role']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo $u['is_active'] ? 'success' : 'danger'; ?>">
                                                    <?php echo $u['is_active'] ? 'Active' : 'Inactive'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <i class="fas fa-database me-2 <?php echo $hasRecords ? 'text-success' : 'text-muted'; ?>" title="<?php echo $u['client_count']; ?> clients, <?php echo $u['loan_count']; ?> loans, <?php echo $u['repayment_count']; ?> repayments"></i>
                                                    <span><?php echo $totalRecords; ?> records</span>
                                                </div>
                                            </td>
                                            <td><?php echo date('M j, Y', strtotime($u['created_at'])); ?></td>
                                            <td><?php echo $u['last_login'] ? date('M j, Y H:i', strtotime($u['last_login'])) : 'Never'; ?></td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <button type="button" class="btn btn-outline-primary btn-sm edit-user" 
                                                            data-id="<?php echo $u['id']; ?>"
                                                            data-username="<?php echo htmlspecialchars($u['username']); ?>"
                                                            data-email="<?php echo htmlspecialchars($u['email']); ?>"
                                                            data-fullname="<?php echo htmlspecialchars($u['full_name']); ?>"
                                                            data-role="<?php echo $u['role']; ?>"
                                                            data-active="<?php echo $u['is_active']; ?>"
                                                            title="Edit User">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    
                                                    <?php if ($u['id'] !== $_SESSION['user_id']): ?>
                                                        <button type="button" class="btn btn-outline-warning btn-sm toggle-user" 
                                                                data-id="<?php echo $u['id']; ?>"
                                                                data-action="<?php echo $u['is_active'] ? 'deactivate' : 'activate'; ?>"
                                                                title="<?php echo $u['is_active'] ? 'Deactivate' : 'Activate'; ?> User">
                                                            <i class="fas fa-<?php echo $u['is_active'] ? 'ban' : 'check'; ?>"></i>
                                                        </button>
                                                        
                                                        <button type="button" class="btn btn-outline-danger btn-sm delete-user" 
                                                                data-id="<?php echo $u['id']; ?>"
                                                                data-username="<?php echo htmlspecialchars($u['username']); ?>"
                                                                <?php echo $hasRecords ? 'disabled title="Cannot delete user with associated records"' : 'title="Delete User"'; ?>>
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- User Modal -->
    <div class="modal" id="userModal">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-user me-2"></i>
                        <span id="modalTitle">Add New User</span>
                    </h5>
                    <button type="button" class="modal-close" onclick="closeModal()">&times;</button>
                </div>
                <form method="POST" action="" id="userForm" data-validate>
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="user_id" id="userId">
                    
                    <div class="modal-body">
                        <div class="mb-4">
                            <label for="username" class="block text-sm font-medium text-gray-700 mb-1">Username</label>
                            <input type="text" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500" id="username" name="username" required>
                        </div>
                        
                        <div class="mb-4">
                            <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                            <input type="email" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500" id="email" name="email" required>
                        </div>
                        
                        <div class="mb-4">
                            <label for="full_name" class="block text-sm font-medium text-gray-700 mb-1">Full Name</label>
                            <input type="text" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500" id="full_name" name="full_name" required>
                        </div>
                        
                        <div class="mb-4">
                            <label for="role" class="block text-sm font-medium text-gray-700 mb-1">Role</label>
                            <select class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500" id="role" name="role" required>
                                <option value="staff">Staff</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>
                        
                        <div class="mb-4">
                            <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password <small class="text-white-200">(Leave blank to keep current when editing)</small></label>
                            <input type="password" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500" id="password" name="password">
                            <p class="text-sm text-gray-500 mt-1">Minimum 8 characters</p>
                        </div>
                        
                        <div class="mb-4">
                            <div class="flex items-center">
                                <input type="checkbox" class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded" id="is_active" name="is_active" checked>
                                <label for="is_active" class="ml-2 block text-sm text-gray-900">
                                    Active Account
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Save User
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteModal">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-exclamation-triangle me-2"></i>Confirm Delete
                    </h5>
                    <button type="button" class="modal-close" onclick="closeDeleteModal()">&times;</button>
                </div>
                <form method="POST" action="" id="deleteForm">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="user_id" id="deleteUserId">
                    
                    <div class="modal-body">
                        <p>Are you sure you want to delete the user <strong id="deleteUsername"></strong>?</p>
                        <p class="text-danger text-sm">This action cannot be undone.</p>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-trash me-2"></i>Delete User
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        // Modal functions
        function openModal() {
            document.getElementById('userModal').style.display = 'block';
        }
        
        function closeModal() {
            document.getElementById('userModal').style.display = 'none';
            // Reset form
            document.getElementById('userForm').reset();
            document.getElementById('modalTitle').textContent = 'Add New User';
            document.getElementById('formAction').value = 'add';
            document.getElementById('userId').value = '';
            document.getElementById('password').required = true;
        }
        
        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }
        
        // Edit user functionality
        document.querySelectorAll('.edit-user').forEach(button => {
            button.addEventListener('click', function() {
                openModal();
                document.getElementById('modalTitle').textContent = 'Edit User';
                document.getElementById('formAction').value = 'edit';
                document.getElementById('userId').value = this.dataset.id;
                document.getElementById('username').value = this.dataset.username;
                document.getElementById('email').value = this.dataset.email;
                document.getElementById('full_name').value = this.dataset.fullname;
                document.getElementById('role').value = this.dataset.role;
                document.getElementById('is_active').checked = this.dataset.active === '1';
                document.getElementById('password').required = false;
            });
        });
        
        // Delete user functionality
        document.querySelectorAll('.delete-user').forEach(button => {
            button.addEventListener('click', function() {
                document.getElementById('deleteModal').style.display = 'block';
                document.getElementById('deleteUserId').value = this.dataset.id;
                document.getElementById('deleteUsername').textContent = this.dataset.username;
            });
        });
        
        // Toggle status functionality
        document.querySelectorAll('.toggle-user').forEach(button => {
            button.addEventListener('click', function() {
                const userId = this.dataset.id;
                const action = this.dataset.action;
                
                if (confirm(`Are you sure you want to ${action} this user?`)) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="action" value="toggle_status">
                        <input type="hidden" name="user_id" value="${userId}">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        });
    </script>
</body>
</html>
