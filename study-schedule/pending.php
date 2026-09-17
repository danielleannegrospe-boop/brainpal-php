<?php
require_once __DIR__ . '/../_shared/db.php';

brainpal_cors('GET, OPTIONS');
$conn = brainpal_db();

$studentId = (int)($_GET['student_id'] ?? 0);

if ($studentId <= 0) {
    echo json_encode([
        'status' => 'error',
        'success' => false,
        'message' => 'Invalid student_id.',
        'data' => []
    ]);
    $conn->close();
    exit;
}

$conn->query(
    'CREATE TABLE IF NOT EXISTS study_schedule_pending (
        pending_id INT NOT NULL AUTO_INCREMENT,
        student_id INT NOT NULL,
        subject_id INT NOT NULL,
        last_missed_week_start DATE NOT NULL,
        carry_count INT NOT NULL DEFAULT 1,
        date_created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        date_updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (pending_id),
        UNIQUE KEY uq_pending_student_subject (student_id, subject_id),
        KEY idx_pending_student (student_id),
        KEY idx_pending_week (last_missed_week_start)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
);

$stmt = $conn->prepare(
    'SELECT
        p.subject_id,
        s.subject_name,
        p.carry_count,
        p.last_missed_week_start,
        COALESCE(ps.average_score, 100) AS percentage
     FROM study_schedule_pending p
     INNER JOIN subject s ON s.subject_id = p.subject_id
     LEFT JOIN progress_summary ps
       ON ps.student_id = p.student_id
      AND ps.subject_id = p.subject_id
     WHERE p.student_id = ?
       AND s.date_deleted IS NULL
     ORDER BY p.carry_count DESC, p.date_updated ASC'
);

if (!$stmt) {
    echo json_encode([
        'status' => 'error',
        'success' => false,
        'message' => 'Unable to load pending subjects.',
        'data' => []
    ]);
    $conn->close();
    exit;
}

$stmt->bind_param('i', $studentId);
$stmt->execute();
$result = $stmt->get_result();
$data = [];

while ($row = $result->fetch_assoc()) {
    $percentage = (float)($row['percentage'] ?? 100);
    $priority = $percentage < 60 ? 'High' : ($percentage < 80 ? 'Medium' : 'Low');

    $data[] = [
        'subject_id' => (int)$row['subject_id'],
        'subject_name' => $row['subject_name'],
        'percentage' => $percentage,
        'priority' => $priority,
        'carry_count' => (int)$row['carry_count'],
        'last_missed_week_start' => $row['last_missed_week_start']
    ];
}

$stmt->close();
$conn->close();

echo json_encode([
    'status' => 'success',
    'success' => true,
    'data' => $data,
    'total' => count($data)
], JSON_UNESCAPED_UNICODE);
