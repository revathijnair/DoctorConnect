<?php
/**
 * DOCTOR CONNECT - Patient Registration Page
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/csrf.php';

$pageTitle = "Patient Registration";

if (is_logged_in()) {
    $user = current_user();
    header("Location: " . BASE_URL . "/" . $user['role'] . "/dashboard.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_post();

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // Validation
    if (empty($name) || empty($email) || empty($phone) || empty($password)) {
        $error = "All fields are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters long.";
    } elseif ($password !== $confirmPassword) {
        $error = "Passwords do not match.";
    } else {
        try {
            $db = get_db();

            // Check if email or phone already exists
            $checkStmt = $db->prepare("SELECT id, email, phone FROM users WHERE email = :email OR phone = :phone LIMIT 1");
            $checkStmt->execute([':email' => $email, ':phone' => $phone]);
            $existing = $checkStmt->fetch();

            if ($existing) {
                if (strtolower($existing['email']) === strtolower($email)) {
                    $error = "An account with this email address already exists.";
                } else {
                    $error = "An account with this phone number already exists.";
                }
            } else {
                // Insert new patient with bcrypt hash
                $passwordHash = password_hash($password, PASSWORD_BCRYPT);
                $insertStmt = $db->prepare("
                    INSERT INTO users (name, email, phone, password_hash, role, created_at)
                    VALUES (:name, :email, :phone, :hash, 'patient', NOW())
                ");
                $insertStmt->execute([
                    ':name'  => $name,
                    ':email' => $email,
                    ':phone' => $phone,
                    ':hash'  => $passwordHash
                ]);

                $newUserId = (int)$db->lastInsertId();

                // Auto-login user
                login_user([
                    'id'    => $newUserId,
                    'name'  => $name,
                    'email' => $email,
                    'phone' => $phone,
                    'role'  => 'patient'
                ]);

                set_flash('success', "Welcome to DoctorConnect, {$name}! Your account has been registered successfully.");
                header("Location: " . BASE_URL . "/patient/dashboard.php");
                exit;
            }
        } catch (Exception $e) {
            $error = "Registration failed: " . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div style="max-width: 520px; margin: 40px auto;">
    <div class="card" style="padding: 36px;">
        <div style="text-align:center; margin-bottom: 24px;">
            <div class="brand-icon" style="margin: 0 auto 12px auto; width:44px; height:44px; font-size:22px;">⚕</div>
            <h1 style="font-size: 22px; font-weight:800; color:#0f172a;">Create Patient Account</h1>
            <p style="color:#64748b; font-size: 13px; margin-top:4px;">Join to schedule appointments and receive instant digital tokens</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <span>✕</span> <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <?= csrf_field() ?>

            <div class="form-group">
                <label class="form-label" for="name">Full Name</label>
                <input type="text" id="name" name="name" class="form-control" placeholder="e.g. Eleanor Vance" required value="<?= e($_POST['name'] ?? '') ?>">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="email">Email Address</label>
                    <input type="email" id="email" name="email" class="form-control" placeholder="patient@example.com" required value="<?= e($_POST['email'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label class="form-label" for="phone">Phone Number</label>
                    <input type="tel" id="phone" name="phone" class="form-control" placeholder="+15551234567" required value="<?= e($_POST['phone'] ?? '') ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <input type="password" id="password" name="password" class="form-control" placeholder="Min. 6 chars" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="confirm_password">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" placeholder="Re-enter password" required>
                </div>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px; font-size: 15px; margin-top:10px;">
                Complete Registration &rarr;
            </button>
        </form>

        <div style="margin-top: 24px; text-align: center; font-size: 13px; color:#64748b;">
            Already have an account? <a href="<?= BASE_URL ?>/login.php" style="font-weight:600;">Sign in here</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
