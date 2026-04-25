-- REDLINE Database Schema
CREATE DATABASE IF NOT EXISTS `redline`;
USE `redline`;

-- Users table
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(150) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `phone` VARCHAR(20) DEFAULT NULL,
    `role` ENUM('buyer','seller','admin') DEFAULT 'buyer',
    `avatar` VARCHAR(255) DEFAULT NULL,
    `banner` VARCHAR(255) DEFAULT NULL,
    `bio` TEXT DEFAULT NULL,
    `is_verified` TINYINT(1) DEFAULT 0,
    `otp` VARCHAR(10) DEFAULT NULL,
    `otp_expiry` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seller Applications (KYC)
CREATE TABLE IF NOT EXISTS `seller_applications` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `aadhar_path` VARCHAR(255) NOT NULL,
    `pan_path` VARCHAR(255) NOT NULL,
    `status` ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    `admin_notes` TEXT DEFAULT NULL,
    `applied_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Categories (product lines)
CREATE TABLE IF NOT EXISTS `categories` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `slug` VARCHAR(100) NOT NULL UNIQUE,
    `badge_label` VARCHAR(30) DEFAULT NULL,
    `badge_type` VARCHAR(30) DEFAULT 'default',
    `sort_order` INT DEFAULT 0,
    `status` ENUM('active','inactive') DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed default categories
INSERT INTO `categories` (`name`, `slug`, `badge_label`, `badge_type`, `sort_order`) VALUES
('HW Mainline', 'hw-mainline', 'STARTER', 'starter', 1),
('Premium', 'premium', 'POPULAR', 'popular', 2),
('Treasure Hunt', 'treasure-hunt', 'RARE', 'rare', 3),
('Super Treasure Hunt', 'super-treasure-hunt', 'ULTRA RARE', 'ultra-rare', 4),
('Kaido House', 'kaido-house', 'EXCLUSIVE', 'exclusive', 5),
('Mini GT', 'mini-gt', 'DETAIL', 'detail', 6),
('Majorette', 'majorette', 'VALUE', 'value', 7),
('Tomica', 'tomica', 'JDM', 'jdm', 8),
('Matchbox', 'matchbox', 'CLASSIC', 'classic', 9);

-- Listings table
CREATE TABLE IF NOT EXISTS `listings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `seller_id` INT NOT NULL,
    `category_id` INT NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `price` DECIMAL(10,2) NOT NULL,
    `image` VARCHAR(255) DEFAULT NULL,
    `condition` ENUM('new','opened','used') DEFAULT 'new',
    `status` ENUM('active','sold','inactive') DEFAULT 'active',
    `views` INT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`seller_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Admin settings
CREATE TABLE IF NOT EXISTS `admin_settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(100) NOT NULL UNIQUE,
    `setting_value` TEXT DEFAULT NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default admin user (password: admin123)
INSERT INTO `users` (`name`, `email`, `password`, `role`, `is_verified`) VALUES
('Admin', 'admin@redline.in', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1);
