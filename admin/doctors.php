<?php
/**
 * DOCTOR CONNECT - Doctor Management (Admin)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';

require_role('admin');
$user = current_user();
$pageTitle = "Manage Doctors";

$error = '';

try {
    $db = get_db();

    // Handle Add Doctor
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_doctor') {
        verify_csrf_post();

        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $specialization = trim($_POST['specialization'] ?? '');
        $fee = (float)($_POST['consultation_fee'] ?? 50.00);
        $avgMins = (int)($_POST['avg_consultation_mins'] ?? 15);
        $bio = trim($_POST['bio'] ?? '');
        $password = $_POST['password'] ?? 'password123';

        if (empty($name) || empty($email) || empty($specialization)) {
            $error = "Doctor name, email, and specialization are required.";
        } else {
            // Check if user exists
            $check = $db->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
            $check->execute([':email' => $email]);
            if ($check->fetch()) {
                $error = "A user account with this email already exists.";
            } else {
                $db->beginTransaction();

                // 1. Create User
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $insUser = $db->prepare("
                    INSERT INTO users (name, email, phone, password_hash, role, created_at)
                    VALUES (:name, :email, :phone, :hash, 'doctor', NOW())
                ");
                $insUser->execute([
                    ':name'  => $name,
                    ':email' => $email,
                    ':phone' => $phone,
                    ':hash'  => $hash
                ]);
                $newUserId = (int)$db->lastInsertId();

                // 2. Create Doctor Record
                $insDoc = $db->prepare("
                    INSERT INTO doctors (user_id, specialization, consultation_fee, avg_consultation_mins, bio, is_active, created_at)
                    VALUES (:uid, :spec, :fee, :mins, :bio, 1, NOW())
                ");
                $insDoc->execute([
                    ':uid'  => $newUserId,
                    ':spec' => $specialization,
                    ':fee'  => $fee,
                    ':mins' => $avgMins,
                    ':bio'  => $bio
                ]);
                $newDocId = (int)$db->lastInsertId();

                // 3. Create default Monday - Friday schedule (09:00 - 13:00)
                $insSched = $db->prepare("
                    INSERT INTO doctor_schedules (doctor_id, day_of_week, start_time, end_time, slot_duration, is_active)
                    VALUES (:did, :dow, '09:00:00', '13:00:00', :dur, 1)
                ");
                for ($d = 1; $d <= 5; $d++) {
                    $insSched->execute([':did' => $newDocId, ':dow' => $d, ':dur' => $avgMins]);
                }

                $db->commit();
                set_flash('success', "Doctor Dr. {$name} successfully created with default schedule.");
                header("Location: " . BASE_URL . "/admin/doctors.php");
                exit;
            }
        }
    }

    // Handle Edit Doctor
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_doctor') {
        verify_csrf_post();

        $docId = filter_input(INPUT_POST, 'doctor_id', FILTER_VALIDATE_INT);
        $name = trim($_POST['name'] ?? '');
        $specialization = trim($_POST['specialization'] ?? '');
        $fee = (float)($_POST['consultation_fee'] ?? 50.00);
        $avgMins = (int)($_POST['avg_consultation_mins'] ?? 15);
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $bio = trim($_POST['bio'] ?? '');

        if ($docId && !empty($name) && !empty($specialization)) {
            $db->beginTransaction();

            // Fetch user_id
            $getU = $db->prepare("SELECT user_id FROM doctors WHERE id = :did");
            $getU->execute([':did' => $docId]);
            $uRow = $getU->fetch();

            if ($uRow) {
                $updU = $db->prepare("UPDATE users SET name = :name WHERE id = :uid");
                $updU->execute([':name' => $name, ':uid' => $uRow['user_id']]);

                $updD = $db->prepare("
                    UPDATE doctors 
                    SET specialization = :spec, consultation_fee = :fee, avg_consultation_mins = :mins, bio = :bio, is_active = :act
                    WHERE id = :did
                ");
                $updD->execute([
                    ':spec' => $specialization,
                    ':fee'  => $fee,
                    ':mins' => $avgMins,
                    ':bio'  => $bio,
                    ':act'  => $isActive,
                    ':did'  => $docId
                ]);

                $db->commit();
                set_flash('success', "Doctor details updated.");
                header("Location: " . BASE_URL . "/admin/doctors.php");
                exit;
            }
        }
    }

    // Fetch all doctors with user info
    $doctors = $db->query("
        SELECT d.*, u.name as doctor_name, u.email as doctor_email, u.phone as doctor_phone,
               (SELECT COUNT(*) FROM appointments WHERE doctor_id = d.id) as total_appointments
        FROM doctors d
        JOIN users u ON d.user_id = u.id
        ORDER BY d.id ASC
    ")->fetchAll();

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    die("Database error: " . $e->getMessage());
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Manage Physicians & Specialists</h1>
        <p class="page-subtitle">Add doctors, adjust consultation fees, and configure default slot durations.</p>
    </div>
    <button type="button" class="btn btn-primary" onclick="openAddDocModal()">
        + Add New Doctor
    </button>
</div>

<?php if ($error): ?>
    <div class="alert alert-error">
        <span>✕</span> <?= e($error) ?>
    </div>
<?php endif; ?>

<div class="card">
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Doctor Name</th>
                    <th>Specialization</th>
                    <th>Email & Contact</th>
                    <th>Fee</th>
                    <th>Avg. Time</th>
                    <th>Total Consults</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($doctors as $doc): ?>
                    <tr>
                        <td>
                            <strong style="font-size:15px; color:#0f172a;">Dr. <?= e($doc['doctor_name']) ?></strong>
                        </td>
                        <td><span style="color:var(--primary); font-weight:600;"><?= e($doc['specialization']) ?></span></td>
                        <td>
                            <div><?= e($doc['doctor_email']) ?></div>
                            <div style="font-size:12px; color:#64748b;"><?= e($doc['doctor_phone'] ?: '--') ?></div>
                        </td>
                        <td><strong>$<?= number_format($doc['consultation_fee'], 2) ?></strong></td>
                        <td><?= (int)$doc['avg_consultation_mins'] ?> mins</td>
                        <td><?= (int)$doc['total_appointments'] ?></td>
                        <td>
                            <?php if ($doc['is_active']): ?>
                                <span class="status-badge badge-serving">Active</span>
                            <?php else: ?>
                                <span class="status-badge badge-noshow">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button type="button" class="btn btn-sm btn-secondary" 
                                    onclick='openEditDocModal(<?= json_encode($doc) ?>)'>
                                Edit
                            </button>
                            <a href="<?= BASE_URL ?>/admin/queue_monitor.php?doctor_id=<?= (int)$doc['id'] ?>" class="btn btn-sm btn-primary">
                                Queue
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Add New Doctor -->
<div id="add-doc-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.6); z-index:999; align-items:center; justify-content:center; padding:20px;">
    <div style="background:#fff; border-radius:16px; max-width:540px; width:100%; padding:28px; max-height:90vh; overflow-y:auto; box-shadow:var(--shadow-lg);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <h2 style="font-size:18px; font-weight:800; color:#0f172a;">Add New Doctor Account</h2>
            <button type="button" onclick="closeAddDocModal()" style="border:none; background:transparent; font-size:20px; cursor:pointer;">&times;</button>
        </div>

        <form method="POST" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_doctor">

            <div class="form-group">
                <label class="form-label">Full Name *</label>
                <input type="text" name="name" class="form-control" placeholder="e.g. Dr. Arthur Dent" required>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Email Address *</label>
                    <input type="email" name="email" class="form-control" placeholder="doctor@doctorconnect.com" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Phone Number</label>
                    <input type="tel" name="phone" class="form-control" placeholder="+15551234567">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Specialization / Department *</label>
                <input type="text" name="specialization" class="form-control" placeholder="e.g. Pediatrician / Neurologist" required>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Consultation Fee ($)</label>
                    <input type="number" step="0.01" name="consultation_fee" class="form-control" value="50.00" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Avg. Duration (Mins)</label>
                    <input type="number" name="avg_consultation_mins" class="form-control" value="15" required>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Temporary Password</label>
                <input type="text" name="password" class="form-control" value="password123" required>
            </div>

            <div class="form-group">
                <label class="form-label">Bio / Profile Summary</label>
                <textarea name="bio" class="form-control" rows="2" placeholder="Brief clinical background..."></textarea>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                <button type="button" class="btn btn-secondary" onclick="closeAddDocModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Doctor & Provision Schedule</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Edit Doctor -->
<div id="edit-doc-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.6); z-index:999; align-items:center; justify-content:center; padding:20px;">
    <div style="background:#fff; border-radius:16px; max-width:540px; width:100%; padding:28px; max-height:90vh; overflow-y:auto; box-shadow:var(--shadow-lg);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <h2 style="font-size:18px; font-weight:800; color:#0f172a;">Edit Doctor Details</h2>
            <button type="button" onclick="closeEditDocModal()" style="border:none; background:transparent; font-size:20px; cursor:pointer;">&times;</button>
        </div>

        <form method="POST" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_doctor">
            <input type="hidden" id="edit-doc-id" name="doctor_id" value="">

            <div class="form-group">
                <label class="form-label">Full Name *</label>
                <input type="text" id="edit-doc-name" name="name" class="form-control" required>
            </div>

            <div class="form-group">
                <label class="form-label">Specialization *</label>
                <input type="text" id="edit-doc-spec" name="specialization" class="form-control" required>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Consultation Fee ($)</label>
                    <input type="number" step="0.01" id="edit-doc-fee" name="consultation_fee" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Avg. Duration (Mins)</label>
                    <input type="number" id="edit-doc-mins" name="avg_consultation_mins" class="form-control" required>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Bio Summary</label>
                <textarea id="edit-doc-bio" name="bio" class="form-control" rows="2"></textarea>
            </div>

            <div class="form-group">
                <label style="cursor:pointer; display:flex; align-items:center; gap:8px;">
                    <input type="checkbox" id="edit-doc-active" name="is_active" value="1">
                    <span>Account Active (Accepting Appointments)</span>
                </label>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                <button type="button" class="btn btn-secondary" onclick="closeEditDocModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddDocModal() {
    document.getElementById('add-doc-modal').style.display = 'flex';
}
function closeAddDocModal() {
    document.getElementById('add-doc-modal').style.display = 'none';
}
function openEditDocModal(doc) {
    document.getElementById('edit-doc-id').value = doc.id;
    document.getElementById('edit-doc-name').value = doc.doctor_name;
    document.getElementById('edit-doc-spec').value = doc.specialization;
    document.getElementById('edit-doc-fee').value = doc.consultation_fee;
    document.getElementById('edit-doc-mins').value = doc.avg_consultation_mins;
    document.getElementById('edit-doc-bio').value = doc.bio || '';
    document.getElementById('edit-doc-active').checked = doc.is_active == 1;
    document.getElementById('edit-doc-modal').style.display = 'flex';
}
function closeEditDocModal() {
    document.getElementById('edit-doc-modal').style.display = 'none';
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
