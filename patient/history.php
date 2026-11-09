<?php
/**
 * DOCTOR CONNECT - Patient Consultation History
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role('patient');
$user = current_user();
$pageTitle = "My Consultations";

$statusFilter = filter_input(INPUT_GET, 'status', FILTER_SANITIZE_SPECIAL_CHARS) ?: 'all';

try {
    $db = get_db();

    $sql = "
        SELECT a.*, d.specialization, d.consultation_fee, u.name as doctor_name, p.id as prescription_id
        FROM appointments a
        JOIN doctors d ON a.doctor_id = d.id
        JOIN users u ON d.user_id = u.id
        LEFT JOIN prescriptions p ON a.id = p.appointment_id
        WHERE a.patient_id = :pid
    ";
    $params = [':pid' => $user['id']];

    if ($statusFilter !== 'all') {
        $sql .= " AND a.status = :status";
        $params[':status'] = $statusFilter;
    }

    $sql .= " ORDER BY a.appointment_date DESC, a.token_number DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $appointments = $stmt->fetchAll();

} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">My Appointments & Consultations</h1>
        <p class="page-subtitle">View complete history of your tokens, consultations, and digital prescriptions.</p>
    </div>
    <a href="<?= BASE_URL ?>/patient/book.php" class="btn btn-primary">
        ⚡ Book New Token
    </a>
</div>

<!-- Filters -->
<div style="margin-bottom:20px; display:flex; gap:10px; flex-wrap:wrap;">
    <a href="?status=all" class="btn btn-sm <?= $statusFilter === 'all' ? 'btn-primary' : 'btn-secondary' ?>">All</a>
    <a href="?status=waiting" class="btn btn-sm <?= $statusFilter === 'waiting' ? 'btn-primary' : 'btn-secondary' ?>">Waiting</a>
    <a href="?status=completed" class="btn btn-sm <?= $statusFilter === 'completed' ? 'btn-primary' : 'btn-secondary' ?>">Completed</a>
    <a href="?status=no_show" class="btn btn-sm <?= $statusFilter === 'no_show' ? 'btn-primary' : 'btn-secondary' ?>">No-Show</a>
    <a href="?status=cancelled" class="btn btn-sm <?= $statusFilter === 'cancelled' ? 'btn-primary' : 'btn-secondary' ?>">Cancelled</a>
</div>

<div class="card">
    <?php if (empty($appointments)): ?>
        <div style="text-align:center; padding:40px; color:#64748b;">
            <div style="font-size:32px; margin-bottom:8px;">📁</div>
            <p style="font-weight:600; color:#0f172a;">No appointments found matching this filter.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Token #</th>
                        <th>Doctor & Specialty</th>
                        <th>Reason for Visit</th>
                        <th>Status</th>
                        <th>Action / Prescription</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($appointments as $app): ?>
                        <tr>
                            <td><strong><?= format_date($app['appointment_date']) ?></strong></td>
                            <td><span style="font-size:16px; font-weight:800; color:var(--primary);">#<?= (int)$app['token_number'] ?></span></td>
                            <td>
                                <div style="font-weight:600;"><?= e($app['doctor_name']) ?></div>
                                <div style="font-size:12px; color:#64748b;"><?= e($app['specialization']) ?></div>
                            </td>
                            <td><?= e($app['reason_for_visit'] ?: 'General') ?></td>
                            <td><?= render_status_badge($app['status']) ?></td>
                            <td>
                                <?php if (in_array($app['status'], ['waiting', 'serving'])): ?>
                                    <a href="<?= BASE_URL ?>/patient/queue.php?appointment_id=<?= (int)$app['id'] ?>" class="btn btn-sm btn-primary">
                                        Track Live Queue &rarr;
                                    </a>
                                <?php elseif ($app['status'] === 'completed' && !empty($app['prescription_id'])): ?>
                                    <a href="<?= BASE_URL ?>/prescription.php?appointment_id=<?= (int)$app['id'] ?>" target="_blank" class="btn btn-sm btn-success">
                                        📄 View Prescription
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
