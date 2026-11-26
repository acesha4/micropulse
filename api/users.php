<?php
/**
 * MicroPulse: User Management API
 * Provides RESTful endpoints for user management operations
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once '../config/config.php';
require_once '../config/database.php';

// Check admin access
requireAdmin();

// Initialize response
$response = [
    'success' => false,
    'message' => '',
    'data' => null
];

try {
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        throw new Exception('Database connection failed');
    }
    
    $method = $_SERVER['REQUEST_METHOD'];
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $path_parts = explode('/', trim($path, '/'));
    
    // Get user ID from URL if present
    $user_id = null;
    if (count($path_parts) > 2 && is_numeric($path_parts[2])) {
        $user_id = (int)$path_parts[2];
    }
    
    switch ($method) {
        case 'GET':
            if ($user_id) {
                // Get single user
                $query = "SELECT id, username, email, full_name, role, is_active, created_at, last_login 
                         FROM users WHERE id = ?";
                $stmt = $db->prepare($query);
                $stmt->execute([$user_id]);
                $user = $stmt->fetch();
                
                if ($user) {
                    $response['success'] = true;
                    $response['data'] = $user;
                } else {
                    $response['message'] = 'User not found';
                    http_response_code(404);
                }
            } else {
                // Get all users with pagination
                $page = max(1, (int)($_GET['page'] ?? 1));
                $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
                $offset = ($page - 1) * $limit;
                
                $search = sanitizeInput($_GET['search'] ?? '');
                $role = sanitizeInput($_GET['role'] ?? '');
                $status = $_GET['status'] ?? '';
                
                // Build query
                $where_clauses = [];
                $params = [];
                
                if (!empty($search)) {
                    $where_clauses[] = "(username LIKE ? OR email LIKE ? OR full_name LIKE ?)";
                    $search_param = "%$search%";
                    $params[] = $search_param;
                    $params[] = $search_param;
                    $params[] = $search_param;
                }
                
                if (!empty($role) && in_array($role, ['admin', 'staff'])) {
                    $where_clauses[] = "role = ?";
                    $params[] = $role;
                }
                
                if ($status !== '') {
                    $where_clauses[] = "is_active = ?";
                    $params[] = $status === 'active' ? 1 : 0;
                }
                
                $where_clause = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
                
                // Get total count
                $count_query = "SELECT COUNT(*) FROM users $where_clause";
                $count_stmt = $db->prepare($count_query);
                $count_stmt->execute($params);
                $total_users = $count_stmt->fetchColumn();
                
                // Get users
                $query = "SELECT id, username, email, full_name, role, is_active, created_at, last_login 
                         FROM users $where_clause 
                         ORDER BY created_at DESC 
                         LIMIT ? OFFSET ?";
                $params[] = $limit;
                $params[] = $offset;
                
                $stmt = $db->prepare($query);
                $stmt->execute($params);
                $users = $stmt->fetchAll();
                
                $response['success'] = true;
                $response['data'] = [
                    'users' => $users,
                    'pagination' => [
                        'current_page' => $page,
                        'per_page' => $limit,
                        'total' => $total_users,
                        'total_pages' => ceil($total_users / $limit)
                    ]
                ];
            }
            break;
            
        case 'POST':
            // Create new user
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                throw new Exception('Invalid JSON input');
            }
            
            $username = sanitizeInput($input['username'] ?? '');
            $email = sanitizeInput($input['email'] ?? '');
            $password = $input['password'] ?? '';
            $full_name = sanitizeInput($input['full_name'] ?? '');
            $role = sanitizeInput($input['role'] ?? 'staff');
            $is_active = isset($input['is_active']) ? (int)$input['is_active'] : 1;
            
            // Validation
            if (empty($username) || empty($email) || empty($password) || empty($full_name)) {
                throw new Exception('Username, email, password, and full name are required');
            }
            
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Invalid email format');
            }
            
            if (strlen($password) < 8) {
                throw new Exception('Password must be at least 8 characters long');
            }
            
            if (!in_array($role, ['admin', 'staff'])) {
                throw new Exception('Invalid role. Must be admin or staff');
            }
            
            // Check if username or email already exists
            $check_query = "SELECT id FROM users WHERE username = ? OR email = ?";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->execute([$username, $email]);
            
            if ($check_stmt->fetch()) {
                throw new Exception('Username or email already exists');
            }
            
            // Create user
            $password_hash = hashPassword($password);
            $insert_query = "INSERT INTO users (username, email, password_hash, full_name, role, is_active) 
                           VALUES (?, ?, ?, ?, ?, ?)";
            $insert_stmt = $db->prepare($insert_query);
            $insert_stmt->execute([$username, $email, $password_hash, $full_name, $role, $is_active]);
            
            $new_user_id = $db->lastInsertId();
            
            // Log activity
            logActivity($_SESSION['user_id'], 'create', 'users', $new_user_id);
            
            // Get created user data
            $get_query = "SELECT id, username, email, full_name, role, is_active, created_at 
                        FROM users WHERE id = ?";
            $get_stmt = $db->prepare($get_query);
            $get_stmt->execute([$new_user_id]);
            $new_user = $get_stmt->fetch();
            
            $response['success'] = true;
            $response['message'] = 'User created successfully';
            $response['data'] = $new_user;
            http_response_code(201);
            break;
            
        case 'PUT':
            // Update user
            if (!$user_id) {
                throw new Exception('User ID is required for update');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                throw new Exception('Invalid JSON input');
            }
            
            // Get current user data for logging
            $old_query = "SELECT * FROM users WHERE id = ?";
            $old_stmt = $db->prepare($old_query);
            $old_stmt->execute([$user_id]);
            $old_data = $old_stmt->fetch();
            
            if (!$old_data) {
                throw new Exception('User not found');
            }
            
            $username = sanitizeInput($input['username'] ?? $old_data['username']);
            $email = sanitizeInput($input['email'] ?? $old_data['email']);
            $full_name = sanitizeInput($input['full_name'] ?? $old_data['full_name']);
            $role = sanitizeInput($input['role'] ?? $old_data['role']);
            $is_active = isset($input['is_active']) ? (int)$input['is_active'] : $old_data['is_active'];
            $password = $input['password'] ?? '';
            
            // Validation
            if (empty($username) || empty($email) || empty($full_name)) {
                throw new Exception('Username, email, and full name are required');
            }
            
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Invalid email format');
            }
            
            if (!empty($password) && strlen($password) < 8) {
                throw new Exception('Password must be at least 8 characters long');
            }
            
            if (!in_array($role, ['admin', 'staff'])) {
                throw new Exception('Invalid role. Must be admin or staff');
            }
            
            // Check if username or email already exists for other users
            $check_query = "SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->execute([$username, $email, $user_id]);
            
            if ($check_stmt->fetch()) {
                throw new Exception('Username or email already exists');
            }
            
            // Update user
            if (!empty($password)) {
                $password_hash = hashPassword($password);
                $update_query = "UPDATE users SET username = ?, email = ?, password_hash = ?, 
                               full_name = ?, role = ?, is_active = ? WHERE id = ?";
                $update_stmt = $db->prepare($update_query);
                $update_stmt->execute([$username, $email, $password_hash, $full_name, $role, $is_active, $user_id]);
            } else {
                $update_query = "UPDATE users SET username = ?, email = ?, full_name = ?, 
                               role = ?, is_active = ? WHERE id = ?";
                $update_stmt = $db->prepare($update_query);
                $update_stmt->execute([$username, $email, $full_name, $role, $is_active, $user_id]);
            }
            
            // Get updated user data for logging
            $new_query = "SELECT * FROM users WHERE id = ?";
            $new_stmt = $db->prepare($new_query);
            $new_stmt->execute([$user_id]);
            $new_data = $new_stmt->fetch();
            
            // Log activity
            logActivity($_SESSION['user_id'], 'update', 'users', $user_id, $old_data, $new_data);
            
            // Return updated user without sensitive data
            $response_data = [
                'id' => $new_data['id'],
                'username' => $new_data['username'],
                'email' => $new_data['email'],
                'full_name' => $new_data['full_name'],
                'role' => $new_data['role'],
                'is_active' => $new_data['is_active'],
                'created_at' => $new_data['created_at'],
                'last_login' => $new_data['last_login']
            ];
            
            $response['success'] = true;
            $response['message'] = 'User updated successfully';
            $response['data'] = $response_data;
            break;
            
        case 'DELETE':
            // Delete user
            if (!$user_id) {
                throw new Exception('User ID is required for deletion');
            }
            
            if ($user_id === $_SESSION['user_id']) {
                throw new Exception('You cannot delete your own account');
            }
            
            // Get user data for logging
            $old_query = "SELECT * FROM users WHERE id = ?";
            $old_stmt = $db->prepare($old_query);
            $old_stmt->execute([$user_id]);
            $old_data = $old_stmt->fetch();
            
            if (!$old_data) {
                throw new Exception('User not found');
            }
            
            // Check if user has associated records
            $check_query = "SELECT 
                              (SELECT COUNT(*) FROM clients WHERE created_by = ?) as client_count,
                              (SELECT COUNT(*) FROM loans WHERE created_by = ?) as loan_count,
                              (SELECT COUNT(*) FROM repayments WHERE created_by = ?) as repayment_count";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->execute([$user_id, $user_id, $user_id]);
            $counts = $check_stmt->fetch();
            
            $total_records = $counts['client_count'] + $counts['loan_count'] + $counts['repayment_count'];
            
            if ($total_records > 0) {
                throw new Exception("Cannot delete user - they have associated records ({$counts['client_count']} clients, {$counts['loan_count']} loans, {$counts['repayment_count']} repayments). Consider deactivating instead.");
            }
            
            // Delete user
            $delete_query = "DELETE FROM users WHERE id = ?";
            $delete_stmt = $db->prepare($delete_query);
            $delete_stmt->execute([$user_id]);
            
            // Log activity
            logActivity($_SESSION['user_id'], 'delete', 'users', $user_id, $old_data);
            
            $response['success'] = true;
            $response['message'] = 'User deleted successfully';
            break;
            
        default:
            throw new Exception('Method not allowed');
    }
    
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    http_response_code(400);
}

echo json_encode($response);
?>
