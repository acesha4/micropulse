# MicroPulse: Real-Time Loan Management Platform

A comprehensive microfinance management system that automates client, loan, and repayment management with secure access and financial insights.

## Features

### 🔐 Authentication System
- Secure login and logout functionality
- Password hashing using PHP's `password_hash()`
- Session protection and CSRF prevention
- Role-based access control (Admin, Staff)
- Activity logging and audit trail

### 👥 Client Management
- Add, edit, and delete borrower records
- Store comprehensive client information
- Search and filter capabilities
- Client activity tracking

### 💰 Loan Management
- Create and manage loans per client
- Automatic interest calculation
- Flexible loan terms and payment schedules
- Loan status tracking (pending, active, completed, overdue)
- Loan disbursement management

### 💳 Repayment Tracking
- Real-time payment recording
- Automatic balance updates
- Partial and full payment identification
- Payment history tracking
- Multiple payment methods support

### 📊 Dashboard
- Real-time key metrics display
- Interactive charts and visualizations
- Recent activities overview
- Performance indicators

### 📈 Reports
- Daily, weekly, and monthly summaries
- Loan performance analytics
- Client summary reports
- Export functionality
- Print-ready reports

## Technology Stack

- **Backend**: PHP 8.0+
- **Database**: MySQL/MariaDB
- **Frontend**: HTML5, CSS3, JavaScript
- **UI Framework**: Tailwind CSS
- **Charts**: Chart.js
- **Icons**: Font Awesome

## Security Features

- **Password Hashing**: Secure password storage using PHP's built-in functions
- **SQL Injection Prevention**: Prepared statements for all database operations
- **XSS Protection**: Input sanitization and output encoding
- **CSRF Protection**: Token-based request validation
- **Session Security**: Secure session handling with regeneration
- **Access Control**: Role-based permissions
- **Activity Logging**: Comprehensive audit trail

## Installation

### Prerequisites
- PHP 8.0 or higher
- MySQL/MariaDB 5.7 or higher
- Web server (Apache/NginX)
- XAMPP/WAMP/MAMP (for local development)

### Setup Instructions

1. **Clone/Download the Project**
   ```bash
   git clone <repository-url>
   cd micropulse
   ```

2. **Database Setup**
   - Create a new database named `micropulse`
   - Import the `database.sql` file to create tables and sample data
   ```sql
   mysql -u root -p micropulse < database.sql
   ```

3. **Configuration**
   - Update database credentials in `config/database.php` if needed
   - Verify settings in `config/config.php`

4. **Web Server Configuration**
   - Place the project in your web server's document root
   - Ensure `uploads/` directory is writable
   - Configure virtual host if needed

5. **Access the Application**
   - Open your browser and navigate to `http://localhost/micropulse`
   - Default admin credentials:
     - Username: `admin`
     - Password: `admin123`

## Directory Structure

```
micropulse/
├── api/                    # API endpoints
├── assets/                 # Static assets
│   ├── css/               # Stylesheets
│   └── js/                # JavaScript files
├── config/                 # Configuration files
├── includes/               # Reusable components
├── uploads/                # File uploads (if any)
├── database.sql           # Database schema and sample data
├── login.php              # Login page
├── logout.php             # Logout handler
├── dashboard.php           # Main dashboard
├── clients.php            # Client management
├── loans.php              # Loan management
├── make_payment.php       # Payment processing
├── repayments.php         # Repayment tracking
├── reports.php            # Reports and analytics
└── README.md              # This file
```

## Database Schema

The system uses the following main tables:

- **users**: User accounts and authentication
- **clients**: Borrower information and records
- **loans**: Loan accounts and details
- **repayments**: Payment records and history
- **sessions**: Secure session management
- **system_logs**: Activity audit trail

## Usage Guide

### For Administrators
1. Log in with admin credentials
2. Manage user accounts and permissions
3. Monitor system performance through reports
4. Configure system settings

### For Staff Members
1. Log in with assigned credentials
2. Add and manage client records
3. Create and manage loan accounts
4. Record payments and track repayments
5. Generate reports as needed

### Key Workflows

#### Creating a Loan
1. Navigate to "Loans" → "Create New Loan"
2. Select an existing client or add a new one
3. Enter loan details (amount, interest rate, term)
4. Review calculated payments and due dates
5. Save and disburse the loan

#### Recording a Payment
1. Navigate to "Loans" and select the active loan
2. Click "Make Payment" button
3. Enter payment amount and details
4. Save to update loan balance automatically

#### Generating Reports
1. Navigate to "Reports"
2. Select report type (daily, weekly, monthly)
3. Choose date range if applicable
4. Generate, export, or print the report

## API Endpoints

The system provides several API endpoints for real-time updates:

- `GET /api/dashboard_stats.php` - Dashboard statistics
- `GET /api/loan_statuses.php` - Loan status updates
- `GET /api/notifications.php` - System notifications
- `GET /api/get_client.php?id={id}` - Client details

## Security Considerations

1. **Regular Updates**: Keep PHP and dependencies updated
2. **Strong Passwords**: Enforce strong password policies
3. **Backup Strategy**: Regular database backups
4. **Access Control**: Limit admin access to authorized personnel
5. **HTTPS**: Use SSL certificates in production
6. **File Permissions**: Secure file upload directories

## Troubleshooting

### Common Issues

1. **Database Connection Error**
   - Check database credentials in `config/database.php`
   - Ensure MySQL service is running
   - Verify database exists and user has permissions

2. **Login Issues**
   - Clear browser cookies and cache
   - Check session configuration in PHP
   - Verify user account is active

3. **Permission Errors**
   - Ensure `uploads/` directory is writable
   - Check file permissions for PHP scripts
   - Verify web server user permissions

4. **Chart Not Displaying**
   - Check browser console for JavaScript errors
   - Ensure Chart.js library is loading
   - Verify data format from API endpoints

### Debug Mode
To enable detailed error reporting:
```php
// In config/config.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
```

## Support

For support and contributions:
- Report issues via the project repository
- Check the documentation for common solutions
- Contact the development team for assistance

## License

This project is licensed under the MIT License. See LICENSE file for details.

---

**MicroPulse** - Empowering microfinance with technology.
