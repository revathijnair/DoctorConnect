<?php
/**
 * DOCTOR CONNECT - Doctor Active Consultation Room & Prescription Notes
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';

require_role('doctor');
$user = current_user();
$pageTitle = "Active Consultation Room";

$appointmentId = filter_input(INPUT_GET, 'appointment_id', FILTER_VALIDATE_INT);

if (!$appointmentId) {
    header("Location: " . BASE_URL . "/doctor/dashboard.php");
    exit;
}

try {
    $db = get_db();

    // 1. Fetch appointment details
    $stmt = $db->prepare("
        SELECT a.*, d.id as doc_id, d.specialization, u.name as patient_name, u.email as patient_email, 
               u.phone as patient_phone, doc_u.name as doctor_name
        FROM appointments a
        JOIN doctors d ON a.doctor_id = d.id
        JOIN users doc_u ON d.user_id = doc_u.id
        JOIN users u ON a.patient_id = u.id
        WHERE a.id = :aid AND d.user_id = :uid
        LIMIT 1
    ");
    $stmt->execute([':aid' => $appointmentId, ':uid' => $user['id']]);
    $app = $stmt->fetch();

    if (!$app) {
        die("Appointment not found or you are not authorized to conduct this consultation.");
    }

    // 2. Fetch existing prescription if any
    $prescStmt = $db->prepare("SELECT * FROM prescriptions WHERE appointment_id = :aid LIMIT 1");
    $prescStmt->execute([':aid' => $appointmentId]);
    $existingPrescription = $prescStmt->fetch();

    // 3. Fetch past consultations history of this patient with this doctor
    $pastStmt = $db->prepare("
        SELECT a.appointment_date, a.reason_for_visit, p.diagnosis, p.id as prescription_id
        FROM appointments a
        LEFT JOIN prescriptions p ON a.id = p.appointment_id
        WHERE a.patient_id = :pid AND a.id != :aid AND a.status = 'completed'
        ORDER BY a.appointment_date DESC
        LIMIT 3
    ");
    $pastStmt->execute([':pid' => $app['patient_id'], ':aid' => $appointmentId]);
    $pastHistory = $pastStmt->fetchAll();

} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}

$error = '';

// Handle Prescription submission & visit completion
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_post();

    $diagnosis = trim($_POST['diagnosis'] ?? '');
    $symptoms = trim($_POST['symptoms'] ?? '');
    $advice = trim($_POST['advice'] ?? '');
    $followUpDate = !empty($_POST['follow_up_date']) ? $_POST['follow_up_date'] : null;

    // Collect structured medicines JSON
    $medNames = $_POST['med_name'] ?? [];
    $medDosages = $_POST['med_dosage'] ?? [];
    $medFreqs = $_POST['med_freq'] ?? [];
    $medDurs = $_POST['med_duration'] ?? [];

    $medicinesList = [];
    for ($i = 0; $i < count($medNames); $i++) {
        if (!empty(trim($medNames[$i]))) {
            $medicinesList[] = [
                'name'      => trim($medNames[$i]),
                'dosage'    => trim($medDosages[$i] ?? ''),
                'frequency' => trim($medFreqs[$i] ?? ''),
                'duration'  => trim($medDurs[$i] ?? '')
            ];
        }
    }
    $medicinesJson = json_encode($medicinesList);

    if (empty($diagnosis)) {
        $error = "Please provide a clinical diagnosis before finalizing the consultation.";
    } else {
        try {
            $db->beginTransaction();

            // Insert or update prescription
            if ($existingPrescription) {
                $updPresc = $db->prepare("
                    UPDATE prescriptions 
                    SET diagnosis = :diag, symptoms = :sym, medicines = :meds, advice = :adv, follow_up_date = :fup
                    WHERE id = :prid
                ");
                $updPresc->execute([
                    ':diag' => $diagnosis,
                    ':sym'  => $symptoms,
                    ':meds' => $medicinesJson,
                    ':adv'  => $advice,
                    ':fup'  => $followUpDate,
                    ':prid' => $existingPrescription['id']
                ]);
            } else {
                $insPresc = $db->prepare("
                    INSERT INTO prescriptions (appointment_id, doctor_id, patient_id, diagnosis, symptoms, medicines, advice, follow_up_date, created_at)
                    VALUES (:aid, :did, :pid, :diag, :sym, :meds, :adv, :fup, NOW())
                ");
                $insPresc->execute([
                    ':aid'  => $appointmentId,
                    ':did'  => $app['doctor_id'],
                    ':pid'  => $app['patient_id'],
                    ':diag' => $diagnosis,
                    ':sym'  => $symptoms,
                    ':meds' => $medicinesJson,
                    ':adv'  => $advice,
                    ':fup'  => $followUpDate
                ]);
            }

            // Mark appointment completed
            $updApp = $db->prepare("UPDATE appointments SET status = 'completed' WHERE id = :aid");
            $updApp->execute([':aid' => $appointmentId]);

            // Audit log
            log_queue_action($appointmentId, 'completed', $user['id']);

            // Patient Notification
            $notifMsg = "Your consultation with Dr. {$app['doctor_name']} is complete. Your digital prescription is ready.";
            create_notification((int)$app['patient_id'], $appointmentId, $notifMsg, 'token_completed');

            $db->commit();

            set_flash('success', "✓ Consultation completed and prescription successfully generated!");
            header("Location: " . BASE_URL . "/prescription.php?appointment_id=" . $appointmentId);
            exit;

        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = "Failed to save prescription: " . $e->getMessage();
        }
    }
}

// Decode existing medicines if any
$decodedMedicines = [];
if ($existingPrescription && !empty($existingPrescription['medicines'])) {
    $decodedMedicines = json_decode($existingPrescription['medicines'], true) ?: [];
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">
            Consultation Room &bull; Token #<?= (int)$app['token_number'] ?>
        </h1>
        <p class="page-subtitle">Patient: <strong><?= e($app['patient_name']) ?></strong> &bull; <?= e($app['patient_phone']) ?></p>
    </div>
    <div style="display:flex; gap:10px;">
        <a href="<?= e($app['meeting_link']) ?>" target="_blank" class="btn btn-secondary btn-sm">
            ↗ Open Video in New Window
        </a>
        <a href="<?= BASE_URL ?>/doctor/dashboard.php" class="btn btn-secondary btn-sm">&larr; Return to Queue</a>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-error">
        <span>✕</span> <?= e($error) ?>
    </div>
<?php endif; ?>

<!-- Split Layout: Video Call (Left) and Clinical Notes (Right) -->
<div style="display:grid; grid-template-columns: 1fr 1fr; gap:24px;">
    <!-- LEFT: Video Conference Frame -->
    <div>
        <div class="card" style="padding:16px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                <span style="font-weight:700; color:#0f172a; font-size:14px; display:flex; align-items:center;">
                    <span class="pulse-dot"></span> Telehealth Video Feed (Jitsi Meet)
                </span>
                <span class="status-badge badge-serving">LIVE SESSION</span>
            </div>

            <div class="video-frame-container" style="height:520px;">
                <iframe src="<?= e($app['meeting_link']) ?>" allow="camera; microphone; fullscreen; display-capture" allowfullscreen></iframe>
            </div>

            <div style="margin-top:14px; font-size:12px; color:#64748b; display:flex; justify-content:space-between;">
                <span>Room: <code>doctor-connect-<?= (int)$app['id'] ?></code></span>
                <span>Camera & Mic active in iframe</span>
            </div>
        </div>

        <!-- Past Medical History with this Doctor -->
        <div class="card" style="margin-top:20px;">
            <h3 style="font-size:15px; font-weight:700; color:#0f172a; margin-bottom:12px;">Patient Past Visit History</h3>
            <?php if (empty($pastHistory)): ?>
                <div style="font-size:13px; color:#64748b;">No prior completed consultations found for this patient.</div>
            <?php else: ?>
                <ul style="list-style:none; padding:0; display:flex; flex-direction:column; gap:8px;">
                    <?php foreach ($pastHistory as $ph): ?>
                        <li style="font-size:13px; padding:10px; background:#f8fafc; border-radius:8px; border:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
                            <div>
                                <strong><?= format_date($ph['appointment_date']) ?>:</strong> <?= e($ph['diagnosis'] ?: $ph['reason_for_visit']) ?>
                            </div>
                            <?php if ($ph['prescription_id']): ?>
                                <a href="<?= BASE_URL ?>/prescription.php?appointment_id=<?= (int)$app['id'] ?>" target="_blank" class="btn btn-secondary btn-sm">Rx</a>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <!-- RIGHT: Clinical Notes & Prescription Form -->
    <div>
        <div class="card">
            <h2 class="card-title">Consultation Notes & Prescription</h2>

            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:14px; margin-bottom:20px; font-size:13px;">
                <div><strong>Reason for Visit:</strong> <?= e($app['reason_for_visit']) ?></div>
                <div style="margin-top:4px; color:#64748b;">Date: <?= format_date($app['appointment_date']) ?> &bull; Token #<?= (int)$app['token_number'] ?></div>
            </div>

            <form method="POST" action="">
                <?= csrf_field() ?>

                <div class="form-group">
                    <label class="form-label" for="diagnosis">Clinical Diagnosis *</label>
                    <input type="text" id="diagnosis" name="diagnosis" class="form-control" required 
                           placeholder="e.g. Acute Viral Pharyngitis / Allergic Rhinitis" 
                           value="<?= e($existingPrescription['diagnosis'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label class="form-label" for="symptoms">Observed Symptoms & Complaints</label>
                    <textarea id="symptoms" name="symptoms" class="form-control" rows="2" 
                              placeholder="Fever for 3 days, sore throat, mild productive cough..."><?= e($existingPrescription['symptoms'] ?? $app['reason_for_visit']) ?></textarea>
                </div>

                <!-- Prescription Medicines Table -->
                <div class="form-group">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                        <label class="form-label" style="margin-bottom:0;">Prescribed Medications</label>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="addMedRow()">+ Add Medicine</button>
                    </div>

                    <div class="table-responsive" style="margin-bottom:8px;">
                        <table class="data-table" id="med-table">
                            <thead>
                                <tr>
                                    <th>Medicine Name</th>
                                    <th>Dosage</th>
                                    <th>Frequency</th>
                                    <th>Duration</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="med-table-body">
                                <?php if (!empty($decodedMedicines)): ?>
                                    <?php foreach ($decodedMedicines as $med): ?>
                                        <tr>
                                            <td><input type="text" name="med_name[]" class="form-control" value="<?= e($med['name']) ?>" required></td>
                                            <td><input type="text" name="med_dosage[]" class="form-control" value="<?= e($med['dosage']) ?>"></td>
                                            <td><input type="text" name="med_freq[]" class="form-control" value="<?= e($med['frequency']) ?>"></td>
                                            <td><input type="text" name="med_duration[]" class="form-control" value="<?= e($med['duration']) ?>"></td>
                                            <td><button type="button" class="btn btn-sm btn-danger" onclick="this.closest('tr').remove()">✕</button></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td><input type="text" name="med_name[]" class="form-control" placeholder="e.g. Paracetamol 650mg"></td>
                                        <td><input type="text" name="med_dosage[]" class="form-control" placeholder="1 tablet"></td>
                                        <td><input type="text" name="med_freq[]" class="form-control" placeholder="TDS (3 times/day)"></td>
                                        <td><input type="text" name="med_duration[]" class="form-control" placeholder="5 days"></td>
                                        <td><button type="button" class="btn btn-sm btn-danger" onclick="this.closest('tr').remove()">✕</button></td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="advice">Medical Advice & Lifestyle Instructions</label>
                    <textarea id="advice" name="advice" class="form-control" rows="2" 
                              placeholder="Drink plenty of warm fluids. Salt water gargle twice daily. Rest."><?= e($existingPrescription['advice'] ?? '') ?></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label" for="follow_up_date">Follow-up Date (Optional)</label>
                    <input type="date" id="follow_up_date" name="follow_up_date" class="form-control" 
                           min="<?= date('Y-m-d') ?>" value="<?= e($existingPrescription['follow_up_date'] ?? '') ?>">
                </div>

                <button type="submit" class="btn btn-success btn-lg" style="width:100%; margin-top:12px;">
                    ✓ Finalize Consultation & Issue Prescription &rarr;
                </button>
            </form>
        </div>
    </div>
</div>

<script>
function addMedRow() {
    const tbody = document.getElementById('med-table-body');
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td><input type="text" name="med_name[]" class="form-control" placeholder="Medicine name" required></td>
        <td><input type="text" name="med_dosage[]" class="form-control" placeholder="Dosage"></td>
        <td><input type="text" name="med_freq[]" class="form-control" placeholder="Frequency"></td>
        <td><input type="text" name="med_duration[]" class="form-control" placeholder="Duration"></td>
        <td><button type="button" class="btn btn-sm btn-danger" onclick="this.closest('tr').remove()">✕</button></td>
    `;
    tbody.appendChild(tr);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
