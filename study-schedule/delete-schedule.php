<?php
require_once __DIR__ . '/../_shared/db.php';
brainpal_cors('POST, OPTIONS');
$conn = brainpal_db();
$data = brainpal_json_input();
$studentId = (int)($data['student_id'] ?? 0);

if ($studentId <= 0) {
    echo json_encode(['status'=>'error','success'=>false,'message'=>'Invalid student_id.']);
    $conn->close();
    exit;
}

$stmt = $conn->prepare(
    'UPDATE study_schedule
     SET date_deleted = NOW(), status = "deleted"
     WHERE student_id = ? AND date_deleted IS NULL'
);
$stmt->bind_param('i', $studentId);
$ok = $stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();
$conn->close();

echo json_encode([
    'status' => $ok ? 'success' : 'error',
    'success' => $ok,
    'deleted' => $affected,
    'message' => $ok ? 'Old study schedule cleared.' : 'Unable to clear the old study schedule.'
]);
