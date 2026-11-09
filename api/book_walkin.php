<?php
/**
 * DOCTOR CONNECT - Reception Walk-In Token Booking API
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';

if (!is_logged_in() || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized. Admin only.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

$name = trim($input['name'] ?? '');
$phone = trim($input['phone'] ?? '');
$email = trim($input['email'] ?? '');
$doctorId = filter_var($input['doctor_id'] ?? null, FILTER_VALIDATE_INT);
$date = trim($input['date'] ?? date('Y-m-d'));
$reason = trim($input['reason'] ?? 'Walk-In Consultation');

if (empty($name) || empty($phone) || !$doctorId) {
    echo json_encode(['success' => false, 'error' => 'Patient name, phone, and doctor are required.']);
    exit;
}

if (empty($email)) {
    // Generate synthetic walk-in email if not provided
    $cleanedPhone = preg_replace('/[^0-9]/', '', $phone);
    $email = "walkin_{$cleanedPhone}@doctorconnect.local";
}

try {
    $db = get_db();

    // 1. Find or create patient user
    $userStmt = $db->prepare("SELECT id FROM users WHERE phone = :phone OR email = :email LIMIT 1");
    $userStmt->execute([':phone' => $phone, ':email' => $email]);
    $existingUser = $userStmt->fetch();

    if ($existingUser) {
        $patientId = (int)$existingUser['id'];
    } else {
        $hash = password_hash('password123', PASSWORD_BCRYPT);
        $insUser = $db->prepare("
            INSERT INTO users (name, email, phone, password_hash, role)
            VALUES (:name, :email, :phone, :hash, 'patient')
        ");
        $insUser->execute([
            ':name'  => $name,
            ':email' => $email,
            ':phone' => $phone,
            ':hash'  => $hash
        ]);
        $patientId = (int)$db->lastInsertId();
    }

    // 2. Fetch doctor schedule & info
    $dayOfWeek = (int)date('w', strtotime($date));
    $docStmt = $db->prepare("
        SELECT d.*, s.start_time, s.slot_duration 
        FROM doctors d
        LEFT JOIN doctor_schedules s ON d.id = s.doctor_id AND s.day_of_week = :dow AND s.is_active = 1
        WHERE d.id = :did
    ");
    $docStmt->execute([':dow' => $dayOfWeek, ':did' => $doctorId]);
    $doc = $docStmt->fetch();

    if (!$doc) {
        echo json_encode(['success' => false, 'error' => 'Doctor not found.']);
        exit;
    }

    $startTime = $doc['start_time'] ?? '09:00:00';
    $avgMins = (int)($doc['avg_consultation_mins'] ?: 15);

    // 3. Atomic Token Generation inside Transaction
    $db->beginTransaction();

    // Lock and get current max token number
    $tokenStmt = $db->prepare("
        SELECT COALESCE(MAX(token_number), 0) AS max_token 
        FROM appointments 
        WHERE doctor_id = :did AND appointment_date = :adate 
        FOR UPDATE
    ");
    $tokenStmt->execute([':did' => $doctorId, ':adate' => $date]);
    $row = $tokenStmt->fetch();
    $tokenNumber = (int)$row['max_token'] + 1;

    // Calculate Estimated Meeting Time
    $estimatedTime = calculate_estimated_time($startTime, $tokenNumber, $avgMins, $date);

    // Insert Appointment
    $insApp = $db->prepare("
        INSERT INTO appointments (patient_id, doctor_id, appointment_date, token_number, status, estimated_time, reason_for_visit, created_at)
        VALUES (:pid, :did, :adate, :tnum, 'waiting', :est, :reason, NOW())
    ");
    $insApp->execute([
        ':pid'    => $patientId,
        ':did'    => $doctorId,
        ':adate'  => $date,
        ':tnum'   => $tokenNumber,
        ':est'    => $estimatedTime,
        ':reason' => $reason
    ]);
    $appointmentId = (int)$db->lastInsertId();

    // Generate meeting link
    $meetingLink = generate_meeting_link($appointmentId, $doc['custom_meeting_link']);
    $updLink = $db->prepare("UPDATE appointments SET meeting_link = :link WHERE id = :aid");
    $updLink->execute([':link' => $meetingLink, ':aid' => $appointmentId]);

    // Audit Log
    log_queue_action($appointmentId, 'called', $_SESSION['user_id']);

    $db->commit();

    echo json_encode([
        'success' => true,
        'appointment_id' => $appointmentId,
        'token_number' => $tokenNumber,
        'estimated_time' => format_time($estimatedTime),
        'patient_name' => $name
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
