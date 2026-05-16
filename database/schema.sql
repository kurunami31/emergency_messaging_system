SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS emergency_messaging_system
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE emergency_messaging_system;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    google_id VARCHAR(255) DEFAULT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) DEFAULT NULL,
    display_name VARCHAR(255) NOT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    emergency_contact_name VARCHAR(255) DEFAULT NULL,
    emergency_contact_phone VARCHAR(20) DEFAULT NULL,
    avatar_url VARCHAR(500) DEFAULT NULL,
    role ENUM('admin', 'responder', 'operator', 'viewer', 'victim') NOT NULL DEFAULT 'victim',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_google_id (google_id),
    INDEX idx_email (email),
    INDEX idx_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emergency_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    severity ENUM('critical', 'high', 'medium', 'low') NOT NULL DEFAULT 'medium',
    description TEXT DEFAULT NULL,
    location VARCHAR(500) DEFAULT NULL,
    status ENUM('active', 'resolved', 'archived') NOT NULL DEFAULT 'active',
    created_by INT NOT NULL,
    resolved_by INT DEFAULT NULL,
    resolved_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_severity (severity),
    INDEX idx_created_by (created_by),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    sender_id INT DEFAULT NULL,
    content TEXT NOT NULL,
    message_type ENUM('text', 'alert', 'system', 'command') NOT NULL DEFAULT 'text',
    priority ENUM('normal', 'high', 'urgent') NOT NULL DEFAULT 'normal',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_event_id (event_id),
    INDEX idx_sender_id (sender_id),
    INDEX idx_priority (priority),
    FOREIGN KEY (event_id) REFERENCES emergency_events(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alerts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    type ENUM('evacuation', 'lockdown', 'medical', 'fire', 'weather', 'security', 'test') NOT NULL DEFAULT 'test',
    target_role ENUM('admin', 'responder', 'operator', 'viewer', 'all') NOT NULL DEFAULT 'all',
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    is_acknowledged TINYINT(1) NOT NULL DEFAULT 0,
    acknowledged_by INT DEFAULT NULL,
    acknowledged_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_event_id (event_id),
    INDEX idx_type (type),
    INDEX idx_target_role (target_role),
    FOREIGN KEY (event_id) REFERENCES emergency_events(id) ON DELETE CASCADE,
    FOREIGN KEY (acknowledged_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    action VARCHAR(255) NOT NULL,
    details TEXT DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_action (action),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dead_letter_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    original_message_id VARCHAR(255) DEFAULT NULL,
    queue_name VARCHAR(255) NOT NULL,
    payload TEXT NOT NULL,
    error_message TEXT DEFAULT NULL,
    retry_count INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS distress_signals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    victim_id INT NOT NULL,
    event_id INT DEFAULT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT DEFAULT NULL,
    location VARCHAR(500) DEFAULT NULL,
    status ENUM('active', 'responded', 'resolved') NOT NULL DEFAULT 'active',
    assigned_to INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_victim_id (victim_id),
    INDEX idx_event_id (event_id),
    INDEX idx_status (status),
    INDEX idx_assigned_to (assigned_to),
    FOREIGN KEY (victim_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (event_id) REFERENCES emergency_events(id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Announcements for in-app system broadcasts
-- ============================================
CREATE TABLE IF NOT EXISTS announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    target_role ENUM('all', 'admin', 'responder', 'operator', 'victim') NOT NULL DEFAULT 'all',
    created_by INT DEFAULT NULL,
    expires_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_target_role (target_role),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Event resources (personnel, equipment, supplies)
-- ============================================
CREATE TABLE IF NOT EXISTS event_resources (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    type ENUM('personnel', 'equipment', 'supplies') NOT NULL DEFAULT 'personnel',
    name VARCHAR(255) NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    status ENUM('available', 'deployed', 'depleted') NOT NULL DEFAULT 'available',
    assigned_by INT DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_event_id (event_id),
    FOREIGN KEY (event_id) REFERENCES emergency_events(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Event timeline entries
-- ============================================
CREATE TABLE IF NOT EXISTS event_timeline (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    user_id INT DEFAULT NULL,
    action VARCHAR(255) NOT NULL,
    description TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_event_id (event_id),
    FOREIGN KEY (event_id) REFERENCES emergency_events(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Message attachments
-- ============================================
CREATE TABLE IF NOT EXISTS message_attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    message_id INT NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size INT NOT NULL DEFAULT 0,
    mime_type VARCHAR(100) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_message_id (message_id),
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
