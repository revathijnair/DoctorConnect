<?php
/**
 * DOCTOR CONNECT - Common Header Template
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/csrf.php';

$user = current_user();
$clinicName = get_clinic_setting('clinic_name', 'DoctorConnect Clinic');
$currentPath = $_SERVER['PHP_SELF'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? e($pageTitle) . ' | ' : '' ?><?= e($clinicName) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <script>
        window.DoctorConnectBaseUrl = <?= json_encode(BASE_URL) ?>;
        window.DoctorConnectUser = <?= json_encode($user ? ['id' => $user['id'], 'role' => $user['role']] : null) ?>;
    </script>
</head>
<body>

<header class="navbar">
    <div class="nav-container">
        <a href="<?= BASE_URL ?>/index.php" class="brand-link">
            <span class="brand-icon">⚕</span>
            <span><?= e($clinicName) ?></span>
        </a>

        <ul class="nav-menu">
            <?php if (!$user): ?>
                <li><a href="<?= BASE_URL ?>/index.php" class="nav-link <?= str_ends_with($currentPath, 'index.php') ? 'active' : '' ?>">Home</a></li>
                <li><a href="<?= BASE_URL ?>/login.php" class="nav-link <?= str_ends_with($currentPath, 'login.php') ? 'active' : '' ?>">Login</a></li>
                <li><a href="<?= BASE_URL ?>/register.php" class="btn btn-primary btn-sm">Register as Patient</a></li>
            <?php elseif ($user['role'] === 'patient'): ?>
                <li><a href="<?= BASE_URL ?>/patient/dashboard.php" class="nav-link <?= str_contains($currentPath, 'dashboard.php') ? 'active' : '' ?>">Dashboard</a></li>
                <li><a href="<?= BASE_URL ?>/patient/book.php" class="nav-link <?= str_contains($currentPath, 'book.php') ? 'active' : '' ?>">Book Token</a></li>
                <li><a href="<?= BASE_URL ?>/patient/history.php" class="nav-link <?= str_contains($currentPath, 'history.php') ? 'active' : '' ?>">My Consultations</a></li>
                <li><a href="<?= BASE_URL ?>/patient/profile.php" class="nav-link <?= str_contains($currentPath, 'profile.php') ? 'active' : '' ?>">Profile</a></li>
            <?php elseif ($user['role'] === 'doctor'): ?>
                <li><a href="<?= BASE_URL ?>/doctor/dashboard.php" class="nav-link <?= str_contains($currentPath, 'dashboard.php') ? 'active' : '' ?>">Today's Queue</a></li>
                <li><a href="<?= BASE_URL ?>/doctor/schedule.php" class="nav-link <?= str_contains($currentPath, 'schedule.php') ? 'active' : '' ?>">My Schedule & Leaves</a></li>
                <li><a href="<?= BASE_URL ?>/doctor/history.php" class="nav-link <?= str_contains($currentPath, 'history.php') ? 'active' : '' ?>">Patient Records</a></li>
            <?php elseif ($user['role'] === 'admin'): ?>
                <li><a href="<?= BASE_URL ?>/admin/dashboard.php" class="nav-link <?= str_contains($currentPath, 'dashboard.php') ? 'active' : '' ?>">Overview</a></li>
                <li><a href="<?= BASE_URL ?>/admin/queue_monitor.php" class="nav-link <?= str_contains($currentPath, 'queue_monitor.php') ? 'active' : '' ?>">Live Reception Queue</a></li>
                <li><a href="<?= BASE_URL ?>/admin/doctors.php" class="nav-link <?= str_contains($currentPath, 'doctors.php') ? 'active' : '' ?>">Doctors</a></li>
                <li><a href="<?= BASE_URL ?>/admin/reports.php" class="nav-link <?= str_contains($currentPath, 'reports.php') ? 'active' : '' ?>">Reports & CSV</a></li>
                <li><a href="<?= BASE_URL ?>/admin/settings.php" class="nav-link <?= str_contains($currentPath, 'settings.php') ? 'active' : '' ?>">Settings</a></li>
            <?php endif; ?>

            <?php if ($user): ?>
                <li style="display:flex; align-items:center; gap:8px;">
                    <span class="user-badge">
                        <span>👤 <?= e($user['name']) ?></span>
                        <span class="role-pill role-<?= e($user['role']) ?>"><?= e($user['role']) ?></span>
                    </span>
                    <a href="<?= BASE_URL ?>/logout.php" class="btn btn-secondary btn-sm" title="Log Out">Log Out</a>
                </li>
            <?php endif; ?>
        </ul>
    </div>
</header>

<main class="main-wrapper">
    <!-- Flash Messages -->
    <?php $flashes = get_flashes(); ?>
    <?php if (!empty($flashes)): ?>
        <div class="alert-container">
            <?php foreach ($flashes as $type => $messages): ?>
                <?php foreach ($messages as $msg): ?>
                    <div class="alert alert-<?= e($type) ?>">
                        <span><?= $type === 'success' ? '✓' : ($type === 'error' ? '✕' : 'ℹ') ?></span>
                        <div><?= e($msg) ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
