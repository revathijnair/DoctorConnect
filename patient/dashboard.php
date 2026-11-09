<?php
/**
 * DOCTOR CONNECT - Patient Dashboard
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role('patient');
$user = current_user();
$pageTitle = "Patient Dashboard";

$today = date('Y-m-d');

try {
    $db = get_db();

    // 1. Fetch today's active appointment (waiting or serving)
    $activeStmt = $db->prepare("
        SELECT a.*, d.specialization, d.avg_consultation_mins, u.name as doctor_name
        FROM appointments a
        JOIN doctors d ON a.doctor_id = d.id
        JOIN users u ON d.user_id = u.id
        WHERE a.patient_id = :pid AND a.appointment_date = :today AND a.status IN ('waiting', 'serving')
        ORDER BY a.token_number ASC
        LIMIT 1
    ");
    $activeStmt->execute([':pid' => $user['id'], ':today' => $today]);
    $activeAppointment = $activeStmt->fetch();

    // 2. Fetch stats
    $statsStmt = $db->prepare("
        SELECT 
            SUM(CASE WHEN appointment_date = :today AND status IN ('waiting', 'serving') THEN 1 ELSE 0 END) as active_today,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as total_completed,
            COUNT(*) as total_appointments
        FROM appointments
        WHERE patient_id = :pid
    ");
    $statsStmt->execute([':today' => $today, ':pid' => $user['id']]);
    $stats = $statsStmt->fetch();

    // 3. Fetch recent appointments
    $recentStmt = $db->prepare("
        SELECT a.*, d.specialization, u.name as doctor_name, p.id as prescription_id
        FROM appointments a
        JOIN doctors d ON a.doctor_id = d.id
        JOIN users u ON d.user_id = u.id
        LEFT JOIN prescriptions p ON a.id = p.appointment_id
        WHERE a.patient_id = :pid
        ORDER BY a.appointment_date DESC, a.token_number DESC
        LIMIT 5
    ");
    $recentStmt->execute([':pid' => $user['id']]);
    $recentAppointments = $recentStmt->fetchAll();

} catch (Exception $e) {
    die("Error loading patient dashboard: " . $e->getMessage());
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Welcome, <?= e($user['name']) ?></h1>
        <p class="page-subtitle">Track your medical tokens, queue positions, and telehealth video meetings.</p>
    </div>
    <a href="<?= BASE_URL ?>/patient/book.php" class="btn btn-primary btn-lg">
        ⚡ Book New Token
    </a>
</div>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon blue">🎫</div>
        <div>
            <div class="stat-value"><?= (int)($stats['active_today'] ?? 0) ?></div>
            <div class="stat-label">Active Tokens Today</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon green">✓</div>
        <div>
            <div class="stat-value"><?= (int)($stats['total_completed'] ?? 0) ?></div>
            <div class="stat-label">Consultations Completed</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon amber">📅</div>
        <div>
            <div class="stat-value"><?= (int)($stats['total_appointments'] ?? 0) ?></div>
            <div class="stat-label">Total Appointments Booked</div>
        </div>
    </div>
</div>

<!-- Active Live Token Callout Banner (if patient has token today) -->
<?php if ($activeAppointment): ?>
    <div class="live-token-banner">
        <div>
            <div style="display:flex; align-items:center; gap:8px; margin-bottom:8px;">
                <span class="pulse-dot"></span>
                <span style="font-weight:700; text-transform:uppercase; letter-spacing:1px; font-size:12px; background:rgba(255,255,255,0.2); padding:3px 10px; border-radius:9999px;">
                    Active Queue Today
                </span>
            </div>
            <h2 style="font-size:24px; font-weight:800; margin-bottom:6px; color:#ffffff;">
                Dr. <?= e($activeAppointment['doctor_name']) ?>
            </h2>
            <div style="font-size:14px; opacity:0.9; margin-bottom:12px;">
                Specialization: <?= e($activeAppointment['specialization']) ?> &bull; Reason: <?= e($activeAppointment['reason_for_visit']) ?>
            </div>
            <div style="font-size:13px; opacity:0.95;">
                ⏱ <strong>Estimated Time:</strong> <?= format_time($activeAppointment['estimated_time']) ?>
            </div>
        </div>

        <div class="token-highlight-box">
            <div class="token-highlight-num">#<?= (int)$activeAppointment['token_number'] ?></div>
            <div class="token-highlight-label">Your Token Number</div>
            <div style="margin-top:12px;">
                <?= render_status_badge($activeAppointment['status']) ?>
            </div>
        </div>

        <div style="display:flex; flex-direction:column; gap:10px;">
            <a href="<?= BASE_URL ?>/patient/queue.php?appointment_id=<?= (int)$activeAppointment['id'] ?>" class="btn btn-warning btn-lg" style="color:#0f172a; font-weight:700;">
                🔴 Open Live Queue Board &rarr;
            </a>
            <?php if ($activeAppointment['status'] === 'serving'): ?>
                <a href="<?= e($activeAppointment['meeting_link']) ?>" target="_blank" class="btn btn-success btn-lg">
                    🎥 Join Video Call Now!
                </a>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<!-- Recent Consultations Table -->
<div class="card">
    <div class="card-title">
        <span>Recent Appointments & Tokens</span>
        <a href="<?= BASE_URL ?>/patient/history.php" class="btn btn-secondary btn-sm">View All History &rarr;</a>
    </div>

    <?php if (empty($recentAppointments)): ?>
        <div style="text-align:center; padding:36px; color:#64748b;">
            <div style="font-size:32px; margin-bottom:8px;">📅</div>
            <p style="font-size:15px; font-weight:600; color:#0f172a;">No appointments booked yet.</p>
            <p style="font-size:13px; margin-top:4px;">Schedule your first consultation token with one of our specialized doctors.</p>
            <a href="<?= BASE_URL ?>/patient/book.php" class="btn btn-primary" style="margin-top:16px;">Book Token Now</a>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Token #</th>
                        <th>Doctor</th>
                        <th>Estimated Time</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentAppointments as $app): ?>
                        <tr>
                            <td><strong><?= format_date($app['appointment_date']) ?></strong></td>
                            <td><span style="font-size:16px; font-weight:800; color:var(--primary);">#<?= (int)$app['token_number'] ?></span></td>
                            <td>
                                <div style="font-weight:600;"><?= e($app['doctor_name']) ?></div>
                                <div style="font-size:12px; color:#64748b;"><?= e($app['specialization']) ?></div>
                            </td>
                            <td><?= format_time($app['estimated_time']) ?></td>
                            <td><?= render_status_badge($app['status']) ?></td>
                            <td>
                                <?php if (in_array($app['status'], ['waiting', 'serving'])): ?>
                                    <a href="<?= BASE_URL ?>/patient/queue.php?appointment_id=<?= (int)$app['id'] ?>" class="btn btn-sm btn-primary">
                                        Track Queue
                                    </a>
                                <?php elseif ($app['status'] === 'completed' && !empty($app['prescription_id'])): ?>
                                    <a href="<?= BASE_URL ?>/prescription.php?appointment_id=<?= (int)$app['id'] ?>" target="_blank" class="btn btn-sm btn-secondary">
                                        📄 Prescription
                                    </a>
                                <?php else: ?>
                                    <span style="color:#94a3b8; font-size:12px;">--</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
