// MicroPulse: Real-Time Loan Management Platform - Custom JavaScript

// Global variables
let currentTheme = 'light';
let notifications = [];

// Logout functionality
function setupLogoutHandler() {
    const logoutLinks = document.querySelectorAll('a[href="logout.php"]');
    logoutLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            
            if (confirm('Are you sure you want to logout?')) {
                // Show loading state
                const originalText = this.innerHTML;
                this.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Logging out...';
                this.style.pointerEvents = 'none';
                
                // Perform logout
                fetch('logout.php', {
                    method: 'GET',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        window.location.href = data.redirect;
                    } else {
                        window.location.href = 'login.php';
                    }
                })
                .catch(error => {
                    console.error('Logout error:', error);
                    // Fallback to direct navigation
                    window.location.href = 'logout.php';
                });
            }
        });
    });
}

// Modal functionality
function setupModals() {
    console.log('Setting up modals...');
    
    // Add click handlers for modal triggers
    const modalTriggers = document.querySelectorAll('[data-modal-target]');
    console.log('Found modal triggers:', modalTriggers.length);
    
    modalTriggers.forEach(trigger => {
        trigger.addEventListener('click', function(e) {
            e.preventDefault();
            const modalId = this.getAttribute('data-modal-target');
            console.log('Modal trigger clicked, modal ID:', modalId);
            openModal(modalId);
        });
    });

    // Add click handlers for modal close buttons
    const modalCloseButtons = document.querySelectorAll('[data-modal-close], .modal-close');
    console.log('Found modal close buttons:', modalCloseButtons.length);
    
    modalCloseButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            const modal = this.closest('.modal');
            closeModal(modal);
        });
    });

    // Close modal when clicking outside
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('modal')) {
            closeModal(e.target);
        }
    });

    // Close modal with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const openModal = document.querySelector('.modal.show');
            if (openModal) {
                closeModal(openModal);
            }
        }
    });
}

function openModal(modalId) {
    console.log('Opening modal:', modalId);
    const modal = document.getElementById(modalId);
    console.log('Modal element:', modal);
    
    if (modal) {
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
        console.log('Modal opened successfully');
        
        // Focus first input in modal
        const firstInput = modal.querySelector('input, select, textarea');
        if (firstInput) {
            setTimeout(() => firstInput.focus(), 100);
        }
    } else {
        console.error('Modal not found:', modalId);
    }
}

function closeModal(modal) {
    if (typeof modal === 'string') {
        modal = document.getElementById(modal);
    }
    if (modal) {
        modal.classList.remove('show');
        document.body.style.overflow = '';
    }
}

// Initialize application
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded, initializing app...');
    initializeApp();
    setupLogoutHandler();
    setupModals();
    console.log('App initialization complete');
});

function initializeApp() {
    // Initialize tooltips
    initializeTooltips();
    
    // Initialize charts if on dashboard
    if (window.location.pathname.includes('dashboard.php')) {
        initializeDashboardCharts();
    }
    
    // Setup form validations
    setupFormValidations();
    
    // Setup real-time updates
    setupRealTimeUpdates();
    
    // Setup notifications
    setupNotifications();
}

// Initialize tooltips
function initializeTooltips() {
    // Simple tooltip implementation without Bootstrap
    const tooltipElements = document.querySelectorAll('[data-tooltip]');
    tooltipElements.forEach(element => {
        element.addEventListener('mouseenter', function() {
            const tooltipText = this.getAttribute('data-tooltip');
            const tooltip = document.createElement('div');
            tooltip.className = 'absolute z-50 px-2 py-1 text-sm text-white bg-gray-800 rounded shadow-lg';
            tooltip.textContent = tooltipText;
            tooltip.style.top = (this.offsetTop - 30) + 'px';
            tooltip.style.left = this.offsetLeft + 'px';
            tooltip.id = 'tooltip-' + Math.random().toString(36).substr(2, 9);
            document.body.appendChild(tooltip);
        });
        
        element.addEventListener('mouseleave', function() {
            const tooltips = document.querySelectorAll('[id^="tooltip-"]');
            tooltips.forEach(tooltip => tooltip.remove());
        });
    });
}

// Dashboard Charts
function initializeDashboardCharts() {
    // Loan Status Chart
    const loanStatusCtx = document.getElementById('loanStatusChart');
    if (loanStatusCtx) {
        new Chart(loanStatusCtx, {
            type: 'doughnut',
            data: {
                labels: ['Active', 'Completed', 'Overdue', 'Pending'],
                datasets: [{
                    data: [45, 30, 15, 10],
                    backgroundColor: [
                        '#28a745',
                        '#17a2b8',
                        '#dc3545',
                        '#ffc107'
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    }
    
    // Monthly Collections Chart
    const monthlyCollectionsCtx = document.getElementById('monthlyCollectionsChart');
    if (monthlyCollectionsCtx) {
        new Chart(monthlyCollectionsCtx, {
            type: 'line',
            data: {
                labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
                datasets: [{
                    label: 'Collections',
                    data: [12000, 15000, 18000, 14000, 22000, 25000],
                    borderColor: '#667eea',
                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '₱' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });
    }
}

// Form Validation
function setupFormValidations() {
    // Add validation to all forms
    const forms = document.querySelectorAll('form[data-validate]');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            if (!validateForm(form)) {
                e.preventDefault();
            }
        });
    });
    
    // Real-time validation for input fields
    const inputs = document.querySelectorAll('input[data-validate], select[data-validate], textarea[data-validate]');
    inputs.forEach(input => {
        input.addEventListener('blur', function() {
            validateField(input);
        });
        
        input.addEventListener('input', function() {
            if (input.classList.contains('is-invalid')) {
                validateField(input);
            }
        });
    });
}

function validateForm(form) {
    let isValid = true;
    const inputs = form.querySelectorAll('input[data-validate], select[data-validate], textarea[data-validate]');
    
    inputs.forEach(input => {
        if (!validateField(input)) {
            isValid = false;
        }
    });
    
    return isValid;
}

function validateField(field) {
    const rules = field.dataset.validate.split('|');
    let isValid = true;
    let errorMessage = '';
    
    rules.forEach(rule => {
        const [ruleName, ruleValue] = rule.split(':');
        
        switch (ruleName) {
            case 'required':
                if (!field.value.trim()) {
                    isValid = false;
                    errorMessage = 'This field is required';
                }
                break;
                
            case 'email':
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (field.value && !emailRegex.test(field.value)) {
                    isValid = false;
                    errorMessage = 'Please enter a valid email address';
                }
                break;
                
            case 'phone':
                const phoneRegex = /^[\d\s\-\+\(\)]+$/;
                if (field.value && !phoneRegex.test(field.value)) {
                    isValid = false;
                    errorMessage = 'Please enter a valid phone number';
                }
                break;
                
            case 'min':
                if (field.value && parseFloat(field.value) < parseFloat(ruleValue)) {
                    isValid = false;
                    errorMessage = `Minimum value is ${ruleValue}`;
                }
                break;
                
            case 'max':
                if (field.value && parseFloat(field.value) > parseFloat(ruleValue)) {
                    isValid = false;
                    errorMessage = `Maximum value is ${ruleValue}`;
                }
                break;
                
            case 'minlength':
                if (field.value && field.value.length < parseInt(ruleValue)) {
                    isValid = false;
                    errorMessage = `Minimum length is ${ruleValue} characters`;
                }
                break;
        }
    });
    
    // Update field validation display
    const feedbackElement = field.parentNode.querySelector('.invalid-feedback') || 
                           field.parentNode.parentNode.querySelector('.invalid-feedback');
    
    if (isValid) {
        field.classList.remove('is-invalid');
        field.classList.add('is-valid');
        if (feedbackElement) {
            feedbackElement.textContent = '';
        }
    } else {
        field.classList.remove('is-valid');
        field.classList.add('is-invalid');
        if (feedbackElement) {
            feedbackElement.textContent = errorMessage;
        }
    }
    
    return isValid;
}

// Real-time Updates
function setupRealTimeUpdates() {
    // Update dashboard stats every 30 seconds
    if (window.location.pathname.includes('dashboard.php')) {
        setInterval(updateDashboardStats, 30000);
    }
    
    // Update loan statuses every minute
    if (window.location.pathname.includes('loans.php')) {
        setInterval(updateLoanStatuses, 60000);
    }
}

function updateDashboardStats() {
    fetch('api/dashboard_stats.php')
        .then(response => response.json())
        .then(data => {
            // Update stat cards
            updateStatCard('total-clients', data.total_clients);
            updateStatCard('active-loans', data.active_loans);
            updateStatCard('total-collected', data.total_collected);
            updateStatCard('outstanding', data.outstanding);
        })
        .catch(error => console.error('Error updating dashboard stats:', error));
}

function updateStatCard(id, value) {
    const element = document.getElementById(id);
    if (element) {
        const formattedValue = typeof value === 'number' && value >= 1000 
            ? '₱' + value.toLocaleString() 
            : value;
        element.textContent = formattedValue;
    }
}

function updateLoanStatuses() {
    fetch('api/loan_statuses.php')
        .then(response => response.json())
        .then(data => {
            // Update loan status badges in the table
            data.loans.forEach(loan => {
                const statusElement = document.querySelector(`[data-loan-id="${loan.id}"] .loan-status`);
                if (statusElement) {
                    statusElement.className = `badge bg-${getStatusColor(loan.status)} loan-status`;
                    statusElement.textContent = loan.status.charAt(0).toUpperCase() + loan.status.slice(1);
                }
            });
        })
        .catch(error => console.error('Error updating loan statuses:', error));
}

function getStatusColor(status) {
    const colors = {
        'active': 'success',
        'pending': 'warning',
        'overdue': 'danger',
        'completed': 'info',
        'defaulted': 'dark'
    };
    return colors[status] || 'secondary';
}

// Notifications
function setupNotifications() {
    // Check for new notifications every 20 seconds
    setInterval(checkNotifications, 20000);
}

function checkNotifications() {
    fetch('api/notifications.php')
        .then(response => response.json())
        .then(data => {
            if (data.notifications && data.notifications.length > 0) {
                showNotifications(data.notifications);
            }
        })
        .catch(error => console.error('Error checking notifications:', error));
}

function showNotifications(notifications) {
    notifications.forEach(notification => {
        showNotification(notification.message, notification.type);
    });
}

function showNotification(message, type = 'info') {
    const toast = document.createElement('div');
    
    // Set background color based on type
    const bgColors = {
        'success': 'bg-green-500',
        'danger': 'bg-red-500',
        'warning': 'bg-yellow-500',
        'info': 'bg-blue-500',
        'primary': 'bg-indigo-500'
    };
    
    toast.className = `fixed top-4 right-4 z-50 px-4 py-3 text-white rounded-lg shadow-lg ${bgColors[type] || bgColors.info} transition-all duration-300 transform translate-x-full`;
    toast.setAttribute('role', 'alert');
    toast.setAttribute('aria-live', 'assertive');
    toast.setAttribute('aria-atomic', 'true');
    
    toast.innerHTML = `
        <div class="flex items-center justify-between">
            <div class="flex items-center">
                <i class="fas fa-${getNotificationIcon(type)} mr-2"></i>
                <span>${message}</span>
            </div>
            <button type="button" class="ml-4 text-white hover:text-gray-200" onclick="this.parentElement.parentElement.remove()">
                <i class="fas fa-times"></i>
            </button>
        </div>
    `;
    
    document.body.appendChild(toast);
    
    // Animate in
    setTimeout(() => {
        toast.classList.remove('translate-x-full');
        toast.classList.add('translate-x-0');
    }, 100);
    
    // Auto remove after 5 seconds
    setTimeout(() => {
        toast.classList.add('translate-x-full');
        setTimeout(() => {
            if (toast.parentNode) {
                toast.remove();
            }
        }, 300);
    }, 5000);
}

function getNotificationIcon(type) {
    const icons = {
        'success': 'check-circle',
        'danger': 'exclamation-circle',
        'warning': 'exclamation-triangle',
        'info': 'info-circle',
        'primary': 'bell'
    };
    return icons[type] || icons.info;
}

function createToastContainer() {
    // No longer needed with the new notification system
    return null;
}

// Utility Functions
function formatCurrency(amount) {
    return new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency: 'MXN'
    }).format(amount);
}

function formatDate(dateString) {
    return new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
}

function confirmAction(message, callback) {
    if (confirm(message)) {
        callback();
    }
}

// Loading indicator
function showLoading(element) {
    element.disabled = true;
    element.innerHTML = '<span class="spinner"></span> Loading...';
}

function hideLoading(element, originalText) {
    element.disabled = false;
    element.innerHTML = originalText;
}

// Export functions to global scope
window.MicroPulse = {
    validateForm,
    validateField,
    showNotification,
    confirmAction,
    showLoading,
    hideLoading,
    formatCurrency,
    formatDate,
    openModal,
    closeModal
};
