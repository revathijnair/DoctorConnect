<?php
/**
 * DOCTOR CONNECT - Universal Login Page
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/csrf.php';

$pageTitle = "Login";

// If already logged in, redirect to appropriate portal
if (is_logged_in()) {
    $user = current_user();
    header("Location: " . BASE_URL . "/" . $user['role'] . "/dashboard.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_post();
    
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = "Please provide both email and password.";
    } else {
        try {
            $db = get_db();
            $stmt = $db->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                login_user($user);
                
                // Redirect logic
                $redirect = $_SESSION['redirect_after_login'] ?? (BASE_URL . "/" . $user['role'] . "/dashboard.php");
                unset($_SESSION['redirect_after_login']);
                header("Location: " . $redirect);
                exit;
            } else {
                $error = "Invalid email or password. Please try again.";
            }
        } catch (Exception $e) {
            $error = "System error during authentication: " . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div style="max-width: 480px; margin: 40px auto;">
    <div class="card" style="padding: 32px;">
        <div style="text-align:center; margin-bottom: 24px;">
            <div class="brand-icon" style="margin: 0 auto 12px auto; width:44px; height:44px; font-size:22px;">⚕</div>
            <h1 style="font-size: 22px; font-weight:800; color:#0f172a;">Sign In to DoctorConnect</h1>
            <p style="color:#64748b; font-size: 13px; margin-top:4px;">Access your consultation queue, appointments & records</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <span>✕</span> <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <?= csrf_field() ?>

            <div class="form-group">
                <label class="form-label" for="email">Email Address</label>
                <input type="email" id="email" name="email" class="form-control" placeholder="name@example.com" required autofocus value="<?= e($_POST['email'] ?? '') ?>">
            </div>

            <div class="form-group">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                    <label class="form-label" for="password" style="margin-bottom:0;">Password</label>
                </div>
                <input type="password" id="password" name="password" class="form-control" placeholder="••••••••" required>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 11px; font-size: 15px; margin-top:8px;">
                Sign In to Account
            </button>
        </form>

        <div style="margin-top: 24px; text-align: center; font-size: 13px; color:#64748b;">
            Don't have an account yet? <a href="<?= BASE_URL ?>/register.php" style="font-weight:600;">Register as Patient</a>
        </div>

        <!-- 1-Click Demo Accounts for Rapid Evaluation -->
        <hr style="border:0; border-top:1px solid #e2e8f0; margin:24px 0;">
        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:16px;">
            <div style="font-size:12px; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:10px; text-align:center;">
                ⚡ 1-Click Demo Account Quick Fill:
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:8px;">
                <button type="button" class="btn btn-secondary btn-sm" onclick="fillDemo('john.doe@example.com')">
                    Patient
                </button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="fillDemo('dr.vance@doctorconnect.com')">
                    Doctor
                </button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="fillDemo('admin@doctorconnect.com')">
                    Admin
                </button>
            </div>
            <div style="font-size:11px; color:#94a3b8; text-align:center; margin-top:8px;">
                Default password for demo accounts: <code>password123</code>
            </div>
        </div>
    </div>
</div>

<script>
function fillDemo(email) {
    document.getElementById('email').value = email;
    document.getElementById('password').value = 'password123';
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
