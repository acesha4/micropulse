-- MicroPulse: Real-Time Loan Management Platform Database Schema
-- Created for MySQL/MariaDB

-- Create database
CREATE DATABASE IF NOT EXISTS micropulse;
USE micropulse;

-- Users table for authentication
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('admin', 'staff') DEFAULT 'staff',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL
);

-- Clients table for borrower information
CREATE TABLE clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    email VARCHAR(100) UNIQUE,
    phone VARCHAR(20) NOT NULL,
    address TEXT NOT NULL,
    id_number VARCHAR(50) UNIQUE,
    date_of_birth DATE,
    employment_status VARCHAR(50),
    monthly_income DECIMAL(10,2),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Loans table for loan management
CREATE TABLE loans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    loan_amount DECIMAL(10,2) NOT NULL,
    interest_rate DECIMAL(5,2) NOT NULL DEFAULT 10.00,
    loan_term_months INT NOT NULL,
    total_payable DECIMAL(10,2) NOT NULL,
    monthly_payment DECIMAL(10,2) NOT NULL,
    status ENUM('pending', 'active', 'completed', 'overdue', 'defaulted') DEFAULT 'pending',
    disbursement_date DATE,
    due_date DATE,
    completed_date DATE NULL,
    balance_remaining DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    FOREIGN KEY (client_id) REFERENCES clients(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Repayments table for payment tracking
CREATE TABLE repayments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    loan_id INT NOT NULL,
    payment_amount DECIMAL(10,2) NOT NULL,
    payment_date DATE NOT NULL,
    payment_method ENUM('cash', 'bank_transfer', 'mobile_money', 'check') DEFAULT 'cash',
    payment_reference VARCHAR(100),
    is_partial_payment BOOLEAN DEFAULT FALSE,
    balance_before_payment DECIMAL(10,2) NOT NULL,
    balance_after_payment DECIMAL(10,2) NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT,
    FOREIGN KEY (loan_id) REFERENCES loans(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Sessions table for secure session management
CREATE TABLE sessions (
    id VARCHAR(128) PRIMARY KEY,
    user_id INT NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    payload TEXT NOT NULL,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- System logs for audit trail
CREATE TABLE system_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(100) NOT NULL,
    table_name VARCHAR(50),
    record_id INT,
    old_values JSON,
    new_values JSON,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Create indexes for better performance
CREATE INDEX idx_clients_email ON clients(email);
CREATE INDEX idx_clients_phone ON clients(phone);
CREATE INDEX idx_loans_client_id ON loans(client_id);
CREATE INDEX idx_loans_status ON loans(status);
CREATE INDEX idx_loans_due_date ON loans(due_date);
CREATE INDEX idx_repayments_loan_id ON repayments(loan_id);
CREATE INDEX idx_repayments_payment_date ON repayments(payment_date);
CREATE INDEX idx_sessions_user_id ON sessions(user_id);
CREATE INDEX idx_system_logs_user_id ON system_logs(user_id);
CREATE INDEX idx_system_logs_created_at ON system_logs(created_at);

-- Insert default admin user (password: password)
INSERT INTO users (username, email, password_hash, full_name, role) VALUES 
('admin', 'admin@micropulse.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Administrator', 'admin');

-- Insert sample data for testing
INSERT INTO clients (first_name, last_name, email, phone, address, id_number, date_of_birth, employment_status, monthly_income) VALUES 
('John', 'Doe', 'john.doe@email.com', '+1234567890', '123 Main St, City, State', 'ID123456', '1990-01-15', 'Employed', 2500.00),
('Jane', 'Smith', 'jane.smith@email.com', '+1234567891', '456 Oak Ave, City, State', 'ID789012', '1985-05-20', 'Self-Employed', 3200.00);

-- Insert sample loans
INSERT INTO loans (client_id, loan_amount, interest_rate, loan_term_months, total_payable, monthly_payment, status, disbursement_date, due_date, balance_remaining, created_by) VALUES 
(1, 5000.00, 10.00, 12, 5500.00, 458.33, 'active', '2024-01-01', '2025-01-01', 2750.00, 1),
(2, 3000.00, 12.00, 6, 3360.00, 560.00, 'active', '2024-02-01', '2024-08-01', 1680.00, 1);

-- Insert sample repayments
INSERT INTO repayments (loan_id, payment_amount, payment_date, payment_method, balance_before_payment, balance_after_payment, created_by) VALUES 
(1, 458.33, '2024-02-01', 'cash', 5500.00, 5041.67, 1),
(1, 458.33, '2024-03-01', 'bank_transfer', 5041.67, 4583.34, 1),
(1, 458.33, '2024-04-01', 'cash', 4583.34, 4125.01, 1),
(1, 458.33, '2024-05-01', 'cash', 4125.01, 3666.68, 1),
(1, 458.33, '2024-06-01', 'cash', 3666.68, 3208.35, 1),
(1, 458.33, '2024-07-01', 'cash', 3208.35, 2750.02, 1),
(2, 560.00, '2024-03-01', 'cash', 3360.00, 2800.00, 1),
(2, 560.00, '2024-04-01', 'bank_transfer', 2800.00, 2240.00, 1),
(2, 560.00, '2024-05-01', 'cash', 2240.00, 1680.00, 1);

-- Settings table for application configuration
CREATE TABLE settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    setting_type ENUM('string', 'integer', 'float', 'boolean', 'json') DEFAULT 'string',
    description TEXT,
    is_editable BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert default settings
INSERT INTO settings (setting_key, setting_value, setting_type, description) VALUES
('app_name', 'MicroPulse', 'string', 'Application name displayed in the interface'),
('app_version', '1.0.0', 'string', 'Application version'),
('app_url', 'http://localhost/micropulse', 'string', 'Application URL'),
('app_timezone', 'UTC', 'string', 'Application timezone'),
('company_name', 'MicroPulse Loans', 'string', 'Company or organization name'),
('company_email', 'info@micropulse.com', 'string', 'Company email address'),
('company_phone', '+1234567890', 'string', 'Company phone number'),
('company_address', '123 Business Ave, City, State', 'string', 'Company address'),
('default_interest_rate', '10.00', 'float', 'Default interest rate for new loans (percentage)'),
('min_loan_amount', '100.00', 'float', 'Minimum loan amount allowed'),
('max_loan_amount', '50000.00', 'float', 'Maximum loan amount allowed'),
('min_loan_term', '1', 'integer', 'Minimum loan term in months'),
('max_loan_term', '60', 'integer', 'Maximum loan term in months'),
('late_fee_percentage', '5.00', 'float', 'Late fee percentage'),
('grace_period_days', '5', 'integer', 'Grace period in days'),
('session_lifetime', '3600', 'integer', 'Session lifetime in seconds'),
('max_login_attempts', '5', 'integer', 'Maximum login attempts allowed'),
('login_lockout_time', '900', 'integer', 'Login lockout time in seconds'),
('password_min_length', '8', 'integer', 'Minimum password length'),
('require_password_change', 'false', 'boolean', 'Require password change periodically'),
('password_expiry_days', '90', 'integer', 'Password expiry days'),
('email_notifications', 'true', 'boolean', 'Enable email notifications'),
('smtp_host', 'localhost', 'string', 'SMTP server host'),
('smtp_port', '587', 'integer', 'SMTP server port'),
('smtp_username', '', 'string', 'SMTP username'),
('smtp_password', '', 'string', 'SMTP password'),
('smtp_encryption', 'tls', 'string', 'SMTP encryption type'),
('email_from_name', 'MicroPulse', 'string', 'From name for emails'),
('email_from_address', 'noreply@micropulse.com', 'string', 'From address for emails'),
('items_per_page', '20', 'integer', 'Number of items per page'),
('date_format', 'Y-m-d', 'string', 'Date format'),
('time_format', 'H:i:s', 'string', 'Time format'),
('currency', 'USD', 'string', 'Currency code'),
('currency_symbol', '$', 'string', 'Currency symbol'),
('decimal_places', '2', 'integer', 'Decimal places for currency'),
('auto_backup', 'true', 'boolean', 'Enable automatic backups'),
('backup_frequency', 'daily', 'string', 'Backup frequency'),
('backup_retention_days', '30', 'integer', 'Backup retention days'),
('backup_path', 'backups/', 'string', 'Backup storage path'),
('payment_reminder_days', '3', 'integer', 'Payment reminder days'),
('overdue_notification_days', '1', 'integer', 'Overdue notification days'),
('loan_approval_notification', 'true', 'boolean', 'Loan approval notification'),
('payment_received_notification', 'true', 'boolean', 'Payment received notification'),
('debug_mode', 'false', 'boolean', 'Debug mode'),
('maintenance_mode', 'false', 'boolean', 'Maintenance mode'),
('log_level', 'info', 'string', 'Log level'),
('max_file_upload_size', '5242880', 'integer', 'Maximum file upload size'),
('allowed_file_types', '["pdf","doc","docx","jpg","jpeg","png"]', 'json', 'Allowed file types');
