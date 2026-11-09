-- ==============================================================================
-- DOCTOR CONNECT - DATABASE SCHEMA (MYSQL 5.7+ / MariaDB 10.3+)
-- ==============================================================================

CREATE DATABASE IF NOT EXISTS `doctor_connect` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `doctor_connect`;

-- 1. USERS TABLE
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(150) NOT NULL UNIQUE,
    `phone` VARCHAR(20) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` ENUM('patient', 'doctor', 'admin') NOT NULL DEFAULT 'patient',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. DOCTORS TABLE
CREATE TABLE IF NOT EXISTS `doctors` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL UNIQUE,
    `specialization` VARCHAR(100) NOT NULL,
    `consultation_fee` DECIMAL(10,2) NOT NULL DEFAULT 50.00,
    `avg_consultation_mins` INT NOT NULL DEFAULT 15,
    `custom_meeting_link` VARCHAR(500) DEFAULT NULL,
    `bio` TEXT DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. DOCTOR SCHEDULES TABLE
CREATE TABLE IF NOT EXISTS `doctor_schedules` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `doctor_id` INT NOT NULL,
    `day_of_week` TINYINT NOT NULL COMMENT '0=Sun, 1=Mon, 2=Tue, 3=Wed, 4=Thu, 5=Fri, 6=Sat',
    `start_time` TIME NOT NULL,
    `end_time` TIME NOT NULL,
    `slot_duration` INT NOT NULL DEFAULT 15 COMMENT 'in minutes',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (`doctor_id`) REFERENCES `doctors`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_doc_day` (`doctor_id`, `day_of_week`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. DOCTOR LEAVES TABLE
CREATE TABLE IF NOT EXISTS `doctor_leaves` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `doctor_id` INT NOT NULL,
    `leave_date` DATE NOT NULL,
    `reason` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`doctor_id`) REFERENCES `doctors`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_doc_leave` (`doctor_id`, `leave_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. APPOINTMENTS TABLE (WITH CRITICAL UNIQUE RACE-SAFE CONSTRAINT)
CREATE TABLE IF NOT EXISTS `appointments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `patient_id` INT NOT NULL,
    `doctor_id` INT NOT NULL,
    `appointment_date` DATE NOT NULL,
    `token_number` INT NOT NULL,
    `status` ENUM('waiting', 'serving', 'completed', 'no_show', 'cancelled') NOT NULL DEFAULT 'waiting',
    `meeting_link` VARCHAR(500) DEFAULT NULL,
    `estimated_time` DATETIME DEFAULT NULL,
    `reason_for_visit` VARCHAR(255) DEFAULT 'General Consultation',
    `notes` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`patient_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`doctor_id`) REFERENCES `doctors`(`id`) ON DELETE CASCADE,
    -- CRITICAL REQUIREMENT: Token numbers must be unique per doctor per day
    UNIQUE KEY `unique_token_per_doctor_day` (`doctor_id`, `appointment_date`, `token_number`),
    INDEX `idx_doc_date_status` (`doctor_id`, `appointment_date`, `status`),
    INDEX `idx_patient_date` (`patient_id`, `appointment_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. QUEUE AUDIT LOGS TABLE
CREATE TABLE IF NOT EXISTS `queue_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `appointment_id` INT NOT NULL,
    `action` ENUM('called', 'serving', 'completed', 'no_show', 'skipped', 'cancelled') NOT NULL,
    `action_time` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `acted_by` INT DEFAULT NULL,
    FOREIGN KEY (`appointment_id`) REFERENCES `appointments`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`acted_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_queue_app_action` (`appointment_id`, `action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. PRESCRIPTIONS & CONSULTATION NOTES TABLE
CREATE TABLE IF NOT EXISTS `prescriptions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `appointment_id` INT NOT NULL UNIQUE,
    `doctor_id` INT NOT NULL,
    `patient_id` INT NOT NULL,
    `diagnosis` VARCHAR(255) NOT NULL,
    `symptoms` TEXT DEFAULT NULL,
    `medicines` TEXT DEFAULT NULL COMMENT 'JSON array or structured text of prescriptions',
    `advice` TEXT DEFAULT NULL,
    `follow_up_date` DATE DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`appointment_id`) REFERENCES `appointments`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`doctor_id`) REFERENCES `doctors`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`patient_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. NOTIFICATIONS TABLE
CREATE TABLE IF NOT EXISTS `notifications` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `appointment_id` INT DEFAULT NULL,
    `message` TEXT NOT NULL,
    `type` ENUM('token_booked', 'token_next', 'token_serving', 'token_completed', 'token_no_show', 'general') DEFAULT 'general',
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`appointment_id`) REFERENCES `appointments`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_unread` (`user_id`, `is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. CLINIC SETTINGS TABLE
CREATE TABLE IF NOT EXISTS `clinic_settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(50) NOT NULL UNIQUE,
    `setting_value` TEXT NOT NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
