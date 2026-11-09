<?php
/**
 * DOCTOR CONNECT - Patient Profile Management
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';

require_role('patient');
$user = current_user();
$pageTitle = "My Profile";

$error = '';

try {
    $db = get_db();
    $stmt = $db->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $user['id']]);
    $currentUser = $stmt->fetch();
} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_post();

    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (empty($name) || empty($phone)) {
        $error = "Name and phone cannot be empty.";
    } elseif (!empty($newPassword) && strlen($newPassword) < 6) {
        $error = "New password must be at least 6 characters long.";
    } elseif (!empty($newPassword) && $newPassword !== $confirmPassword) {
        $error = "Passwords do not match.";
    } else {
        try {
            if (!empty($newPassword)) {
                $hash = password_hash($newPassword, PASSWORD_BCRYPT);
                $upd = $db->prepare("UPDATE users SET name = :name, phone = :phone, password_hash = :hash WHERE id = :id");
                $upd->execute([':name' => $name, ':phone' => $phone, ':hash' => $hash, ':id' => $user['id']]);
            } else {
                $upd = $db->prepare("UPDATE users SET name = :name, phone = :phone WHERE id = :id");
                $upd->execute([':name' => $name, ':phone' => $phone, ':id' => $user['id']]);
            }

            $_SESSION['user_name'] = $name;
            $_SESSION['user_phone'] = $phone;
            set_flash('success', "Your profile details have been updated.");
            header("Location: " . BASE_URL . "/patient/profile.php");
            exit;
        } catch (Exception $e) {
            $error = "Failed to update profile: " . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Profile Settings</h1>
        <p class="page-subtitle">Manage your personal information and contact details.</p>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-error">
        <span>✕</span> <?= e($error) ?>
    </div>
<?php endif; ?>

<div style="max-width: 600px;">
    <div class="card">
        <form method="POST" action="">
            <?= csrf_field() ?>

            <div class="form-group">
                <label class="form-label">Email Address (Read-only)</label>
                <input type="email" class="form-control" value="<?= e($currentUser['email']) ?>" disabled style="background:#f1f5f9;">
            </div>

            <div class="form-group">
                <label class="form-label" for="name">Full Name</label>
                <input type="text" id="name" name="name" class="form-control" required value="<?= e($currentUser['name']) ?>">
            </div>

            <div class="form-group">
                <label class="form-label" for="phone">Phone Number</label>
                <input type="tel" id="phone" name="phone" class="form-control" required value="<?= e($currentUser['phone']) ?>">
            </div>

            <hr style="border:0; border-top:1px solid #e2e8f0; margin:24px 0;">
            <div style="font-weight:700; color:#0f172a; margin-bottom:12px;">Change Password (Optional)</div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password" class="form-control" placeholder="Leave blank to keep current">
                </div>

                <div class="form-group">
                    <label class="form-label" for="confirm_password">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" placeholder="Re-enter new password">
                </div>
            </div>

            <button type="submit" class="btn btn-primary" style="margin-top:10px;">
                Save Profile Changes
            </button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
