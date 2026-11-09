<?php
/**
 * DOCTOR CONNECT - Modern Telehealth Landing Page
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

$pageTitle = "Online Token & Consultation Platform";
$user = current_user();

// Fetch active doctors and their specializations for public display
$doctors = [];
try {
    $db = get_db();
    $stmt = $db->query("
        SELECT d.*, u.name as doctor_name, u.email as doctor_email
        FROM doctors d
        JOIN users u ON d.user_id = u.id
        WHERE d.is_active = 1
        ORDER BY d.id ASC
    ");
    $doctors = $stmt->fetchAll();
} catch (Exception $e) {
    // If DB not installed yet, redirect to setup.php
    header("Location: " . BASE_URL . "/setup.php");
    exit;
}

require_once __DIR__ . '/includes/header.php';
?>

<!-- Hero Section -->
<div class="card" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color:#ffffff; border:none; padding:48px 36px; border-radius:20px; margin-bottom:36px;">
    <div style="max-width: 720px;">
        <span style="display:inline-block; padding:4px 12px; background:rgba(2, 132, 199, 0.3); border:1px solid #0284c7; border-radius:9999px; font-size:12px; font-weight:700; color:#38bdf8; text-transform:uppercase; letter-spacing:1px; margin-bottom:16px;">
            Next-Gen Telehealth & Queue System
        </span>
        <h1 style="font-size:38px; font-weight:900; line-height:1.2; margin-bottom:16px; color:#ffffff;">
            Skip The Waiting Room.<br><span style="color:#38bdf8;">Join Online By Token.</span>
        </h1>
        <p style="font-size:17px; color:#cbd5e1; line-height:1.6; margin-bottom:28px;">
            Book an online consultation token for your doctor. Track your real-time queue position from anywhere, and connect directly to your high-definition video meeting when your token is called.
        </p>

        <div style="display:flex; flex-wrap:wrap; gap:14px;">
            <?php if ($user && $user['role'] === 'patient'): ?>
                <a href="<?= BASE_URL ?>/patient/book.php" class="btn btn-primary btn-lg">⚡ Book Doctor Token Now</a>
                <a href="<?= BASE_URL ?>/patient/dashboard.php" class="btn btn-secondary btn-lg" style="background:rgba(255,255,255,0.1); color:#fff; border-color:rgba(255,255,255,0.2);">Go to My Dashboard</a>
            <?php elseif ($user): ?>
                <a href="<?= BASE_URL ?>/<?= $user['role'] ?>/dashboard.php" class="btn btn-primary btn-lg">Go to <?= ucfirst($user['role']) ?> Dashboard &rarr;</a>
            <?php else: ?>
                <a href="<?= BASE_URL ?>/register.php" class="btn btn-primary btn-lg">Get Started & Book Token</a>
                <a href="<?= BASE_URL ?>/login.php" class="btn btn-secondary btn-lg" style="background:rgba(255,255,255,0.1); color:#fff; border-color:rgba(255,255,255,0.2);">Patient / Doctor Login</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Workflow Steps -->
<div style="margin-bottom:36px;">
    <h2 style="font-size:22px; font-weight:800; color:#0f172a; margin-bottom:8px; text-align:center;">How DoctorConnect Works</h2>
    <p style="color:#64748b; font-size:14px; text-align:center; margin-bottom:28px;">Four simple steps to seamless healthcare from your home or mobile device.</p>

    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:20px;">
        <div class="card" style="text-align:center; padding:28px 20px;">
            <div style="width:48px; height:48px; border-radius:50%; background:var(--primary-light); color:var(--primary); font-size:20px; font-weight:800; display:flex; align-items:center; justify-content:center; margin:0 auto 16px auto;">1</div>
            <h3 style="font-size:16px; font-weight:700; color:#0f172a; margin-bottom:8px;">Select Doctor & Date</h3>
            <p style="font-size:13px; color:#64748b;">Browse specialized physicians, review available session times, and pick your preferred appointment date.</p>
        </div>

        <div class="card" style="text-align:center; padding:28px 20px;">
            <div style="width:48px; height:48px; border-radius:50%; background:var(--primary-light); color:var(--primary); font-size:20px; font-weight:800; display:flex; align-items:center; justify-content:center; margin:0 auto 16px auto;">2</div>
            <h3 style="font-size:16px; font-weight:700; color:#0f172a; margin-bottom:8px;">Receive Race-Safe Token</h3>
            <p style="font-size:13px; color:#64748b;">The system issues your numbered token with an exact estimated meeting time based on consultation duration.</p>
        </div>

        <div class="card" style="text-align:center; padding:28px 20px;">
            <div style="width:48px; height:48px; border-radius:50%; background:var(--primary-light); color:var(--primary); font-size:20px; font-weight:800; display:flex; align-items:center; justify-content:center; margin:0 auto 16px auto;">3</div>
            <h3 style="font-size:16px; font-weight:700; color:#0f172a; margin-bottom:8px;">Live Queue Tracking</h3>
            <p style="font-size:13px; color:#64748b;">Watch the live queue board update in real-time. Hear a chime and receive in-app alerts when your turn approaches.</p>
        </div>

        <div class="card" style="text-align:center; padding:28px 20px;">
            <div style="width:48px; height:48px; border-radius:50%; background:var(--primary-light); color:var(--primary); font-size:20px; font-weight:800; display:flex; align-items:center; justify-content:center; margin:0 auto 16px auto;">4</div>
            <h3 style="font-size:16px; font-weight:700; color:#0f172a; margin-bottom:8px;">Join Video Meeting</h3>
            <p style="font-size:13px; color:#64748b;">Your secure Jitsi video room unlocks when your token is called. Receive instant digital prescription notes after.</p>
        </div>
    </div>
</div>

<!-- Available Doctors Section -->
<div style="margin-bottom:36px;">
    <div class="page-header" style="margin-bottom:20px;">
        <div>
            <h2 class="page-title" style="font-size:22px;">Available Physicians & Specialists</h2>
            <p class="page-subtitle">Schedule your token directly with one of our certified clinical specialists.</p>
        </div>
        <a href="<?= BASE_URL ?>/patient/book.php" class="btn btn-primary">Book Consultation</a>
    </div>

    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:24px;">
        <?php foreach ($doctors as $doc): ?>
            <div class="card" style="display:flex; flex-direction:column; justify-content:space-between; margin-bottom:0;">
                <div>
                    <div style="display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:12px;">
                        <div>
                            <h3 style="font-size:18px; font-weight:700; color:#0f172a;"><?= e($doc['doctor_name']) ?></h3>
                            <span style="font-size:13px; color:var(--primary); font-weight:600;"><?= e($doc['specialization']) ?></span>
                        </div>
                        <span style="background:#f1f5f9; padding:4px 10px; border-radius:6px; font-size:14px; font-weight:700; color:#0f172a;">
                            $<?= number_format($doc['consultation_fee'], 2) ?>
                        </span>
                    </div>

                    <p style="font-size:13px; color:#64748b; line-height:1.5; margin-bottom:16px;">
                        <?= e($doc['bio'] ?: 'Dedicated medical practitioner committed to quality virtual patient consultations.') ?>
                    </p>

                    <div style="font-size:12px; color:#475569; background:#f8fafc; padding:10px 14px; border-radius:8px; margin-bottom:18px; border:1px solid #e2e8f0;">
                        <div>⏱ <strong>Avg Session:</strong> <?= (int)$doc['avg_consultation_mins'] ?> minutes per patient</div>
                        <div style="margin-top:4px;">📅 <strong>Live Queue:</strong> Token-based orderly admissions</div>
                    </div>
                </div>

                <a href="<?= BASE_URL ?>/patient/book.php?doctor_id=<?= (int)$doc['id'] ?>" class="btn btn-outline-primary" style="width:100%; text-align:center;">
                    Book Token with <?= e($doc['doctor_name']) ?> &rarr;
                </a>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
