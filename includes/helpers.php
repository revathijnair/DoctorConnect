<?php
/**
 * DOCTOR CONNECT - General Helper Functions
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

/**
 * HTML/XSS Safe Escape
 */
function e(?string $value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Flash messaging system
 */
function set_flash(string $type, string $message): void {
    if (!isset($_SESSION['flash'])) {
        $_SESSION['flash'] = [];
    }
    $_SESSION['flash'][$type][] = $message;
}

function get_flashes(): array {
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

/**
 * Date/Time Formatters
 */
function format_time(?string $timeStr): string {
    if (empty($timeStr)) return '--';
    $timestamp = strtotime($timeStr);
    return $timestamp ? date('g:i A', $timestamp) : $timeStr;
}

function format_date(?string $dateStr): string {
    if (empty($dateStr)) return '--';
    $timestamp = strtotime($dateStr);
    return $timestamp ? date('M j, Y', $timestamp) : $dateStr;
}

function format_datetime(?string $dtStr): string {
    if (empty($dtStr)) return '--';
    $timestamp = strtotime($dtStr);
    return $timestamp ? date('M j, Y g:i A', $timestamp) : $dtStr;
}

/**
 * Calculate Estimated Meeting Time
 * Formula: session_start + (token_number - 1) * avg_consultation_mins
 */
function calculate_estimated_time(string $sessionStartTime, int $tokenNumber, int $avgMins, string $appointmentDate): string {
    $baseTimestamp = strtotime($appointmentDate . ' ' . $sessionStartTime);
    $offsetMinutes = max(0, $tokenNumber - 1) * $avgMins;
    $estimatedTimestamp = $baseTimestamp + ($offsetMinutes * 60);
    return date('Y-m-d H:i:s', $estimatedTimestamp);
}

/**
 * Generate Meeting Link (Jitsi or Custom)
 */
function generate_meeting_link(int $appointmentId, ?string $customLink = null): string {
    if (!empty($customLink)) {
        return trim($customLink);
    }
    $domain = get_clinic_setting('jitsi_domain', DEFAULT_JITSI_DOMAIN);
    return "https://" . rtrim($domain, '/') . "/doctor-connect-" . $appointmentId;
}

/**
 * Check if Patient can access Meeting Link
 * Requirement 3.4: "Link visible to patient only when token is NEXT or SERVING"
 */
function can_patient_access_meeting(string $status, bool $isNextInQueue): bool {
    if ($status === 'serving') {
        return true;
    }
    if ($status === 'waiting' && $isNextInQueue) {
        return true;
    }
    return false;
}

/**
 * Render Status Badge HTML
 */
function render_status_badge(string $status): string {
    $statusMap = [
        'waiting'   => ['label' => 'Waiting in Queue', 'class' => 'badge-waiting',   'icon' => '⏳'],
        'serving'   => ['label' => 'Now Serving',      'class' => 'badge-serving',   'icon' => '🟢'],
        'completed' => ['label' => 'Completed',        'class' => 'badge-completed', 'icon' => '✓'],
        'no_show'   => ['label' => 'No-Show',          'class' => 'badge-noshow',    'icon' => '✕'],
        'cancelled' => ['label' => 'Cancelled',        'class' => 'badge-cancelled', 'icon' => '⊘']
    ];

    $badge = $statusMap[$status] ?? ['label' => ucfirst($status), 'class' => 'badge-default', 'icon' => '•'];
    return sprintf(
        '<span class="status-badge %s"><span class="badge-icon">%s</span> %s</span>',
        e($badge['class']),
        $badge['icon'],
        e($badge['label'])
    );
}

/**
 * Audit Logger: Write to queue_logs table
 */
function log_queue_action(int $appointmentId, string $action, ?int $actedBy = null): void {
    try {
        $db = get_db();
        $stmt = $db->prepare("
            INSERT INTO queue_logs (appointment_id, action, acted_by, action_time)
            VALUES (:aid, :act, :aby, NOW())
        ");
        $stmt->execute([
            ':aid' => $appointmentId,
            ':act' => $action,
            ':aby' => $actedBy
        ]);
    } catch (Exception $e) {
        error_log("Failed to write queue_log: " . $e->getMessage());
    }
}

/**
 * Create In-App Notification
 */
function create_notification(int $userId, ?int $appointmentId, string $message, string $type = 'general'): void {
    try {
        $db = get_db();
        $stmt = $db->prepare("
            INSERT INTO notifications (user_id, appointment_id, message, type, is_read, created_at)
            VALUES (:uid, :aid, :msg, :type, 0, NOW())
        ");
        $stmt->execute([
            ':uid'  => $userId,
            ':aid'  => $appointmentId,
            ':msg'  => $message,
            ':type' => $type
        ]);
    } catch (Exception $e) {
        error_log("Failed to insert notification: " . $e->getMessage());
    }
}

/**
 * Retrieve Clinic Setting
 */
function get_clinic_setting(string $key, string $default = ''): string {
    static $settingsCache = null;
    if ($settingsCache === null) {
        try {
            $db = get_db();
            $stmt = $db->query("SELECT setting_key, setting_value FROM clinic_settings");
            $rows = $stmt->fetchAll();
            $settingsCache = [];
            foreach ($rows as $r) {
                $settingsCache[$r['setting_key']] = $r['setting_value'];
            }
        } catch (Exception $e) {
            $settingsCache = [];
        }
    }
    return $settingsCache[$key] ?? $default;
}
