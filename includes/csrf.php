<?php
/**
 * DOCTOR CONNECT - CSRF Protection Utilities
 */

require_once __DIR__ . '/../config/config.php';

function generate_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function get_csrf_token(): string {
    return generate_csrf_token();
}

function validate_csrf_token(?string $token): bool {
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function csrf_field(): string {
    $token = htmlspecialchars(get_csrf_token(), ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

/**
 * Verify CSRF on POST or abort with 403
 */
function verify_csrf_post(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (!validate_csrf_token($token)) {
            http_response_code(403);
            if (!empty($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) {
                echo json_encode(['success' => false, 'error' => 'CSRF token validation failed.']);
            } else {
                die("<h1>403 Forbidden</h1><p>CSRF token validation failed. Please refresh and try again.</p>");
            }
            exit;
        }
    }
}
