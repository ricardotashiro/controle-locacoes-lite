<?php
require_once __DIR__ . '/includes/helpers.php';
function db(): mysqli
{
    static $conn = null;
    if ($conn instanceof mysqli) {
        return $conn;
    }

    $config = require __DIR__ . '/config.php';
    $host = $config['db_host'] ?? 'localhost';
    $user = $config['db_user'] ?? 'root';
    $pass = $config['db_pass'] ?? '';
    $name = $config['db_name'] ?? '';

    mysqli_report(MYSQLI_REPORT_OFF);
    $conn = @new mysqli($host, $user, $pass, $name);

    if ($conn->connect_errno) {
        die('Erro ao conectar no banco de dados: ' . htmlspecialchars($conn->connect_error));
    }

    $conn->set_charset('utf8mb4');

    if (!empty($config['timezone'])) {
        @date_default_timezone_set($config['timezone']);
    }
    if (!empty($config['locale'])) {
        @setlocale(LC_TIME, $config['locale'], 'pt_BR', 'Portuguese_Brazil.1252');
    }

    $dbTimezone = $config['db_timezone'] ?? '-03:00';
    if (!empty($dbTimezone)) {
        @$conn->query("SET time_zone = '" . $conn->real_escape_string($dbTimezone) . "'");
    }

    ensureUsersStructure($conn);
    ensureClientsStructure($conn);
    ensureClientsUniqueIndexes($conn);
    ensureBookingsStructure($conn);
    return $conn;
}

function ensureUsersStructure(mysqli $conn): void
{
    static $done = false;
    if ($done) return;

    $conn->query("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        username VARCHAR(80) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        is_admin TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $columns = [];
    $result = $conn->query("SHOW COLUMNS FROM users");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $columns[] = $row['Field'];
        }
    }

    if (!in_array('is_admin', $columns, true)) {
        @$conn->query("ALTER TABLE users ADD is_admin TINYINT(1) NOT NULL DEFAULT 1 AFTER password_hash");
    }

    $done = true;
}

function ensureClientsStructure(mysqli $conn): void
{
    static $done = false;
    if ($done) return;

    $conn->query("CREATE TABLE IF NOT EXISTS clients (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $columns = [];
    $result = $conn->query("SHOW COLUMNS FROM bookings");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $columns[] = $row['Field'];
        }
    }

    if (!in_array('client_id', $columns, true)) {
        @$conn->query("ALTER TABLE bookings ADD client_id INT NULL AFTER apartment_id");
        @$conn->query("ALTER TABLE bookings ADD INDEX idx_bookings_client_id (client_id)");
        @$conn->query("ALTER TABLE bookings ADD CONSTRAINT fk_bookings_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL");
    }

    $done = true;
}

function ensureClientsUniqueIndexes(mysqli $conn): void
{
    static $done = false;
    if ($done) return;

    @$conn->query("UPDATE clients SET phone = REGEXP_REPLACE(phone, '[^0-9]', '') WHERE phone IS NOT NULL");
    @$conn->query("UPDATE clients SET whatsapp = NULLIF(REGEXP_REPLACE(COALESCE(whatsapp, ''), '[^0-9]', ''), '')");
    @$conn->query("UPDATE clients SET document = NULL WHERE TRIM(COALESCE(document, '')) = ''");
    @$conn->query("UPDATE clients SET document = UPPER(REGEXP_REPLACE(document, '[^A-Za-z0-9]', '')) WHERE document IS NOT NULL");

    $indexes = [];
    $result = $conn->query("SHOW INDEX FROM clients");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $indexes[] = $row['Key_name'];
        }
    }

    if (!in_array('uniq_clients_phone', $indexes, true)) {
        @$conn->query("ALTER TABLE clients ADD UNIQUE KEY uniq_clients_phone (phone)");
    }

    if (!in_array('uniq_clients_document', $indexes, true)) {
        @$conn->query("ALTER TABLE clients ADD UNIQUE KEY uniq_clients_document (document)");
    }

    $done = true;
}



function ensureBookingsStructure(mysqli $conn): void
{
    static $done = false;
    if ($done) return;

    $columns = [];
    $result = $conn->query("SHOW COLUMNS FROM bookings");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $columns[] = $row['Field'];
        }
    }

    if (!in_array('entry_amount', $columns, true)) {
        @$conn->query("ALTER TABLE bookings ADD COLUMN entry_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER daily_rate");
    }

    if (!in_array('client_id', $columns, true)) {
        @$conn->query("ALTER TABLE bookings ADD client_id INT NULL AFTER apartment_id");
        @$conn->query("ALTER TABLE bookings ADD INDEX idx_bookings_client_id (client_id)");
        @$conn->query("ALTER TABLE bookings ADD CONSTRAINT fk_bookings_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL");
    }

    $done = true;
}
