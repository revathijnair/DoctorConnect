<?php
/**
 * DOCTOR CONNECT - Real-Time Live Queue Polling Endpoint
 * Response Time Target: <= 3 seconds
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

$doctorId = filter_input(INPUT_GET, 'doctor_id', FILTER_VALIDATE_INT);
$date = filter_input(INPUT_GET, 'date', FILTER_SANITIZE_SPECIAL_CHARS) ?: date('Y-m-d');
$appointmentId = filter_input(INPUT_GET, 'appointment_id', FILTER_VALIDATE_INT);

if (!$doctorId) {
    echo json_encode(['success' => false, 'error' => 'Doctor ID is required.']);
    exit;
}

try {
    $db = get_db();

    // 1. Fetch currently serving appointment
    $servingStmt = $db->prepare("
        SELECT a.id, a.token_number, a.patient_id, u.name as patient_name
        FROM appointments a
        JOIN users u ON a.patient_id = u.id
        WHERE a.doctor_id = :did AND a.appointment_date = :adate AND a.status = 'serving'
        ORDER BY a.token_number ASC
        LIMIT 1
    ");
    $servingStmt->execute([':did' => $doctorId, ':adate' => $date]);
    $serving = $servingStmt->fetch();

    // 2. Fetch the next in line (waiting token with lowest token_number)
    $nextStmt = $db->prepare("
        SELECT a.id, a.token_number, a.patient_id, u.name as patient_name
        FROM appointments a
        JOIN users u ON a.patient_id = u.id
        WHERE a.doctor_id = :did AND a.appointment_date = :adate AND a.status = 'waiting'
        ORDER BY a.token_number ASC
        LIMIT 1
    ");
    $nextStmt->execute([':did' => $doctorId, ':adate' => $date]);
    $next = $nextStmt->fetch();

    // 3. Count statuses
    $countsStmt = $db->prepare("
        SELECT 
            SUM(CASE WHEN status = 'waiting' THEN 1 ELSE 0 END) as waiting_count,
            SUM(CASE WHEN status = 'serving' THEN 1 ELSE 0 END) as serving_count,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count,
            SUM(CASE WHEN status = 'no_show' THEN 1 ELSE 0 END) as noshow_count,
            COUNT(*) as total_count
        FROM appointments
        WHERE doctor_id = :did AND appointment_date = :adate AND status != 'cancelled'
    ");
    $countsStmt->execute([':did' => $doctorId, ':adate' => $date]);
    $counts = $countsStmt->fetch();

    $response = [
        'success' => true,
        'doctor_id' => $doctorId,
        'date' => $date,
        'queue' => [
            'serving_token' => $serving ? (int)$serving['token_number'] : null,
            'serving_patient' => $serving ? $serving['patient_name'] : null,
            'next_token' => $next ? (int)$next['token_number'] : null,
            'waiting_count' => (int)($counts['waiting_count'] ?? 0),
            'completed_count' => (int)($counts['completed_count'] ?? 0),
            'noshow_count' => (int)($counts['noshow_count'] ?? 0),
            'total_count' => (int)($counts['total_count'] ?? 0)
        ]
    ];

    // 4. If an individual appointment is tracked (Patient View)
    if ($appointmentId) {
        $appStmt = $db->prepare("
            SELECT a.*, d.avg_consultation_mins, u.name as doctor_name
            FROM appointments a
            JOIN doctors d ON a.doctor_id = d.id
            JOIN users u ON d.user_id = u.id
            WHERE a.id = :aid
            LIMIT 1
        ");
        $appStmt->execute([':aid' => $appointmentId]);
        $app = $appStmt->fetch();

        if ($app) {
            $isNext = ($next && (int)$next['id'] === (int)$app['id']);
            $canAccessMeeting = can_patient_access_meeting($app['status'], $isNext);

            // Calculate how many waiting tokens are ahead
            $aheadStmt = $db->prepare("
                SELECT COUNT(*) as ahead 
                FROM appointments 
                WHERE doctor_id = :did AND appointment_date = :adate 
                  AND status = 'waiting' AND token_number < :tnum
            ");
            $aheadStmt->execute([
                ':did'   => $app['doctor_id'],
                ':adate' => $app['appointment_date'],
                ':tnum'  => $app['token_number']
            ]);
            $aheadData = $aheadStmt->fetch();
            $tokensAhead = (int)($aheadData['ahead'] ?? 0);

            $response['user_appointment'] = [
                'id' => (int)$app['id'],
                'token_number' => (int)$app['token_number'],
                'status' => $app['status'],
                'status_badge_html' => render_status_badge($app['status']),
                'is_next' => $isNext,
                'tokens_ahead' => $tokensAhead,
                'estimated_time_formatted' => format_datetime($app['estimated_time']),
                'can_access_meeting' => $canAccessMeeting,
                // Reveal meeting link only when token is NEXT or SERVING
                'meeting_link' => $canAccessMeeting ? $app['meeting_link'] : null
            ];
        }
    }

    // 5. If doctor or admin is requesting full appointment list
    $user = current_user();
    if ($user && in_array($user['role'], ['doctor', 'admin'])) {
        $listStmt = $db->prepare("
            SELECT a.id, a.token_number, a.status, a.estimated_time, a.reason_for_visit, a.meeting_link,
                   u.name as patient_name, u.phone as patient_phone, u.email as patient_email
            FROM appointments a
            JOIN users u ON a.patient_id = u.id
            WHERE a.doctor_id = :did AND a.appointment_date = :adate
            ORDER BY a.token_number ASC
        ");
        $listStmt->execute([':did' => $doctorId, ':adate' => $date]);
        $appointments = $listStmt->fetchAll();

        foreach ($appointments as &$item) {
            $item['estimated_time_formatted'] = format_time($item['estimated_time']);
        }
        $response['all_appointments'] = $appointments;
    }

    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
