<?php
require_once __DIR__ . '/../_shared/db.php';
brainpal_cors('GET, OPTIONS');
$conn = brainpal_db();

$studentId = (int)($_GET['student_id'] ?? 0);
$scheduleId = (int)($_GET['schedule_id'] ?? 0);

if ($studentId <= 0 || $scheduleId <= 0) {
    echo json_encode([
        'status' => 'error',
        'success' => false,
        'has_material' => false,
        'message' => 'Invalid student_id or schedule_id.'
    ]);
    $conn->close();
    exit;
}

$stmt = $conn->prepare(
    'SELECT lm.material_id, lm.title
     FROM learning_material lm
     INNER JOIN study_schedule ss ON ss.schedule_id = lm.schedule_id
     WHERE lm.schedule_id = ?
       AND ss.student_id = ?
       AND lm.date_deleted IS NULL
       AND lm.approval_status = \'approved\'
       AND ss.status = \'scheduled\'
     LIMIT 1'
);
$stmt->bind_param('ii', $scheduleId, $studentId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();

echo json_encode([
    'status' => 'success',
    'success' => true,
    'has_material' => $row ? 1 : 0,
    'material_id' => $row ? (int)$row['material_id'] : null,
    'title' => $row['title'] ?? null
], JSON_UNESCAPED_UNICODE);
