<?php
/**
 * DOCTOR CONNECT - Doctor Weekly Schedule & Leave Management
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';

require_role('doctor');
$user = current_user();
$pageTitle = "My Schedule & Leaves";

$error = '';

try {
    $db = get_db();

    // Fetch doctor record
    $docStmt = $db->prepare("SELECT * FROM doctors WHERE user_id = :uid LIMIT 1");
    $docStmt->execute([':uid' => $user['id']]);
    $doctor = $docStmt->fetch();

    if (!$doctor) {
        die("Doctor profile not found.");
    }
    $doctorId = (int)$doctor['id'];

    // Handle Schedule Update
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_schedule') {
        verify_csrf_post();

        $days = $_POST['days'] ?? [];
        $startTimes = $_POST['start_time'] ?? [];
        $endTimes = $_POST['end_time'] ?? [];
        $durations = $_POST['slot_duration'] ?? [];
        $customLink = trim($_POST['custom_meeting_link'] ?? '');

        $db->beginTransaction();

        // Update custom meeting link
        $updLink = $db->prepare("UPDATE doctors SET custom_meeting_link = :link WHERE id = :did");
        $updLink->execute([':link' => $customLink ?: null, ':did' => $doctorId]);

        // Process all 7 days (0 to 6)
        for ($d = 0; $d <= 6; $d++) {
            $isActive = in_array((string)$d, $days) ? 1 : 0;
            $start = !empty($startTimes[$d]) ? $startTimes[$d] : '09:00:00';
            $end = !empty($endTimes[$d]) ? $endTimes[$d] : '13:00:00';
            $dur = !empty($durations[$d]) ? (int)$durations[$d] : (int)$doctor['avg_consultation_mins'];

            $upsert = $db->prepare("
                INSERT INTO doctor_schedules (doctor_id, day_of_week, start_time, end_time, slot_duration, is_active)
                VALUES (:did, :dow, :st, :et, :dur, :act)
                ON DUPLICATE KEY UPDATE 
                    start_time = VALUES(start_time),
                    end_time = VALUES(end_time),
                    slot_duration = VALUES(slot_duration),
                    is_active = VALUES(is_active)
            ");
            $upsert->execute([
                ':did' => $doctorId,
                ':dow' => $d,
                ':st'  => $start,
                ':et'  => $end,
                ':dur' => $dur,
                ':act' => $isActive
            ]);
        }

        $db->commit();
        set_flash('success', "Weekly consultation schedule updated successfully.");
        header("Location: " . BASE_URL . "/doctor/schedule.php");
        exit;
    }

    // Handle Add Leave
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_leave') {
        verify_csrf_post();

        $leaveDate = trim($_POST['leave_date'] ?? '');
        $reason = trim($_POST['leave_reason'] ?? 'Personal / Conference');

        if (empty($leaveDate) || $leaveDate < date('Y-m-d')) {
            $error = "Please choose a valid upcoming date for leave.";
        } else {
            $insLeave = $db->prepare("
                INSERT INTO doctor_leaves (doctor_id, leave_date, reason, created_at)
                VALUES (:did, :ldate, :reason, NOW())
                ON DUPLICATE KEY UPDATE reason = VALUES(reason)
            ");
            $insLeave->execute([
                ':did'    => $doctorId,
                ':ldate'  => $leaveDate,
                ':reason' => $reason
            ]);
            set_flash('success', "Leave recorded for " . format_date($leaveDate) . ".");
            header("Location: " . BASE_URL . "/doctor/schedule.php");
            exit;
        }
    }

    // Handle Delete Leave
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_leave') {
        verify_csrf_post();
        $leaveId = filter_input(INPUT_POST, 'leave_id', FILTER_VALIDATE_INT);
        if ($leaveId) {
            $del = $db->prepare("DELETE FROM doctor_leaves WHERE id = :lid AND doctor_id = :did");
            $del->execute([':lid' => $leaveId, ':did' => $doctorId]);
            set_flash('info', "Leave entry removed.");
            header("Location: " . BASE_URL . "/doctor/schedule.php");
            exit;
        }
    }

    // Fetch existing schedules indexed by day_of_week
    $schedStmt = $db->prepare("SELECT * FROM doctor_schedules WHERE doctor_id = :did");
    $schedStmt->execute([':did' => $doctorId]);
    $scheduleRows = $schedStmt->fetchAll();
    $schedules = [];
    foreach ($scheduleRows as $r) {
        $schedules[(int)$r['day_of_week']] = $r;
    }

    // Fetch upcoming leaves
    $leaveStmt = $db->prepare("
        SELECT * FROM doctor_leaves 
        WHERE doctor_id = :did AND leave_date >= CURRENT_DATE() 
        ORDER BY leave_date ASC
    ");
    $leaveStmt->execute([':did' => $doctorId]);
    $leaves = $leaveStmt->fetchAll();

} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}

$dayNames = [
    0 => 'Sunday',
    1 => 'Monday',
    2 => 'Tuesday',
    3 => 'Wednesday',
    4 => 'Thursday',
    5 => 'Friday',
    6 => 'Saturday'
];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Weekly Schedule & Leave Management</h1>
        <p class="page-subtitle">Configure your consultation availability and mark leave dates.</p>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-error">
        <span>✕</span> <?= e($error) ?>
    </div>
<?php endif; ?>

<div style="display:grid; grid-template-columns: 1fr 340px; gap: 28px;">
    <!-- Weekly Availability Setup Form -->
    <div class="card">
        <h2 class="card-title">Weekly Working Hours</h2>

        <form method="POST" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_schedule">

            <div class="form-group" style="background:#f8fafc; padding:14px; border-radius:10px; border:1px solid #e2e8f0; margin-bottom:20px;">
                <label class="form-label" for="custom_meeting_link">Custom Meeting URL (Optional)</label>
                <input type="url" id="custom_meeting_link" name="custom_meeting_link" class="form-control" 
                       placeholder="e.g. https://us04web.zoom.us/j/123456789 or Google Meet URL" 
                       value="<?= e($doctor['custom_meeting_link'] ?? '') ?>">
                <div style="font-size:12px; color:#64748b; margin-top:4px;">
                    Leave blank to automatically use standard secure Jitsi Meet tele-rooms (<code>meet.jit.si/doctor-connect-{id}</code>).
                </div>
            </div>

            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th style="width:120px;">Day</th>
                            <th>Active</th>
                            <th>Start Time</th>
                            <th>End Time</th>
                            <th>Slot Duration</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dayNames as $d => $dayName): ?>
                            <?php 
                                $sch = $schedules[$d] ?? null;
                                $isActive = $sch ? (bool)$sch['is_active'] : in_array($d, [1, 2, 3, 4, 5]);
                                $startVal = $sch ? substr($sch['start_time'], 0, 5) : '09:00';
                                $endVal = $sch ? substr($sch['end_time'], 0, 5) : '13:00';
                                $durVal = $sch ? (int)$sch['slot_duration'] : (int)$doctor['avg_consultation_mins'];
                            ?>
                            <tr>
                                <td><strong><?= $dayName ?></strong></td>
                                <td>
                                    <label style="cursor:pointer; display:flex; align-items:center; gap:6px;">
                                        <input type="checkbox" name="days[]" value="<?= $d ?>" <?= $isActive ? 'checked' : '' ?>>
                                        <span>Consulting</span>
                                    </label>
                                </td>
                                <td>
                                    <input type="time" name="start_time[<?= $d ?>]" class="form-control" style="width:120px; padding:4px 8px;" value="<?= $startVal ?>">
                                </td>
                                <td>
                                    <input type="time" name="end_time[<?= $d ?>]" class="form-control" style="width:120px; padding:4px 8px;" value="<?= $endVal ?>">
                                </td>
                                <td>
                                    <select name="slot_duration[<?= $d ?>]" class="form-control" style="width:110px; padding:4px 8px;">
                                        <option value="10" <?= $durVal === 10 ? 'selected' : '' ?>>10 mins</option>
                                        <option value="15" <?= $durVal === 15 ? 'selected' : '' ?>>15 mins</option>
                                        <option value="20" <?= $durVal === 20 ? 'selected' : '' ?>>20 mins</option>
                                        <option value="30" <?= $durVal === 30 ? 'selected' : '' ?>>30 mins</option>
                                    </select>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <button type="submit" class="btn btn-primary btn-lg" style="margin-top:20px;">
                Save Schedule Changes
            </button>
        </form>
    </div>

    <!-- Leave Day Marking (Requirement 3.2) -->
    <div>
        <div class="card">
            <h2 class="card-title">Mark Leave / Out of Office</h2>
            <p style="font-size:13px; color:#64748b; margin-bottom:14px;">
                Patients will not be able to book tokens on marked leave dates.
            </p>

            <form method="POST" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_leave">

                <div class="form-group">
                    <label class="form-label" for="leave_date">Leave Date</label>
                    <input type="date" id="leave_date" name="leave_date" class="form-control" min="<?= date('Y-m-d') ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="leave_reason">Reason</label>
                    <input type="text" id="leave_reason" name="leave_reason" class="form-control" placeholder="e.g. Medical Conference / Leave">
                </div>

                <button type="submit" class="btn btn-warning" style="width:100%;">
                    Record Leave Date
                </button>
            </form>
        </div>

        <div class="card">
            <h3 style="font-size:15px; font-weight:700; color:#0f172a; margin-bottom:12px;">Upcoming Leaves</h3>
            <?php if (empty($leaves)): ?>
                <div style="font-size:13px; color:#94a3b8; text-align:center; padding:12px 0;">No upcoming leaves scheduled.</div>
            <?php else: ?>
                <ul style="list-style:none; padding:0; display:flex; flex-direction:column; gap:8px;">
                    <?php foreach ($leaves as $l): ?>
                        <li style="font-size:13px; padding:10px; background:#fefce8; border:1px solid #fef08a; border-radius:8px; display:flex; justify-content:space-between; align-items:center;">
                            <div>
                                <strong style="color:#854d0e;"><?= format_date($l['leave_date']) ?></strong>
                                <div style="font-size:12px; color:#a16207;"><?= e($l['reason']) ?></div>
                            </div>
                            <form method="POST" action="" onsubmit="return confirm('Remove this leave date?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_leave">
                                <input type="hidden" name="leave_id" value="<?= (int)$l['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger" style="padding:2px 8px;">✕</button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
