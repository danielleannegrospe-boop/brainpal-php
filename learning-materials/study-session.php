<?php
require_once __DIR__ . '/../_shared/db.php';
require_once __DIR__ . '/../_shared/notification-helper.php';

date_default_timezone_set('Asia/Manila');
brainpal_cors('POST, OPTIONS');
$conn = brainpal_db();
$data = brainpal_json_input();

$studentId = (int)($data['student_id'] ?? 0);
$materialId = (int)($data['material_id'] ?? 0);
$action = strtolower(trim((string)($data['action'] ?? 'status')));

if ($studentId <= 0 || $materialId <= 0 || !in_array($action, ['start','heartbeat','finish','status'], true)) {
    echo json_encode(['success'=>false,'status'=>'error','message'=>'Invalid study session request.']);
    $conn->close();
    exit;
}

/* Confirm that this material belongs to a schedule assigned to this student. */
$check = $conn->prepare(
    'SELECT lm.material_id, lm.schedule_id, lm.title, lm.approval_status,
             ss.scheduled_day, ss.start_time, ss.duration_minutes
     FROM learning_material lm
     INNER JOIN study_schedule ss ON ss.schedule_id = lm.schedule_id
     WHERE lm.material_id = ?
       AND ss.student_id = ?
       AND lm.date_deleted IS NULL
       AND lm.approval_status = \'approved\'
       AND ss.date_deleted IS NULL
       AND ss.status = \'scheduled\'
     LIMIT 1'
);
$check->bind_param('ii', $materialId, $studentId);
$check->execute();
$material = $check->get_result()->fetch_assoc();
$check->close();

if (!$material) {
    echo json_encode(['success'=>false,'status'=>'error','message'=>'This learning material is not assigned to this student or is not approved yet.']);
    $conn->close();
    exit;
}

if (($material['scheduled_day'] ?? '') !== date('Y-m-d')) {
    echo json_encode([
        'success'=>false,
        'status'=>'not_today',
        'message'=>'This learning material is only available on its scheduled day and time.'
    ]);
    $conn->close();
    exit;
}

$startTime = (string)($material['start_time'] ?? '');
$durationMinutes = max(1, (int)($material['duration_minutes'] ?? 60));
$startTs = strtotime(date('Y-m-d') . ' ' . $startTime);
$endTs = $startTs !== false ? $startTs + ($durationMinutes * 60) : false;
$nowTs = time();

if ($startTs === false || $endTs === false || $nowTs < $startTs || $nowTs >= $endTs) {
    echo json_encode([
        'success'=>false,
        'status'=>'not_time',
        'message'=>'This learning material can only be opened during the scheduled study time.'
    ]);
    $conn->close();
    exit;
}

$scheduleId = (int)$material['schedule_id'];

if ($action === 'start') {
    $stmt = $conn->prepare(
        "INSERT INTO study_material_sessions
         (student_id, material_id, schedule_id, status, started_at, last_heartbeat_at, total_seconds, completed_at)
         VALUES (?, ?, ?, 'studying', NOW(), NOW(), 0, NULL)
         ON DUPLICATE KEY UPDATE
           schedule_id = VALUES(schedule_id),
           status = IF(status = 'completed', 'completed', 'studying'),
           last_heartbeat_at = IF(status = 'completed', last_heartbeat_at, NOW())"
    );
    $stmt->bind_param('iii', $studentId, $materialId, $scheduleId);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        echo json_encode(['success'=>false,'status'=>'error','message'=>'Unable to start the study timer.']);
        $conn->close();
        exit;
    }
}

/* Heartbeat/finish updates only the time since the last server heartbeat.
   A maximum of 30 seconds per heartbeat prevents long background-tab gaps
   from falsely adding hours of study time. */
if (in_array($action, ['heartbeat','finish'], true)) {
    $stmt = $conn->prepare(
        "UPDATE study_material_sessions
         SET total_seconds = total_seconds + LEAST(30, GREATEST(0, TIMESTAMPDIFF(SECOND, last_heartbeat_at, NOW()))),
             last_heartbeat_at = NOW()
         WHERE student_id = ? AND material_id = ? AND status = 'studying'"
    );
    $stmt->bind_param('ii', $studentId, $materialId);
    $stmt->execute();
    $stmt->close();

    if ($action === 'finish') {
        $stmt = $conn->prepare(
            "UPDATE study_material_sessions
             SET status = CASE WHEN total_seconds >= 60 THEN 'completed' ELSE 'abandoned' END,
                 completed_at = CASE WHEN total_seconds >= 60 THEN NOW() ELSE NULL END
             WHERE student_id = ? AND material_id = ? AND status = 'studying'"
        );
        $stmt->bind_param('ii', $studentId, $materialId);
        $stmt->execute();
        $stmt->close();

        /* Notify admins only when the study was actually completed. */
        $stmt = $conn->prepare(
            "SELECT total_seconds, status FROM study_material_sessions
             WHERE student_id = ? AND material_id = ? LIMIT 1"
        );
        $stmt->bind_param('ii', $studentId, $materialId);
        $stmt->execute();
        $session = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (($session['status'] ?? '') === 'completed') {
            $studentName = getStudentName($conn, $studentId);
            notifyAllAdmins(
                $conn,
                'material_studied',
                'Learning Material Studied',
                $studentName . ' completed studying "' . ($material['title'] ?? 'Learning Material') . '".',
                $materialId
            );
        }
    }
}

/* Return the current state. */
$stmt = $conn->prepare(
    "SELECT session_id, status, started_at, last_heartbeat_at, total_seconds, completed_at,
            (status = 'studying' AND last_heartbeat_at >= DATE_SUB(NOW(), INTERVAL 30 SECOND)) AS currently_studying
     FROM study_material_sessions
     WHERE student_id = ? AND material_id = ?
     LIMIT 1"
);
$stmt->bind_param('ii', $studentId, $materialId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();

echo json_encode([
    'success'=>true,
    'status'=>'success',
    'material_id'=>$materialId,
    'study_completed'=>($row && ($row['status'] ?? '') === 'completed') ? 1 : 0,
    'currently_studying'=>$row ? (int)$row['currently_studying'] : 0,
    'session_status'=>$row['status'] ?? 'not_started',
    'total_seconds'=>$row ? (int)$row['total_seconds'] : 0,
    'started_at'=>$row['started_at'] ?? null,
    'last_heartbeat_at'=>$row['last_heartbeat_at'] ?? null,
    'completed_at'=>$row['completed_at'] ?? null
], JSON_UNESCAPED_UNICODE);
