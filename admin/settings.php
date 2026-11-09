<?php
/**
 * DOCTOR CONNECT - Clinic Configuration & Settings
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';

require_role('admin');
$user = current_user();
$pageTitle = "Clinic Settings";

$error = '';

try {
    $db = get_db();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf_post();

        $settings = [
            'clinic_name'           => trim($_POST['clinic_name'] ?? 'DoctorConnect Clinic'),
            'clinic_phone'          => trim($_POST['clinic_phone'] ?? ''),
            'clinic_email'          => trim($_POST['clinic_email'] ?? ''),
            'clinic_address'        => trim($_POST['clinic_address'] ?? ''),
            'clinic_currency'       => trim($_POST['clinic_currency'] ?? '$'),
            'default_slot_duration' => (int)($_POST['default_slot_duration'] ?? 15),
            'cancel_window_hours'   => (int)($_POST['cancel_window_hours'] ?? 2),
            'jitsi_domain'          => trim($_POST['jitsi_domain'] ?? 'meet.jit.si')
        ];

        $stmt = $db->prepare("
            INSERT INTO clinic_settings (setting_key, setting_value)
            VALUES (:k, :v)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");

        foreach ($settings as $k => $v) {
            $stmt->execute([':k' => $k, ':v' => (string)$v]);
        }

        set_flash('success', "Clinic settings updated successfully.");
        header("Location: " . BASE_URL . "/admin/settings.php");
        exit;
    }

    // Load settings
    $settingsRows = $db->query("SELECT setting_key, setting_value FROM clinic_settings")->fetchAll();
    $currentSettings = [];
    foreach ($settingsRows as $sr) {
        $currentSettings[$sr['setting_key']] = $sr['setting_value'];
    }

} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Clinic Profile & System Settings</h1>
        <p class="page-subtitle">Configure clinic identity, cancellation policies, and video server preferences.</p>
    </div>
</div>

<div style="max-width:720px;">
    <div class="card">
        <form method="POST" action="">
            <?= csrf_field() ?>

            <h2 style="font-size:16px; font-weight:800; color:#0f172a; margin-bottom:14px;">Clinic Identity</h2>

            <div class="form-group">
                <label class="form-label" for="clinic_name">Clinic / Telehealth Brand Name</label>
                <input type="text" id="clinic_name" name="clinic_name" class="form-control" required 
                       value="<?= e($currentSettings['clinic_name'] ?? 'DoctorConnect Telehealth Clinic') ?>">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="clinic_phone">Support Contact Phone</label>
                    <input type="tel" id="clinic_phone" name="clinic_phone" class="form-control" 
                           value="<?= e($currentSettings['clinic_phone'] ?? '+1 (800) 555-0199') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="clinic_email">Support Contact Email</label>
                    <input type="email" id="clinic_email" name="clinic_email" class="form-control" 
                           value="<?= e($currentSettings['clinic_email'] ?? 'care@doctorconnect.com') ?>">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="clinic_address">Clinic Physical Address</label>
                <input type="text" id="clinic_address" name="clinic_address" class="form-control" 
                       value="<?= e($currentSettings['clinic_address'] ?? '742 Evergreen Medical Suites, Health City, HC 94102') ?>">
            </div>

            <hr style="border:0; border-top:1px solid #e2e8f0; margin:24px 0;">
            <h2 style="font-size:16px; font-weight:800; color:#0f172a; margin-bottom:14px;">Queue & Policy Rules</h2>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="default_slot_duration">Default Slot Duration (Minutes)</label>
                    <input type="number" id="default_slot_duration" name="default_slot_duration" class="form-control" min="5" max="60" 
                           value="<?= e($currentSettings['default_slot_duration'] ?? '15') ?>">
                    <div style="font-size:12px; color:#64748b; margin-top:4px;">Used when calculating default time increments.</div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="cancel_window_hours">Cancellation Window (Hours Before)</label>
                    <input type="number" id="cancel_window_hours" name="cancel_window_hours" class="form-control" min="1" max="24" 
                           value="<?= e($currentSettings['cancel_window_hours'] ?? '2') ?>">
                    <div style="font-size:12px; color:#64748b; margin-top:4px;">Patients cannot self-cancel within this window.</div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="clinic_currency">Currency Symbol</label>
                    <input type="text" id="clinic_currency" name="clinic_currency" class="form-control" style="width:100px;" 
                           value="<?= e($currentSettings['clinic_currency'] ?? '$') ?>">
                </div>

                <div class="form-group">
                    <label class="form-label" for="jitsi_domain">Jitsi Server Domain</label>
                    <input type="text" id="jitsi_domain" name="jitsi_domain" class="form-control" 
                           value="<?= e($currentSettings['jitsi_domain'] ?? 'meet.jit.si') ?>">
                    <div style="font-size:12px; color:#64748b; margin-top:4px;">Default free public Jitsi or your self-hosted Jitsi domain.</div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-lg" style="margin-top:16px;">
                Save Configuration
            </button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
