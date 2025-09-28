-- phpMyAdmin SQL Dump
-- version 5.1.1deb5ubuntu1
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Sep 28, 2025 at 03:37 AM
-- Server version: 10.6.22-MariaDB-0ubuntu0.22.04.1
-- PHP Version: 8.1.2-1ubuntu2.22

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `isp_billing`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `add_column_if_missing` (IN `in_schema` VARCHAR(64), IN `in_table` VARCHAR(64), IN `in_column` VARCHAR(64), IN `in_ddl` TEXT)  BEGIN
  DECLARE cnt INT DEFAULT 0;
  SELECT COUNT(*) INTO cnt
    FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = in_schema
     AND TABLE_NAME   = in_table
     AND COLUMN_NAME  = in_column;
  IF cnt = 0 THEN
    SET @sql := in_ddl;
    PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
  END IF;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `add_column_if_not_exists` (IN `tbl_name` VARCHAR(64), IN `col_name` VARCHAR(64), IN `col_def` TEXT)  BEGIN
    DECLARE col_count INT;
    SELECT COUNT(*) INTO col_count
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = tbl_name
      AND COLUMN_NAME = col_name;

    IF col_count = 0 THEN
        SET @s = CONCAT('ALTER TABLE `', tbl_name, '` ADD COLUMN `', col_name, '` ', col_def);
        PREPARE stmt FROM @s;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `add_fk_if_missing` (IN `in_schema` VARCHAR(64), IN `in_table` VARCHAR(64), IN `ref_table` VARCHAR(64), IN `in_sql` TEXT)  BEGIN
  DECLARE cnt INT DEFAULT 0;
  SELECT COUNT(*) INTO cnt
    FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
   WHERE CONSTRAINT_SCHEMA = in_schema
     AND TABLE_NAME        = in_table
     AND REFERENCED_TABLE_NAME = ref_table;
  IF cnt = 0 THEN
    SET @sql := in_sql;
    PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
  END IF;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `show_all_create_tables` (IN `db_name` VARCHAR(64))  BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE tbl_name VARCHAR(64);
    DECLARE cur CURSOR FOR 
        SELECT table_name 
        FROM information_schema.tables
        WHERE table_schema = db_name;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;

    OPEN cur;
    read_loop: LOOP
        FETCH cur INTO tbl_name;
        IF done THEN
            LEAVE read_loop;
        END IF;
        SET @qry = CONCAT('SHOW CREATE TABLE `', db_name, '`.`', tbl_name, '`;');
        PREPARE stmt FROM @qry;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END LOOP;
    CLOSE cur;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_sync_invoice_totals` (IN `p_bill_id` INT UNSIGNED)  BEGIN
  DECLARE v_amount    DECIMAL(10,2);
  DECLARE v_discount  DECIMAL(10,2);
  DECLARE v_paid      DECIMAL(10,2);
  DECLARE v_due       DECIMAL(10,2);

  SELECT amount, discount, IFNULL(paid_amount,0)
  INTO v_amount, v_discount, v_paid
  FROM invoices WHERE id = p_bill_id LIMIT 1;

  -- টেবিল payments থেকে মোট paid/discount (সেফ থাকার জন্য)
  SELECT IFNULL(SUM(amount),0), IFNULL(SUM(discount),0)
  INTO v_paid, v_discount
  FROM payments WHERE bill_id = p_bill_id;

  SET v_due = (v_amount - v_discount) - v_paid;

  UPDATE invoices
     SET paid_amount = v_paid,
         discount    = v_discount,
         status      = CASE
                         WHEN v_due <= 0 THEN 'paid'
                         WHEN v_paid > 0 THEN 'partial'
                         ELSE 'due'
                       END
   WHERE id = p_bill_id;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `accounts`
--

CREATE TABLE `accounts` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `type` enum('cash','bank','mfs','other','user') DEFAULT 'user',
  `number` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `user_id` int(11) DEFAULT NULL,
  `balance` decimal(12,2) DEFAULT 0.00,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `accounts`
--

INSERT INTO `accounts` (`id`, `name`, `type`, `number`, `is_active`, `created_at`, `user_id`, `balance`, `updated_at`) VALUES
(13, 'Wallet of Super Admin', 'user', NULL, 1, '2025-09-07 05:30:53', 1, '1009941.00', NULL),
(16, 'Wallet of swapon', 'user', NULL, 1, '2025-09-10 04:15:35', 20, '66975.00', NULL),
(17, 'Wallet of Durjoy', 'user', NULL, 1, '2025-09-16 14:32:11', 21, '0.00', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `status` enum('present','absent','leave') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL DEFAULT 0,
  `action` varchar(100) NOT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `entity` varchar(64) NOT NULL,
  `entity_id` bigint(20) DEFAULT NULL,
  `old_json` longtext DEFAULT NULL,
  `new_json` longtext DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`id`, `user_id`, `action`, `details`, `created_at`, `entity`, `entity_id`, `old_json`, `new_json`, `ip`, `user_agent`) VALUES
(1, 20, '8', NULL, '2025-09-15 07:55:37', 'client.left', 21783, '{\"pppoe_id\":\"Sunny Leone\",\"name\":\"Sunny Leone\",\"before\":{\"is_left\":0,\"left_at\":null},\"after\":{\"is_left\":1,\"left_at\":\"NOW()\"},\"source\":\"client_left_toggle.php\"}', NULL, '103.118.77.156', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36'),
(2, 20, '8', NULL, '2025-09-15 08:24:22', 'client.left', 21778, '{\"pppoe_id\":\"96545567\",\"name\":\"Durakuti\",\"before\":{\"is_left\":0,\"left_at\":null},\"after\":{\"is_left\":1,\"left_at\":\"NOW()\"},\"source\":\"client_left_toggle.php\"}', NULL, '103.221.67.208', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36'),
(3, 20, '8', NULL, '2025-09-15 08:24:25', 'client.left', 21777, '{\"pppoe_id\":\"asdasd\",\"name\":\"adas4\",\"before\":{\"is_left\":0,\"left_at\":null},\"after\":{\"is_left\":1,\"left_at\":\"NOW()\"},\"source\":\"client_left_toggle.php\"}', NULL, '103.221.67.208', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36'),
(4, 1, '8', NULL, '2025-09-15 11:19:17', 'client.undo_left', 21783, '{\"pppoe_id\":\"Sunny Leone\",\"name\":\"Sunny Leone\",\"before\":{\"is_left\":1,\"left_at\":\"2025-09-15 13:55:37\"},\"after\":{\"is_left\":0,\"left_at\":null},\"source\":\"client_left_toggle.php\"}', NULL, '103.175.242.18', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36'),
(5, 1, '8', NULL, '2025-09-15 11:19:19', 'client.undo_left', 21778, '{\"pppoe_id\":\"96545567\",\"name\":\"Durakuti\",\"before\":{\"is_left\":1,\"left_at\":\"2025-09-15 14:24:21\"},\"after\":{\"is_left\":0,\"left_at\":null},\"source\":\"client_left_toggle.php\"}', NULL, '103.175.242.18', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36'),
(6, 1, '8', NULL, '2025-09-15 11:19:21', 'client.undo_left', 21777, '{\"pppoe_id\":\"asdasd\",\"name\":\"adas4\",\"before\":{\"is_left\":1,\"left_at\":\"2025-09-15 14:24:25\"},\"after\":{\"is_left\":0,\"left_at\":null},\"source\":\"client_left_toggle.php\"}', NULL, '103.175.242.18', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36');

-- --------------------------------------------------------

--
-- Table structure for table `auth_logins`
--

CREATE TABLE `auth_logins` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) DEFAULT NULL,
  `username` varchar(191) DEFAULT NULL,
  `event` enum('login','logout','failed') NOT NULL DEFAULT 'login',
  `success` tinyint(1) NOT NULL DEFAULT 1,
  `ip` varchar(45) NOT NULL,
  `user_agent` text DEFAULT NULL,
  `session_id` varchar(191) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bills`
--

CREATE TABLE `bills` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `bill_month` varchar(7) NOT NULL,
  `due_date` date NOT NULL,
  `status` enum('paid','due') DEFAULT 'due',
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bkash_inbox`
--

CREATE TABLE `bkash_inbox` (
  `id` bigint(20) NOT NULL,
  `raw_text` text NOT NULL,
  `sender_msisdn` varchar(20) DEFAULT NULL,
  `amount` decimal(12,2) DEFAULT NULL,
  `trxid` varchar(32) DEFAULT NULL,
  `sms_time` datetime DEFAULT NULL,
  `direction` enum('in','out') DEFAULT 'in',
  `status` enum('new','parsed','matched','applied','ignored','error') DEFAULT 'new',
  `error_msg` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `clients`
--

CREATE TABLE `clients` (
  `id` int(11) NOT NULL,
  `mobile` varchar(20) DEFAULT NULL,
  `router_id` int(11) DEFAULT NULL,
  `olt_id` int(10) UNSIGNED DEFAULT NULL,
  `client_code` varchar(50) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `pppoe_id` varchar(50) NOT NULL,
  `ap_mac` varchar(32) DEFAULT NULL,
  `pppoe_pass` varchar(150) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `payment_method` varchar(100) DEFAULT NULL,
  `payment_status` varchar(100) DEFAULT NULL,
  `last_payment_date` varchar(100) DEFAULT NULL,
  `monthly_bill` varchar(100) DEFAULT NULL,
  `ledger_balance` decimal(14,2) NOT NULL DEFAULT 0.00,
  `expiry_date` varchar(100) DEFAULT NULL,
  `ip_address` varchar(100) DEFAULT NULL,
  `router_mac` varchar(17) DEFAULT NULL,
  `connection_type` varchar(100) DEFAULT NULL,
  `nid` varchar(100) DEFAULT NULL,
  `area` varchar(100) DEFAULT NULL,
  `package_id` int(11) NOT NULL,
  `join_date` date NOT NULL,
  `expire_date` date DEFAULT NULL,
  `status` enum('active','inactive','expired','pending','left') DEFAULT 'pending',
  `is_whitelist` tinyint(1) NOT NULL DEFAULT 0,
  `auto_control_optout` tinyint(1) NOT NULL DEFAULT 0,
  `is_vip` tinyint(1) NOT NULL DEFAULT 0,
  `is_left` tinyint(1) NOT NULL DEFAULT 0,
  `left_at` datetime DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_online` tinyint(1) DEFAULT 0,
  `is_deleted` tinyint(1) DEFAULT 0,
  `next_due_date` date DEFAULT NULL,
  `photo_url` varchar(255) DEFAULT NULL,
  `last_logout_at` datetime DEFAULT NULL,
  `suspend_by_billing` tinyint(1) NOT NULL DEFAULT 0,
  `suspended_at` datetime DEFAULT NULL,
  `bkash_msisdn` varchar(20) DEFAULT NULL,
  `lat` decimal(10,7) DEFAULT NULL,
  `lng` decimal(10,7) DEFAULT NULL,
  `reseller_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `clients`
--

INSERT INTO `clients` (`id`, `mobile`, `router_id`, `olt_id`, `client_code`, `name`, `pppoe_id`, `ap_mac`, `pppoe_pass`, `email`, `address`, `payment_method`, `payment_status`, `last_payment_date`, `monthly_bill`, `ledger_balance`, `expiry_date`, `ip_address`, `router_mac`, `connection_type`, `nid`, `area`, `package_id`, `join_date`, `expire_date`, `status`, `is_whitelist`, `auto_control_optout`, `is_vip`, `is_left`, `left_at`, `remarks`, `created_at`, `updated_at`, `is_online`, `is_deleted`, `next_due_date`, `photo_url`, `last_logout_at`, `suspend_by_billing`, `suspended_at`, `bkash_msisdn`, `lat`, `lng`, `reseller_id`) VALUES
(21778, '01943922530', 8, NULL, '456789', 'Durakuti', '96545567', NULL, '96543456', NULL, 'lalmonirhat,durakuti', NULL, 'clear', '2025-09-15 13:05:00', '1000', '0.00', '2025-09-26', NULL, NULL, NULL, NULL, NULL, 5, '0000-00-00', NULL, 'inactive', 0, 0, 0, 0, NULL, NULL, '2025-09-15 04:52:47', '2025-09-15 11:19:19', 0, 0, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL),
(21784, '01713821678', 8, NULL, '821215', 'Saddam', '821215', NULL, '123456', '', 'West kawnia', NULL, NULL, NULL, '1000', '1000.00', '2026-04-21', NULL, NULL, NULL, NULL, 'Barishal', 5, '0000-00-00', NULL, 'active', 0, 0, 0, 0, NULL, NULL, '2025-09-15 07:14:04', '2025-09-15 11:30:22', 0, 0, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL),
(21785, '017536482886', 8, NULL, '76829', 'Nkshi', 'njk97', NULL, '1234', '', 'Jskan', NULL, 'clear', '2025-09-15 19:35:00', '500', '-11075.00', NULL, NULL, NULL, NULL, NULL, 'Dikaj', 2, '0000-00-00', NULL, 'active', 0, 0, 0, 0, NULL, NULL, '2025-09-15 07:46:33', '2025-09-15 17:16:46', 0, 0, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL),
(21786, '01779544880', 8, NULL, '5826', 'MD.SHAHIN ISLAM', 'shahin@s2', NULL, '1234', 'aishaakta@gmail.com', 'Sunatola', NULL, 'clear', '2032-01-15 23:05:00', '1000', '-2000.00', '2026-04-20', NULL, NULL, NULL, NULL, 'Bogura', 5, '0000-00-00', NULL, 'active', 0, 0, 0, 0, NULL, NULL, '2025-09-15 07:55:56', '2025-09-15 17:06:23', 0, 0, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL),
(21788, '01779544881', 8, NULL, '582600', 'MD.SHAHIN ISLAM', 'shahin', NULL, '1234', 'aishaakta@gmail.com', 'Sunatola', NULL, 'clear', '2025-09-15 14:45:00', '1000', '0.00', '2145-06-15', NULL, NULL, NULL, NULL, 'Bogura', 5, '0000-00-00', NULL, 'active', 0, 0, 0, 0, NULL, NULL, '2025-09-15 07:56:49', '2025-09-20 16:00:34', 0, 0, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL),
(21805, NULL, 8, NULL, NULL, 'server', 'server', NULL, '9991', NULL, NULL, NULL, NULL, NULL, '600', '600.00', NULL, NULL, NULL, NULL, NULL, NULL, 4, '0000-00-00', NULL, 'active', 0, 0, 0, 0, NULL, NULL, '2025-09-15 17:50:53', '2025-09-16 09:21:53', 0, 0, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL),
(21806, NULL, 8, NULL, NULL, 'ap', 'ap', NULL, '9991', NULL, NULL, NULL, 'clear', '2025-09-16 17:55:00', '1000', '0.00', NULL, NULL, NULL, NULL, NULL, NULL, 5, '0000-00-00', NULL, 'active', 0, 0, 0, 0, NULL, NULL, '2025-09-16 09:22:42', '2025-09-16 11:55:43', 0, 0, NULL, '/uploads/clients/ap.jpg', NULL, 0, NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `client_ledger`
--

CREATE TABLE `client_ledger` (
  `client_id` int(11) NOT NULL,
  `balance` decimal(14,2) NOT NULL DEFAULT 0.00,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `client_traffic_log`
--

CREATE TABLE `client_traffic_log` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `log_time` datetime NOT NULL,
  `rx_speed` int(11) NOT NULL,
  `tx_speed` int(11) NOT NULL,
  `total_download_gb` decimal(10,2) NOT NULL,
  `total_upload_gb` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cron_runs`
--

CREATE TABLE `cron_runs` (
  `id` int(10) UNSIGNED NOT NULL,
  `job_key` varchar(64) NOT NULL,
  `title` varchar(255) NOT NULL,
  `status` enum('success','failed') DEFAULT NULL,
  `started_at` datetime NOT NULL,
  `finished_at` datetime DEFAULT NULL,
  `duration_ms` int(10) UNSIGNED DEFAULT NULL,
  `output` mediumtext DEFAULT NULL,
  `error` mediumtext DEFAULT NULL,
  `triggered_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`id`, `name`, `created_at`, `updated_at`) VALUES
(1, 'Accounts', '2025-09-01 12:09:14', '2025-09-01 12:09:14'),
(2, 'Support', '2025-09-01 12:09:14', '2025-09-01 12:09:14'),
(3, 'Network', '2025-09-01 12:09:14', '2025-09-01 12:09:14'),
(4, 'HR', '2025-09-01 12:09:14', '2025-09-01 12:09:14');

-- --------------------------------------------------------

--
-- Table structure for table `employees`
--

CREATE TABLE `employees` (
  `emp_id` int(25) NOT NULL,
  `name` varchar(100) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `designation` varchar(50) NOT NULL,
  `salary` decimal(10,2) NOT NULL,
  `join_date` date NOT NULL,
  `status` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `employees_roles`
--

CREATE TABLE `employees_roles` (
  `emp_id` varchar(40) NOT NULL,
  `role_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `employee_payments`
--

CREATE TABLE `employee_payments` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `method` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `employee_code` varchar(64) DEFAULT NULL,
  `wallet_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `expenses`
--

CREATE TABLE `expenses` (
  `id` int(11) NOT NULL,
  `category` varchar(100) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `date` date NOT NULL,
  `notes` text DEFAULT NULL,
  `paid_at` datetime NOT NULL,
  `account_id` int(11) DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` bigint(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `expenses`
--

INSERT INTO `expenses` (`id`, `category`, `amount`, `date`, `notes`, `paid_at`, `account_id`, `category_id`, `is_deleted`, `deleted_at`, `deleted_by`) VALUES
(2, 'Employee Salary', '60000.00', '0000-00-00', NULL, '2025-09-07 10:13:00', 4, 3, 1, '2025-09-14 14:26:39', 1);

-- --------------------------------------------------------

--
-- Table structure for table `expense_accounts`
--

CREATE TABLE `expense_accounts` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `type` varchar(20) DEFAULT NULL,
  `opening_balance` decimal(14,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `expense_accounts`
--

INSERT INTO `expense_accounts` (`id`, `name`, `type`, `opening_balance`, `is_active`, `created_at`) VALUES
(1, 'Cash', 'Cash', '0.00', 1, '2025-08-28 12:23:41'),
(2, 'bKash Merchant', 'Mobile', '0.00', 1, '2025-08-28 12:23:41');

-- --------------------------------------------------------

--
-- Table structure for table `expense_categories`
--

CREATE TABLE `expense_categories` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `expense_categories`
--

INSERT INTO `expense_categories` (`id`, `name`, `parent_id`, `is_active`, `created_at`) VALUES
(1, 'Upstream Bill', NULL, 1, '2025-08-28 11:29:47'),
(2, 'Network Purchase', NULL, 1, '2025-08-28 11:29:47'),
(3, 'Employee Salary', NULL, 1, '2025-08-28 11:29:47'),
(4, 'Office Rent', NULL, 1, '2025-08-28 11:29:47'),
(5, 'Utility', NULL, 1, '2025-08-28 11:29:47'),
(6, 'Other', NULL, 1, '2025-08-28 11:29:47');

-- --------------------------------------------------------

--
-- Table structure for table `income`
--

CREATE TABLE `income` (
  `id` int(11) NOT NULL,
  `source` varchar(100) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `date` date NOT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `invoices`
--

CREATE TABLE `invoices` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `months` tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `billing_month` date NOT NULL,
  `is_auto_generated` tinyint(1) DEFAULT 0,
  `package_id` int(11) UNSIGNED DEFAULT NULL,
  `invoice_number` varchar(50) NOT NULL,
  `invoice_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `discount` decimal(10,2) DEFAULT 0.00,
  `vat_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `vat_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payable` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(10,2) NOT NULL,
  `paid_amount` decimal(10,2) DEFAULT 0.00,
  `status` enum('unpaid','paid','partial') DEFAULT 'unpaid',
  `is_void` tinyint(1) NOT NULL DEFAULT 0,
  `paid_at` datetime DEFAULT NULL,
  `method` varchar(32) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reseller_id` int(11) DEFAULT NULL,
  `reseller_price` decimal(10,2) DEFAULT NULL,
  `reseller_commission` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `invoices`
--

INSERT INTO `invoices` (`id`, `client_id`, `period_start`, `period_end`, `months`, `amount`, `billing_month`, `is_auto_generated`, `package_id`, `invoice_number`, `invoice_date`, `due_date`, `subtotal`, `discount`, `vat_percent`, `vat_amount`, `total`, `payable`, `total_amount`, `paid_amount`, `status`, `is_void`, `paid_at`, `method`, `note`, `notes`, `created_by`, `created_at`, `reseller_id`, `reseller_price`, `reseller_commission`) VALUES
(4316, 21778, '2025-09-01', '2025-09-30', 1, '0.00', '2025-09-01', 0, NULL, 'INV-202509-21778-376F', '2025-09-15', '2025-09-22', '0.00', '0.00', '0.00', '0.00', '1000.00', '0.00', '0.00', '1000.00', 'paid', 0, '2025-09-15 13:05:00', 'Cash', NULL, NULL, NULL, '2025-09-15 04:52:47', NULL, NULL, NULL),
(4321, 21784, '2025-09-01', '2025-09-30', 1, '0.00', '2025-09-01', 0, NULL, 'INV-202509-21784-F7C2', '2025-09-15', '2025-09-22', '0.00', '0.00', '0.00', '0.00', '1000.00', '0.00', '0.00', '0.00', 'unpaid', 0, NULL, NULL, NULL, NULL, NULL, '2025-09-15 07:14:04', NULL, NULL, NULL),
(4322, 21785, '2025-09-01', '2025-09-30', 1, '0.00', '2025-09-01', 0, NULL, 'INV-202509-21785-13A1', '2025-09-15', '2025-09-22', '0.00', '0.00', '0.00', '0.00', '500.00', '0.00', '0.00', '0.00', 'unpaid', 0, NULL, NULL, NULL, NULL, NULL, '2025-09-15 07:46:33', NULL, NULL, NULL),
(4323, 21786, '2025-09-01', '2025-09-30', 1, '0.00', '2025-09-01', 0, NULL, 'INV-202509-21786-7509', '2025-09-15', '2025-09-22', '0.00', '0.00', '0.00', '0.00', '1000.00', '0.00', '0.00', '3000.00', 'paid', 0, '2032-01-15 23:05:00', 'Cash', NULL, NULL, NULL, '2025-09-15 07:55:56', NULL, NULL, NULL),
(4324, 21788, '2025-09-01', '2025-09-30', 1, '0.00', '2025-09-01', 0, NULL, 'INV-202509-21788-5810', '2025-09-15', '2025-09-22', '0.00', '0.00', '0.00', '0.00', '1000.00', '0.00', '0.00', '1000.00', 'paid', 0, '2025-09-15 14:45:00', 'Cash', NULL, NULL, NULL, '2025-09-15 07:56:49', NULL, NULL, NULL),
(4327, 21785, '2025-09-15', '2025-09-16', 1, '500.00', '2025-09-15', 0, 2, 'INV-202509-0014', '2025-09-15', '2025-09-16', '500.00', '0.00', '5.00', '25.00', '525.00', '525.00', '525.00', '11575.00', 'paid', 0, '2025-09-15 19:35:00', 'BKash', NULL, NULL, NULL, '2025-09-15 10:56:09', NULL, NULL, NULL),
(4333, 21805, '2025-09-01', '2025-09-30', 1, '0.00', '2025-09-01', 0, NULL, 'INV-202509-21805-9787', '2025-09-15', '2025-09-22', '0.00', '0.00', '0.00', '0.00', '600.00', '0.00', '0.00', '0.00', 'unpaid', 0, NULL, NULL, NULL, NULL, NULL, '2025-09-15 17:50:53', NULL, NULL, NULL),
(4334, 21806, '2025-09-01', '2025-09-30', 1, '0.00', '2025-09-01', 0, NULL, 'INV-202509-21806-A146', '2025-09-16', '2025-09-23', '0.00', '0.00', '0.00', '0.00', '1000.00', '0.00', '0.00', '1000.00', 'paid', 0, '2025-09-16 17:55:00', 'Cash', NULL, NULL, NULL, '2025-09-16 09:22:43', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `invoice_items`
--

CREATE TABLE `invoice_items` (
  `id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `description` varchar(255) NOT NULL,
  `quantity` int(11) DEFAULT 1,
  `unit_price` decimal(10,2) NOT NULL,
  `total` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `mac_vendors`
--

CREATE TABLE `mac_vendors` (
  `id` int(11) NOT NULL,
  `mac_prefix` char(6) NOT NULL,
  `vendor` varchar(255) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `menu_overrides`
--

CREATE TABLE `menu_overrides` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `menu_key` varchar(191) NOT NULL,
  `allowed` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `menu_overrides`
--

INSERT INTO `menu_overrides` (`id`, `user_id`, `menu_key`, `allowed`, `sort_order`) VALUES
(1, 9, 'dashboard', 0, NULL),
(2, 9, 'clients', 0, NULL),
(3, 9, 'client_add', 0, NULL),
(4, 9, 'billing', 1, NULL),
(5, 9, 'invoices', 1, NULL),
(6, 9, 'payments', 1, NULL),
(7, 9, 'routers', 1, NULL),
(8, 9, 'packages', 1, NULL),
(9, 9, 'employees', 1, NULL),
(10, 9, 'employee_add', 1, NULL),
(11, 9, 'hr_toggle', 1, NULL),
(12, 9, 'roles_perms', 1, NULL),
(13, 9, 'users_menu_access', 1, NULL),
(14, 9, 'settings', 1, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `network_areas`
--

CREATE TABLE `network_areas` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(120) NOT NULL,
  `color` varchar(16) NOT NULL DEFAULT '#0d6efd',
  `geojson` mediumtext NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `olts`
--

CREATE TABLE `olts` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `vendor` enum('huawei','zte','bdcom','vsol') NOT NULL,
  `host` varchar(100) NOT NULL,
  `ssh_port` int(11) NOT NULL DEFAULT 22,
  `username` varchar(100) NOT NULL,
  `password` varchar(200) NOT NULL,
  `enable_password` varchar(200) DEFAULT NULL,
  `prompt_regex` varchar(200) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `olts`
--

INSERT INTO `olts` (`id`, `name`, `vendor`, `host`, `ssh_port`, `username`, `password`, `enable_password`, `prompt_regex`, `is_active`, `created_at`) VALUES
(1, 'VSOL-Core', 'vsol', '192.168.200.2', 22, 'swapon', 'SWApon9124', '', '(>|#)\\s*$', 1, '2025-09-08 19:08:36');

-- --------------------------------------------------------

--
-- Table structure for table `packages`
--

CREATE TABLE `packages` (
  `id` int(11) NOT NULL,
  `router_id` int(11) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `profile` varchar(100) DEFAULT NULL,
  `speed` varchar(50) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `price` decimal(10,2) NOT NULL,
  `validity` int(11) NOT NULL,
  `description` text DEFAULT NULL,
  `duration_days` int(11) NOT NULL DEFAULT 30,
  `profile_name` varchar(64) DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `packages`
--

INSERT INTO `packages` (`id`, `router_id`, `name`, `profile`, `speed`, `is_active`, `price`, `validity`, `description`, `duration_days`, `profile_name`, `is_deleted`) VALUES
(2, 1, '10Mbps', '10Mbps', '', 1, '500.00', 30, NULL, 30, NULL, 0),
(3, 1, '20Mbps', '20Mbps', '', 1, '800.00', 30, NULL, 30, NULL, 0),
(4, 1, '15Mbps', '15Mbps', '', 1, '600.00', 30, NULL, 30, NULL, 0),
(5, 1, '35Mbps', '35Mbps', '', 1, '1000.00', 30, NULL, 30, NULL, 0),
(6, 1, '5Mbps', '5Mbps', '', 1, '400.00', 30, NULL, 30, NULL, 0);

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL,
  `email` varchar(200) NOT NULL,
  `token` varchar(64) NOT NULL,
  `otp` varchar(10) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL,
  `used` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `bill_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `discount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_date` datetime NOT NULL,
  `method` varchar(32) DEFAULT NULL,
  `txn_id` varchar(100) DEFAULT NULL,
  `remarks` varchar(100) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `paid_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `received_by` int(11) DEFAULT NULL,
  `transaction_id` varchar(100) DEFAULT NULL,
  `account_id` int(11) DEFAULT NULL,
  `wallet_id` int(11) DEFAULT NULL,
  `received_ip` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `reseller_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`id`, `invoice_id`, `client_id`, `bill_id`, `amount`, `discount`, `payment_date`, `method`, `txn_id`, `remarks`, `note`, `paid_at`, `received_by`, `transaction_id`, `account_id`, `wallet_id`, `received_ip`, `created_at`, `reseller_id`) VALUES
(282, 4334, 21806, 0, '1000.00', '0.00', '0000-00-00 00:00:00', 'Cash', NULL, NULL, NULL, '2025-09-16 11:55:00', NULL, NULL, 16, NULL, '2401:1900:1ce:c0e7:fc78:28ff:fead:c5c6', '2025-09-16 17:55:43', NULL);

--
-- Triggers `payments`
--
DELIMITER $$
CREATE TRIGGER `trg_pay_del` AFTER DELETE ON `payments` FOR EACH ROW BEGIN
  CALL sp_sync_invoice_totals(OLD.bill_id);
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_pay_ins` AFTER INSERT ON `payments` FOR EACH ROW BEGIN
  CALL sp_sync_invoice_totals(NEW.bill_id);
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_pay_upd` AFTER UPDATE ON `payments` FOR EACH ROW BEGIN
  CALL sp_sync_invoice_totals(NEW.bill_id);
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `page` varchar(100) NOT NULL,
  `can_view` tinyint(1) DEFAULT 0,
  `can_edit` tinyint(1) DEFAULT 0,
  `can_delete` tinyint(1) DEFAULT 0,
  `perm_key` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `permissions`
--

INSERT INTO `permissions` (`id`, `name`, `page`, `can_view`, `can_edit`, `can_delete`, `perm_key`, `description`) VALUES
(1, 'Add Client', '', 0, 0, 0, 'add.client', NULL),
(2, 'Edit Client', '', 0, 0, 0, 'edit.client', NULL),
(3, 'Delete Client', '', 0, 0, 0, 'delete.client', NULL),
(4, 'View Billing', '', 0, 0, 0, 'view.billing', NULL),
(5, 'Generate Invoice', '', 0, 0, 0, 'generate.invoice', NULL),
(21, 'View All', '', 0, 0, 0, 'view.all', NULL),
(24, 'client.view', '', 1, 0, 0, 'client.view', NULL),
(25, 'Create client', '', 1, 1, 0, 'crate.client', NULL),
(27, 'Mark/Undo Left Client', '', 1, 1, 0, 'mark.left', NULL),
(28, 'View routers', '', 1, 0, 0, 'view.routers', NULL),
(29, 'Edit routers', '', 1, 1, 0, 'edit.routers', NULL),
(30, 'Enable/Disable PPP user', '', 1, 1, 0, 'enable.disable.ppp', NULL),
(31, 'Bulk profile change', '', 1, 1, 0, 'profile.change', NULL),
(32, 'View billing dashboard', '', 1, 0, 0, 'view.billing.dhashboard', NULL),
(33, 'Generate monthly invoices', '', 1, 1, 0, 'generate.monthly.invoice', NULL),
(34, 'Add payment', '', 1, 1, 0, 'add.payments', NULL),
(35, 'View reports', '', 1, 0, 0, 'view.reports', NULL),
(36, 'Export CSV/Excel', '', 1, 0, 0, 'export.csv', NULL),
(37, 'Manage global settings', '', 1, 1, 0, 'manage.global.setting', NULL),
(38, 'User & Role management', '', 1, 1, 0, 'role.management', NULL),
(39, 'View audit log', '', 1, 0, 0, 'view.audit.log', NULL),
(75, 'Account View', '', 0, 0, 0, 'accounts.view', NULL),
(76, 'Account Wallet', '', 0, 0, 0, 'accounts.wallet', NULL),
(81, 'hrm', '', 0, 0, 0, 'hrm.view', 'View employees and profiles'),
(88, 'hr.add', '', 0, 0, 0, 'hr.add', 'Add a new employee'),
(89, 'hr.edit', '', 0, 0, 0, 'hr.edit', 'Edit employee info'),
(90, 'hr.toggle', '', 0, 0, 0, 'hr.toggle', 'Toggle employee status'),
(91, 'hr.export', '', 0, 0, 0, 'hr.export', 'Export employee list'),
(92, 'hr.audit', '', 0, 0, 0, 'hr.audit', 'View HR audit logs'),
(99, 'Dashboard', '', 0, 0, 0, 'view.dashboard', NULL),
(100, 'clients', '', 0, 0, 0, 'clients', NULL),
(101, 'view.all.bill', '', 0, 0, 0, 'view.all.bill', NULL),
(102, 'view.pai.bill', '', 0, 0, 0, 'view.pai.bill', NULL),
(103, 'view.package', '', 0, 0, 0, 'view.package', NULL),
(104, 'view.accounts', '', 0, 0, 0, 'view.accounts', NULL),
(105, 'wallet.approval', '', 0, 0, 0, 'wallet.approval', NULL),
(106, 'wallet.settlement', '', 0, 0, 0, 'wallet.settlement', NULL),
(107, 'expense.view', '', 0, 0, 0, 'expense.view', NULL),
(108, 'expense.add', '', 0, 0, 0, 'expense.add', NULL),
(109, 'olt.view', '', 0, 0, 0, 'olt.view', NULL),
(110, 'sms.view', '', 0, 0, 0, 'sms.view', NULL),
(111, 'report.view', '', 0, 0, 0, 'report.view', NULL),
(112, 'view.wallets', '', 0, 0, 0, 'view.wallets', NULL),
(113, 'user.permission', '', 0, 0, 0, 'user.permission', NULL),
(114, 'tickets', '', 0, 0, 0, 'tickets', NULL),
(115, 'due.report', '', 0, 0, 0, 'due.report', NULL),
(116, 'due.report.pro', '', 0, 0, 0, 'due.report.pro', NULL),
(117, 'bill.report', '', 0, 0, 0, 'bill.report', NULL),
(118, 'payment.reports', '', 0, 0, 0, 'payment.reports', NULL),
(119, 'income.expense', '', 0, 0, 0, 'income.expense', NULL),
(120, 'Bbb', '', 0, 0, 0, '220033', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `portal_users`
--

CREATE TABLE `portal_users` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `username` varchar(120) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `last_login` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `resellers`
--

CREATE TABLE `resellers` (
  `id` int(11) NOT NULL,
  `code` varchar(32) DEFAULT NULL,
  `reseller_code` varchar(50) DEFAULT NULL,
  `name` varchar(120) DEFAULT NULL,
  `email` varchar(120) DEFAULT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `commission_rate` decimal(5,2) DEFAULT 0.00,
  `balance` decimal(12,2) DEFAULT 0.00,
  `status` tinyint(4) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `resellers`
--

INSERT INTO `resellers` (`id`, `code`, `reseller_code`, `name`, `email`, `phone`, `address`, `user_id`, `commission_rate`, `balance`, `status`, `created_at`) VALUES
(1, 'RS3254', NULL, 'munnbrb', '', '', '', NULL, '0.00', '0.00', 1, '2025-09-16 17:33:42');

-- --------------------------------------------------------

--
-- Table structure for table `reseller_packages`
--

CREATE TABLE `reseller_packages` (
  `id` int(11) NOT NULL,
  `reseller_id` int(11) NOT NULL,
  `package_id` int(11) NOT NULL,
  `mode` enum('fixed','percent') NOT NULL DEFAULT 'fixed',
  `price_override` decimal(10,2) DEFAULT NULL,
  `share_percent` decimal(6,3) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reseller_package_rates`
--

CREATE TABLE `reseller_package_rates` (
  `id` int(11) NOT NULL,
  `reseller_user_id` int(11) NOT NULL,
  `package_id` int(11) NOT NULL,
  `sell_rate` decimal(12,2) NOT NULL,
  `commission_rate` decimal(5,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reseller_users`
--

CREATE TABLE `reseller_users` (
  `reseller_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reseller_wallet_txns`
--

CREATE TABLE `reseller_wallet_txns` (
  `id` bigint(20) NOT NULL,
  `reseller_id` int(11) NOT NULL,
  `type` enum('deposit','withdraw','commission','adjust') NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `ref_table` varchar(50) DEFAULT NULL,
  `ref_id` bigint(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `label` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `name`, `description`, `label`, `created_at`) VALUES
(1, 'admin', NULL, 'Administrator', '2025-08-25 07:15:58'),
(2, 'manager', NULL, 'Manager', '2025-08-25 07:15:58'),
(3, 'accounts', NULL, 'Accounts', '2025-08-25 07:15:58'),
(4, 'support', NULL, 'Support', '2025-08-25 07:15:58'),
(5, 'reseller', NULL, 'Reseller', '2025-08-25 07:15:58'),
(6, 'viewer', NULL, 'Read-only Viewer', '2025-08-25 07:15:58'),
(23, 'hr_manager', NULL, 'HR Manager', '2025-09-01 14:57:35');

-- --------------------------------------------------------

--
-- Table structure for table `role_permissions`
--

CREATE TABLE `role_permissions` (
  `role_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `role_permissions`
--

INSERT INTO `role_permissions` (`role_id`, `permission_id`) VALUES
(1, 1),
(1, 2),
(1, 3),
(1, 4),
(1, 5),
(1, 21),
(1, 24),
(1, 25),
(1, 27),
(1, 28),
(1, 29),
(1, 30),
(1, 31),
(1, 32),
(1, 33),
(1, 34),
(1, 35),
(1, 36),
(1, 37),
(1, 38),
(1, 39),
(1, 75),
(1, 76),
(1, 81),
(1, 88),
(1, 89),
(1, 90),
(1, 91),
(1, 92),
(1, 99),
(1, 100),
(1, 117),
(2, 1),
(2, 2),
(2, 3),
(2, 4),
(2, 5),
(2, 21),
(2, 24),
(2, 25),
(2, 27),
(2, 28),
(2, 29),
(2, 30),
(2, 31),
(2, 32),
(2, 33),
(2, 34),
(2, 35),
(2, 36),
(2, 37),
(2, 38),
(2, 39),
(2, 75),
(2, 76),
(2, 81),
(2, 88),
(2, 89),
(2, 90),
(2, 91),
(2, 92),
(2, 99),
(2, 100),
(2, 101),
(2, 102),
(2, 103),
(2, 104),
(2, 105),
(2, 106),
(2, 107),
(2, 108),
(2, 109),
(2, 110),
(2, 111),
(2, 112),
(2, 113),
(2, 114),
(2, 115),
(2, 116),
(2, 117),
(2, 118),
(2, 119),
(4, 1),
(4, 2),
(4, 3),
(4, 4),
(4, 5),
(4, 21),
(4, 24),
(4, 25),
(4, 27),
(4, 28),
(4, 29),
(4, 30),
(4, 31),
(4, 32),
(4, 33),
(4, 34),
(4, 35),
(4, 36),
(4, 37),
(4, 38),
(4, 39),
(4, 75),
(4, 76),
(4, 81),
(4, 88),
(4, 89),
(4, 90),
(4, 91),
(4, 92),
(4, 99),
(4, 100),
(4, 101),
(4, 102),
(4, 103),
(4, 104),
(4, 105),
(4, 106),
(4, 107),
(4, 108),
(4, 109),
(4, 110),
(4, 111),
(4, 112),
(4, 113),
(4, 114),
(4, 115),
(4, 116),
(4, 117),
(4, 118),
(4, 119),
(5, 1),
(5, 24),
(5, 25),
(5, 34),
(5, 75),
(5, 76),
(5, 81),
(5, 99),
(5, 100),
(5, 103),
(5, 109),
(5, 112),
(5, 117),
(23, 81),
(23, 109),
(23, 112);

-- --------------------------------------------------------

--
-- Table structure for table `routers`
--

CREATE TABLE `routers` (
  `id` int(11) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `type` enum('mikrotik','olt','switch','other') NOT NULL,
  `ip` varchar(45) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `api_port` int(11) DEFAULT 8728,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `notes` varchar(255) DEFAULT NULL,
  `snmp_community` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `status` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `lat` decimal(10,7) DEFAULT NULL,
  `lng` decimal(10,7) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `routers`
--

INSERT INTO `routers` (`id`, `name`, `type`, `ip`, `username`, `password`, `api_port`, `is_active`, `notes`, `snmp_community`, `description`, `status`, `created_at`, `lat`, `lng`) VALUES
(8, 'Access_Router_1', 'mikrotik', '172.16.171.22', 'billing', 'billing', 7999, 1, NULL, 'public', NULL, 1, '2025-08-14 11:53:43', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `settlements`
--

CREATE TABLE `settlements` (
  `id` bigint(20) NOT NULL,
  `wallet_id` int(11) NOT NULL,
  `company_wallet_id` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `settled_by` int(11) NOT NULL,
  `settled_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sms_inbox`
--

CREATE TABLE `sms_inbox` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `gateway` varchar(64) DEFAULT 'httpsms',
  `msisdn_from` varchar(32) DEFAULT NULL,
  `msisdn_to` varchar(32) DEFAULT NULL,
  `raw_body` text NOT NULL,
  `trx_id` varchar(32) DEFAULT NULL,
  `amount` decimal(12,2) DEFAULT NULL,
  `sender_number` varchar(32) DEFAULT NULL,
  `ref_code` varchar(64) DEFAULT NULL,
  `received_at` datetime DEFAULT current_timestamp(),
  `processed` tinyint(1) DEFAULT 0,
  `error_msg` varchar(255) DEFAULT NULL,
  `meta_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta_json`)),
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sms_inbox`
--

INSERT INTO `sms_inbox` (`id`, `gateway`, `msisdn_from`, `msisdn_to`, `raw_body`, `trx_id`, `amount`, `sender_number`, `ref_code`, `received_at`, `processed`, `error_msg`, `meta_json`, `created_at`) VALUES
(1, 'httpsms', 'bKash', '01732197767', 'You have received Tk 1200.00 from 01712345678. TrxID TSTABC3 on 2025-09-09. Ref INV202509-001', 'TSTABC3', '1200.00', '01712345678', 'INV202509-001', '2025-09-09 15:58:11', 0, 'unmatched client', '{\"sim_id\":null}', '2025-09-09 19:58:21');

-- --------------------------------------------------------

--
-- Table structure for table `sms_queue`
--

CREATE TABLE `sms_queue` (
  `id` bigint(20) NOT NULL,
  `client_id` int(11) DEFAULT NULL,
  `mobile` varchar(20) NOT NULL,
  `message` varchar(480) NOT NULL,
  `status` enum('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `attempts` tinyint(4) NOT NULL DEFAULT 0,
  `last_error` text DEFAULT NULL,
  `scheduled_at` datetime NOT NULL DEFAULT current_timestamp(),
  `sent_at` datetime DEFAULT NULL,
  `dedupe_key` varchar(100) DEFAULT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `telegram_link_tokens`
--

CREATE TABLE `telegram_link_tokens` (
  `id` bigint(20) NOT NULL,
  `token` varchar(50) NOT NULL,
  `client_id` bigint(20) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `used_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `telegram_link_tokens`
--

INSERT INTO `telegram_link_tokens` (`id`, `token`, `client_id`, `created_at`, `used_at`) VALUES
(1, '692504235c477460', 18006, '2025-09-09 10:47:40', NULL),
(2, '031d5dc07d72b65e', 18006, '2025-09-09 10:47:48', NULL),
(3, '0049849123a6516c', 18006, '2025-09-09 10:47:52', NULL),
(4, '98e23c1c8d5a12e3', 18007, '2025-09-09 10:47:57', NULL),
(5, 'fea35e672189a1f3', 18007, '2025-09-09 10:47:59', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `telegram_queue`
--

CREATE TABLE `telegram_queue` (
  `id` bigint(20) NOT NULL,
  `client_id` bigint(20) NOT NULL,
  `template_key` varchar(80) NOT NULL,
  `payload_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload_json`)),
  `status` enum('queued','sent','failed') NOT NULL DEFAULT 'queued',
  `retries` int(11) NOT NULL DEFAULT 0,
  `send_after` datetime NOT NULL DEFAULT current_timestamp(),
  `sent_at` datetime DEFAULT NULL,
  `uniq_key` varchar(120) DEFAULT NULL,
  `last_error` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `telegram_queue`
--

INSERT INTO `telegram_queue` (`id`, `client_id`, `template_key`, `payload_json`, `status`, `retries`, `send_after`, `sent_at`, `uniq_key`, `last_error`, `created_at`) VALUES
(87, 21806, 'payment_confirm', '{\"amount\":\"1000.00\",\"invoice_id\":4334,\"portal_link\":\"https://api.telegram.org/public/portal.php?client_id=21806\",\"method\":\"Cash\",\"txn_id\":\"\",\"paid_at\":\"2025-09-16 17:55:00\",\"received_by\":20,\"received_by_name\":\"swapon\"}', 'queued', 0, '2025-09-16 17:55:44', '2025-09-16 17:55:44', 'pay-confirm-282', 'HTTP404: Not Found', '2025-09-16 17:55:44');

-- --------------------------------------------------------

--
-- Table structure for table `telegram_settings`
--

CREATE TABLE `telegram_settings` (
  `k` varchar(64) NOT NULL,
  `v` text DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `telegram_settings`
--

INSERT INTO `telegram_settings` (`k`, `v`, `updated_at`) VALUES
('app_base_url', 'https://api.telegram.org', '2025-09-16 14:13:09'),
('batch_limit', '1000', '2025-09-16 14:13:09'),
('bot_token', 'Hasnine66', '2025-09-16 14:13:09'),
('bot_user', 'Hasnine55', '2025-09-16 14:13:09'),
('min_gap_m', '30', '2025-09-16 14:13:09'),
('parse_mode', 'HTML', '2025-09-16 14:13:09'),
('test_chat_id', '-4789876035', '2025-09-16 14:13:09'),
('wh_secret', '', '2025-09-16 14:13:09');

-- --------------------------------------------------------

--
-- Table structure for table `telegram_subscribers`
--

CREATE TABLE `telegram_subscribers` (
  `id` bigint(20) NOT NULL,
  `client_id` bigint(20) NOT NULL,
  `chat_id` bigint(20) NOT NULL,
  `username` varchar(100) DEFAULT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `telegram_templates`
--

CREATE TABLE `telegram_templates` (
  `id` int(11) NOT NULL,
  `template_key` varchar(80) NOT NULL,
  `body` text NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `telegram_templates`
--

INSERT INTO `telegram_templates` (`id`, `template_key`, `body`, `active`, `created_at`, `updated_at`) VALUES
(1, 'due_reminder', 'Dear {{name}}, Pay your Due bill {{amount}} . {{pay_link}}', 1, '2025-09-09 09:54:55', '2025-09-09 11:41:23'),
(2, 'payment_confirm', 'Payment recieved TK {{amount}} From {{name}} Recieved by {{receiver}} . ', 1, '2025-09-09 09:54:55', '2025-09-09 12:11:12'),
(3, 'generic', 'Hi {{name}}, {{message}}', 1, '2025-09-09 09:54:55', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `tickets`
--

CREATE TABLE `tickets` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `status` enum('open','in_progress','closed') DEFAULT 'open',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `tickets`
--

INSERT INTO `tickets` (`id`, `client_id`, `subject`, `message`, `status`, `created_at`, `updated_at`) VALUES
(1, 14203, 'support', 'hel me', 'closed', '2025-08-26 23:30:46', '2025-09-15 17:45:41'),
(4, 18006, 'net probolem', 'Priority: High | Category: Complaint | PPPoE: myhome | Client Code: myhome\n\nnet nai', 'closed', '2025-09-08 16:46:24', '2025-09-16 15:21:07');

-- --------------------------------------------------------

--
-- Table structure for table `ticket_replies`
--

CREATE TABLE `ticket_replies` (
  `id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `user_type` enum('client','admin') NOT NULL,
  `message` text NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `ticket_replies`
--

INSERT INTO `ticket_replies` (`id`, `ticket_id`, `user_type`, `message`, `created_at`) VALUES
(26, 3, 'admin', 'ok by', '2025-08-31 17:56:57');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `mobile` varchar(50) DEFAULT NULL,
  `full_name` varchar(100) NOT NULL,
  `role` enum('admin','account','manager','support','viewer') NOT NULL DEFAULT 'account',
  `user_image_url` varchar(255) DEFAULT NULL,
  `status` tinyint(1) DEFAULT 1,
  `last_login` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `last_login_ip` varchar(50) DEFAULT NULL,
  `role_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `mobile`, `full_name`, `role`, `user_image_url`, `status`, `last_login`, `created_at`, `last_login_ip`, `role_id`) VALUES
(1, 'admin', '035a1aca59a9aae39c9c6683458167e3', NULL, 'Super Admin', 'admin', '/uploads/users/admin.jpg', 1, NULL, '2025-08-13 14:02:17', NULL, 1);

-- --------------------------------------------------------

--
-- Table structure for table `user_permissions`
--

CREATE TABLE `user_permissions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `permission_code` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_permission_denies`
--

CREATE TABLE `user_permission_denies` (
  `user_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_registrations`
--

CREATE TABLE `user_registrations` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `email` varchar(200) NOT NULL,
  `pass_hash` varchar(255) NOT NULL,
  `token` varchar(64) NOT NULL,
  `otp` varchar(10) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL,
  `ip` varchar(64) DEFAULT NULL,
  `consumed` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_registrations`
--

INSERT INTO `user_registrations` (`id`, `name`, `email`, `pass_hash`, `token`, `otp`, `expires_at`, `created_at`, `ip`, `consumed`) VALUES
(2, 'FOYZUL NETWORK', 'foyzulislamalif@gmail.com', '$2y$10$TTHehijkXcqDCefqv8mIXesDkF6nF2yLj2enktk.szLZy.bMiktEa', '703809737cd2094ff47186fdeaab90d7', '717375', '2025-09-15 15:47:42', '2025-09-15 15:32:42', '172.71.124.235', 0);

-- --------------------------------------------------------

--
-- Table structure for table `user_sessions`
--

CREATE TABLE `user_sessions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `username` varchar(191) DEFAULT NULL,
  `session_id` varchar(191) NOT NULL,
  `ip` varchar(45) NOT NULL,
  `user_agent` text DEFAULT NULL,
  `login_time` datetime NOT NULL DEFAULT current_timestamp(),
  `last_seen` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `wallets`
--

CREATE TABLE `wallets` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `balance` decimal(14,2) NOT NULL DEFAULT 0.00,
  `updated_at` datetime DEFAULT NULL,
  `employee_code` varchar(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `wallets`
--

INSERT INTO `wallets` (`id`, `employee_id`, `name`, `description`, `is_active`, `created_at`, `balance`, `updated_at`, `employee_code`) VALUES
(1, 0, 'Undeposited Funds', 'Temporary holding account', 1, '2025-08-29 21:15:32', '0.00', '2025-09-07 10:47:17', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `wallet_transactions`
--

CREATE TABLE `wallet_transactions` (
  `id` int(11) NOT NULL,
  `wallet_id` int(11) NOT NULL,
  `payment_id` int(11) DEFAULT NULL,
  `client_id` int(11) DEFAULT NULL,
  `invoice_id` int(11) DEFAULT NULL,
  `direction` enum('in','out') NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `reason` varchar(40) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `wallet_transfers`
--

CREATE TABLE `wallet_transfers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `from_account_id` int(11) NOT NULL,
  `to_account_id` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `method` varchar(50) DEFAULT NULL,
  `ref_no` varchar(100) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `decision_note` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `wallet_transfers`
--

INSERT INTO `wallet_transfers` (`id`, `from_account_id`, `to_account_id`, `amount`, `method`, `ref_no`, `notes`, `created_by`, `created_at`, `status`, `approved_by`, `approved_at`, `decision_note`) VALUES
(3, 13, 14, '10000.00', 'Cash', '', '', 1, '2025-09-07 05:30:53', 'approved', 1, '2025-09-07 11:31:03', ''),
(4, 13, 16, '20000.00', 'Cash', '', '', 1, '2025-09-10 04:15:35', 'approved', 1, '2025-09-10 10:15:38', ''),
(5, 13, 16, '21740.00', 'Cash', '', '', 1, '2025-09-10 12:36:50', 'approved', 1, '2025-09-10 18:36:54', ''),
(6, 16, 17, '58.00', 'bKash', '', '', 20, '2025-09-16 14:32:11', 'pending', NULL, NULL, NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `accounts`
--
ALTER TABLE `accounts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `is_active` (`is_active`),
  ADD KEY `type` (`type`),
  ADD KEY `idx_accounts_user` (`user_id`);

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`),
  ADD KEY `employee_id` (`employee_id`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_audit_entity` (`entity`),
  ADD KEY `idx_audit_entity_id` (`entity_id`),
  ADD KEY `idx_audit_action` (`action`),
  ADD KEY `idx_audit_created` (`created_at`);

--
-- Indexes for table `auth_logins`
--
ALTER TABLE `auth_logins`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_time` (`user_id`,`created_at`),
  ADD KEY `idx_event_time` (`event`,`created_at`),
  ADD KEY `idx_ip_time` (`ip`,`created_at`);

--
-- Indexes for table `bills`
--
ALTER TABLE `bills`
  ADD PRIMARY KEY (`id`),
  ADD KEY `client_id` (`client_id`);

--
-- Indexes for table `bkash_inbox`
--
ALTER TABLE `bkash_inbox`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_inbox_trxid` (`trxid`);

--
-- Indexes for table `clients`
--
ALTER TABLE `clients`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `pppoe_id` (`pppoe_id`),
  ADD UNIQUE KEY `uq_clients_pppoe_id` (`pppoe_id`),
  ADD UNIQUE KEY `uq_pppoe_id` (`pppoe_id`),
  ADD UNIQUE KEY `uq_clients_client_code` (`client_code`),
  ADD UNIQUE KEY `uq_router_mac` (`router_mac`),
  ADD UNIQUE KEY `uq_mobile` (`mobile`),
  ADD KEY `client_code` (`client_code`) USING BTREE,
  ADD KEY `idx_clients_olt_id` (`olt_id`),
  ADD KEY `idx_clients_is_left` (`is_left`),
  ADD KEY `idx_clients_left_at` (`left_at`),
  ADD KEY `idx_clients_router_mac` (`router_mac`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_is_left` (`is_left`),
  ADD KEY `idx_router` (`router_id`),
  ADD KEY `idx_package` (`package_id`),
  ADD KEY `idx_expiry` (`expiry_date`),
  ADD KEY `idx_area` (`area`),
  ADD KEY `idx_clients_ap_mac` (`ap_mac`),
  ADD KEY `idx_clients_reseller` (`reseller_id`);

--
-- Indexes for table `client_ledger`
--
ALTER TABLE `client_ledger`
  ADD PRIMARY KEY (`client_id`);

--
-- Indexes for table `client_traffic_log`
--
ALTER TABLE `client_traffic_log`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `cron_runs`
--
ALTER TABLE `cron_runs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `job_key` (`job_key`),
  ADD KEY `status` (`status`),
  ADD KEY `started_at` (`started_at`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `employees`
--
ALTER TABLE `employees`
  ADD PRIMARY KEY (`emp_id`);

--
-- Indexes for table `employees_roles`
--
ALTER TABLE `employees_roles`
  ADD PRIMARY KEY (`emp_id`,`role_id`),
  ADD KEY `idx_emprole_role` (`role_id`);

--
-- Indexes for table `employee_payments`
--
ALTER TABLE `employee_payments`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `expenses`
--
ALTER TABLE `expenses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `is_deleted` (`is_deleted`);

--
-- Indexes for table `expense_accounts`
--
ALTER TABLE `expense_accounts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `is_active` (`is_active`),
  ADD KEY `type` (`type`);

--
-- Indexes for table `expense_categories`
--
ALTER TABLE `expense_categories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `parent_id` (`parent_id`),
  ADD KEY `is_active` (`is_active`);

--
-- Indexes for table `income`
--
ALTER TABLE `income`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `invoice_number` (`invoice_number`),
  ADD UNIQUE KEY `uniq_client_period` (`client_id`,`period_start`,`period_end`),
  ADD UNIQUE KEY `invoice_unique_period` (`client_id`,`period_start`,`period_end`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `package_id` (`package_id`),
  ADD KEY `idx_invoices_client` (`client_id`),
  ADD KEY `idx_invoices_billing_month` (`billing_month`),
  ADD KEY `idx_inv_client` (`client_id`),
  ADD KEY `idx_inv_month` (`billing_month`),
  ADD KEY `idx_inv_status` (`status`),
  ADD KEY `idx_inv_date` (`invoice_date`);

--
-- Indexes for table `invoice_items`
--
ALTER TABLE `invoice_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `invoice_id` (`invoice_id`);

--
-- Indexes for table `mac_vendors`
--
ALTER TABLE `mac_vendors`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `mac_prefix` (`mac_prefix`),
  ADD KEY `idx_mac_vendors_prefix` (`mac_prefix`);

--
-- Indexes for table `menu_overrides`
--
ALTER TABLE `menu_overrides`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_user_menu` (`user_id`,`menu_key`);

--
-- Indexes for table `network_areas`
--
ALTER TABLE `network_areas`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `olts`
--
ALTER TABLE `olts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `u1` (`host`);

--
-- Indexes for table `packages`
--
ALTER TABLE `packages`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ux_packages_name` (`name`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_token` (`token`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_method_txn` (`method`,`txn_id`),
  ADD KEY `fk_payments_invoice` (`bill_id`),
  ADD KEY `invoice_id` (`invoice_id`),
  ADD KEY `idx_pay_client` (`client_id`),
  ADD KEY `idx_invoice_id` (`invoice_id`),
  ADD KEY `idx_client_id` (`client_id`),
  ADD KEY `idx_pay_invoice` (`invoice_id`),
  ADD KEY `idx_pay_txn` (`txn_id`),
  ADD KEY `idx_payments_invoice` (`invoice_id`),
  ADD KEY `idx_payments_client` (`client_id`),
  ADD KEY `idx_payments_account` (`account_id`),
  ADD KEY `idx_payments_received_by` (`received_by`),
  ADD KEY `fk_payments_wallet` (`wallet_id`),
  ADD KEY `idx_payments_account_id` (`account_id`),
  ADD KEY `idx_pay_date` (`payment_date`),
  ADD KEY `idx_pay_method` (`method`),
  ADD KEY `idx_pay_acc` (`account_id`),
  ADD KEY `idx_pay_recv` (`received_by`),
  ADD KEY `idx_payments_txn` (`txn_id`),
  ADD KEY `idx_payments_method` (`method`),
  ADD KEY `idx_payments_method_txn` (`method`,`txn_id`),
  ADD KEY `idx_payments_paid_at` (`paid_at`);

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_permissions_perm_key` (`perm_key`);

--
-- Indexes for table `portal_users`
--
ALTER TABLE `portal_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `client_id` (`client_id`);

--
-- Indexes for table `resellers`
--
ALTER TABLE `resellers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `reseller_code` (`reseller_code`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `reseller_packages`
--
ALTER TABLE `reseller_packages`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_rpkg` (`reseller_id`,`package_id`),
  ADD KEY `idx_rpkg_reseller` (`reseller_id`),
  ADD KEY `idx_rpkg_package` (`package_id`);

--
-- Indexes for table `reseller_package_rates`
--
ALTER TABLE `reseller_package_rates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_rpr` (`reseller_user_id`,`package_id`);

--
-- Indexes for table `reseller_users`
--
ALTER TABLE `reseller_users`
  ADD PRIMARY KEY (`reseller_id`,`user_id`),
  ADD KEY `idx_ru_reseller` (`reseller_id`),
  ADD KEY `idx_ru_user` (`user_id`);

--
-- Indexes for table `reseller_wallet_txns`
--
ALTER TABLE `reseller_wallet_txns`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_rw_reseller` (`reseller_id`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD PRIMARY KEY (`role_id`,`permission_id`),
  ADD KEY `fk_rp_perm` (`permission_id`);

--
-- Indexes for table `routers`
--
ALTER TABLE `routers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_router_ip` (`ip`),
  ADD KEY `idx_active` (`is_active`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `settlements`
--
ALTER TABLE `settlements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_settle_src` (`wallet_id`),
  ADD KEY `fk_settle_dst` (`company_wallet_id`);

--
-- Indexes for table `sms_inbox`
--
ALTER TABLE `sms_inbox`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_trx` (`gateway`,`trx_id`);

--
-- Indexes for table `sms_queue`
--
ALTER TABLE `sms_queue`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_dedupe` (`dedupe_key`),
  ADD KEY `status` (`status`),
  ADD KEY `scheduled_at` (`scheduled_at`);

--
-- Indexes for table `telegram_link_tokens`
--
ALTER TABLE `telegram_link_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`);

--
-- Indexes for table `telegram_queue`
--
ALTER TABLE `telegram_queue`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status` (`status`,`send_after`),
  ADD KEY `idx_client` (`client_id`),
  ADD KEY `idx_uniq` (`uniq_key`);

--
-- Indexes for table `telegram_settings`
--
ALTER TABLE `telegram_settings`
  ADD PRIMARY KEY (`k`);

--
-- Indexes for table `telegram_subscribers`
--
ALTER TABLE `telegram_subscribers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_client` (`client_id`),
  ADD UNIQUE KEY `uk_chat` (`chat_id`),
  ADD KEY `idx_active` (`is_active`);

--
-- Indexes for table `telegram_templates`
--
ALTER TABLE `telegram_templates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `template_key` (`template_key`),
  ADD UNIQUE KEY `uk_active` (`template_key`,`active`);

--
-- Indexes for table `tickets`
--
ALTER TABLE `tickets`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `ticket_replies`
--
ALTER TABLE `ticket_replies`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `mobile` (`mobile`),
  ADD KEY `idx_users_role_id` (`role_id`);

--
-- Indexes for table `user_permissions`
--
ALTER TABLE `user_permissions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `user_permission_denies`
--
ALTER TABLE `user_permission_denies`
  ADD PRIMARY KEY (`user_id`,`permission_id`),
  ADD KEY `fk_ud_perm` (`permission_id`);

--
-- Indexes for table `user_registrations`
--
ALTER TABLE `user_registrations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_email` (`email`),
  ADD KEY `idx_token` (`token`);

--
-- Indexes for table `user_sessions`
--
ALTER TABLE `user_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `session_id` (`session_id`);

--
-- Indexes for table `wallets`
--
ALTER TABLE `wallets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_employee` (`employee_id`),
  ADD UNIQUE KEY `uniq_wallet_empid` (`employee_id`),
  ADD UNIQUE KEY `uniq_wallet_empcode` (`employee_code`);

--
-- Indexes for table `wallet_transactions`
--
ALTER TABLE `wallet_transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_wt_wallet` (`wallet_id`),
  ADD KEY `fk_wt_payment` (`payment_id`);

--
-- Indexes for table `wallet_transfers`
--
ALTER TABLE `wallet_transfers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `from_account_id` (`from_account_id`),
  ADD KEY `to_account_id` (`to_account_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_wt_status` (`status`),
  ADD KEY `idx_wt_approved_by` (`approved_by`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `accounts`
--
ALTER TABLE `accounts`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `auth_logins`
--
ALTER TABLE `auth_logins`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bills`
--
ALTER TABLE `bills`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bkash_inbox`
--
ALTER TABLE `bkash_inbox`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `clients`
--
ALTER TABLE `clients`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21807;

--
-- AUTO_INCREMENT for table `client_traffic_log`
--
ALTER TABLE `client_traffic_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cron_runs`
--
ALTER TABLE `cron_runs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `employees`
--
ALTER TABLE `employees`
  MODIFY `emp_id` int(25) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=202517;

--
-- AUTO_INCREMENT for table `employee_payments`
--
ALTER TABLE `employee_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `expenses`
--
ALTER TABLE `expenses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `expense_accounts`
--
ALTER TABLE `expense_accounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `expense_categories`
--
ALTER TABLE `expense_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `income`
--
ALTER TABLE `income`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4335;

--
-- AUTO_INCREMENT for table `invoice_items`
--
ALTER TABLE `invoice_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `mac_vendors`
--
ALTER TABLE `mac_vendors`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `menu_overrides`
--
ALTER TABLE `menu_overrides`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=57;

--
-- AUTO_INCREMENT for table `network_areas`
--
ALTER TABLE `network_areas`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `olts`
--
ALTER TABLE `olts`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `packages`
--
ALTER TABLE `packages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=42;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=283;

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=121;

--
-- AUTO_INCREMENT for table `portal_users`
--
ALTER TABLE `portal_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `resellers`
--
ALTER TABLE `resellers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `reseller_packages`
--
ALTER TABLE `reseller_packages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reseller_package_rates`
--
ALTER TABLE `reseller_package_rates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reseller_wallet_txns`
--
ALTER TABLE `reseller_wallet_txns`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `routers`
--
ALTER TABLE `routers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `settlements`
--
ALTER TABLE `settlements`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sms_inbox`
--
ALTER TABLE `sms_inbox`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `sms_queue`
--
ALTER TABLE `sms_queue`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `telegram_link_tokens`
--
ALTER TABLE `telegram_link_tokens`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `telegram_queue`
--
ALTER TABLE `telegram_queue`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=88;

--
-- AUTO_INCREMENT for table `telegram_subscribers`
--
ALTER TABLE `telegram_subscribers`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `telegram_templates`
--
ALTER TABLE `telegram_templates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `tickets`
--
ALTER TABLE `tickets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `ticket_replies`
--
ALTER TABLE `ticket_replies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=40;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=46;

--
-- AUTO_INCREMENT for table `user_permissions`
--
ALTER TABLE `user_permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `user_registrations`
--
ALTER TABLE `user_registrations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=44;

--
-- AUTO_INCREMENT for table `user_sessions`
--
ALTER TABLE `user_sessions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wallets`
--
ALTER TABLE `wallets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `wallet_transactions`
--
ALTER TABLE `wallet_transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `wallet_transfers`
--
ALTER TABLE `wallet_transfers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`emp_id`) ON DELETE CASCADE;

--
-- Constraints for table `clients`
--
ALTER TABLE `clients`
  ADD CONSTRAINT `fk_clients_olts_id` FOREIGN KEY (`olt_id`) REFERENCES `olts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `client_ledger`
--
ALTER TABLE `client_ledger`
  ADD CONSTRAINT `fk_ledger_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`);

--
-- Constraints for table `employees_roles`
--
ALTER TABLE `employees_roles`
  ADD CONSTRAINT `fk_er_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `invoices`
--
ALTER TABLE `invoices`
  ADD CONSTRAINT `invoices_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `invoices_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `invoice_items`
--
ALTER TABLE `invoice_items`
  ADD CONSTRAINT `invoice_items_ibfk_1` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `fk_pay_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_payments_user` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_payments_wallet` FOREIGN KEY (`wallet_id`) REFERENCES `wallets` (`id`),
  ADD CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `portal_users`
--
ALTER TABLE `portal_users`
  ADD CONSTRAINT `portal_users_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD CONSTRAINT `fk_rp_perm` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `settlements`
--
ALTER TABLE `settlements`
  ADD CONSTRAINT `fk_settle_dst` FOREIGN KEY (`company_wallet_id`) REFERENCES `wallets` (`id`),
  ADD CONSTRAINT `fk_settle_src` FOREIGN KEY (`wallet_id`) REFERENCES `wallets` (`id`);

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_role_isp` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `user_permissions`
--
ALTER TABLE `user_permissions`
  ADD CONSTRAINT `user_permissions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_permission_denies`
--
ALTER TABLE `user_permission_denies`
  ADD CONSTRAINT `fk_ud_perm` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ud_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
