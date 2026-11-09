<?php
/**
 * DOCTOR CONNECT - Doctor Queue Console & Dashboard
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';

require_role('doctor');
$user = current_user();
$pageTitle = "Doctor Queue Console";

$date = filter_input(INPUT_GET, 'date', FILTER_SANITIZE_SPECIAL_CHARS) ?: date('Y-m-d');

try {
    $db = get_db();

    // 1. Fetch Doctor profile details
    $docStmt = $db->prepare("SELECT * FROM doctors WHERE user_id = :uid LIMIT 1");
    $docStmt->execute([':uid' => $user['id']]);
    $doctor = $docStmt->fetch();

    if (!$doctor) {
        die("Doctor profile not found for user account.");
    }

    $doctorId = (int)$doctor['id'];

    // 2. Fetch today's summary stats
    $statsStmt = $db->prepare("
        SELECT 
            SUM(CASE WHEN status = 'waiting' THEN 1 ELSE 0 END) as waiting_count,
            SUM(CASE WHEN status = 'serving' THEN 1 ELSE 0 END) as serving_count,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count,
            SUM(CASE WHEN status = 'no_show' THEN 1 ELSE 0 END) as noshow_count,
            COUNT(*) as total_count
        FROM appointments
        WHERE doctor_id = :did AND appointment_date = :adate AND status != 'cancelled'
    ");
    $statsStmt->execute([':did' => $doctorId, ':adate' => $date]);
    $stats = $statsStmt->fetch();

    // 3. Fetch currently serving appointment
    $servingStmt = $db->prepare("
        SELECT a.*, u.name as patient_name, u.phone as patient_phone, u.email as patient_email
        FROM appointments a
        JOIN users u ON a.patient_id = u.id
        WHERE a.doctor_id = :did AND a.appointment_date = :adate AND a.status = 'serving'
        LIMIT 1
    ");
    $servingStmt->execute([':did' => $doctorId, ':adate' => $date]);
    $currentServing = $servingStmt->fetch();

    // 4. Fetch the next waiting patient in line
    $nextWaitingStmt = $db->prepare("
        SELECT a.*, u.name as patient_name
        FROM appointments a
        JOIN users u ON a.patient_id = u.id
        WHERE a.doctor_id = :did AND a.appointment_date = :adate AND a.status = 'waiting'
        ORDER BY a.token_number ASC
        LIMIT 1
    ");
    $nextWaitingStmt->execute([':did' => $doctorId, ':adate' => $date]);
    $nextWaiting = $nextWaitingStmt->fetch();

    // 5. Fetch all appointments for date
    $listStmt = $db->prepare("
        SELECT a.*, u.name as patient_name, u.phone as patient_phone, u.email as patient_email
        FROM appointments a
        JOIN users u ON a.patient_id = u.id
        WHERE a.doctor_id = :did AND a.appointment_date = :adate
        ORDER BY a.token_number ASC
    ");
    $listStmt->execute([':did' => $doctorId, ':adate' => $date]);
    $appointments = $listStmt->fetchAll();

} catch (Exception $e) {
    die("Error loading doctor console: " . $e->getMessage());
}

$extraScripts = '<script src="' . BASE_URL . '/assets/js/queue.js"></script>
<script>
let queueManager;
document.addEventListener("DOMContentLoaded", () => {
    queueManager = new LiveQueueManager({
        doctorId: ' . $doctorId . ',
        date: ' . json_encode($date) . ',
        isDoctor: true,
        pollIntervalMs: 3000
    });
});

async function advanceQueue(appointmentId, action) {
    try {
        const res = await fetch("' . BASE_URL . '/api/call_token.php", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": ' . json_encode(get_csrf_token()) . '
            },
            body: JSON.stringify({ appointment_id: appointmentId, action: action })
        });
        const data = await res.json();
        if (data.success) {
            Toast.show(`Action executed: ${action.toUpperCase()}`, "success");
            setTimeout(() => window.location.reload(), 600);
        } else {
            alert("Error: " + data.error);
        }
    } catch (e) {
        alert("Network error updating token.");
    }
}
</script>';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Doctor Consultation Console</h1>
        <p class="page-subtitle">Dr. <?= e($user['name']) ?> &bull; <?= e($doctor['specialization']) ?></p>
    </div>

    <!-- Date Filter -->
    <form method="GET" style="display:flex; align-items:center; gap:10px;">
        <label for="date-select" style="font-weight:600; font-size:13px; color:#475569;">Queue Date:</label>
        <input type="date" id="date-select" name="date" class="form-control" style="width:auto; padding:6px 12px;" 
               value="<?= e($date) ?>" onchange="this.form.submit()">
    </form>
</div>

<!-- Stats Bar -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon green">🟢</div>
        <div>
            <div class="stat-value"><?= (int)($stats['serving_count'] ?? 0) ?></div>
            <div class="stat-label">Currently Serving</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon amber">⏳</div>
        <div>
            <div class="stat-value" id="queue-waiting-count"><?= (int)($stats['waiting_count'] ?? 0) ?></div>
            <div class="stat-label">Waiting in Queue</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon blue">✓</div>
        <div>
            <div class="stat-value"><?= (int)($stats['completed_count'] ?? 0) ?></div>
            <div class="stat-label">Served & Completed</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon red">✕</div>
        <div>
            <div class="stat-value"><?= (int)($stats['noshow_count'] ?? 0) ?></div>
            <div class="stat-label">No-Shows</div>
        </div>
    </div>
</div>

<!-- Live Serving / Call Action Section -->
<div style="display:grid; grid-template-columns: 1fr 1fr; gap:24px; margin-bottom:28px;">
    <!-- Active Consultation Box -->
    <div class="card" style="border-left: 4px solid var(--success); margin-bottom:0;">
        <div style="font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:1px; color:#059669; margin-bottom:8px;">
            <span class="pulse-dot"></span> Active Patient In Consultation
        </div>

        <?php if ($currentServing): ?>
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:14px;">
                <div>
                    <h2 style="font-size:22px; font-weight:800; color:#0f172a; margin-bottom:4px;">
                        Token #<?= (int)$currentServing['token_number'] ?> &mdash; <?= e($currentServing['patient_name']) ?>
                    </h2>
                    <div style="font-size:13px; color:#64748b;">
                        Phone: <?= e($currentServing['patient_phone']) ?> &bull; Reason: <strong><?= e($currentServing['reason_for_visit']) ?></strong>
                    </div>
                </div>
                <span class="status-badge badge-serving">SERVING</span>
            </div>

            <div style="display:flex; gap:10px; margin-top:20px;">
                <a href="<?= BASE_URL ?>/doctor/consult.php?appointment_id=<?= (int)$currentServing['id'] ?>" class="btn btn-success btn-lg" style="flex:2;">
                    🎥 Open Video Call & Prescription &rarr;
                </a>
                <button type="button" class="btn btn-secondary btn-lg" style="flex:1;" onclick="advanceQueue(<?= (int)$currentServing['id'] ?>, 'completed')">
                    Mark Done
                </button>
            </div>
        <?php else: ?>
            <div style="text-align:center; padding:32px 10px; color:#64748b;">
                <div style="font-size:32px; margin-bottom:6px;">☕</div>
                <div style="font-weight:700; color:#0f172a; font-size:16px;">No Active Consultation Right Now</div>
                <div style="font-size:13px; margin-top:4px;">Call the next patient in line when you are ready to begin.</div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Quick Call Next Token Action -->
    <div class="card" style="margin-bottom:0; display:flex; flex-direction:column; justify-content:space-between;">
        <div>
            <div style="font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:1px; color:#475569; margin-bottom:8px;">
                ⚡ Queue Controller
            </div>

            <?php if ($nextWaiting): ?>
                <div style="margin-bottom:16px;">
                    <div style="font-size:13px; color:#64748b;">Next Patient in Line:</div>
                    <div style="font-size:20px; font-weight:800; color:var(--primary); margin-top:2px;">
                        Token #<?= (int)$nextWaiting['token_number'] ?> &mdash; <?= e($nextWaiting['patient_name']) ?>
                    </div>
                    <div style="font-size:12px; color:#64748b; margin-top:2px;">
                        Est. Time: <?= format_time($nextWaiting['estimated_time']) ?> &bull; Reason: <?= e($nextWaiting['reason_for_visit']) ?>
                    </div>
                </div>
            <?php else: ?>
                <div style="padding:16px 0; color:#64748b; font-size:14px;">
                    ✓ No waiting patients remaining in queue for this date.
                </div>
            <?php endif; ?>
        </div>

        <div>
            <?php if ($nextWaiting): ?>
                <div style="display:flex; gap:10px;">
                    <button type="button" class="btn btn-primary btn-lg" style="flex:2;" onclick="advanceQueue(<?= (int)$nextWaiting['id'] ?>, 'serving')">
                        🔔 Call Token #<?= (int)$nextWaiting['token_number'] ?> (Start Consultation)
                    </button>
                    <button type="button" class="btn btn-secondary btn-lg" style="flex:1;" onclick="advanceQueue(<?= (int)$nextWaiting['id'] ?>, 'no_show')">
                        Mark No-Show
                    </button>
                </div>
            <?php else: ?>
                <button class="btn btn-secondary btn-lg" style="width:100%;" disabled>
                    Queue is Clear
                </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- All Appointments for Date Table -->
<div class="card">
    <div class="card-title">
        <span>Daily Token Queue &bull; <?= format_date($date) ?></span>
        <span id="queue-last-updated" style="font-size:12px; color:#64748b; font-weight:400;"></span>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Token #</th>
                    <th>Patient Name & Phone</th>
                    <th>Est. Time</th>
                    <th>Status</th>
                    <th>Reason for Visit</th>
                    <th>Queue Actions</th>
                </tr>
            </thead>
            <tbody id="queue-table-body">
                <?php if (empty($appointments)): ?>
                    <tr>
                        <td colspan="6" style="text-align:center; padding:32px; color:#94a3b8;">
                            No tokens booked for <?= format_date($date) ?>.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($appointments as $app): ?>
                        <tr class="<?= $app['status'] === 'serving' ? 'highlight-row' : '' ?>">
                            <td><strong style="font-size:16px;">#<?= (int)$app['token_number'] ?></strong></td>
                            <td>
                                <div style="font-weight:600;"><?= e($app['patient_name']) ?></div>
                                <div style="font-size:12px; color:#64748b;"><?= e($app['patient_phone']) ?></div>
                            </td>
                            <td><?= format_time($app['estimated_time']) ?></td>
                            <td><?= render_status_badge($app['status']) ?></td>
                            <td><?= e($app['reason_for_visit'] ?: 'General') ?></td>
                            <td>
                                <?php if ($app['status'] === 'waiting'): ?>
                                    <button class="btn btn-sm btn-primary" onclick="advanceQueue(<?= (int)$app['id'] ?>, 'serving')">
                                        Call Next
                                    </button>
                                    <button class="btn btn-sm btn-secondary" onclick="advanceQueue(<?= (int)$app['id'] ?>, 'no_show')">
                                        No-Show
                                    </button>
                                <?php elseif ($app['status'] === 'serving'): ?>
                                    <a href="<?= BASE_URL ?>/doctor/consult.php?appointment_id=<?= (int)$app['id'] ?>" class="btn btn-sm btn-success">
                                        Open Room &rarr;
                                    </a>
                                    <button class="btn btn-sm btn-secondary" onclick="advanceQueue(<?= (int)$app['id'] ?>, 'completed')">
                                        Mark Done
                                    </button>
                                <?php elseif ($app['status'] === 'completed'): ?>
                                    <a href="<?= BASE_URL ?>/prescription.php?appointment_id=<?= (int)$app['id'] ?>" target="_blank" class="btn btn-sm btn-secondary">
                                        📄 Prescription
                                    </a>
                                <?php else: ?>
                                    <span style="color:#94a3b8; font-size:12px;">--</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
