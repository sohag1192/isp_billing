-- ISP Billing & Management Software Database
-- Author: SWAPON MAHMUD Project Plan
-- Engine: InnoDB, Charset: utf8mb4

CREATE DATABASE IF NOT EXISTS isp_billing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE isp_billing;

-- 1. Users Table
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('admin','manager','branch_manager') DEFAULT 'admin',
    status TINYINT(1) DEFAULT 1,
    last_login DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 2. Clients Table
CREATE TABLE clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_code VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(100) NOT NULL,
    pppoe_id VARCHAR(50) UNIQUE NOT NULL,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(100) DEFAULT NULL,
    address TEXT NOT NULL,
    package_id INT NOT NULL,
    join_date DATE NOT NULL,
    status ENUM('active','inactive','expired','pending','left') DEFAULT 'pending',
    remarks TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 3. Packages Table
CREATE TABLE packages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    speed VARCHAR(50) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    validity INT NOT NULL,
    description TEXT DEFAULT NULL
) ENGINE=InnoDB;

-- 4. Bills Table
CREATE TABLE bills (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    bill_month VARCHAR(7) NOT NULL,
    due_date DATE NOT NULL,
    status ENUM('paid','due') DEFAULT 'due',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 5. Payments Table
CREATE TABLE payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bill_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_date DATETIME NOT NULL,
    payment_method ENUM('cash','bank','bkash','nagad','online') NOT NULL,
    received_by INT NOT NULL,
    FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE CASCADE,
    FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 6. Employees Table
CREATE TABLE employees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    designation VARCHAR(50) NOT NULL,
    salary DECIMAL(10,2) NOT NULL,
    join_date DATE NOT NULL,
    status TINYINT(1) DEFAULT 1
) ENGINE=InnoDB;

-- 7. Attendance Table
CREATE TABLE attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    date DATE NOT NULL,
    status ENUM('present','absent','leave') NOT NULL,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 8. Income Table
CREATE TABLE income (
    id INT AUTO_INCREMENT PRIMARY KEY,
    source VARCHAR(100) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    date DATE NOT NULL,
    notes TEXT DEFAULT NULL
) ENGINE=InnoDB;

-- 9. Expenses Table
CREATE TABLE expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(100) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    date DATE NOT NULL,
    notes TEXT DEFAULT NULL
) ENGINE=InnoDB;

-- 10. OLT Data Table
CREATE TABLE olt_data (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    onu_serial VARCHAR(50) NOT NULL,
    rx_power VARCHAR(20) NOT NULL,
    timestamp DATETIME NOT NULL,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 11. Audit Log Table
CREATE TABLE audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    action TEXT NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 12. Settings Table
CREATE TABLE settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT NOT NULL
) ENGINE=InnoDB;



-- 13. Routers Table
CREATE TABLE routers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    type ENUM('mikrotik','olt','switch','other') NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    username VARCHAR(50) NOT NULL,
    password VARCHAR(255) NOT NULL,
    api_port INT DEFAULT 8728,
    snmp_community VARCHAR(50) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    status TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
