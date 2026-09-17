<?php
require_once __DIR__ . '/../_shared/db.php';

require_once __DIR__ . '/../_shared/admin-auth.php';

bp_cors('GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$adminId = (int)($_GET['admin_id'] ?? 0);

bp_require_role(
    ['admin_id' => $adminId],
    ['super_admin', 'student_admin', 'content_admin', 'admin']
);

$c = bp_admin_conn();

if (!$c) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database connection failed.'
    ]);
    exit;
}

function bp_count(BrainPalDb $c, string $sql, string $field): int
{
    $result = $c->query($sql);

    if (!$result) {
        return 0;
    }

    $row = $result->fetch_assoc();
    return (int)($row[$field] ?? 0);
}

$totalUsers = bp_count(
    $c,
    "SELECT
        (
            (SELECT COUNT(*) FROM student WHERE date_deleted IS NULL)
            +
            (SELECT COUNT(*) FROM admin WHERE date_deleted IS NULL)
        ) AS totalUsers",
    'totalUsers'
);

$totalStudents = bp_count(
    $c,
    "SELECT COUNT(*) AS totalStudents
     FROM student
     WHERE date_deleted IS NULL",
    'totalStudents'
);

$totalSubjects = bp_count(
    $c,
    "SELECT COUNT(*) AS totalSubjects
     FROM subject
     WHERE date_deleted IS NULL",
    'totalSubjects'
);

$totalQuizzes = bp_count(
    $c,
    "SELECT COUNT(*) AS totalQuizzes
     FROM quiz",
    'totalQuizzes'
);

$totalStrands = bp_count(
    $c,
    "SELECT COUNT(*) AS totalStrands
     FROM strand
     WHERE date_deleted IS NULL",
    'totalStrands'
);

$totalSpecializations = bp_count(
    $c,
    "SELECT COUNT(*) AS totalSpecializations
     FROM specialization
     WHERE date_deleted IS NULL",
    'totalSpecializations'
);

/* =====================================================
   TOTAL ACADEMIC YEARS
   Only non-deleted records are counted.
===================================================== */
$totalAcademicYears = bp_count(
    $c,
    "SELECT COUNT(*) AS totalAcademicYears
     FROM academic
     WHERE date_deleted IS NULL",
    'totalAcademicYears'
);


$totalDiagnosticQuestions = bp_count(
    $c,
    "SELECT COUNT(*) AS totalDiagnosticQuestions
     FROM diagnostic
     WHERE date_deleted IS NULL",
    'totalDiagnosticQuestions'
);

/* =====================================================
   ACTIVE STUDENTS / SYSTEM USAGE
===================================================== */
$activeStudents = bp_count(
    $c,
    "SELECT COUNT(DISTINCT student_id) AS activeStudents
     FROM (
        SELECT student_id
        FROM student
        WHERE date_deleted IS NULL
          AND last_open_date >= CURDATE() - INTERVAL 30 DAY

        UNION

        SELECT student_id
        FROM quiz_attempt
        WHERE date_created >= NOW() - INTERVAL 30 DAY

        UNION

        SELECT student_id
        FROM diagnostic_result
        WHERE date_taken >= NOW() - INTERVAL 30 DAY
          AND date_deleted IS NULL
     ) AS active_users",
    'activeStudents'
);

$systemUsage = $totalStudents > 0
    ? round(($activeStudents / $totalStudents) * 100)
    : 0;

$systemUsage = max(0, min(100, $systemUsage));

echo json_encode([
    'status' => 'success',
    'totalUsers' => $totalUsers,
    'totalStudents' => $totalStudents,
    'totalSubjects' => $totalSubjects,
    'totalQuizzes' => $totalQuizzes,
    'totalStrands' => $totalStrands,
    'totalSpecializations' => $totalSpecializations,
    'totalAcademicYears' => $totalAcademicYears,
    'totalDiagnosticQuestions' => $totalDiagnosticQuestions,
    'activeStudents' => $activeStudents,
    'systemUsage' => $systemUsage
]);

$c->close();
