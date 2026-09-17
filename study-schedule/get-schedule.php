<?php
require_once __DIR__ . '/../_shared/db.php';
brainpal_cors('GET, OPTIONS');
$conn = brainpal_db();

$studentId = (int)($_GET['student_id'] ?? 0);
if ($studentId <= 0) {
    echo json_encode(['status'=>'error','success'=>false,'message'=>'Invalid student_id.','data'=>[]]);
    $conn->close();
    exit;
}

$stmt = $conn->prepare(
    'SELECT
        ss.schedule_id,
        ss.student_id,
        ss.subject_id,
        s.subject_name,
        ss.scheduled_day,
        DATE_FORMAT(ss.scheduled_day, "%M %e, %Y") AS formatted_date,
        DAYNAME(ss.scheduled_day) AS day,
        ss.start_time,
        ss.duration_minutes,
        ss.ranking,
        ss.status,
        ss.ai_generated,
        ss.academic_id,
        ss.result_id,
        ss.date_created,
        lm.material_id,
        lm.title AS material_title,
        CASE WHEN lm.material_id IS NULL THEN 0 ELSE 1 END AS has_material
     FROM study_schedule ss
     LEFT JOIN subject s ON s.subject_id = ss.subject_id
     LEFT JOIN learning_material lm
       ON lm.material_id = (
          SELECT MIN(lm2.material_id)
          FROM learning_material lm2
          WHERE lm2.schedule_id = ss.schedule_id
            AND lm2.date_deleted IS NULL
       )
     WHERE ss.student_id = ?
       AND ss.date_deleted IS NULL
       AND ss.status = \'scheduled\'
     ORDER BY ss.scheduled_day ASC, ss.start_time ASC, ss.ranking ASC, ss.schedule_id ASC'
);
$stmt->bind_param('i', $studentId);
$stmt->execute();
$result = $stmt->get_result();
$data = [];
while ($row = $result->fetch_assoc()) {
    $row['schedule_id'] = (int)$row['schedule_id'];
    $row['student_id'] = (int)$row['student_id'];
    $row['subject_id'] = $row['subject_id'] !== null ? (int)$row['subject_id'] : null;
    $row['duration_minutes'] = (int)($row['duration_minutes'] ?? 0);
    $row['ranking'] = (int)($row['ranking'] ?? 0);
    $row['ai_generated'] = (int)($row['ai_generated'] ?? 0);
    $row['academic_id'] = $row['academic_id'] !== null ? (int)$row['academic_id'] : null;
    $row['result_id'] = $row['result_id'] !== null ? (int)$row['result_id'] : null;
    $row['material_id'] = $row['material_id'] !== null ? (int)$row['material_id'] : null;
    $row['has_material'] = (int)$row['has_material'];
    $data[] = $row;
}
$stmt->close();
$conn->close();

echo json_encode([
    'status' => 'success',
    'success' => true,
    'data' => $data,
    'total' => count($data),
    'weekly' => true
], JSON_UNESCAPED_UNICODE);
