<?php
$page_title = 'Client Management';
require_once 'includes/header.php';

require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    die("Database connection failed. Please check your configuration.");
}

$success_message = null;
$error_message = null;

// Handle success message from redirect
if (isset($_GET['success']) && $_GET['success'] == '1') {
    $success_message = 'Client added successfully!';
}

// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!validateCSRFToken($csrf_token)) {
        $error_message = 'Invalid request. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'add':
                $first_name = sanitizeInput($_POST['first_name'] ?? '');
                $last_name = sanitizeInput($_POST['last_name'] ?? '');
                $email = sanitizeInput($_POST['email'] ?? '');
                $phone = sanitizeInput($_POST['phone'] ?? '');
                $address = sanitizeInput($_POST['address'] ?? '');
                $id_number = sanitizeInput($_POST['id_number'] ?? '');
                $date_of_birth = $_POST['date_of_birth'] ?? '';
                $employment_status = sanitizeInput($_POST['employment_status'] ?? '');
                $monthly_income = floatval($_POST['monthly_income'] ?? 0);
                
                try {
                    // Ensure user_id is set
                    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
                        throw new Exception('User session not found. Please log in again.');
                    }
                    
                    $created_by = intval($_SESSION['user_id']);
                    
                    // Validate required fields
                    if (empty($first_name) || empty($last_name) || empty($phone) || empty($address)) {
                        throw new Exception('Please fill in all required fields.');
                    }

                    $query = "INSERT INTO clients (first_name, last_name, email, phone, address, id_number, date_of_birth, employment_status, monthly_income, is_active, created_by) 
                              VALUES (:first_name, :last_name, :email, :phone, :address, :id_number, :date_of_birth, :employment_status, :monthly_income, 1, :created_by)";
                    
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':first_name', $first_name);
                    $stmt->bindParam(':last_name', $last_name);
                    $stmt->bindParam(':email', $email);
                    $stmt->bindParam(':phone', $phone);
                    $stmt->bindParam(':address', $address);
                    $stmt->bindParam(':id_number', $id_number);
                    $stmt->bindParam(':date_of_birth', $date_of_birth);
                    $stmt->bindParam(':employment_status', $employment_status);
                    $stmt->bindParam(':monthly_income', $monthly_income, PDO::PARAM_STR);
                    $stmt->bindParam(':created_by', $created_by, PDO::PARAM_INT);
                    
                    if ($stmt->execute()) {
                        $client_id = $db->lastInsertId();
                        
                        // Log activity if logging function exists
                        if (function_exists('logActivity')) {
                            logActivity($_SESSION['user_id'], 'create', 'clients', $client_id, null, $_POST);
                        }
                        
                        $success_message = 'Client added successfully!';
                        
                        // Redirect to clear the form and refresh the page
                        header("Location: clients.php?success=1");
                        exit();
                    } else {
                        $error_message = 'Failed to add client. Please check your input and try again.';
                        $error_info = $stmt->errorInfo();
                        if ($error_info[0] !== '00000') {
                            $error_message .= ' SQL Error: ' . $error_info[2];
                            error_log("SQL Error in client addition: " . print_r($error_info, true));
                        }
                    }
                } catch (Exception $e) {
                    $error_message = 'Error adding client: ' . $e->getMessage();
                    error_log("Client addition error: " . $e->getMessage());
                }
                break;
                
            case 'edit':
                $client_id = intval($_POST['client_id'] ?? 0);
                $first_name = sanitizeInput($_POST['first_name'] ?? '');
                $last_name = sanitizeInput($_POST['last_name'] ?? '');
                $email = sanitizeInput($_POST['email'] ?? '');
                $phone = sanitizeInput($_POST['phone'] ?? '');
                $address = sanitizeInput($_POST['address'] ?? '');
                $id_number = sanitizeInput($_POST['id_number'] ?? '');
                $date_of_birth = $_POST['date_of_birth'] ?? '';
                $employment_status = sanitizeInput($_POST['employment_status'] ?? '');
                $monthly_income = floatval($_POST['monthly_income'] ?? 0);
                
                try {
                    // Get old values for logging
                    $old_query = "SELECT * FROM clients WHERE id = :id";
                    $old_stmt = $db->prepare($old_query);
                    $old_stmt->bindParam(':id', $client_id);
                    $old_stmt->execute();
                    $old_values = $old_stmt->fetch();
                    
                    $query = "UPDATE clients SET first_name = :first_name, last_name = :last_name, email = :email, 
                              phone = :phone, address = :address, id_number = :id_number, date_of_birth = :date_of_birth, 
                              employment_status = :employment_status, monthly_income = :monthly_income, updated_at = NOW() 
                              WHERE id = :id";
                    
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':first_name', $first_name);
                    $stmt->bindParam(':last_name', $last_name);
                    $stmt->bindParam(':email', $email);
                    $stmt->bindParam(':phone', $phone);
                    $stmt->bindParam(':address', $address);
                    $stmt->bindParam(':id_number', $id_number);
                    $stmt->bindParam(':date_of_birth', $date_of_birth);
                    $stmt->bindParam(':employment_status', $employment_status);
                    $stmt->bindParam(':monthly_income', $monthly_income);
                    $stmt->bindParam(':id', $client_id);
                    
                    if ($stmt->execute()) {
                        logActivity($_SESSION['user_id'], 'update', 'clients', $client_id, $old_values, $_POST);
                        $success_message = 'Client updated successfully!';
                    } else {
                        $error_message = 'Failed to update client.';
                    }
                } catch (Exception $e) {
                    $error_message = 'Error: ' . $e->getMessage();
                }
                break;
                
            case 'delete':
                $client_id = intval($_POST['client_id'] ?? 0);
                
                try {
                    // Check if client has active loans
                    $loan_check = "SELECT COUNT(*) as count FROM loans WHERE client_id = :id AND status IN ('active', 'overdue')";
                    $loan_stmt = $db->prepare($loan_check);
                    $loan_stmt->bindParam(':id', $client_id);
                    $loan_stmt->execute();
                    $loan_count = $loan_stmt->fetch()['count'];
                    
                    if ($loan_count > 0) {
                        $error_message = 'Cannot delete client with active loans.';
                    } else {
                        // Get old values for logging
                        $old_query = "SELECT * FROM clients WHERE id = :id";
                        $old_stmt = $db->prepare($old_query);
                        $old_stmt->bindParam(':id', $client_id);
                        $old_stmt->execute();
                        $old_values = $old_stmt->fetch();
                        
                        $query = "UPDATE clients SET is_active = 0, updated_at = NOW() WHERE id = :id";
                        $stmt = $db->prepare($query);
                        $stmt->bindParam(':id', $client_id);
                        
                        if ($stmt->execute()) {
                            logActivity($_SESSION['user_id'], 'delete', 'clients', $client_id, $old_values, null);
                            $success_message = 'Client deleted successfully!';
                        } else {
                            $error_message = 'Failed to delete client.';
                        }
                    }
                } catch (Exception $e) {
                    $error_message = 'Error: ' . $e->getMessage();
                }
                break;
        }
    }
}

// Get clients for display
try {
    // Use raw trimmed search term (no HTML-escaping here; handled on output)
    $search = trim($_GET['search'] ?? '');
    $page = max(1, intval($_GET['page'] ?? 1));
    $offset = ($page - 1) * ITEMS_PER_PAGE;
    
    $query = "SELECT * FROM clients WHERE is_active = 1";
    $params = [];
    
    if (!empty($search)) {
        $query .= " AND (first_name LIKE :search_first OR last_name LIKE :search_last OR email LIKE :search_email OR phone LIKE :search_phone)";
        $search_param = "%$search%";
        $params[':search_first'] = $search_param;
        $params[':search_last'] = $search_param;
        $params[':search_email'] = $search_param;
        $params[':search_phone'] = $search_param;
    }
    
    $query .= " ORDER BY created_at DESC LIMIT " . ITEMS_PER_PAGE . " OFFSET " . $offset;
    
    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $clients = $stmt->fetchAll();
    
    // Get total count for pagination
    $count_query = "SELECT COUNT(*) as total FROM clients WHERE is_active = 1";
    if (!empty($search)) {
        $count_query .= " AND (first_name LIKE :search_first OR last_name LIKE :search_last OR email LIKE :search_email OR phone LIKE :search_phone)";
    }
    
    $count_stmt = $db->prepare($count_query);
    foreach ($params as $key => $value) {
        $count_stmt->bindValue($key, $value);
    }
    $count_stmt->execute();
    $total_clients = $count_stmt->fetch()['total'];
    $total_pages = ceil($total_clients / ITEMS_PER_PAGE);
    
} catch (Exception $e) {
    $error_message = "Error loading clients: " . $e->getMessage();
    $clients = [];
    $total_pages = 0;
    error_log("Client query error: " . $e->getMessage());
}

?>

<div class="row mb-4">
    <div class="col-md-8">
        <h1 class="h3 mb-0">Client Management</h1>
        <p class="text-muted">Manage borrower information and records</p>
    </div>
    <div class="col-md-4 text-end">
        <button type="button" class="btn btn-primary" onclick="openAddClientModal()">
            <i class="fas fa-plus me-2"></i>Add New Client
        </button>
    </div>
</div>

<!-- Success/Error Messages -->
<?php if ($success_message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars($success_message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars($error_message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Search and Filter -->
<div class="row mb-4">
    <div class="col-md-6">
        <form method="GET" action="">
            <div class="input-group">
                <input type="text" class="form-control" name="search" placeholder="Search clients..." value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="btn btn-outline-primary">
                    <i class="fas fa-search"></i>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Clients Table -->
<div class="card">
    <div class="card-header">
        <h5 style="padding: 20px;
                    margin-bottom: 0;
                    background: var(--bg-secondary);
                    color: var(--text-primary);
                    border-radius: 10px 10px 0 0;
                    font-weight: 600;
                    border-bottom: 1px solid var(--border-color);"  class="card-title mb-0">Clients List</h5>
    </div>
    <div class="card-body">
        <?php if (count($clients) > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Contact</th>
                            <th>Address</th>
                            <th>Employment</th>
                            <th>Monthly Income</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($clients as $client): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($client['first_name'] . ' ' . $client['last_name']); ?></strong><br>
                                    <small class="text-muted">ID: <?php echo htmlspecialchars($client['id_number'] ?? 'N/A'); ?></small>
                                </td>
                                <td>
                                    <i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($client['email'] ?? 'N/A'); ?><br>
                                    <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($client['phone']); ?>
                                </td>
                                <td>
                                    <?php
                                        $address = trim((string)($client['address'] ?? ''));
                                        echo htmlspecialchars(strlen($address) > 30 ? substr($address, 0, 30) . '...' : $address);
                                    ?>
                                </td>
                                <td><?php echo htmlspecialchars($client['employment_status'] ?? 'N/A'); ?></td>
                                <td><?php echo formatCurrency($client['monthly_income'] ?? 0); ?></td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-outline-primary" onclick="editClient(<?php echo $client['id']; ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button type="button" class="btn btn-outline-success" onclick="viewClient(<?php echo $client['id']; ?>)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button type="button" class="btn btn-outline-danger" onclick="deleteClient(<?php echo $client['id']; ?>)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <nav aria-label="Page navigation">
                    <ul class="pagination justify-content-center">
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>">Previous</a>
                            </li>
                        <?php endif; ?>
                        
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>">Next</a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        <?php else: ?>
            <div class="text-center py-5">
                <i class="fas fa-users fa-3x text-muted mb-3"></i>
                <h5>No clients found</h5>
                <p class="text-muted">Start by adding your first client.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add Client Modal -->
<div class="modal" id="addClientModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Client</h5>
                <button type="button" class="modal-close" onclick="closeAddClientModal()">&times;</button>
            </div>
            <form method="POST" action="clients.php" data-validate id="addClientForm">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">First Name *</label>
                                <input type="text" class="form-control" name="first_name" data-validate="required" required>
                                <div class="invalid-feedback"></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Last Name *</label>
                                <input type="text" class="form-control" name="last_name" data-validate="required" required>
                                <div class="invalid-feedback"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" name="email" data-validate="email">
                                <div class="invalid-feedback"></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Phone *</label>
                                <input type="tel" class="form-control" name="phone" data-validate="required|phone" required>
                                <div class="invalid-feedback"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Address *</label>
                        <textarea class="form-control" name="address" rows="2" data-validate="required" required></textarea>
                        <div class="invalid-feedback"></div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">ID Number</label>
                                <input type="text" class="form-control" name="id_number">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Date of Birth</label>
                                <input type="date" class="form-control" name="date_of_birth">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Employment Status</label>
                                <select class="form-select" name="employment_status">
                                    <option value="">Select...</option>
                                    <option value="Employed">Employed</option>
                                    <option value="Self-Employed">Self-Employed</option>
                                    <option value="Unemployed">Unemployed</option>
                                    <option value="Student">Student</option>
                                    <option value="Retired">Retired</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Monthly Income</label>
                                <input type="number" class="form-control" name="monthly_income" step="0.01" min="0" data-validate="min:0">
                                <div class="invalid-feedback"></div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeAddClientModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Client</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Client Modal (will be populated by JavaScript) -->
<div class="modal" id="editClientModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Client</h5>
                <button type="button" class="modal-close" onclick="closeEditClientModal()">&times;</button>
            </div>
            <form method="POST" action="" data-validate>
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="client_id" id="edit_client_id">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                
                <div class="modal-body" id="editClientModalBody">
                    <!-- Content will be populated by JavaScript -->
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeEditClientModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Client</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Custom Modal Functions
function openAddClientModal() {
    document.getElementById('addClientModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeAddClientModal() {
    document.getElementById('addClientModal').classList.remove('show');
    document.body.style.overflow = 'auto';
    // Clear form when closing
    document.getElementById('addClientForm').reset();
    // Clear validation states
    const form = document.getElementById('addClientForm');
    form.querySelectorAll('.is-invalid, .is-valid').forEach(field => {
        field.classList.remove('is-invalid', 'is-valid');
    });
    form.querySelectorAll('.invalid-feedback').forEach(feedback => {
        feedback.textContent = '';
    });
}

function closeEditClientModal() {
    document.getElementById('editClientModal').classList.remove('show');
    document.body.style.overflow = 'auto';
}

// Handle add client form submission
document.addEventListener('DOMContentLoaded', function() {
    const addClientForm = document.getElementById('addClientForm');
    if (addClientForm) {
        addClientForm.addEventListener('submit', function(e) {
            if (!MicroPulse.validateForm(this)) {
                e.preventDefault();
                return false;
            }
            
            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Adding...';
            submitBtn.disabled = true;
            
            // Form will submit normally and redirect, so no need to handle response
        });
    }
    
    // Handle success message display
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('success') === '1') {
        // Clear the URL parameter
        const newUrl = window.location.pathname;
        window.history.replaceState({}, '', newUrl);
        
        // Show success notification if MicroPulse object exists
        if (typeof MicroPulse !== 'undefined' && MicroPulse.showNotification) {
            MicroPulse.showNotification('Client added successfully!', 'success');
        }
    }
});

// Close modal when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('show');
        document.body.style.overflow = 'auto';
    }
}

function editClient(clientId) {
    // Fetch client data and populate edit modal
    fetch('api/get_client.php?id=' + clientId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const client = data.client;
                const modalBody = document.getElementById('editClientModalBody');
                
                modalBody.innerHTML = `
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">First Name *</label>
                                <input type="text" class="form-control" name="first_name" value="${client.first_name}" data-validate="required" required>
                                <div class="invalid-feedback"></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Last Name *</label>
                                <input type="text" class="form-control" name="last_name" value="${client.last_name}" data-validate="required" required>
                                <div class="invalid-feedback"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" name="email" value="${client.email || ''}" data-validate="email">
                                <div class="invalid-feedback"></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Phone *</label>
                                <input type="tel" class="form-control" name="phone" value="${client.phone}" data-validate="required|phone" required>
                                <div class="invalid-feedback"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Address *</label>
                        <textarea class="form-control" name="address" rows="2" data-validate="required" required>${client.address}</textarea>
                        <div class="invalid-feedback"></div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">ID Number</label>
                                <input type="text" class="form-control" name="id_number" value="${client.id_number || ''}">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Date of Birth</label>
                                <input type="date" class="form-control" name="date_of_birth" value="${client.date_of_birth || ''}">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Employment Status</label>
                                <select class="form-select" name="employment_status">
                                    <option value="">Select...</option>
                                    <option value="Employed" ${client.employment_status === 'Employed' ? 'selected' : ''}>Employed</option>
                                    <option value="Self-Employed" ${client.employment_status === 'Self-Employed' ? 'selected' : ''}>Self-Employed</option>
                                    <option value="Unemployed" ${client.employment_status === 'Unemployed' ? 'selected' : ''}>Unemployed</option>
                                    <option value="Student" ${client.employment_status === 'Student' ? 'selected' : ''}>Student</option>
                                    <option value="Retired" ${client.employment_status === 'Retired' ? 'selected' : ''}>Retired</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Monthly Income</label>
                                <input type="number" class="form-control" name="monthly_income" step="0.01" min="0" value="${client.monthly_income || 0}" data-validate="min:0">
                                <div class="invalid-feedback"></div>
                            </div>
                        </div>
                    </div>
                `;
                
                document.getElementById('edit_client_id').value = clientId;
                document.getElementById('editClientModal').classList.add('show');
                document.body.style.overflow = 'hidden';
            } else {
                MicroPulse.showNotification('Error loading client data', 'danger');
            }
        })
        .catch(error => {
            MicroPulse.showNotification('Error fetching client data', 'danger');
        });
}

function deleteClient(clientId) {
    MicroPulse.confirmAction('Are you sure you want to delete this client?', function() {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="client_id" value="${clientId}">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
        `;
        document.body.appendChild(form);
        form.submit();
    });
}

function viewClient(clientId) {
    window.location.href = 'client_details.php?id=' + clientId;
}
</script>

<?php require_once 'includes/footer.php'; ?>
