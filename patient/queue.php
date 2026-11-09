<?php
/**
 * DOCTOR CONNECT - Patient Live Queue & Consultation Screen
 * Live Polling <= 3s, Audio Chime, Video Meeting Activation on NEXT/SERVING
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';

require_role('patient');
$user = current_user();
$pageTitle = "Live Queue & Consultation";

$appointmentId = filter_input(INPUT_GET, 'appointment_id', FILTER_VALIDATE_INT);

if (!$appointmentId) {
    header("Location: " . BASE_URL . "/patient/dashboard.php");
    exit;
}

try {
    $db = get_db();

    // Fetch appointment with doctor details
    $stmt = $db->prepare("
        SELECT a.*, d.specialization, d.avg_consultation_mins, d.consultation_fee, u.name as doctor_name
        FROM appointments a
        JOIN doctors d ON a.doctor_id = d.id
        JOIN users u ON d.user_id = u.id
        WHERE a.id = :aid AND a.patient_id = :pid
        LIMIT 1
    ");
    $stmt->execute([':aid' => $appointmentId, ':pid' => $user['id']]);
    $app = $stmt->fetch();

    if (!$app) {
        die("Appointment not found or unauthorized access.");
    }

    // Cancellation Action Handling
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel') {
        verify_csrf_post();
        
        // Cancellation policy: Cannot cancel within 2 hours of estimated meeting time (Decision 5)
        $estTimestamp = strtotime($app['estimated_time']);
        $diffHours = ($estTimestamp - time()) / 3600;

        if ($app['status'] !== 'waiting') {
            set_flash('error', "Only waiting appointments can be cancelled.");
        } elseif ($diffHours < CANCELLATION_WINDOW_HOURS && $app['appointment_date'] === date('Y-m-d')) {
            set_flash('error', "Cancellation policy: Cannot cancel within " . CANCELLATION_WINDOW_HOURS . " hours of estimated meeting time.");
        } else {
            $upd = $db->prepare("UPDATE appointments SET status = 'cancelled' WHERE id = :aid");
            $upd->execute([':aid' => $appointmentId]);
            log_queue_action($appointmentId, 'cancelled', $user['id']);
            set_flash('info', "Your Token #{$app['token_number']} has been cancelled.");
            header("Location: " . BASE_URL . "/patient/dashboard.php");
            exit;
        }
    }

} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}

$extraScripts = '<script src="' . BASE_URL . '/assets/js/queue.js"></script>
<script>
document.addEventListener("DOMContentLoaded", () => {
    new LiveQueueManager({
        doctorId: ' . (int)$app['doctor_id'] . ',
        date: ' . json_encode($app['appointment_date']) . ',
        appointmentId: ' . (int)$app['id'] . ',
        isDoctor: false,
        pollIntervalMs: 3000
    });
});
</script>';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Live Consultation Queue</h1>
        <p class="page-subtitle">Real-time status for your appointment with Dr. <?= e($app['doctor_name']) ?></p>
    </div>
    <div style="display:flex; gap:10px;">
        <span id="queue-last-updated" style="font-size:12px; color:#64748b; align-self:center;">Connecting to live queue...</span>
        <a href="<?= BASE_URL ?>/patient/dashboard.php" class="btn btn-secondary btn-sm">&larr; Dashboard</a>
    </div>
</div>

<!-- Main Live Queue Status Display -->
<div style="display:grid; grid-template-columns: 340px 1fr; gap: 28px; margin-bottom: 28px;">
    <!-- Patient Token Identity Card -->
    <div class="card" style="text-align:center; padding:32px 24px;">
        <div style="font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:1px; color:#64748b; margin-bottom:6px;">
            Your Token Number
        </div>
        <div style="font-size:64px; font-weight:900; color:var(--primary); line-height:1; margin-bottom:12px;">
            #<?= (int)$app['token_number'] ?>
        </div>
        <div id="queue-user-status" style="margin-bottom:16px;">
            <?= render_status_badge($app['status']) ?>
        </div>

        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:14px; text-align:left; font-size:13px; color:#475569;">
            <div style="margin-bottom:6px;">👨‍⚕️ <strong>Doctor:</strong> Dr. <?= e($app['doctor_name']) ?></div>
            <div style="margin-bottom:6px;">🩺 <strong>Specialty:</strong> <?= e($app['specialization']) ?></div>
            <div style="margin-bottom:6px;">📅 <strong>Date:</strong> <?= format_date($app['appointment_date']) ?></div>
            <div>⏱ <strong>Est. Time:</strong> <?= format_time($app['estimated_time']) ?></div>
        </div>

        <?php if ($app['status'] === 'waiting'): ?>
            <form method="POST" style="margin-top:20px;" onsubmit="return confirm('Are you sure you want to cancel this appointment token?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="cancel">
                <button type="submit" class="btn btn-secondary btn-sm" style="color:#ef4444; border-color:#fecaca;">
                    Cancel My Token
                </button>
            </form>
        <?php endif; ?>
    </div>

    <!-- Live Queue Board Card -->
    <div class="card">
        <h2 class="card-title">
            <span>🔴 Live Queue Tracker</span>
            <span style="font-size:12px; font-weight:500; color:#059669; display:flex; align-items:center;">
                <span class="pulse-dot"></span> Live Updates Every 3s
            </span>
        </h2>

        <div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:16px; margin-bottom:24px;">
            <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:18px; text-align:center;">
                <div style="font-size:12px; text-transform:uppercase; letter-spacing:0.5px; font-weight:700; color:#15803d; margin-bottom:4px;">Now Calling</div>
                <div id="queue-current-serving" style="font-size:32px; font-weight:900; color:#15803d; line-height:1;">
                    Loading...
                </div>
            </div>

            <div style="background:#fefce8; border:1px solid #fef08a; border-radius:10px; padding:18px; text-align:center;">
                <div style="font-size:12px; text-transform:uppercase; letter-spacing:0.5px; font-weight:700; color:#a16207; margin-bottom:4px;">Next In Line</div>
                <div id="queue-next-in-line" style="font-size:32px; font-weight:900; color:#a16207; line-height:1;">
                    Loading...
                </div>
            </div>

            <div style="background:#f1f5f9; border:1px solid #cbd5e1; border-radius:10px; padding:18px; text-align:center;">
                <div style="font-size:12px; text-transform:uppercase; letter-spacing:0.5px; font-weight:700; color:#475569; margin-bottom:4px;">Waiting Queue</div>
                <div id="queue-waiting-count" style="font-size:32px; font-weight:900; color:#0f172a; line-height:1;">
                    Loading...
                </div>
            </div>
        </div>

        <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:10px; padding:16px; margin-bottom:24px; display:flex; align-items:center; justify-content:space-between;">
            <div>
                <div style="font-size:13px; font-weight:600; color:#1e40af;">Your Queue Position:</div>
                <div id="queue-user-position" style="font-size:15px; font-weight:700; color:#1d4ed8; margin-top:2px;">
                    Calculating position...
                </div>
            </div>
            <button class="btn btn-secondary btn-sm" onclick="SoundFX.playChime()">
                🔊 Test Audio Chime
            </button>
        </div>

        <!-- Video Meeting Room Section (Conditioned strictly by Requirement 3.4) -->
        <!-- Link visible to patient only when token is NEXT or SERVING -->
        <div id="queue-meeting-area" style="display:none; border-top:1px solid #e2e8f0; padding-top:24px;">
            <div style="background:#ecfdf5; border:2px solid #10b981; border-radius:12px; padding:24px; text-align:center;">
                <div style="font-size:24px; margin-bottom:6px;">🎥</div>
                <h3 style="font-size:18px; font-weight:800; color:#065f46; margin-bottom:6px;">
                    Your Video Consultation is Ready!
                </h3>
                <p style="font-size:13px; color:#047857; margin-bottom:18px;">
                    Dr. <?= e($app['doctor_name']) ?>'s tele-room is open. Click below to join the secure consultation.
                </p>

                <div style="display:flex; justify-content:center; gap:12px; flex-wrap:wrap;">
                    <a id="queue-meeting-btn" href="<?= e($app['meeting_link']) ?>" target="_blank" class="btn btn-success btn-lg">
                        Launch Video Call (New Tab) &rarr;
                    </a>
                    <button type="button" class="btn btn-outline-primary btn-lg" onclick="toggleEmbeddedVideo()">
                        📺 Open Embedded Video Here
                    </button>
                </div>

                <!-- Embedded Jitsi Meet Container -->
                <div id="embedded-video-box" style="display:none; margin-top:20px;">
                    <div class="video-frame-container">
                        <iframe id="video-iframe" src="" allow="camera; microphone; fullscreen; display-capture" allowfullscreen></iframe>
                    </div>
                </div>
            </div>
        </div>

        <!-- Meeting Locked Notice when not yet called -->
        <div id="queue-meeting-locked" class="meeting-lock-notice" style="margin-top:20px;">
            <div style="font-size:36px; margin-bottom:12px; opacity:0.6;">🔒</div>
            <h3 style="font-size:16px; font-weight:700; color:#475569; margin-bottom:6px;">Video Consultation Room Locked</h3>
            <p style="font-size:13px; color:#64748b; max-width:440px;">
                As per privacy & queue rules, the video link activates automatically when your token is called or when you are next in line. Please keep this tab open.
            </p>
        </div>
    </div>
</div>

<script>
function toggleEmbeddedVideo() {
    const box = document.getElementById('embedded-video-box');
    const iframe = document.getElementById('video-iframe');
    const link = document.getElementById('queue-meeting-btn').href;

    if (box.style.display === 'none') {
        iframe.src = link;
        box.style.display = 'block';
    } else {
        iframe.src = '';
        box.style.display = 'none';
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
