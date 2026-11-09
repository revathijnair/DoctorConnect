<?php
/**
 * DOCTOR CONNECT - Patient Token Booking Workflow
 * Race-Safe Atomic Token Generation (Meets Non-Functional Requirement & Decision 1)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';

require_role('patient');
$user = current_user();
$pageTitle = "Book Doctor Token";

$selectedDoctorId = filter_input(INPUT_GET, 'doctor_id', FILTER_VALIDATE_INT) ?: 0;
$today = date('Y-m-d');
$maxDate = date('Y-m-d', strtotime('+30 days'));

$error = '';

try {
    $db = get_db();
    
    // Fetch all active doctors
    $docStmt = $db->query("
        SELECT d.*, u.name as doctor_name
        FROM doctors d
        JOIN users u ON d.user_id = u.id
        WHERE d.is_active = 1
        ORDER BY u.name ASC
    ");
    $doctors = $docStmt->fetchAll();
} catch (Exception $e) {
    die("Error loading doctors: " . $e->getMessage());
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_post();

    $doctorId = filter_input(INPUT_POST, 'doctor_id', FILTER_VALIDATE_INT);
    $appointmentDate = trim($_POST['appointment_date'] ?? '');
    $reason = trim($_POST['reason_for_visit'] ?? 'General Consultation');

    // Basic Validations
    if (!$doctorId || empty($appointmentDate)) {
        $error = "Please select both a doctor and a consultation date.";
    } elseif ($appointmentDate < $today) {
        $error = "Appointment date cannot be in the past.";
    } else {
        // Prevent patient from double booking the same doctor on the same date
        $checkExisting = $db->prepare("
            SELECT id, token_number FROM appointments 
            WHERE patient_id = :pid AND doctor_id = :did AND appointment_date = :adate AND status != 'cancelled'
            LIMIT 1
        ");
        $checkExisting->execute([':pid' => $user['id'], ':did' => $doctorId, ':adate' => $appointmentDate]);
        $existingBooking = $checkExisting->fetch();

        if ($existingBooking) {
            $error = "You already have an active Token (#{$existingBooking['token_number']}) with this doctor on {$appointmentDate}.";
        } else {
            // Check leave days
            $leaveCheck = $db->prepare("SELECT reason FROM doctor_leaves WHERE doctor_id = :did AND leave_date = :adate LIMIT 1");
            $leaveCheck->execute([':did' => $doctorId, ':adate' => $appointmentDate]);
            $leave = $leaveCheck->fetch();

            if ($leave) {
                $error = "Dr. is on leave on {$appointmentDate}: " . ($leave['reason'] ?: 'Unavailable');
            } else {
                // Check schedule for day of week
                $dayOfWeek = (int)date('w', strtotime($appointmentDate));
                $schedStmt = $db->prepare("
                    SELECT s.*, d.avg_consultation_mins, d.custom_meeting_link, u.name as doctor_name
                    FROM doctor_schedules s
                    JOIN doctors d ON s.doctor_id = d.id
                    JOIN users u ON d.user_id = u.id
                    WHERE s.doctor_id = :did AND s.day_of_week = :dow AND s.is_active = 1
                    LIMIT 1
                ");
                $schedStmt->execute([':did' => $doctorId, ':dow' => $dayOfWeek]);
                $schedule = $schedStmt->fetch();

                if (!$schedule) {
                    $error = "The selected doctor does not have scheduled consultation hours on this day of the week.";
                } else {
                    // ATOMIC RACE-SAFE TOKEN GENERATION WITH TRANSACTION AND RETRY
                    $maxRetries = 3;
                    $retryCount = 0;
                    $success = false;
                    $appointmentId = null;
                    $tokenNumber = 0;

                    while (!$success && $retryCount < $maxRetries) {
                        try {
                            $db->beginTransaction();

                            // 1. Lock the doctor's tokens for that date to prevent race condition
                            $maxTokenStmt = $db->prepare("
                                SELECT COALESCE(MAX(token_number), 0) AS max_token 
                                FROM appointments 
                                WHERE doctor_id = :did AND appointment_date = :adate 
                                FOR UPDATE
                            ");
                            $maxTokenStmt->execute([':did' => $doctorId, ':adate' => $appointmentDate]);
                            $maxRow = $maxTokenStmt->fetch();
                            $tokenNumber = (int)$maxRow['max_token'] + 1;

                            // 2. Calculate Estimated Meeting Time
                            // Formula: session_start + (token_number - 1) * avg_consultation_mins
                            $startTime = $schedule['start_time'];
                            $avgMins = (int)$schedule['avg_consultation_mins'] ?: 15;
                            $estimatedTime = calculate_estimated_time($startTime, $tokenNumber, $avgMins, $appointmentDate);

                            // 3. Insert Appointment
                            $insStmt = $db->prepare("
                                INSERT INTO appointments (
                                    patient_id, doctor_id, appointment_date, token_number, 
                                    status, estimated_time, reason_for_visit, created_at
                                ) VALUES (
                                    :pid, :did, :adate, :tnum, 
                                    'waiting', :est, :reason, NOW()
                                )
                            ");
                            $insStmt->execute([
                                ':pid'    => $user['id'],
                                ':did'    => $doctorId,
                                ':adate'  => $appointmentDate,
                                ':tnum'   => $tokenNumber,
                                ':est'    => $estimatedTime,
                                ':reason' => $reason
                            ]);

                            $appointmentId = (int)$db->lastInsertId();

                            // 4. Generate Meeting Link
                            $meetingLink = generate_meeting_link($appointmentId, $schedule['custom_meeting_link']);
                            $updLink = $db->prepare("UPDATE appointments SET meeting_link = :link WHERE id = :aid");
                            $updLink->execute([':link' => $meetingLink, ':aid' => $appointmentId]);

                            // 5. In-App Notification
                            $msg = "Token #{$tokenNumber} booked with Dr. {$schedule['doctor_name']} on " . format_date($appointmentDate) . ". Estimated time: " . format_time($estimatedTime) . ".";
                            create_notification($user['id'], $appointmentId, $msg, 'token_booked');

                            // 6. Commit Transaction
                            $db->commit();
                            $success = true;

                        } catch (PDOException $pe) {
                            if ($db->inTransaction()) {
                                $db->rollBack();
                            }
                            // 23000 = integrity constraint violation (e.g. unique token race caught)
                            if ($pe->getCode() == '23000') {
                                $retryCount++;
                                usleep(100000); // 100ms jitter before retry
                            } else {
                                throw $pe;
                            }
                        }
                    }

                    if ($success) {
                        set_flash('success', "🎉 Your Token #{$tokenNumber} has been successfully booked for " . format_date($appointmentDate) . "!");
                        header("Location: " . BASE_URL . "/patient/queue.php?appointment_id=" . $appointmentId);
                        exit;
                    } else {
                        $error = "Could not assign token due to high booking concurrency. Please try again.";
                    }
                }
            }
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Book Consultation Token</h1>
        <p class="page-subtitle">Select your doctor and preferred date to generate an estimated meeting token.</p>
    </div>
    <a href="<?= BASE_URL ?>/patient/dashboard.php" class="btn btn-secondary">
        &larr; Back to Dashboard
    </a>
</div>

<?php if ($error): ?>
    <div class="alert alert-error">
        <span>✕</span> <?= e($error) ?>
    </div>
<?php endif; ?>

<div style="display:grid; grid-template-columns:1fr 380px; gap:28px;">
    <!-- Booking Form -->
    <div class="card">
        <h2 class="card-title">Appointment Details</h2>

        <form method="POST" action="" id="booking-form">
            <?= csrf_field() ?>

            <!-- Doctor Selector -->
            <div class="form-group">
                <label class="form-label" for="doctor_id">Select Physician / Specialist</label>
                <select id="doctor_id" name="doctor_id" class="form-control" required onchange="checkSlots()">
                    <option value="">-- Choose a Doctor --</option>
                    <?php foreach ($doctors as $doc): ?>
                        <option value="<?= (int)$doc['id'] ?>" <?= $selectedDoctorId === (int)$doc['id'] ? 'selected' : '' ?>>
                            Dr. <?= e($doc['doctor_name']) ?> &mdash; <?= e($doc['specialization']) ?> ($<?= number_format($doc['consultation_fee'], 2) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Date Selector -->
            <div class="form-group">
                <label class="form-label" for="appointment_date">Consultation Date</label>
                <input type="date" id="appointment_date" name="appointment_date" class="form-control" 
                       min="<?= $today ?>" max="<?= $maxDate ?>" value="<?= $today ?>" required onchange="checkSlots()">
            </div>

            <!-- Reason for Visit -->
            <div class="form-group">
                <label class="form-label" for="reason_for_visit">Reason for Visit / Symptoms</label>
                <textarea id="reason_for_visit" name="reason_for_visit" class="form-control" rows="3" 
                          placeholder="Briefly describe your symptoms, health concerns or purpose of consultation..."><?= e($_POST['reason_for_visit'] ?? '') ?></textarea>
            </div>

            <button type="submit" id="book-btn" class="btn btn-primary btn-lg" style="width:100%; margin-top:12px;">
                Confirm & Generate Token &rarr;
            </button>
        </form>
    </div>

    <!-- Dynamic Slot & Token Preview Box (Computed dynamically via api/get_slots.php) -->
    <div>
        <div class="card" id="slot-preview-card" style="background:#f8fafc; border:1px solid #cbd5e1;">
            <div style="font-size:16px; font-weight:700; color:#0f172a; margin-bottom:12px; display:flex; align-items:center; gap:8px;">
                <span>📊</span> <span>Live Slot Preview</span>
            </div>

            <div id="slot-loading" style="display:none; text-align:center; padding:20px; color:#64748b;">
                Computing available schedule...
            </div>

            <div id="slot-content">
                <div style="text-align:center; padding:24px 10px; color:#64748b; font-size:13px;">
                    Select a doctor and date to preview available tokens, session timings, and estimated meeting times.
                </div>
            </div>
        </div>

        <div class="card" style="font-size:13px; color:#64748b; line-height:1.6;">
            <strong style="color:#0f172a;">Important Information:</strong>
            <ul style="padding-left:18px; margin-top:8px;">
                <li>Tokens are numbered sequentially (First booked = Token #1).</li>
                <li>Estimated meeting times update live based on consultation pace.</li>
                <li>Your video meeting room link unlocks automatically when your token is called.</li>
            </ul>
        </div>
    </div>
</div>

<script>
async function checkSlots() {
    const docId = document.getElementById('doctor_id').value;
    const date = document.getElementById('appointment_date').value;
    const content = document.getElementById('slot-content');
    const loading = document.getElementById('slot-loading');
    const btn = document.getElementById('book-btn');

    if (!docId || !date) return;

    loading.style.display = 'block';
    content.style.display = 'none';

    try {
        const res = await fetch(`${window.DoctorConnectBaseUrl}/api/get_slots.php?doctor_id=${docId}&date=${date}`);
        const data = await res.json();

        loading.style.display = 'none';
        content.style.display = 'block';

        if (!data.success) {
            content.innerHTML = `<div class="alert alert-error" style="font-size:12px;">${data.error}</div>`;
            btn.disabled = true;
            return;
        }

        if (data.is_on_leave) {
            content.innerHTML = `
                <div class="alert alert-warning" style="font-size:13px;">
                    ⚠️ <strong>Doctor On Leave:</strong><br>${data.leave_reason}
                </div>
            `;
            btn.disabled = true;
            return;
        }

        if (!data.has_schedule) {
            content.innerHTML = `
                <div class="alert alert-info" style="font-size:13px;">
                    ℹ️ ${data.message}
                </div>
            `;
            btn.disabled = true;
            return;
        }

        if (data.is_full) {
            content.innerHTML = `
                <div class="alert alert-error" style="font-size:13px;">
                    ✕ All ${data.total_capacity} tokens for this session are fully booked. Please select another date.
                </div>
            `;
            btn.disabled = true;
            return;
        }

        // Render available token preview
        btn.disabled = false;
        content.innerHTML = `
            <div style="background:#ffffff; border-radius:10px; border:1px solid #e2e8f0; padding:16px; margin-bottom:14px; text-align:center;">
                <div style="font-size:11px; text-transform:uppercase; letter-spacing:1px; color:#64748b; font-weight:700;">Projected Token</div>
                <div style="font-size:42px; font-weight:900; color:var(--primary); line-height:1.1; margin:4px 0;">#${data.next_token_number}</div>
                <div style="font-size:12px; color:#059669; font-weight:600;">⏱ Estimated Time: ${data.estimated_time}</div>
            </div>

            <div style="font-size:13px; color:#475569; display:flex; flex-direction:column; gap:6px;">
                <div>🕒 <strong>Session Hours:</strong> ${data.schedule.start_time} - ${data.schedule.end_time}</div>
                <div>⚡ <strong>Total Capacity:</strong> ${data.total_capacity} patients</div>
                <div>🎫 <strong>Tokens Already Issued:</strong> ${data.booked_count}</div>
                <div>🟢 <strong>Remaining Slots:</strong> <span style="font-weight:700; color:#059669;">${data.remaining_slots}</span></div>
                <div>💵 <strong>Consultation Fee:</strong> $${data.doctor.fee.toFixed(2)}</div>
            </div>
        `;

    } catch (e) {
        loading.style.display = 'none';
        content.style.display = 'block';
        content.innerHTML = `<div class="alert alert-error">Could not connect to slot service.</div>`;
    }
}

// Initial check on load if doctor is pre-selected
document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('doctor_id').value) {
        checkSlots();
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
