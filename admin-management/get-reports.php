<?php
declare(strict_types=1);

require_once __DIR__ . '/../_shared/db.php';
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$bpCorsOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
$bpCorsAllowedOrigins = [
    'http://localhost:8100',
    'http://127.0.0.1:8100',
    'http://localhost',
    'https://localhost',
    'capacitor://localhost',
];

if (in_array($bpCorsOrigin, $bpCorsAllowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $bpCorsOrigin);
}
header('Vary: Origin');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function respond(bool $success, string $message = '', array $extra = [], int $code = 200): void {
    http_response_code($code);
    echo json_encode(
        array_merge([
            'success' => $success,
            'message' => $message
        ], $extra),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

$conn = brainpal_db();

if ($conn->connect_error) {
    respond(false, 'Database connection failed.', [], 500);
}

$conn->set_charset('utf8mb4');

$adminId = intval($_GET['admin_id'] ?? 0);

if ($adminId <= 0) {
    respond(false, 'Invalid admin_id.', [], 401);
}

/*
|--------------------------------------------------------------------------
| NEVER TRUST THE ROLE SENT BY THE FRONTEND.
| The role is always read from the active admin record.
|--------------------------------------------------------------------------
*/
$stmt = $conn->prepare(
    'SELECT admin_id, fullname, role, account_status
     FROM admin
     WHERE admin_id = ?
       AND date_deleted IS NULL
       AND account_status = "active"
     LIMIT 1'
);

if (!$stmt) {
    respond(false, 'Admin validation failed.', [], 500);
}

$stmt->bind_param('i', $adminId);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$admin) {
    respond(false, 'Admin account is not active or does not exist.', [], 403);
}

$role = strtolower(trim((string)$admin['role']));

$roleReports = [
    'super_admin' => [
        'summary', 'students', 'subjects', 'quizzes',
        'content', 'diagnostics', 'activity'
    ],

    // Student Admin: only the student/curriculum areas assigned to this role.
    'student_admin' => [
        'students', 'subjects'
    ],

    // Content Admin: only learning content and assessment-content areas.
    'content_admin' => [
        'quizzes', 'content', 'diagnostics'
    ],

    // No management scope is assigned to the generic Admin role.
    'admin' => []
];

if (!isset($roleReports[$role])) {
    respond(false, 'Invalid admin role.', [], 403);
}

$allowedReports = $roleReports[$role];

$startDate = trim((string)($_GET['start_date'] ?? ''));
$endDate   = trim((string)($_GET['end_date'] ?? ''));

function valid_date_or_empty(string $date): bool {
    if ($date === '') return true;
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

if (!valid_date_or_empty($startDate) || !valid_date_or_empty($endDate)) {
    respond(false, 'Invalid date filter. Use YYYY-MM-DD.', [], 400);
}

if ($startDate !== '' && $endDate !== '' && $startDate > $endDate) {
    respond(false, 'Start date cannot be later than end date.', [], 400);
}

$dateFilter = function(string $column) use ($startDate, $endDate): string {
    $parts = [];

    if ($startDate !== '') {
        $parts[] = $column . " >= '" . $GLOBALS['conn']->real_escape_string($startDate) . " 00:00:00'";
    }

    if ($endDate !== '') {
        $parts[] = $column . " <= '" . $GLOBALS['conn']->real_escape_string($endDate) . " 23:59:59'";
    }

    return $parts ? ' AND ' . implode(' AND ', $parts) : '';
};

function query_rows(BrainPalDb $conn, string $sql): array {
    $result = $conn->query($sql);
    if (!$result) return [];

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    return $rows;
}

function query_one(BrainPalDb $conn, string $sql): array {
    $result = $conn->query($sql);
    if (!$result) return [];
    return $result->fetch_assoc() ?: [];
}

function has_report(string $report, array $allowed): bool {
    return in_array($report, $allowed, true);
}

$summary = [
    'students' => 0,
    'subjects' => 0,
    'quizzes' => 0,
    'attempts' => 0,
    'materials' => 0,
    'diagnostic_questions' => 0
];

/*
|--------------------------------------------------------------------------
| SUMMARY COUNTS
|--------------------------------------------------------------------------
*/
if (has_report('summary', $allowedReports) ||
    has_report('students', $allowedReports) ||
    has_report('subjects', $allowedReports) ||
    has_report('quizzes', $allowedReports) ||
    has_report('content', $allowedReports) ||
    has_report('diagnostics', $allowedReports)) {

    $summary['students'] = intval(
        query_one(
            $conn,
            "SELECT COUNT(*) total
             FROM student
             WHERE date_deleted IS NULL
             {$dateFilter('date_created')}"
        )['total'] ?? 0
    );

    $summary['subjects'] = intval(
        query_one(
            $conn,
            "SELECT COUNT(*) total
             FROM subject
             WHERE date_deleted IS NULL
             {$dateFilter('date_created')}"
        )['total'] ?? 0
    );

    $summary['quizzes'] = intval(
        query_one(
            $conn,
            "SELECT COUNT(*) total
             FROM quiz
             WHERE 1=1
             {$dateFilter('date_created')}"
        )['total'] ?? 0
    );

    $summary['attempts'] = intval(
        query_one(
            $conn,
            "SELECT COUNT(*) total
             FROM quiz_attempt
             WHERE 1=1
             {$dateFilter('date_created')}"
        )['total'] ?? 0
    );

    $summary['materials'] = intval(
        query_one(
            $conn,
            "SELECT COUNT(*) total
             FROM learning_material
             WHERE date_deleted IS NULL
             {$dateFilter('date_created')}"
        )['total'] ?? 0
    );

    $summary['diagnostic_questions'] = intval(
        query_one(
            $conn,
            "SELECT COUNT(*) total
             FROM diagnostic
             WHERE date_deleted IS NULL
             {$dateFilter('date_created')}"
        )['total'] ?? 0
    );
}

/*
|--------------------------------------------------------------------------
| STUDENT REPORT
|--------------------------------------------------------------------------
*/
$studentsData = [];

if (has_report('students', $allowedReports)) {
    $studentsData = query_rows(
        $conn,
        "SELECT
            s.student_id,
            COALESCE(s.studentNo, '') studentNo,
            TRIM(CONCAT(
                s.firstName, ' ',
                COALESCE(NULLIF(s.m_initial, ''), ''), ' ',
                s.lastName,
                CASE
                    WHEN s.extension IS NOT NULL AND s.extension <> ''
                    THEN CONCAT(' ', s.extension)
                    ELSE ''
                END
            )) name,
            s.email,
            s.gender,
            s.age,
            s.grade_level,
            COALESCE(st.strand_name, 'N/A') strand,
            COALESCE(sp.specialization_name, 'N/A') specialization,
            COALESCE(s.points, 0) points,
            COALESCE(s.diagnostic_completed, 0) diagnostic_completed,
            COUNT(DISTINCT qa.attempt_id) quiz_attempts,
            COALESCE(AVG(qa.score), 0) average_quiz_score
         FROM student s
         LEFT JOIN strand st ON st.strand_id = s.strand_id
         LEFT JOIN specialization sp ON sp.specialization_id = s.specialization_id
         LEFT JOIN quiz_attempt qa
           ON qa.student_id = s.student_id
          {$dateFilter('qa.date_created')}
         WHERE s.date_deleted IS NULL
         GROUP BY
            s.student_id, s.studentNo, s.firstName, s.m_initial,
            s.lastName, s.extension, s.email, s.gender, s.age,
            s.grade_level, st.strand_name, sp.specialization_name,
            s.points, s.diagnostic_completed
         ORDER BY s.lastName ASC, s.firstName ASC"
    );
}

/*
|--------------------------------------------------------------------------
| SUBJECT REPORT
|--------------------------------------------------------------------------
*/
$subjects = [];

if (has_report('subjects', $allowedReports) ||
    has_report('summary', $allowedReports)) {

    $subjects = query_rows(
        $conn,
        "SELECT
            s.subject_id,
            s.subject_name name,
            COUNT(DISTINCT st.student_id) students,
            COUNT(DISTINCT lm.material_id) materials,
            COUNT(DISTINCT q.quiz_id) quiz_count,
            COUNT(DISTINCT qa.attempt_id) attempts,
            COALESCE(AVG(qa.score), 0) average_score
         FROM subject s
         LEFT JOIN student st
           ON st.strand_id = s.strand_id
          AND (
                s.specialization_id IS NULL
                OR s.specialization_id = 0
                OR s.specialization_id = st.specialization_id
              )
          AND st.date_deleted IS NULL
         LEFT JOIN learning_material lm
           ON lm.subject_id = s.subject_id
          AND lm.date_deleted IS NULL
         LEFT JOIN quiz q
           ON q.material_id = lm.material_id
         LEFT JOIN quiz_attempt qa
           ON qa.quiz_id = q.quiz_id
          {$dateFilter('qa.date_created')}
         WHERE s.date_deleted IS NULL
         GROUP BY s.subject_id, s.subject_name
         ORDER BY s.subject_name ASC"
    );
}

/*
|--------------------------------------------------------------------------
| QUIZ REPORT
|--------------------------------------------------------------------------
*/
$quizzesData = [];

if (has_report('quizzes', $allowedReports)) {
    $quizzesData = query_rows(
        $conn,
        "SELECT
            q.quiz_id,
            q.quiz_title,
            q.quiz_type,
            q.difficulty,
            COALESCE(s.subject_name, 'N/A') subject_name,
            COUNT(qa.attempt_id) attempts,
            COALESCE(AVG(qa.score), 0) average_score,
            COALESCE(MAX(qa.score), 0) highest_score
         FROM quiz q
         LEFT JOIN learning_material lm
           ON lm.material_id = q.material_id
         LEFT JOIN subject s
           ON s.subject_id = lm.subject_id
         LEFT JOIN quiz_attempt qa
           ON qa.quiz_id = q.quiz_id
          {$dateFilter('qa.date_created')}
         GROUP BY
            q.quiz_id, q.quiz_title, q.quiz_type,
            q.difficulty, s.subject_name
         ORDER BY q.date_created DESC"
    );
}

/*
|--------------------------------------------------------------------------
| CONTENT REPORT
|--------------------------------------------------------------------------
*/
$contentData = null;

if (has_report('content', $allowedReports)) {
    $contentData = [
        'total_materials' => intval(
            query_one(
                $conn,
                "SELECT COUNT(*) total
                 FROM learning_material
                 WHERE date_deleted IS NULL
                 {$dateFilter('date_created')}"
            )['total'] ?? 0
        ),
        'approved_materials' => intval(
            query_one(
                $conn,
                "SELECT COUNT(*) total
                 FROM learning_material
                 WHERE date_deleted IS NULL
                   AND LOWER(approval_status) = 'approved'
                   {$dateFilter('date_created')}"
            )['total'] ?? 0
        ),
        'pending_materials' => intval(
            query_one(
                $conn,
                "SELECT COUNT(*) total
                 FROM learning_material
                 WHERE date_deleted IS NULL
                   AND LOWER(approval_status) = 'pending'
                   {$dateFilter('date_created')}"
            )['total'] ?? 0
        ),
        'rejected_materials' => intval(
            query_one(
                $conn,
                "SELECT COUNT(*) total
                 FROM learning_material
                 WHERE date_deleted IS NULL
                   AND LOWER(approval_status) = 'rejected'
                   {$dateFilter('date_created')}"
            )['total'] ?? 0
        ),
        'total_quizzes' => intval(
            query_one(
                $conn,
                "SELECT COUNT(*) total
                 FROM quiz
                 WHERE 1=1
                 {$dateFilter('date_created')}"
            )['total'] ?? 0
        ),
        'approved_quizzes' => intval(
            query_one(
                $conn,
                "SELECT COUNT(*) total
                 FROM quiz
                 WHERE LOWER(approval_status) = 'approved'
                 {$dateFilter('date_created')}"
            )['total'] ?? 0
        ),
        'pending_quizzes' => intval(
            query_one(
                $conn,
                "SELECT COUNT(*) total
                 FROM quiz
                 WHERE LOWER(approval_status) = 'pending'
                 {$dateFilter('date_created')}"
            )['total'] ?? 0
        ),
        'rejected_quizzes' => intval(
            query_one(
                $conn,
                "SELECT COUNT(*) total
                 FROM quiz
                 WHERE LOWER(approval_status) = 'rejected'
                 {$dateFilter('date_created')}"
            )['total'] ?? 0
        ),
        'material_types' => query_rows(
            $conn,
            "SELECT
                COALESCE(NULLIF(type, ''), 'Unknown') type,
                COUNT(*) total
             FROM learning_material
             WHERE date_deleted IS NULL
             {$dateFilter('date_created')}
             GROUP BY type
             ORDER BY total DESC"
        )
    ];
}

/*
|--------------------------------------------------------------------------
| DIAGNOSTIC REPORT
|--------------------------------------------------------------------------
*/
$diagnosticsData = [];

if (has_report('diagnostics', $allowedReports)) {
    $diagnosticsData = query_rows(
        $conn,
        "SELECT
            COALESCE(s.subject_name, 'N/A') subject_name,
            COUNT(DISTINCT d.diagnostic_id) questions,
            COUNT(DISTINCT dr.student_id) students_tested,
            COALESCE(
                AVG(
                    CASE
                        WHEN dr.total_questions > 0
                        THEN (dr.total_score / dr.total_questions) * 100
                        ELSE NULL
                    END
                ),
                0
            ) average_score,
            COUNT(DISTINCT dr.result_id) results
         FROM diagnostic d
         LEFT JOIN subject s ON s.subject_id = d.subject_id
         LEFT JOIN diagnostic_result dr
           ON dr.diagnostic_id = d.diagnostic_id
          AND dr.date_deleted IS NULL
          {$dateFilter('dr.date_taken')}
         WHERE d.date_deleted IS NULL
         GROUP BY s.subject_id, s.subject_name
         ORDER BY s.subject_name ASC"
    );
}

/*
|--------------------------------------------------------------------------
| ADMIN ACTIVITY REPORT
|--------------------------------------------------------------------------
*/
$activityData = [];

if (has_report('activity', $allowedReports)) {
    $activityData = query_rows(
        $conn,
        "SELECT
            al.log_id,
            COALESCE(a.fullname, 'Unknown Admin') admin_name,
            al.action,
            al.description,
            al.date_created
         FROM activity_logs al
         LEFT JOIN admin a ON a.admin_id = al.admin_id
         WHERE 1=1
         {$dateFilter('al.date_created')}
         ORDER BY al.date_created DESC"
    );
}

respond(true, 'Reports loaded successfully.', [
    'role' => $role,
    'role_label' => (
        $role === 'super_admin' ? 'Super Admin' :
        ($role === 'student_admin' ? 'Student Admin' :
        ($role === 'content_admin' ? 'Content Admin' : 'Admin'))
    ),
    'allowed_reports' => $allowedReports,
    'filters' => [
        'start_date' => $startDate,
        'end_date' => $endDate
    ],
    'summary' => $summary,
    'students_data' => $studentsData,
    'subjects' => $subjects,
    'quizzes_data' => $quizzesData,
    'content_data' => $contentData,
    'diagnostics_data' => $diagnosticsData,
    'activity_data' => $activityData
]);

$conn->close();
?>
