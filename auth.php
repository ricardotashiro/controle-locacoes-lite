<?php
$config = require __DIR__ . '/config.php';
if (!empty($config['timezone'])) {
    @date_default_timezone_set($config['timezone']);
}
if (!empty($config['locale'])) {
    @setlocale(LC_TIME, $config['locale'], 'pt_BR', 'Portuguese_Brazil.1252');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_input(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function verify_csrf_or_fail(): void
{
    $token = (string)($_POST['csrf_token'] ?? '');
    if ($token === '' || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        exit('Sessão inválida. Atualize a página e tente novamente.');
    }
}

function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function is_admin(): bool
{
    if (!empty($_SESSION['is_admin'])) {
        return true;
    }

    $username = strtolower((string)($_SESSION['username'] ?? ''));
    return $username === 'admin';
}

function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        exit('Acesso restrito ao administrador.');
    }
}

function current_user_name(): string
{
    return $_SESSION['user_name'] ?? 'Usuário';
}

function current_username(): string
{
    return $_SESSION['username'] ?? 'usuario';
}
