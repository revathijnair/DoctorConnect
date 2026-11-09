<?php
/**
 * DOCTOR CONNECT - Dynamic Slot Computation API
 * Key Design Decision 2: Slots are computed, not stored.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

$doctorId = filter_input(INPUT_GET, 'doctor_id', FILTER_VALIDATE_INT);
$date = filter_input(INPUT_GET, 'date', FILTER_SANITIZE_SPECIAL_CHARS);

if (!$doctorId || !$date) {
    echo json_encode(['success' => false, 'error' => 'Doctor ID and Date are required.']);
    exit;
}

// Validate date format YYYY-MM-DD
$d = DateTime::createFromFormat('Y-m-d', $date);
if (!$d || $d->format('Y-m-d') !== $date) {
    echo json_encode(['success' => false, 'error' => 'Invalid date format. Expected YYYY-MM-DD.']);
    exit;
}

try {
    $db = get_db();

    // 1. Fetch Doctor info
    $docStmt = $db->prepare("
        SELECT d.*, u.name as doctor_name 
        FROM doctors d 
        JOIN users u ON d.user_id = u.id 
        WHERE d.id = :id AND d.is_active = 1
    ");
    $docStmt->execute([':id' => $doctorId]);
    $doctor = $docStmt->fetch();

    if (!$doctor) {
        echo json_encode(['success' => false, 'error' => 'Doctor not found or inactive.']);
        exit;
    }

    // 2. Check Leave-day marking (Requirement 3.2)
    $leaveStmt = $db->prepare("SELECT reason FROM doctor_leaves WHERE doctor_id = :did AND leave_date = :ldate LIMIT 1");
    $leaveStmt->execute([':did' => $doctorId, ':ldate' => $date]);
    $leave = $leaveStmt->fetch();

    if ($leave) {
        echo json_encode([
            'success' => true,
            'is_on_leave' => true,
            'leave_reason' => $leave['reason'] ?: 'Doctor is on leave on this date.',
            'can_book' => false
        ]);
        exit;
    }

    // 3. Find day of week (0=Sunday ... 6=Saturday)
    $dayOfWeek = (int)date('w', strtotime($date));

    // 4. Fetch Doctor Schedule for this day of week
    $schedStmt = $db->prepare("
        SELECT * FROM doctor_schedules 
        WHERE doctor_id = :did AND day_of_week = :dow AND is_active = 1 
        LIMIT 1
    ");
    $schedStmt->execute([':did' => $doctorId, ':dow' => $dayOfWeek]);
    $schedule = $schedStmt->fetch();

    if (!$schedule) {
        $dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        echo json_encode([
            'success' => true,
            'is_on_leave' => false,
            'has_schedule' => false,
            'can_book' => false,
            'message' => "Dr. {$doctor['doctor_name']} is not scheduled for consultations on {$dayNames[$dayOfWeek]}s."
        ]);
        exit;
    }

    // 5. Compute total slots from schedule
    $startSec = strtotime($date . ' ' . $schedule['start_time']);
    $endSec = strtotime($date . ' ' . $schedule['end_time']);
    $slotDurationMins = (int)$schedule['slot_duration'] > 0 ? (int)$schedule['slot_duration'] : (int)$doctor['avg_consultation_mins'];
    $slotDurationSec = $slotDurationMins * 60;
    
    $totalCapacity = 0;
    if ($endSec > $startSec && $slotDurationSec > 0) {
        $totalCapacity = (int)floor(($endSec - $startSec) / $slotDurationSec);
    }

    // 6. Query existing booked appointments (excluding cancelled)
    $appStmt = $db->prepare("
        SELECT COUNT(*) as booked_count, COALESCE(MAX(token_number), 0) as max_token
        FROM appointments 
        WHERE doctor_id = :did AND appointment_date = :adate AND status != 'cancelled'
    ");
    $appStmt->execute([':did' => $doctorId, ':adate' => $date]);
    $appData = $appStmt->fetch();

    $bookedCount = (int)$appData['booked_count'];
    $maxToken = (int)$appData['max_token'];
    $remainingSlots = max(0, $totalCapacity - $bookedCount);
    $nextToken = $maxToken + 1;
    $isFull = $remainingSlots <= 0;

    // 7. Calculate estimated time for the next token
    $estimatedNextTime = calculate_estimated_time($schedule['start_time'], $nextToken, (int)$doctor['avg_consultation_mins'], $date);

    echo json_encode([
        'success' => true,
        'is_on_leave' => false,
        'has_schedule' => true,
        'can_book' => !$isFull,
        'doctor' => [
            'id' => (int)$doctor['id'],
            'name' => $doctor['doctor_name'],
            'specialization' => $doctor['specialization'],
            'fee' => (float)$doctor['consultation_fee'],
            'avg_mins' => (int)$doctor['avg_consultation_mins']
        ],
        'schedule' => [
            'start_time' => format_time($schedule['start_time']),
            'end_time' => format_time($schedule['end_time']),
            'slot_duration' => $slotDurationMins
        ],
        'total_capacity' => $totalCapacity,
        'booked_count' => $bookedCount,
        'remaining_slots' => $remainingSlots,
        'next_token_number' => $nextToken,
        'estimated_time' => format_time($estimatedNextTime),
        'is_full' => $isFull
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
