# ISP Billing & Management System - Complete Guide

## Table of Contents
1. [System Overview](#system-overview)
2. [Architecture & Technology Stack](#architecture--technology-stack)
3. [Database Schema](#database-schema)
4. [Core Modules](#core-modules)
5. [API Endpoints](#api-endpoints)
6. [User Interfaces](#user-interfaces)
7. [Installation & Setup](#installation--setup)
8. [Configuration](#configuration)
9. [Usage Guide](#usage-guide)
10. [Maintenance & Monitoring](#maintenance--monitoring)
11. [Troubleshooting](#troubleshooting)

## System Overview

This is a comprehensive ISP (Internet Service Provider) billing and management system built with PHP and MySQL. The system provides complete functionality for managing clients, billing, payments, OLT (Optical Line Terminal) operations, and network monitoring.

### Key Features
- **Client Management**: Complete client lifecycle management with PPPoE integration
- **Billing System**: Automated invoice generation, payment tracking, and financial reporting
- **Network Management**: OLT integration, ONU monitoring, and network diagnostics
- **Router Integration**: MikroTik RouterOS API integration for client control
- **Dual Portal System**: Separate admin and client portals
- **Financial Management**: Income/expense tracking, wallet system, and reporting
- **Employee Management**: HR module with attendance tracking
- **Audit System**: Complete activity logging and audit trails

## Architecture & Technology Stack

### Backend
- **PHP 8.0+** with PDO for database operations
- **MySQL/MariaDB** database with InnoDB engine
- **Composer** for dependency management
- **PHPMailer** for email functionality
- **DomPDF** for PDF generation

### Frontend
- **Bootstrap 5.3.3** for responsive UI
- **Bootstrap Icons** for iconography
- **Custom CSS** with modern glass-morphism design
- **JavaScript** for interactive features

### Network Integration
- **MikroTik RouterOS API** for router management
- **Telnet/SSH** for OLT communication
- **SNMP** for network monitoring

### File Structure
```
/
├── api/                    # API endpoints
├── app/                    # Core application logic
├── assets/                 # Static assets (CSS, JS, images)
├── backups/                # Database backups
├── cron/                   # Scheduled tasks
├── laser/                  # Laser-specific modules
├── olt/                    # OLT management files
├── partials/               # Reusable UI components
├── public/                 # Public-facing pages
│   ├── admin/              # Admin-specific pages
│   └── portal/             # Client portal
├── reports/                # Report generation
├── storage/                # Logs and temporary files
├── tools/                  # Utility scripts
└── vendor/                 # Composer dependencies
```

## Database Schema

### Core Tables

#### 1. Users Table
```sql
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('admin','manager','branch_manager') DEFAULT 'admin',
    status TINYINT(1) DEFAULT 1,
    last_login DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

#### 2. Clients Table
```sql
CREATE TABLE clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_code VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(100) NOT NULL,
    pppoe_id VARCHAR(50) UNIQUE NOT NULL,
    pppoe_password VARCHAR(150) DEFAULT NULL,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(100) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    package_id INT NOT NULL,
    router_id INT DEFAULT NULL,
    olt_id INT DEFAULT NULL,
    status ENUM('active','inactive','expired','pending','left') DEFAULT 'pending',
    ledger_balance DECIMAL(14,2) DEFAULT 0.00,
    join_date DATE NOT NULL,
    expire_date DATE DEFAULT NULL,
    is_online TINYINT(1) DEFAULT 0,
    is_deleted TINYINT(1) DEFAULT 0,
    -- Additional fields for OLT integration
    onu_mac VARCHAR(100) DEFAULT NULL,
    onu_model VARCHAR(80) DEFAULT NULL,
    connection_type VARCHAR(100) DEFAULT NULL,
    area VARCHAR(100) DEFAULT NULL,
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

#### 3. Packages Table
```sql
CREATE TABLE packages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    speed VARCHAR(50) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    validity INT NOT NULL,
    description TEXT DEFAULT NULL
);
```

#### 4. Invoices Table
```sql
CREATE TABLE invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    invoice_number VARCHAR(50) NOT NULL,
    invoice_date DATE NOT NULL,
    due_date DATE DEFAULT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    discount DECIMAL(10,2) DEFAULT 0.00,
    vat_percent DECIMAL(5,2) DEFAULT 0.00,
    vat_amount DECIMAL(10,2) DEFAULT 0.00,
    total DECIMAL(10,2) NOT NULL,
    paid_amount DECIMAL(10,2) DEFAULT 0.00,
    status ENUM('unpaid','paid','partial') DEFAULT 'unpaid',
    package_id INT DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

#### 5. Payments Table
```sql
CREATE TABLE payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_date DATETIME NOT NULL,
    payment_method ENUM('cash','bank','bkash','nagad','online') NOT NULL,
    received_by INT NOT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### Additional Tables
- **routers**: MikroTik router configurations
- **olts**: OLT device management
- **olt_data**: ONU monitoring data
- **packages**: Service packages
- **employees**: HR management
- **attendance**: Employee attendance
- **income**: Income tracking
- **expenses**: Expense management
- **accounts**: Financial accounts
- **audit_logs**: System audit trail
- **client_ledger**: Client balance tracking
- **client_traffic_log**: Traffic monitoring

## Core Modules

### 1. Authentication & Authorization (`app/auth.php`)
- MD5 password hashing (consider upgrading to bcrypt)
- Session management
- Role-based access control
- Login/logout functionality

### 2. Database Layer (`app/db.php`)
- PDO-based database connection
- Singleton pattern for connection reuse
- Error handling and exception management

### 3. MikroTik Integration (`app/mikrotik.php`)
- RouterOS API integration
- PPPoE user management
- Real-time connection monitoring
- Bulk client control operations

### 4. OLT Management (`app/olt_telnet.php`)
- Telnet/SSH communication with OLT devices
- ONU monitoring and diagnostics
- Optical power level checking
- Command execution and response parsing

### 5. Billing System (`app/billing_helpers.php`)
- Invoice calculation with VAT support
- Payment processing
- Ledger balance management
- Automated billing workflows

### 6. Helper Functions (`app/helpers.php`)
- Logging system
- SMS/Email notifications (placeholder implementations)
- MAC address normalization
- File and directory utilities

## API Endpoints

### Client Management APIs
- `GET /api/client_status.php` - Get client connection status
- `POST /api/client_change_package.php` - Change client package
- `POST /api/client_restore.php` - Restore suspended client
- `POST /api/client_left_toggle.php` - Toggle client left status

### OLT Management APIs
- `POST /api/olt_run.php` - Execute OLT commands
- `GET /api/onu_power.php` - Get ONU optical power levels
- `POST /api/onu_action.php` - Perform ONU actions (reboot, diagnostics)
- `GET /api/pon_scan.php` - Scan PON ports

### Bulk Operations APIs
- `POST /api/bulk_control.php` - Bulk enable/disable clients
- `POST /api/bulk_notify.php` - Send bulk notifications
- `POST /api/bulk_profile.php` - Bulk profile management

### Billing APIs
- `POST /api/invoice_create.php` - Create new invoice
- `POST /api/invoice_mark_paid.php` - Mark invoice as paid
- `POST /api/payment_mark_paid.php` - Process payment
- `GET /api/get_package_price.php` - Get package pricing

### Network Monitoring APIs
- `GET /api/client_live_status.php` - Real-time client status
- `GET /api/traffic_graph.php` - Traffic usage graphs
- `GET /api/olt_mac_refresh.php` - Refresh OLT MAC tables

## User Interfaces

### Admin Portal (`/public/`)

#### Dashboard (`/public/index.php`)
- System overview with KPIs
- Client statistics (total, online, offline)
- Financial summary
- Quick action buttons
- Recent activity feed

#### Client Management (`/public/clients.php`)
- Client listing with advanced filtering
- Search functionality
- Status management
- Bulk operations
- Export capabilities

#### Billing System (`/public/billing.php`)
- Month-wise billing overview
- Invoice management
- Payment processing
- Due date tracking
- Financial reports

#### Network Management
- **OLT Management** (`/public/olts.php`): OLT device configuration
- **Router Management** (`/public/routers.php`): MikroTik router setup
- **ONU Monitoring** (`/public/onu_monitor.php`): Real-time ONU status

#### Financial Management
- **Income/Expense** (`/public/income_expense.php`): Financial tracking
- **Wallets** (`/public/wallets.php`): Digital wallet system
- **Reports** (`/public/reports/`): Various financial reports

### Client Portal (`/public/portal/`)

#### Client Dashboard (`/public/portal/index.php`)
- Personal account overview
- Service status
- Ledger balance
- Quick actions

#### Billing & Payments
- **Invoices** (`/public/portal/invoices.php`): View invoices
- **Payments** (`/public/portal/payments.php`): Payment history
- **Billing Overview** (`/public/portal/billing_overview.php`): Account summary

#### Support
- **Tickets** (`/public/portal/tickets.php`): Support ticket system
- **Profile** (`/public/portal/profile.php`): Account settings

## Installation & Setup

### Prerequisites
- PHP 8.0 or higher
- MySQL 5.7+ or MariaDB 10.3+
- Web server (Apache/Nginx)
- Composer
- XAMPP/WAMP/LAMP stack

### Installation Steps

1. **Clone/Download the system**
   ```bash
   # Place files in your web server directory
   # For XAMPP: C:\xampp\htdocs\
   # For Linux: /var/www/html/
   ```

2. **Database Setup**
   ```bash
   # Import the database
   mysql -u root -p < isp_billing.sql
   ```

3. **Install Dependencies**
   ```bash
   composer install
   ```

4. **Configure Database**
   ```php
   // Edit app/config.php
   define('DB_HOST', 'localhost');
   define('DB_USER', 'root');
   define('DB_PASS', 'your_password');
   define('DB_NAME', 'isp_billing');
   ```

5. **Set Permissions**
   ```bash
   chmod 755 storage/logs/
   chmod 755 uploads/
   ```

6. **Configure Web Server**
   - Ensure mod_rewrite is enabled
   - Set document root to the project directory
   - Configure virtual host if needed

## Configuration

### Database Configuration (`app/config.php`)
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'isp_billing');
define('SESSION_LIFETIME', 3600);
date_default_timezone_set('Asia/Dhaka');
```

### Router Configuration
Add router details in the `routers` table:
```sql
INSERT INTO routers (name, ip, username, password, api_port, status) 
VALUES ('Main Router', '192.168.1.1', 'admin', 'password', 8728, 1);
```

### OLT Configuration
Add OLT devices in the `olts` table:
```sql
INSERT INTO olts (name, ip, username, password, vendor, status) 
VALUES ('Main OLT', '192.168.1.100', 'admin', 'password', 'VSOL', 1);
```

### Package Setup
Create service packages:
```sql
INSERT INTO packages (name, speed, price, validity, description) 
VALUES ('Home 20 Mbps', '20 Mbps', 1000.00, 30, 'Home internet package');
```

## Usage Guide

### Admin Operations

#### 1. Adding New Clients
1. Navigate to **Clients** → **Add Client**
2. Fill in client details:
   - Client Code (unique identifier)
   - Name and contact information
   - PPPoE credentials
   - Package selection
   - Router assignment
3. Save and activate the client

#### 2. Managing Billing
1. **Generate Invoices**:
   - Go to **Billing** → **Generate Invoices**
   - Select billing month
   - Choose clients or generate for all
2. **Process Payments**:
   - Navigate to **Payments** → **Add Payment**
   - Select invoice and payment method
   - Record payment details

#### 3. Network Management
1. **OLT Operations**:
   - Go to **OLT** → **OLT Management**
   - Select OLT device
   - Execute commands or monitor ONUs
2. **Router Control**:
   - Navigate to **Routers** → **Router Management**
   - Enable/disable clients
   - Monitor active sessions

#### 4. Monitoring & Reports
1. **Client Status**: Real-time online/offline status
2. **Traffic Monitoring**: Bandwidth usage graphs
3. **Financial Reports**: Income, expenses, and profitability
4. **Audit Logs**: System activity tracking

### Client Portal Operations

#### 1. Account Overview
- View service status and package details
- Check ledger balance
- Monitor connection status

#### 2. Billing & Payments
- View invoices and payment history
- Download invoice PDFs
- Check due dates and amounts

#### 3. Support
- Create support tickets
- View ticket status
- Update profile information

## Maintenance & Monitoring

### Scheduled Tasks (`cron/`)

#### 1. Automated Billing (`cron/auto_billing.php`)
- Generates monthly invoices
- Sends payment reminders
- Updates client statuses

#### 2. Network Monitoring (`cron/olt_poll.php`)
- Polls OLT devices for status
- Updates ONU information
- Monitors optical power levels

#### 3. Database Maintenance (`cron/db_backup.php`)
- Automated database backups
- Log rotation
- Cleanup old data

#### 4. Client Management (`cron/auto_inactive.php`)
- Identifies inactive clients
- Sends suspension warnings
- Updates client statuses

### Log Management
- **Application Logs**: `storage/logs/monitor.log`
- **Error Logs**: `storage/logs/error.log`
- **Audit Logs**: Database `audit_logs` table

### Performance Optimization
1. **Database Indexing**: Ensure proper indexes on frequently queried columns
2. **Caching**: Implement Redis/Memcached for session and data caching
3. **CDN**: Use CDN for static assets
4. **Database Optimization**: Regular ANALYZE and OPTIMIZE operations

## Troubleshooting

### Common Issues

#### 1. Database Connection Errors
```php
// Check app/config.php settings
// Verify MySQL service is running
// Test connection with:
$pdo = new PDO($dsn, $username, $password);
```

#### 2. Router API Connection Issues
- Verify router IP and credentials
- Check RouterOS API port (default: 8728)
- Ensure router allows API connections
- Test with RouterOS Winbox

#### 3. OLT Communication Problems
- Verify OLT IP and credentials
- Check telnet/SSH connectivity
- Review OLT vendor compatibility
- Test with manual telnet connection

#### 4. Session Issues
- Check PHP session configuration
- Verify session storage permissions
- Clear browser cookies and cache
- Check SESSION_LIFETIME setting

#### 5. File Permission Errors
```bash
# Set proper permissions
chmod 755 storage/
chmod 755 uploads/
chmod 644 app/config.php
```

### Debug Mode
Enable debug mode in `app/config.php`:
```php
error_reporting(E_ALL);
ini_set('display_errors', 1);
```

### Log Analysis
Check logs for error patterns:
```bash
tail -f storage/logs/monitor.log
tail -f storage/logs/error.log
```

## Security Considerations

### 1. Password Security
- Upgrade from MD5 to bcrypt for password hashing
- Implement password complexity requirements
- Add password reset functionality

### 2. Database Security
- Use prepared statements (already implemented)
- Implement database user with minimal privileges
- Enable SSL for database connections

### 3. Session Security
- Use secure session cookies
- Implement session regeneration
- Add CSRF protection

### 4. File Upload Security
- Validate file types and sizes
- Store uploads outside web root
- Scan uploaded files for malware

## Future Enhancements

### 1. Security Improvements
- Implement OAuth2 authentication
- Add two-factor authentication
- Upgrade password hashing to bcrypt
- Add CSRF protection

### 2. Performance Optimizations
- Implement Redis caching
- Add database query optimization
- Use CDN for static assets
- Implement lazy loading

### 3. Feature Additions
- Mobile app development
- Advanced reporting dashboard
- Automated network diagnostics
- Integration with payment gateways
- Multi-language support

### 4. Monitoring & Alerting
- Real-time system monitoring
- Automated alert system
- Performance metrics dashboard
- Health check endpoints

---

## Support & Documentation

For additional support or documentation updates, please refer to:
- System logs in `storage/logs/`
- Database schema in `isp_billing.sql`
- API documentation in individual endpoint files
- User manual in `User Manual.txt`

This guide provides a comprehensive overview of the ISP Billing & Management System. For specific implementation details, refer to the source code and inline documentation.
