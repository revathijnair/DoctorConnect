<?php
/**
 * DOCTOR CONNECT - Reception Live Queue Monitor & Walk-In Console
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';

require_role('admin');
$user = current_user();
$pageTitle = "Reception Live Queue Console";

$date = filter_input(INPUT_GET, 'date', FILTER_SANITIZE_SPECIAL_CHARS) ?: date('Y-m-d');
$selectedDoctorId = filter_input(INPUT_GET, 'doctor_id', FILTER_VALIDATE_INT);

try {
    $db = get_db();

    // Fetch all active doctors
    $docsStmt = $db->query("
        SELECT d.id, d.specialization, u.name as doctor_name
        FROM doctors d
        JOIN users u ON d.user_id = u.id
        WHERE d.is_active = 1
        ORDER BY u.name ASC
    ");
    $doctors = $docsStmt->fetchAll();

    if (!$selectedDoctorId && !empty($doctors)) {
        $selectedDoctorId = (int)$doctors[0]['id'];
    }

    // Fetch selected doctor details
    $selectedDoctor = null;
    foreach ($doctors as $d) {
        if ((int)$d['id'] === $selectedDoctorId) {
            $selectedDoctor = $d;
            break;
        }
    }

    // Initial appointments list for selected doctor & date
    $appStmt = $db->prepare("
        SELECT a.*, u.name as patient_name, u.phone as patient_phone, u.email as patient_email
        FROM appointments a
        JOIN users u ON a.patient_id = u.id
        WHERE a.doctor_id = :did AND a.appointment_date = :adate
        ORDER BY a.token_number ASC
    ");
    $appStmt->execute([':did' => $selectedDoctorId, ':adate' => $date]);
    $appointments = $appStmt->fetchAll();

} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}

$extraScripts = '<script src="' . BASE_URL . '/assets/js/queue.js"></script>
<script>
let queueManager;
document.addEventListener("DOMContentLoaded", () => {
    queueManager = new LiveQueueManager({
        doctorId: ' . (int)$selectedDoctorId . ',
        date: ' . json_encode($date) . ',
        isAdmin: true,
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
            Toast.show(`Queue advanced: ${action.toUpperCase()}`, "success");
            queueManager.fetchStatus();
        } else {
            alert("Error: " + data.error);
        }
    } catch (e) {
        alert("Network error updating token.");
    }
}

async function submitWalkin(e) {
    e.preventDefault();
    const name = document.getElementById("walkin-name").value;
    const phone = document.getElementById("walkin-phone").value;
    const reason = document.getElementById("walkin-reason").value;
    const btn = document.getElementById("walkin-btn");

    btn.disabled = true;
    try {
        const res = await fetch("' . BASE_URL . '/api/book_walkin.php", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": ' . json_encode(get_csrf_token()) . '
            },
            body: JSON.stringify({
                name: name,
                phone: phone,
                reason: reason,
                doctor_id: ' . (int)$selectedDoctorId . ',
                date: ' . json_encode($date) . '
            })
        });
        const data = await res.json();
        btn.disabled = false;
        if (data.success) {
            Toast.show(`✓ Walk-in Token #${data.token_number} issued for ${data.patient_name}!`, "success", 8000);
            closeWalkinModal();
            queueManager.fetchStatus();
        } else {
            alert("Error issuing walk-in token: " + data.error);
        }
    } catch (err) {
        btn.disabled = false;
        alert("Network error.");
    }
}

function openWalkinModal() {
    document.getElementById("walkin-modal").style.display = "flex";
}
function closeWalkinModal() {
    document.getElementById("walkin-modal").style.display = "none";
}
</script>';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Reception Queue Console</h1>
        <p class="page-subtitle">Real-time token management, no-show processing & walk-in token dispatching.</p>
    </div>
    <div style="display:flex; gap:10px;">
        <button type="button" class="btn btn-primary" onclick="openWalkinModal()">
            + Add Walk-In Patient
        </button>
        <a href="<?= BASE_URL ?>/admin/dashboard.php" class="btn btn-secondary">&larr; Dashboard</a>
    </div>
</div>

<!-- Doctor and Date Filters -->
<div class="card" style="padding:16px 20px; margin-bottom:24px;">
    <form method="GET" style="display:flex; gap:16px; flex-wrap:wrap; align-items:flex-end;">
        <div style="flex:2; min-width:240px;">
            <label class="form-label" for="doctor_id">Select Doctor's Queue</label>
            <select id="doctor_id" name="doctor_id" class="form-control" onchange="this.form.submit()">
                <?php foreach ($doctors as $d): ?>
                    <option value="<?= (int)$d['id'] ?>" <?= $selectedDoctorId === (int)$d['id'] ? 'selected' : '' ?>>
                        Dr. <?= e($d['doctor_name']) ?> &mdash; <?= e($d['specialization']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="flex:1; min-width:160px;">
            <label class="form-label" for="date">Queue Date</label>
            <input type="date" id="date" name="date" class="form-control" value="<?= e($date) ?>" onchange="this.form.submit()">
        </div>

        <div>
            <button type="submit" class="btn btn-secondary">Switch View</button>
        </div>
    </form>
</div>

<!-- Live Counter Header for Selected Doctor -->
<div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:16px; margin-bottom:24px;">
    <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:18px; text-align:center;">
        <div style="font-size:12px; text-transform:uppercase; letter-spacing:0.5px; font-weight:700; color:#15803d; margin-bottom:4px;">Now Calling</div>
        <div id="queue-current-serving" style="font-size:36px; font-weight:900; color:#15803d; line-height:1;">
            Loading...
        </div>
    </div>

    <div style="background:#fefce8; border:1px solid #fef08a; border-radius:10px; padding:18px; text-align:center;">
        <div style="font-size:12px; text-transform:uppercase; letter-spacing:0.5px; font-weight:700; color:#a16207; margin-bottom:4px;">Next In Line</div>
        <div id="queue-next-in-line" style="font-size:36px; font-weight:900; color:#a16207; line-height:1;">
            Loading...
        </div>
    </div>

    <div style="background:#f1f5f9; border:1px solid #cbd5e1; border-radius:10px; padding:18px; text-align:center;">
        <div style="font-size:12px; text-transform:uppercase; letter-spacing:0.5px; font-weight:700; color:#475569; margin-bottom:4px;">Waiting Queue</div>
        <div id="queue-waiting-count" style="font-size:36px; font-weight:900; color:#0f172a; line-height:1;">
            Loading...
        </div>
    </div>
</div>

<!-- Queue Management Table -->
<div class="card">
    <div class="card-title">
        <span>Dr. <?= e($selectedDoctor['doctor_name'] ?? 'Doctor') ?>'s Queue &bull; <?= format_date($date) ?></span>
        <span id="queue-last-updated" style="font-size:12px; color:#64748b; font-weight:400;"></span>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Token #</th>
                    <th>Patient Name & Contact</th>
                    <th>Est. Time</th>
                    <th>Status</th>
                    <th>Reason for Visit</th>
                    <th>Reception Actions</th>
                </tr>
            </thead>
            <tbody id="queue-table-body">
                <?php if (empty($appointments)): ?>
                    <tr>
                        <td colspan="6" style="text-align:center; padding:32px; color:#94a3b8;">
                            No tokens issued for this date.
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
                                        Call Patient
                                    </button>
                                    <button class="btn btn-sm btn-secondary" onclick="advanceQueue(<?= (int)$app['id'] ?>, 'no_show')">
                                        No-Show
                                    </button>
                                <?php elseif ($app['status'] === 'serving'): ?>
                                    <button class="btn btn-sm btn-success" onclick="advanceQueue(<?= (int)$app['id'] ?>, 'completed')">
                                        Mark Served
                                    </button>
                                    <button class="btn btn-sm btn-secondary" onclick="advanceQueue(<?= (int)$app['id'] ?>, 'no_show')">
                                        No-Show
                                    </button>
                                <?php elseif ($app['status'] === 'completed'): ?>
                                    <a href="<?= BASE_URL ?>/prescription.php?appointment_id=<?= (int)$app['id'] ?>" target="_blank" class="btn btn-sm btn-secondary">
                                        Rx Document
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

<!-- Modal: Add Walk-in Patient -->
<div id="walkin-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.6); z-index:999; align-items:center; justify-content:center; padding:20px;">
    <div style="background:#fff; border-radius:16px; max-width:480px; width:100%; padding:28px; box-shadow:var(--shadow-lg);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <h2 style="font-size:18px; font-weight:800; color:#0f172a;">Register Walk-In Patient</h2>
            <button type="button" onclick="closeWalkinModal()" style="border:none; background:transparent; font-size:20px; cursor:pointer;">&times;</button>
        </div>

        <form id="walkin-form" onsubmit="submitWalkin(event)">
            <div class="form-group">
                <label class="form-label" for="walkin-name">Patient Full Name *</label>
                <input type="text" id="walkin-name" class="form-control" placeholder="e.g. Robert Miles" required>
            </div>

            <div class="form-group">
                <label class="form-label" for="walkin-phone">Phone Number *</label>
                <input type="tel" id="walkin-phone" class="form-control" placeholder="+15551234567" required>
            </div>

            <div class="form-group">
                <label class="form-label" for="walkin-reason">Chief Complaint / Reason</label>
                <input type="text" id="walkin-reason" class="form-control" placeholder="Walk-in consultation" value="Walk-in Consultation">
            </div>

            <div style="background:#f8fafc; padding:12px; border-radius:8px; font-size:12px; color:#64748b; margin-bottom:18px;">
                Token will be assigned to <strong>Dr. <?= e($selectedDoctor['doctor_name'] ?? '') ?></strong> for <strong><?= format_date($date) ?></strong>.
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-secondary" onclick="closeWalkinModal()">Cancel</button>
                <button type="submit" id="walkin-btn" class="btn btn-primary">Generate Token & Add to Queue</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
