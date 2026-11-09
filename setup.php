<?php
/**
 * DOCTOR CONNECT - Database Installer & Seeder Tool
 */

require_once __DIR__ . '/config/config.php';

$message = '';
$error = '';
$installed = false;

$isCli = (php_sapi_name() === 'cli');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$isAuto = isset($_GET['auto']) || ($isCli && in_array('install', $argv ?? ['install']));

if ($isPost || $isAuto || $isCli) {
    try {
        if ($isCli) {
            echo "==================================================\n";
            echo " DOCTOR CONNECT - CLI Database Installer\n";
            echo "==================================================\n";
            echo "Connecting to MySQL at " . DB_HOST . ":" . DB_PORT . " as " . DB_USER . "...\n";
        }

        // 1. Connect to MySQL server without selecting DB initially
        $rootDsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";charset=utf8mb4";
        $pdo = new PDO($rootDsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);

        if ($isCli) echo "[1/4] Connected. Creating database `" . DB_NAME . "`...\n";
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `" . DB_NAME . "`");

        if ($isCli) echo "[2/4] Executing schema.sql (tables, indexes, constraints)...\n";
        $schemaSql = file_get_contents(__DIR__ . '/config/schema.sql');
        $pdo->exec($schemaSql);

        if ($isCli) echo "[3/4] Executing seed.sql (demo users, doctors, schedules)...\n";
        $seedSql = file_get_contents(__DIR__ . '/config/seed.sql');
        $pdo->exec($seedSql);

        if ($isCli) echo "[4/4] Setting demo password ('password123') with native bcrypt...\n";
        $defaultHash = password_hash('password123', PASSWORD_BCRYPT);
        $updateHashStmt = $pdo->prepare("UPDATE users SET password_hash = :hash WHERE email IN ('admin@doctorconnect.com', 'dr.vance@doctorconnect.com', 'dr.sarah@doctorconnect.com', 'dr.thorne@doctorconnect.com', 'john.doe@example.com', 'emily.w@example.com', 'mbrown@example.com')");
        $updateHashStmt->execute([':hash' => $defaultHash]);

        $installed = true;
        $message = "Database `" . DB_NAME . "` successfully installed and seeded with demo data!";

        if ($isCli) {
            echo "\n✓ SUCCESS: " . $message . "\n\n";
            echo "Demo Accounts:\n";
            echo " - Admin:   admin@doctorconnect.com     (password: password123)\n";
            echo " - Doctor:  dr.vance@doctorconnect.com  (password: password123)\n";
            echo " - Patient: john.doe@example.com        (password: password123)\n\n";
            echo "Start server with: php -S localhost:8000\n";
            echo "Then open: http://localhost:8000\n";
            exit(0);
        }
    } catch (Exception $e) {
        $error = "Installation failed: " . $e->getMessage();
        if ($isCli) {
            echo "\n✕ ERROR: " . $error . "\n";
            exit(1);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Setup & Seeder | DoctorConnect</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body style="background:#f1f5f9; display:flex; align-items:center; justify-content:center; min-height:100vh;">

<div style="max-width: 620px; width:100%; margin:30px 20px; background:#fff; border-radius:16px; border:1px solid #e2e8f0; padding:32px; box-shadow:0 10px 25px -5px rgba(0,0,0,0.08);">
    <div style="text-align:center; margin-bottom:24px;">
        <span class="brand-icon" style="margin:0 auto 12px auto; width:48px; height:48px; font-size:24px;">⚕</span>
        <h1 style="font-size:24px; font-weight:800; color:#0f172a;">DoctorConnect Setup & Installer</h1>
        <p style="color:#64748b; font-size:14px;">Automated Database Migration & Seed Provisioning</p>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success" style="font-size:14px; margin-bottom:20px;">
            <span>✓</span> <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error" style="font-size:14px; margin-bottom:20px;">
            <span>✕</span> <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:18px; margin-bottom:24px; font-size:13px; color:#475569;">
        <div style="font-weight:700; margin-bottom:8px; color:#0f172a;">Target Database Configuration:</div>
        <div><strong>Host:</strong> <?= htmlspecialchars(DB_HOST) ?>:<?= htmlspecialchars(DB_PORT) ?></div>
        <div><strong>Database Name:</strong> <?= htmlspecialchars(DB_NAME) ?></div>
        <div><strong>User:</strong> <?= htmlspecialchars(DB_USER) ?></div>
        <div style="margin-top:8px; color:#64748b;">(Edit <code>config/config.php</code> or set environment variables to adjust credentials.)</div>
    </div>

    <?php if (!$installed): ?>
        <form method="POST">
            <button type="submit" class="btn btn-primary btn-lg" style="width:100%;">
                ⚡ Initialize Database & Seed Demo Data
            </button>
        </form>
    <?php else: ?>
        <div style="margin-top:20px;">
            <h3 style="font-size:16px; color:#0f172a; margin-bottom:12px;">Demo Accounts (All password: <code>password123</code>):</h3>
            <ul style="list-style:none; padding:0; display:flex; flex-direction:column; gap:8px; font-size:13px;">
                <li style="padding:10px; background:#eff6ff; border-radius:8px; border:1px solid #bfdbfe;">
                    <strong>Admin / Receptionist:</strong> <code>admin@doctorconnect.com</code>
                </li>
                <li style="padding:10px; background:#f0fdf4; border-radius:8px; border:1px solid #bbf7d0;">
                    <strong>Doctor:</strong> <code>dr.vance@doctorconnect.com</code> (Dr. Marcus Vance)
                </li>
                <li style="padding:10px; background:#fefce8; border-radius:8px; border:1px solid #fef08a;">
                    <strong>Patient:</strong> <code>john.doe@example.com</code> (John Doe)
                </li>
            </ul>

            <div style="display:flex; gap:12px; margin-top:24px;">
                <a href="<?= BASE_URL ?>/login.php" class="btn btn-primary" style="flex:1;">Go to Login &rarr;</a>
                <a href="<?= BASE_URL ?>/index.php" class="btn btn-secondary" style="flex:1;">Visit Homepage</a>
            </div>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
