<?php
/**
 * DOCTOR CONNECT - Queue Action & Advancement API
 * Handles: Call next, mark serving, mark served, mark no-show, cancel
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';

// Must be doctor or admin
if (!is_logged_in() || !in_array($_SESSION['user_role'], ['doctor', 'admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized. Must be doctor or admin.']);
    exit;
}

$user = current_user();

// Accept both JSON payload and standard POST
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

$appointmentId = filter_var($input['appointment_id'] ?? null, FILTER_VALIDATE_INT);
$action = trim($input['action'] ?? '');

$allowedActions = ['serving', 'completed', 'no_show', 'cancelled'];
if (!$appointmentId || !in_array($action, $allowedActions)) {
    echo json_encode(['success' => false, 'error' => 'Valid appointment ID and action are required.']);
    exit;
}

try {
    $db = get_db();

    // Fetch appointment details
    $stmt = $db->prepare("
        SELECT a.*, d.user_id as doctor_user_id, u.name as doctor_name
        FROM appointments a
        JOIN doctors d ON a.doctor_id = d.id
        JOIN users u ON d.user_id = u.id
        WHERE a.id = :aid
        LIMIT 1
    ");
    $stmt->execute([':aid' => $appointmentId]);
    $appointment = $stmt->fetch();

    if (!$appointment) {
        echo json_encode(['success' => false, 'error' => 'Appointment not found.']);
        exit;
    }

    // Role check: If doctor, must be their appointment
    if ($user['role'] === 'doctor' && (int)$appointment['doctor_user_id'] !== (int)$user['id']) {
        echo json_encode(['success' => false, 'error' => 'You cannot manage another doctor\'s queue.']);
        exit;
    }

    $db->beginTransaction();

    // If setting to 'serving', complete any currently 'serving' token for this doctor on this day
    if ($action === 'serving') {
        $compStmt = $db->prepare("
            UPDATE appointments 
            SET status = 'completed' 
            WHERE doctor_id = :did AND appointment_date = :adate AND status = 'serving' AND id != :aid
        ");
        $compStmt->execute([
            ':did'   => $appointment['doctor_id'],
            ':adate' => $appointment['appointment_date'],
            ':aid'   => $appointmentId
        ]);
    }

    // Update appointment status
    $updateStmt = $db->prepare("UPDATE appointments SET status = :status WHERE id = :aid");
    $updateStmt->execute([
        ':status' => $action,
        ':aid'    => $appointmentId
    ]);

    // Audit Log
    log_queue_action($appointmentId, $action, $user['id']);

    // Send Notification to Patient
    if ($action === 'serving') {
        $msg = "Dr. {$appointment['doctor_name']} is calling Token #{$appointment['token_number']}! Your consultation is starting now. Click to join!";
        create_notification((int)$appointment['patient_id'], $appointmentId, $msg, 'token_serving');

        // Check who is NEXT in line and notify them
        $nextStmt = $db->prepare("
            SELECT id, patient_id, token_number 
            FROM appointments 
            WHERE doctor_id = :did AND appointment_date = :adate AND status = 'waiting' AND id != :aid
            ORDER BY token_number ASC LIMIT 1
        ");
        $nextStmt->execute([
            ':did'   => $appointment['doctor_id'],
            ':adate' => $appointment['appointment_date'],
            ':aid'   => $appointmentId
        ]);
        $nextPatient = $nextStmt->fetch();
        if ($nextPatient) {
            $nextMsg = "You are NEXT in line (Token #{$nextPatient['token_number']})! Dr. {$appointment['doctor_name']} is serving Token #{$appointment['token_number']}. Please prepare to join.";
            create_notification((int)$nextPatient['patient_id'], (int)$nextPatient['id'], $nextMsg, 'token_next');
        }
    } elseif ($action === 'no_show') {
        $msg = "Your Token #{$appointment['token_number']} was marked as No-Show by clinic reception.";
        create_notification((int)$appointment['patient_id'], $appointmentId, $msg, 'token_no_show');
    } elseif ($action === 'completed') {
        $msg = "Your consultation with Dr. {$appointment['doctor_name']} is completed. You can view your prescription in history.";
        create_notification((int)$appointment['patient_id'], $appointmentId, $msg, 'token_completed');
    }

    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => "Appointment status updated to {$action}.",
        'appointment_id' => $appointmentId,
        'new_status' => $action
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
