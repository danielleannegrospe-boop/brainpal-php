<?php
require_once __DIR__ . '/../_shared/db.php';
brainpal_cors('GET, OPTIONS');

$studentId = (int)($_GET['student_id'] ?? 0);
if ($studentId <= 0) {
    echo json_encode(['success'=>false,'status'=>'error','message'=>'Invalid student_id.','created_count'=>0]);
    exit;
}

$conn = brainpal_db();
/* Respect the student's Notification Preferences. */
$pref = [
    'daily_reminder' => 0,
    'enable_reminder_time' => 0,
    'reminder_time' => '8:00 AM'
];
$prefStmt = $conn->prepare("SELECT daily_reminder, enable_reminder_time, reminder_time
    FROM notification_preferences WHERE user_id=? AND user_role='student' LIMIT 1");
if ($prefStmt) {
    $prefStmt->bind_param('i', $studentId);
    $prefStmt->execute();
    $prefRow = $prefStmt->get_result()->fetch_assoc();
    $prefStmt->close();
    if ($prefRow) {
        $pref = $prefRow;
    }
}

/* If study reminders are OFF, do not generate schedule reminder notifications. */
if (!(int)$pref['daily_reminder']) {
    echo json_encode([
        'success'=>true,
        'status'=>'success',
        'created_count'=>0,
        'notifications'=>[],
        'message'=>'Study reminders are disabled in Notification Preferences.'
    ], JSON_UNESCAPED_UNICODE);
    $conn->close();
    exit;
}


/*
 * Creates in-app reminders for approved learning materials attached to
 * the student's generated study schedule.
 *
 * Reminder levels:
 * 1. scheduled_day_morning: once during 12:00 AM-11:59 AM on the day.
 * 2. scheduled_soon: once during the 30 minutes before the session.
 * 3. scheduled_now: once when the scheduled study window has started.
 *
 * The endpoint is intentionally idempotent: every reminder type is created
 * at most once per material per scheduled date.
 */

$sql = "SELECT
            lm.material_id,
            lm.title,
            lm.subject_id,
            s.subject_name,
            ss.schedule_id,
            ss.scheduled_day,
            ss.start_time,
            ss.duration_minutes
        FROM learning_material lm
        INNER JOIN study_schedule ss ON ss.schedule_id = lm.schedule_id
        LEFT JOIN subject s ON s.subject_id = lm.subject_id
        WHERE ss.student_id = ?
          AND ss.status = 'scheduled'
          AND ss.date_deleted IS NULL
          AND lm.date_deleted IS NULL
          AND lm.approval_status = 'approved'
          AND ss.scheduled_day >= CURDATE()
        ORDER BY ss.scheduled_day ASC, ss.start_time ASC, lm.material_id ASC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['success'=>false,'status'=>'error','message'=>'Unable to prepare reminder query.','created_count'=>0]);
    $conn->close();
    exit;
}
$stmt->bind_param('i', $studentId);
$stmt->execute();
$result = $stmt->get_result();

$created = 0;
$createdItems = [];

$insert = $conn->prepare(
    "INSERT INTO notifications
        (user_id, user_role, type, title, message, reference_id, is_read)
     SELECT ?, 'student', ?, ?, ?, ?, 0
     WHERE NOT EXISTS (
        SELECT 1 FROM notifications
        WHERE user_id = ?
          AND user_role = 'student'
          AND type = ?
          AND reference_id = ?
          AND DATE(date_created) = ?
     )"
);

if (!$insert) {
    $stmt->close();
    $conn->close();
    echo json_encode(['success'=>false,'status'=>'error','message'=>'Unable to prepare reminder insert.','created_count'=>0]);
    exit;
}

$now = time();
$today = date('Y-m-d');
$currentMinutes = ((int)date('H') * 60) + (int)date('i');

while ($row = $result->fetch_assoc()) {
    $date = (string)$row['scheduled_day'];
    $start = (string)$row['start_time'];
    $duration = max(1, (int)($row['duration_minutes'] ?? 60));
    $startTs = strtotime($date . ' ' . $start);
    if ($startTs === false) continue;

    $endTs = $startTs + ($duration * 60);
    $dayLabel = date('l, F j, Y', $startTs);
    $startLabel = date('g:i A', $startTs);
    $endLabel = date('g:i A', $endTs);
    $subject = (string)($row['subject_name'] ?? 'your subject');
    $material = (string)($row['title'] ?? 'Learning Material');
    $materialId = (int)$row['material_id'];

    $reminders = [];

    // Morning reminder only on the scheduled day and only before noon.
    $reminderTs = strtotime($today . ' ' . date('H:i', strtotime((string)$pref['reminder_time'])));
    if ($date === $today && (int)$pref['enable_reminder_time'] && $now >= $reminderTs && $now < $startTs) {
        $reminders[] = [
            'scheduled_day_morning',
            'Study Reminder for Today',
            'You have a learning material to study today: "' . $material . '" for ' . $subject . '. Your scheduled study time is ' . $startLabel . ' to ' . $endLabel . '.',
            $date
        ];
    }

    // 30-minute advance reminder.
    if ($now >= ($startTs - 30 * 60) && $now < $startTs) {
        $reminders[] = [
            'scheduled_soon',
            'Study Time Is Almost Here',
            'Your scheduled study time for "' . $material . '" is in less than 30 minutes. You are scheduled from ' . $startLabel . ' to ' . $endLabel . '.',
            $date
        ];
    }

    // Start reminder. If the app is opened a few minutes late, this still fires
    // while the study window is active, instead of being missed forever.
    if ($now >= $startTs && $now < $endTs) {
        $reminders[] = [
            'scheduled_now',
            'It Is Time to Study',
            'It is now time to study "' . $material . '" for ' . $subject . '. Your scheduled study time is ' . $startLabel . ' to ' . $endLabel . '.',
            $date
        ];
    }

    foreach ($reminders as [$type, $title, $message, $notificationDate]) {
        $insert->bind_param(
            'isssiisis',
            $studentId,
            $type,
            $title,
            $message,
            $materialId,
            $studentId,
            $type,
            $materialId,
            $notificationDate
        );
        if ($insert->execute() && $insert->affected_rows > 0) {
            $created++;
            $createdItems[] = [
                'material_id' => $materialId,
                'type' => $type,
                'title' => $title
            ];
        }
    }
}

$insert->close();
$stmt->close();
$conn->close();

echo json_encode([
    'success' => true,
    'status' => 'success',
    'created_count' => $created,
    'notifications' => $createdItems
], JSON_UNESCAPED_UNICODE);
