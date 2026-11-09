<?php
/**
 * DOCTOR CONNECT - Doctor Consultation Records & History
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role('doctor');
$user = current_user();
$pageTitle = "Patient Consultation Records";

$search = trim($_GET['search'] ?? '');
$filterDate = trim($_GET['date'] ?? '');

try {
    $db = get_db();

    // Fetch doctor record
    $docStmt = $db->prepare("SELECT id FROM doctors WHERE user_id = :uid LIMIT 1");
    $docStmt->execute([':uid' => $user['id']]);
    $doc = $docStmt->fetch();
    $doctorId = (int)$doc['id'];

    $sql = "
        SELECT a.*, u.name as patient_name, u.phone as patient_phone, u.email as patient_email,
               p.id as prescription_id, p.diagnosis, p.advice
        FROM appointments a
        JOIN users u ON a.patient_id = u.id
        LEFT JOIN prescriptions p ON a.id = p.appointment_id
        WHERE a.doctor_id = :did
    ";
    $params = [':did' => $doctorId];

    if (!empty($search)) {
        $sql .= " AND (u.name LIKE :search OR u.phone LIKE :search OR p.diagnosis LIKE :search)";
        $params[':search'] = "%{$search}%";
    }

    if (!empty($filterDate)) {
        $sql .= " AND a.appointment_date = :fdate";
        $params[':fdate'] = $filterDate;
    }

    $sql .= " ORDER BY a.appointment_date DESC, a.token_number DESC LIMIT 100";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll();

} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Patient Consultation History</h1>
        <p class="page-subtitle">Search and review patient consults and issued prescriptions.</p>
    </div>
</div>

<!-- Search & Filter Card -->
<div class="card" style="padding:18px 24px; margin-bottom:24px;">
    <form method="GET" style="display:flex; gap:16px; flex-wrap:wrap; align-items:flex-end;">
        <div style="flex:2; min-width:240px;">
            <label class="form-label" for="search">Search Patient Name, Phone or Diagnosis</label>
            <input type="text" id="search" name="search" class="form-control" placeholder="Search keywords..." value="<?= e($search) ?>">
        </div>

        <div style="flex:1; min-width:160px;">
            <label class="form-label" for="date">Filter by Date</label>
            <input type="date" id="date" name="date" class="form-control" value="<?= e($filterDate) ?>">
        </div>

        <div>
            <button type="submit" class="btn btn-primary" style="padding:10px 20px;">Filter Records</button>
            <?php if (!empty($search) || !empty($filterDate)): ?>
                <a href="<?= BASE_URL ?>/doctor/history.php" class="btn btn-secondary" style="padding:10px 14px;">Reset</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="card">
    <?php if (empty($records)): ?>
        <div style="text-align:center; padding:36px; color:#64748b;">
            <div style="font-size:32px; margin-bottom:8px;">📁</div>
            <p style="font-weight:600; color:#0f172a;">No patient records found.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Token #</th>
                        <th>Patient Info</th>
                        <th>Reason for Visit</th>
                        <th>Diagnosis</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $r): ?>
                        <tr>
                            <td><strong><?= format_date($r['appointment_date']) ?></strong></td>
                            <td><span style="font-size:16px; font-weight:800; color:var(--primary);">#<?= (int)$r['token_number'] ?></span></td>
                            <td>
                                <div style="font-weight:600;"><?= e($r['patient_name']) ?></div>
                                <div style="font-size:12px; color:#64748b;"><?= e($r['patient_phone']) ?></div>
                            </td>
                            <td><?= e($r['reason_for_visit'] ?: 'General') ?></td>
                            <td>
                                <?= !empty($r['diagnosis']) ? '<strong style="color:#0f172a;">' . e($r['diagnosis']) . '</strong>' : '<span style="color:#94a3b8;">--</span>' ?>
                            </td>
                            <td><?= render_status_badge($r['status']) ?></td>
                            <td>
                                <?php if (!empty($r['prescription_id'])): ?>
                                    <a href="<?= BASE_URL ?>/prescription.php?appointment_id=<?= (int)$r['id'] ?>" target="_blank" class="btn btn-sm btn-success">
                                        📄 Prescription
                                    </a>
                                <?php elseif ($r['status'] === 'serving'): ?>
                                    <a href="<?= BASE_URL ?>/doctor/consult.php?appointment_id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-primary">
                                        Open Room
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
