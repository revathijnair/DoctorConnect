<?php
/**
 * DOCTOR CONNECT - Appointment Reports & CSV Export Engine
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role('admin');
$user = current_user();

$doctorId = filter_input(INPUT_GET, 'doctor_id', FILTER_VALIDATE_INT) ?: 0;
$status = trim($_GET['status'] ?? 'all');
$fromDate = trim($_GET['from_date'] ?? date('Y-m-01'));
$toDate = trim($_GET['to_date'] ?? date('Y-m-d'));
$exportCsv = isset($_GET['export']) && $_GET['export'] === 'csv';

try {
    $db = get_db();

    // Fetch doctors for filter dropdown
    $docList = $db->query("
        SELECT d.id, u.name as doctor_name 
        FROM doctors d 
        JOIN users u ON d.user_id = u.id 
        ORDER BY u.name ASC
    ")->fetchAll();

    // Build Query
    $sql = "
        SELECT 
            a.id, a.appointment_date, a.token_number, a.status, a.estimated_time, a.reason_for_visit, a.created_at,
            doc_u.name as doctor_name, d.specialization, d.consultation_fee,
            pat_u.name as patient_name, pat_u.phone as patient_phone, pat_u.email as patient_email
        FROM appointments a
        JOIN doctors d ON a.doctor_id = d.id
        JOIN users doc_u ON d.user_id = doc_u.id
        JOIN users pat_u ON a.patient_id = pat_u.id
        WHERE 1=1
    ";
    $params = [];

    if ($doctorId > 0) {
        $sql .= " AND a.doctor_id = :did";
        $params[':did'] = $doctorId;
    }

    if (!empty($fromDate)) {
        $sql .= " AND a.appointment_date >= :from_date";
        $params[':from_date'] = $fromDate;
    }

    if (!empty($toDate)) {
        $sql .= " AND a.appointment_date <= :to_date";
        $params[':to_date'] = $toDate;
    }

    if ($status !== 'all') {
        $sql .= " AND a.status = :status";
        $params[':status'] = $status;
    }

    $sql .= " ORDER BY a.appointment_date DESC, a.token_number ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // CSV Export Handler
    if ($exportCsv) {
        $filename = "DoctorConnect_Report_" . date('Ymd_His') . ".csv";
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        // Add UTF-8 BOM for Excel compatibility
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

        // Header Row
        fputcsv($out, [
            'Appointment ID',
            'Date',
            'Token #',
            'Doctor Name',
            'Specialization',
            'Patient Name',
            'Patient Phone',
            'Patient Email',
            'Status',
            'Estimated Time',
            'Consultation Fee ($)',
            'Reason for Visit'
        ]);

        foreach ($rows as $r) {
            fputcsv($out, [
                $r['id'],
                $r['appointment_date'],
                $r['token_number'],
                $r['doctor_name'],
                $r['specialization'],
                $r['patient_name'],
                $r['patient_phone'],
                $r['patient_email'],
                strtoupper($r['status']),
                $r['estimated_time'],
                number_format($r['consultation_fee'], 2, '.', ''),
                $r['reason_for_visit']
            ]);
        }
        fclose($out);
        exit;
    }

    // Compute Summary KPIs
    $totalCount = count($rows);
    $completedCount = 0;
    $noshowCount = 0;
    $waitingCount = 0;
    $cancelledCount = 0;
    $totalRevenue = 0.0;

    foreach ($rows as $r) {
        if ($r['status'] === 'completed') {
            $completedCount++;
            $totalRevenue += (float)$r['consultation_fee'];
        } elseif ($r['status'] === 'no_show') {
            $noshowCount++;
        } elseif ($r['status'] === 'waiting' || $r['status'] === 'serving') {
            $waitingCount++;
        } elseif ($r['status'] === 'cancelled') {
            $cancelledCount++;
        }
    }

    $noshowRate = $totalCount > 0 ? round(($noshowCount / $totalCount) * 100, 1) : 0;

} catch (Exception $e) {
    die("Database report error: " . $e->getMessage());
}

$pageTitle = "Reports & Analytics";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Appointment Analytics & Reports</h1>
        <p class="page-subtitle">Filter by doctor, date range, or status and export complete CSV audit logs.</p>
    </div>
    <div>
        <?php
            $exportQuery = $_GET;
            $exportQuery['export'] = 'csv';
            $exportUrl = '?' . http_build_query($exportQuery);
        ?>
        <a href="<?= $exportUrl ?>" class="btn btn-success btn-lg">
            📥 Export to CSV
        </a>
    </div>
</div>

<!-- Filter Bar -->
<div class="card" style="padding:18px 24px; margin-bottom:24px;">
    <form method="GET" style="display:flex; gap:16px; flex-wrap:wrap; align-items:flex-end;">
        <div style="flex:2; min-width:200px;">
            <label class="form-label" for="doctor_id">Doctor</label>
            <select id="doctor_id" name="doctor_id" class="form-control">
                <option value="0">-- All Doctors --</option>
                <?php foreach ($docList as $d): ?>
                    <option value="<?= (int)$d['id'] ?>" <?= $doctorId === (int)$d['id'] ? 'selected' : '' ?>>
                        Dr. <?= e($d['doctor_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="flex:1; min-width:140px;">
            <label class="form-label" for="from_date">From Date</label>
            <input type="date" id="from_date" name="from_date" class="form-control" value="<?= e($fromDate) ?>">
        </div>

        <div style="flex:1; min-width:140px;">
            <label class="form-label" for="to_date">To Date</label>
            <input type="date" id="to_date" name="to_date" class="form-control" value="<?= e($toDate) ?>">
        </div>

        <div style="flex:1; min-width:140px;">
            <label class="form-label" for="status">Status</label>
            <select id="status" name="status" class="form-control">
                <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All Statuses</option>
                <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Completed</option>
                <option value="waiting" <?= $status === 'waiting' ? 'selected' : '' ?>>Waiting</option>
                <option value="serving" <?= $status === 'serving' ? 'selected' : '' ?>>Serving</option>
                <option value="no_show" <?= $status === 'no_show' ? 'selected' : '' ?>>No-Show</option>
                <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
            </select>
        </div>

        <div>
            <button type="submit" class="btn btn-primary" style="padding:10px 20px;">Generate</button>
        </div>
    </form>
</div>

<!-- Summary KPI Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon blue">📊</div>
        <div>
            <div class="stat-value"><?= $totalCount ?></div>
            <div class="stat-label">Total Appointments in Range</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon green">✓</div>
        <div>
            <div class="stat-value"><?= $completedCount ?></div>
            <div class="stat-label">Consultations Completed</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon red">✕</div>
        <div>
            <div class="stat-value"><?= $noshowCount ?> <span style="font-size:14px; font-weight:600; color:#ef4444;">(<?= $noshowRate ?>%)</span></div>
            <div class="stat-label">No-Shows Recorded</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon amber">💵</div>
        <div>
            <div class="stat-value">$<?= number_format($totalRevenue, 2) ?></div>
            <div class="stat-label">Completed Consultation Fees</div>
        </div>
    </div>
</div>

<!-- Table -->
<div class="card">
    <div class="card-title">
        <span>Detailed Appointment Records (<?= $totalCount ?> found)</span>
    </div>

    <?php if (empty($rows)): ?>
        <div style="text-align:center; padding:36px; color:#64748b;">
            No appointment records match the selected parameters.
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Token #</th>
                        <th>Doctor</th>
                        <th>Patient</th>
                        <th>Fee</th>
                        <th>Estimated Time</th>
                        <th>Status</th>
                        <th>Reason</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><strong><?= format_date($r['appointment_date']) ?></strong></td>
                            <td><strong style="color:var(--primary); font-size:15px;">#<?= (int)$r['token_number'] ?></strong></td>
                            <td>
                                <div style="font-weight:600;">Dr. <?= e($r['doctor_name']) ?></div>
                                <div style="font-size:12px; color:#64748b;"><?= e($r['specialization']) ?></div>
                            </td>
                            <td>
                                <div style="font-weight:600;"><?= e($r['patient_name']) ?></div>
                                <div style="font-size:12px; color:#64748b;"><?= e($r['patient_phone']) ?></div>
                            </td>
                            <td>$<?= number_format($r['consultation_fee'], 2) ?></td>
                            <td><?= format_time($r['estimated_time']) ?></td>
                            <td><?= render_status_badge($r['status']) ?></td>
                            <td><?= e($r['reason_for_visit'] ?: 'General') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
