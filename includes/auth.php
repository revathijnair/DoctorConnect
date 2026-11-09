<?php
/**
 * DOCTOR CONNECT - Authentication & Authorization Handlers
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

/**
 * Check session idle timeout
 */
function check_session_timeout(): void {
    if (isset($_SESSION['user_id'])) {
        $now = time();
        $timeout_seconds = SESSION_TIMEOUT_MINS * 60;
        if (isset($_SESSION['last_activity']) && ($now - $_SESSION['last_activity'] > $timeout_seconds)) {
            // Session expired
            session_unset();
            session_destroy();
            session_start();
            $_SESSION['flash_error'] = "Your session has expired due to inactivity. Please log in again.";
            header("Location: " . BASE_URL . "/login.php?timeout=1");
            exit;
        }
        $_SESSION['last_activity'] = $now;
    }
}
check_session_timeout();

function is_logged_in(): bool {
    return !empty($_SESSION['user_id']);
}

function current_user(): ?array {
    if (!is_logged_in()) {
        return null;
    }
    return [
        'id'        => (int)$_SESSION['user_id'],
        'name'      => $_SESSION['user_name'] ?? 'User',
        'email'     => $_SESSION['user_email'] ?? '',
        'phone'     => $_SESSION['user_phone'] ?? '',
        'role'      => $_SESSION['user_role'] ?? 'patient',
        'doctor_id' => $_SESSION['doctor_id'] ?? null
    ];
}

function require_login(): void {
    if (!is_logged_in()) {
        $target = $_SERVER['REQUEST_URI'] ?? '';
        $_SESSION['redirect_after_login'] = $target;
        header("Location: " . BASE_URL . "/login.php");
        exit;
    }
}

/**
 * Enforce role access control
 * @param string|array $allowed_roles
 */
function require_role($allowed_roles): void {
    require_login();
    $roles = is_array($allowed_roles) ? $allowed_roles : [$allowed_roles];
    $user = current_user();
    if (!in_array($user['role'], $roles)) {
        http_response_code(403);
        die("
            <div style='font-family:sans-serif; max-width:500px; margin:60px auto; padding:24px; border:1px solid #fee2e2; border-radius:12px; background:#fff;'>
                <h2 style='color:#dc2626; margin-top:0;'>Access Denied (403)</h2>
                <p style='color:#475569;'>You do not have sufficient permissions to view this section.</p>
                <a href='" . BASE_URL . "/index.php' style='color:#0284c7; font-weight:600;'>&larr; Return to Dashboard</a>
            </div>
        ");
    }
}

/**
 * Log in a user securely
 */
function login_user(array $user): void {
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_phone'] = $user['phone'] ?? '';
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['last_activity'] = time();

    // If role is doctor, cache doctor record ID
    if ($user['role'] === 'doctor') {
        $db = get_db();
        $stmt = $db->prepare("SELECT id FROM doctors WHERE user_id = :uid LIMIT 1");
        $stmt->execute([':uid' => $user['id']]);
        $doc = $stmt->fetch();
        $_SESSION['doctor_id'] = $doc ? (int)$doc['id'] : null;
    } else {
        unset($_SESSION['doctor_id']);
    }
}

/**
 * Log out user
 */
function logout_user(): void {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}
