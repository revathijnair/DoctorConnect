<?php
/**
 * DOCTOR CONNECT - Formal Medical Prescription Document
 * Clean printable clinical layout (Print to PDF ready)
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

require_login();
$user = current_user();

$appointmentId = filter_input(INPUT_GET, 'appointment_id', FILTER_VALIDATE_INT);

if (!$appointmentId) {
    die("Invalid appointment reference.");
}

try {
    $db = get_db();

    // Fetch appointment, doctor, patient, and prescription details
    $stmt = $db->prepare("
        SELECT 
            a.*, p.diagnosis, p.symptoms, p.medicines, p.advice, p.follow_up_date, p.created_at as prescription_created_at,
            doc_u.name as doctor_name, d.specialization,
            pat_u.name as patient_name, pat_u.phone as patient_phone, pat_u.email as patient_email
        FROM appointments a
        JOIN prescriptions p ON a.id = p.appointment_id
        JOIN doctors d ON a.doctor_id = d.id
        JOIN users doc_u ON d.user_id = doc_u.id
        JOIN users pat_u ON a.patient_id = pat_u.id
        WHERE a.id = :aid
        LIMIT 1
    ");
    $stmt->execute([':aid' => $appointmentId]);
    $doc = $stmt->fetch();

    if (!$doc) {
        die("Prescription not found for this appointment.");
    }

    // Access authorization: Patient can only view their own prescription; Doctor can view their own; Admin can view all
    if ($user['role'] === 'patient' && (int)$doc['patient_id'] !== (int)$user['id']) {
        die("Access denied.");
    }
    if ($user['role'] === 'doctor' && (int)$doc['doctor_id'] !== (int)$user['doctor_id']) {
        die("Access denied.");
    }

    $medicines = json_decode($doc['medicines'] ?? '[]', true) ?: [];

    // Clinic settings
    $clinicName = get_clinic_setting('clinic_name', 'DoctorConnect Telehealth Clinic');
    $clinicPhone = get_clinic_setting('clinic_phone', '+1 (800) 555-0199');
    $clinicEmail = get_clinic_setting('clinic_email', 'care@doctorconnect.com');
    $clinicAddress = get_clinic_setting('clinic_address', '742 Evergreen Medical Suites, Health City, HC 94102');

} catch (Exception $e) {
    die("Error loading prescription: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prescription #<?= (int)$doc['id'] ?> - <?= e($doc['patient_name']) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <style>
        .rx-page {
            max-width: 820px;
            margin: 30px auto;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            padding: 40px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.06);
        }
        .rx-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #0284c7;
            padding-bottom: 20px;
            margin-bottom: 24px;
        }
        .rx-symbol {
            font-size: 38px;
            font-family: "Times New Roman", serif;
            font-weight: 700;
            color: #0284c7;
            margin-bottom: 12px;
        }
    </style>
</head>
<body style="background:#f1f5f9;">

<div class="no-print" style="max-width:820px; margin:20px auto 0 auto; display:flex; justify-content:space-between; align-items:center;">
    <a href="javascript:history.back()" class="btn btn-secondary">&larr; Back</a>
    <button onclick="window.print()" class="btn btn-primary btn-lg">
        🖨️ Print / Save Prescription (PDF)
    </button>
</div>

<div class="rx-page prescription-container">
    <!-- Clinic Header -->
    <div class="rx-header">
        <div>
            <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">
                <span class="brand-icon" style="width:32px; height:32px; font-size:18px;">⚕</span>
                <h1 style="font-size:22px; font-weight:800; color:#0f172a; margin:0;"><?= e($clinicName) ?></h1>
            </div>
            <div style="font-size:12px; color:#64748b;"><?= e($clinicAddress) ?></div>
            <div style="font-size:12px; color:#64748b;">Phone: <?= e($clinicPhone) ?> &bull; Email: <?= e($clinicEmail) ?></div>
        </div>

        <div style="text-align:right;">
            <div style="font-size:17px; font-weight:800; color:#0284c7;">Dr. <?= e($doc['doctor_name']) ?></div>
            <div style="font-size:13px; font-weight:600; color:#475569;"><?= e($doc['specialization']) ?></div>
            <div style="font-size:12px; color:#94a3b8; margin-top:4px;">Telehealth License Verified</div>
        </div>
    </div>

    <!-- Patient & Token Metadata Bar -->
    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px 18px; margin-bottom:24px; display:grid; grid-template-columns: repeat(4, 1fr); gap:12px; font-size:13px;">
        <div>
            <div style="font-size:11px; text-transform:uppercase; color:#64748b; font-weight:700;">Patient Name</div>
            <div style="font-weight:700; color:#0f172a; font-size:14px;"><?= e($doc['patient_name']) ?></div>
        </div>
        <div>
            <div style="font-size:11px; text-transform:uppercase; color:#64748b; font-weight:700;">Phone / Contact</div>
            <div style="color:#0f172a;"><?= e($doc['patient_phone']) ?></div>
        </div>
        <div>
            <div style="font-size:11px; text-transform:uppercase; color:#64748b; font-weight:700;">Token # / Date</div>
            <div style="color:#0f172a; font-weight:700;">#<?= (int)$doc['token_number'] ?> &bull; <?= format_date($doc['appointment_date']) ?></div>
        </div>
        <div>
            <div style="font-size:11px; text-transform:uppercase; color:#64748b; font-weight:700;">Prescription ID</div>
            <div style="color:#0f172a;">DC-RX-<?= str_pad($doc['id'], 5, '0', STR_PAD_LEFT) ?></div>
        </div>
    </div>

    <!-- Diagnosis & Symptoms -->
    <div style="margin-bottom:24px;">
        <div style="font-size:14px; font-weight:700; color:#0f172a; margin-bottom:6px;">
            Clinical Diagnosis:
        </div>
        <div style="font-size:16px; font-weight:800; color:#0284c7; background:#f0f9ff; border-left:4px solid #0284c7; padding:10px 14px; border-radius:4px;">
            <?= e($doc['diagnosis']) ?>
        </div>

        <?php if (!empty($doc['symptoms'])): ?>
            <div style="margin-top:10px; font-size:13px; color:#475569;">
                <strong>Observed Symptoms:</strong> <?= e($doc['symptoms']) ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Rx Medicines -->
    <div style="margin-bottom:28px;">
        <div class="rx-symbol">℞</div>

        <?php if (empty($medicines)): ?>
            <div style="font-size:13px; color:#64748b; font-style:italic;">No oral medications prescribed. Follow general advice below.</div>
        <?php else: ?>
            <table style="width:100%; border-collapse:collapse; font-size:13px; text-align:left;">
                <thead>
                    <tr style="border-bottom:2px solid #cbd5e1; color:#475569;">
                        <th style="padding:8px 10px; width:40px;">#</th>
                        <th style="padding:8px 10px;">Medication & Strength</th>
                        <th style="padding:8px 10px;">Dosage</th>
                        <th style="padding:8px 10px;">Frequency</th>
                        <th style="padding:8px 10px;">Duration</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $idx = 1; foreach ($medicines as $m): ?>
                        <tr style="border-bottom:1px solid #e2e8f0;">
                            <td style="padding:10px; font-weight:700; color:#64748b;"><?= $idx++ ?></td>
                            <td style="padding:10px; font-weight:700; color:#0f172a;"><?= e($m['name']) ?></td>
                            <td style="padding:10px; color:#475569;"><?= e($m['dosage'] ?: '--') ?></td>
                            <td style="padding:10px; color:#475569;"><?= e($m['frequency'] ?: '--') ?></td>
                            <td style="padding:10px; color:#475569;"><?= e($m['duration'] ?: '--') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Doctor's Advice & Instructions -->
    <?php if (!empty($doc['advice'])): ?>
        <div style="margin-bottom:24px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px;">
            <div style="font-size:13px; font-weight:700; color:#0f172a; margin-bottom:4px;">Advice & Instructions:</div>
            <div style="font-size:13px; color:#475569; line-height:1.6; white-space:pre-line;">
                <?= e($doc['advice']) ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Follow Up Date -->
    <?php if (!empty($doc['follow_up_date'])): ?>
        <div style="margin-bottom:24px; font-size:13px; color:#0369a1; background:#e0f2fe; padding:8px 14px; border-radius:6px; display:inline-block;">
            📅 <strong>Follow-up Recommendation:</strong> <?= format_date($doc['follow_up_date']) ?>
        </div>
    <?php endif; ?>

    <!-- Signature Footer -->
    <div style="margin-top:60px; display:flex; justify-content:space-between; align-items:flex-end; border-top:1px solid #e2e8f0; padding-top:20px;">
        <div style="font-size:11px; color:#94a3b8; max-width:400px;">
            This electronic medical prescription was generated via DoctorConnect Telehealth System following virtual consultation. Valid for dispensing by licensed pharmacies.
        </div>
        <div style="text-align:center;">
            <div style="font-family:'Courier New', monospace; font-size:16px; font-weight:700; color:#0284c7; margin-bottom:4px;">
                Dr. <?= e($doc['doctor_name']) ?>
            </div>
            <div style="border-top:1px solid #0f172a; width:180px; margin:0 auto; padding-top:4px; font-size:12px; color:#64748b;">
                Authorized Clinical Signature
            </div>
        </div>
    </div>
</div>

</body>
</html>
