<?php
/**
 * DOCTOR CONNECT - Admin & Receptionist Main Dashboard
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role('admin');
$user = current_user();
$pageTitle = "Admin & Clinic Overview";

$today = date('Y-m-d');

try {
    $db = get_db();

    // 1. KPI Counts
    $patientCount = (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'patient'")->fetchColumn();
    $doctorCount = (int)$db->query("SELECT COUNT(*) FROM doctors WHERE is_active = 1")->fetchColumn();

    $todayStatsStmt = $db->prepare("
        SELECT 
            COUNT(*) as total_today,
            SUM(CASE WHEN status = 'serving' THEN 1 ELSE 0 END) as serving_today,
            SUM(CASE WHEN status = 'waiting' THEN 1 ELSE 0 END) as waiting_today,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_today,
            SUM(CASE WHEN status = 'no_show' THEN 1 ELSE 0 END) as noshow_today
        FROM appointments
        WHERE appointment_date = :today AND status != 'cancelled'
    ");
    $todayStatsStmt->execute([':today' => $today]);
    $todayStats = $todayStatsStmt->fetch();

    $totalToday = (int)($todayStats['total_today'] ?? 0);
    $noShowToday = (int)($todayStats['noshow_today'] ?? 0);
    $noShowRate = $totalToday > 0 ? round(($noShowToday / $totalToday) * 100, 1) : 0;

    // 2. Doctor Queues for Today
    $docQueuesStmt = $db->prepare("
        SELECT 
            d.id as doctor_id, d.specialization, u.name as doctor_name,
            (SELECT token_number FROM appointments WHERE doctor_id = d.id AND appointment_date = :today AND status = 'serving' LIMIT 1) as serving_token,
            (SELECT COUNT(*) FROM appointments WHERE doctor_id = d.id AND appointment_date = :today AND status = 'waiting') as waiting_count,
            (SELECT COUNT(*) FROM appointments WHERE doctor_id = d.id AND appointment_date = :today AND status = 'completed') as completed_count
        FROM doctors d
        JOIN users u ON d.user_id = u.id
        WHERE d.is_active = 1
        ORDER BY u.name ASC
    ");
    $docQueuesStmt->execute([':today' => $today]);
    $doctorQueues = $docQueuesStmt->fetchAll();

    // 3. Recent Audit Logs
    $logsStmt = $db->query("
        SELECT ql.*, a.token_number, a.appointment_date, doc_u.name as doctor_name, pat_u.name as patient_name, act_u.name as actor_name
        FROM queue_logs ql
        JOIN appointments a ON ql.appointment_id = a.id
        JOIN doctors d ON a.doctor_id = d.id
        JOIN users doc_u ON d.user_id = doc_u.id
        JOIN users pat_u ON a.patient_id = pat_u.id
        LEFT JOIN users act_u ON ql.acted_by = act_u.id
        ORDER BY ql.action_time DESC
        LIMIT 6
    ");
    $recentLogs = $logsStmt->fetchAll();

} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Clinic Reception & Admin Console</h1>
        <p class="page-subtitle">Live clinic activity, doctor queues, and telehealth operations.</p>
    </div>
    <div style="display:flex; gap:10px;">
        <a href="<?= BASE_URL ?>/admin/queue_monitor.php" class="btn btn-primary btn-lg">
            🔴 Open Live Reception Console &rarr;
        </a>
    </div>
</div>

<!-- KPI Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon blue">📅</div>
        <div>
            <div class="stat-value"><?= $totalToday ?></div>
            <div class="stat-label">Appointments Today</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon amber">⏳</div>
        <div>
            <div class="stat-value"><?= (int)($todayStats['waiting_today'] ?? 0) ?></div>
            <div class="stat-label">Waiting in Queues</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon green">✓</div>
        <div>
            <div class="stat-value"><?= (int)($todayStats['completed_today'] ?? 0) ?></div>
            <div class="stat-label">Completed Today</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon red">✕</div>
        <div>
            <div class="stat-value"><?= $noShowToday ?> <span style="font-size:14px; font-weight:600; color:#ef4444;">(<?= $noShowRate ?>%)</span></div>
            <div class="stat-label">Today's No-Shows</div>
        </div>
    </div>
</div>

<!-- Active Doctor Queues Today -->
<div class="card">
    <div class="card-title">
        <span>Today's Active Queues by Doctor (<?= format_date($today) ?>)</span>
        <a href="<?= BASE_URL ?>/admin/queue_monitor.php" class="btn btn-secondary btn-sm">Full Queue Controller &rarr;</a>
    </div>

    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap:20px;">
        <?php foreach ($doctorQueues as $dq): ?>
            <div style="border:1px solid #e2e8f0; border-radius:12px; padding:20px; background:#f8fafc;">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px;">
                    <div>
                        <h3 style="font-size:17px; font-weight:700; color:#0f172a;">Dr. <?= e($dq['doctor_name']) ?></h3>
                        <span style="font-size:12px; color:var(--primary); font-weight:600;"><?= e($dq['specialization']) ?></span>
                    </div>
                    <?php if ($dq['serving_token']): ?>
                        <span class="status-badge badge-serving"><span class="pulse-dot"></span> Calling #<?= (int)$dq['serving_token'] ?></span>
                    <?php else: ?>
                        <span class="status-badge badge-waiting">No Active Call</span>
                    <?php endif; ?>
                </div>

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin:16px 0; font-size:13px;">
                    <div style="background:#fff; border:1px solid #e2e8f0; padding:10px; border-radius:8px; text-align:center;">
                        <div style="color:#64748b; font-size:11px; text-transform:uppercase;">Waiting</div>
                        <div style="font-size:20px; font-weight:800; color:#d97706;"><?= (int)$dq['waiting_count'] ?></div>
                    </div>
                    <div style="background:#fff; border:1px solid #e2e8f0; padding:10px; border-radius:8px; text-align:center;">
                        <div style="color:#64748b; font-size:11px; text-transform:uppercase;">Served</div>
                        <div style="font-size:20px; font-weight:800; color:#059669;"><?= (int)$dq['completed_count'] ?></div>
                    </div>
                </div>

                <a href="<?= BASE_URL ?>/admin/queue_monitor.php?doctor_id=<?= (int)$dq['doctor_id'] ?>" class="btn btn-outline-primary btn-sm" style="width:100%; text-align:center;">
                    Manage Dr. <?= e($dq['doctor_name']) ?>'s Queue &rarr;
                </a>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Recent Queue Activity Logs -->
<div class="card">
    <div class="card-title">
        <span>Recent Queue Audit Trail</span>
    </div>

    <?php if (empty($recentLogs)): ?>
        <div style="text-align:center; padding:24px; color:#94a3b8;">No audit entries yet today.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Action</th>
                        <th>Token #</th>
                        <th>Patient</th>
                        <th>Doctor</th>
                        <th>Triggered By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentLogs as $log): ?>
                        <tr>
                            <td><?= date('g:i:s A', strtotime($log['action_time'])) ?></td>
                            <td>
                                <span class="status-badge badge-<?= $log['action'] === 'serving' ? 'serving' : ($log['action'] === 'completed' ? 'completed' : ($log['action'] === 'no_show' ? 'noshow' : 'default')) ?>">
                                    <?= strtoupper($log['action']) ?>
                                </span>
                            </td>
                            <td><strong>#<?= (int)$log['token_number'] ?></strong></td>
                            <td><?= e($log['patient_name']) ?></td>
                            <td>Dr. <?= e($log['doctor_name']) ?></td>
                            <td><span style="font-size:12px; color:#64748b;"><?= e($log['actor_name'] ?: 'System') ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
