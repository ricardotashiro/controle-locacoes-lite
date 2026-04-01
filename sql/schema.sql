CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    username VARCHAR(80) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    is_admin TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS apartments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    type VARCHAR(80) DEFAULT NULL,
    color VARCHAR(20) DEFAULT '#0ea5e9',
    weekday_daily_rate DECIMAL(10,2) DEFAULT 0.00,
    weekend_daily_rate DECIMAL(10,2) DEFAULT 0.00,
    holiday_daily_rate DECIMAL(10,2) DEFAULT 0.00,
    default_daily_rate DECIMAL(10,2) DEFAULT 0.00,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    phone VARCHAR(50) NOT NULL,
    whatsapp VARCHAR(50) DEFAULT NULL,
    document VARCHAR(50) DEFAULT NULL,
    email VARCHAR(120) DEFAULT NULL,
    birth_date DATE DEFAULT NULL,
    address VARCHAR(255) DEFAULT NULL,
    city VARCHAR(120) DEFAULT NULL,
    status ENUM('novo','frequente','vip','bloqueado') DEFAULT 'novo',
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS bookings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    apartment_id INT NOT NULL DEFAULT 0,
    client_id INT DEFAULT NULL,
    guest_name VARCHAR(150) NOT NULL,
    guest_phone VARCHAR(50) DEFAULT NULL,
    guest_document VARCHAR(50) DEFAULT NULL,
    checkin_datetime DATETIME NOT NULL,
    checkout_datetime DATETIME NOT NULL,
    daily_rate DECIMAL(10,2) DEFAULT 0.00,
    entry_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_amount DECIMAL(10,2) DEFAULT 0.00,
    status ENUM('confirmada','hospedado','finalizada','cancelada') DEFAULT 'confirmada',
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_bookings_apartment FOREIGN KEY (apartment_id) REFERENCES apartments(id) ON DELETE CASCADE,
    CONSTRAINT fk_bookings_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL
);
