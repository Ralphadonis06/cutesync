-- QueueSync database schema for XAMPP (MySQL 5.7+ / MariaDB 10.3+)
-- Import with phpMyAdmin: Import tab -> choose this file -> Go

CREATE DATABASE IF NOT EXISTS queuesync
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE queuesync;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS announcements, tickets, staff, services, establishments;
SET FOREIGN_KEY_CHECKS = 1;

-- 1. Clinics
CREATE TABLE establishments (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(120) NOT NULL,
  join_code    VARCHAR(20)  NOT NULL UNIQUE,
  ticket_limit INT          NOT NULL DEFAULT 100,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 2. Service categories (departments) per clinic
CREATE TABLE services (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  establishment_id INT          NOT NULL,
  name             VARCHAR(60)  NOT NULL,
  prefix           VARCHAR(5)   NOT NULL,
  color            VARCHAR(7)   NOT NULL DEFAULT '#1E3A8A',
  is_active        TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_service_prefix (establishment_id, prefix),
  CONSTRAINT fk_services_est FOREIGN KEY (establishment_id)
    REFERENCES establishments(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 3. Staff accounts (Super Admin has no clinic: establishment_id NULL)
CREATE TABLE staff (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  establishment_id    INT          NULL,
  name                VARCHAR(100) NOT NULL,
  email               VARCHAR(150) NOT NULL UNIQUE,
  password_hash       VARCHAR(255) NOT NULL,
  role                ENUM('Super Admin','Clinic Manager','Teller') NOT NULL DEFAULT 'Teller',
  assigned_service_id INT          NULL,
  must_change_password TINYINT(1)  NOT NULL DEFAULT 0,
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_staff_est FOREIGN KEY (establishment_id)
    REFERENCES establishments(id) ON DELETE CASCADE,
  CONSTRAINT fk_staff_service FOREIGN KEY (assigned_service_id)
    REFERENCES services(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 4. Tickets (queue numbers restart each day, per service)
CREATE TABLE tickets (
  id            BIGINT AUTO_INCREMENT PRIMARY KEY,
  queue_number  VARCHAR(12)  NOT NULL,
  ticket_date   DATE         NOT NULL,
  service_id    INT          NOT NULL,
  patient_name  VARCHAR(120) NOT NULL DEFAULT 'Walk-in',
  status        ENUM('Waiting','Called','Serving','Completed','Skipped') NOT NULL DEFAULT 'Waiting',
  priority_type ENUM('Regular','Priority') NOT NULL DEFAULT 'Regular',
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  served_at     DATETIME     NULL,
  completed_at  DATETIME     NULL,
  UNIQUE KEY uq_ticket_number (service_id, ticket_date, queue_number),
  KEY idx_queue (service_id, ticket_date, status, created_at),
  CONSTRAINT fk_tickets_service FOREIGN KEY (service_id)
    REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 5. TV ticker announcements
CREATE TABLE announcements (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  establishment_id INT          NOT NULL,
  message          VARCHAR(255) NOT NULL,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ann (establishment_id, created_at),
  CONSTRAINT fk_ann_est FOREIGN KEY (establishment_id)
    REFERENCES establishments(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------- Starter data ----------
INSERT INTO establishments (name, join_code, ticket_limit)
VALUES ('Sunrise Health Clinic', 'SUN-4F7K2', 100);

INSERT INTO services (establishment_id, name, prefix, color) VALUES
  (1, 'Consultation', 'CON', '#1E3A8A'),
  (1, 'Laboratory',   'LAB', '#0F766E'),
  (1, 'Pharmacy',     'PHA', '#B45309');

INSERT INTO announcements (establishment_id, message)
VALUES (1, 'Welcome to Sunrise Health Clinic. Please wait for your number to be called.');

-- Mock Admin account  ->  admin@queuesync.com / admin123
-- (bcrypt hash; change this password after your first login)
INSERT INTO staff (establishment_id, name, email, password_hash, role)
VALUES (NULL, 'System Admin', 'admin@queuesync.com',
        '$2y$12$t4BfeOZPOCeVy.y3/aqJreIcNgvTTPxXBuib9/HWFXSAwW/MeIoYe',
        'Super Admin');

-- If the login ever fails, make a fresh hash on your own XAMPP and paste it above:
--   C:\xampp\php\php.exe -r "echo password_hash('admin123', PASSWORD_BCRYPT);"