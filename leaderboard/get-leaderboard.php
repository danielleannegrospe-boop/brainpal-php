<?php
require_once __DIR__ . '/../_shared/db.php';

error_reporting(E_ALL);
ini_set('display_errors', 0);

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
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$conn = brainpal_db();

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database connection failed."
    ]);
    exit();
}

$conn->set_charset("utf8mb4");

$studentId = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;
$scope = $_GET['scope'] ?? 'overall';

if ($studentId <= 0) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Invalid student ID."
    ]);
    exit();
}

if (!in_array($scope, ['overall', 'strand', 'grade'], true)) {
    $scope = 'overall';
}

/*
|--------------------------------------------------------------------------
| Make sure the participation column exists.
| Run leaderboard_participation.sql once in phpMyAdmin instead of relying
| on this endpoint to alter the database.
|--------------------------------------------------------------------------
*/

$userSql = "
    SELECT
        s.student_id,
        s.firstName,
        s.lastName,
        s.points,
        s.strand_id,
        s.grade_level,
        st.strand_name,
        COALESCE(s.leaderboard_visible, 1) AS leaderboard_visible
    FROM student s
    LEFT JOIN strand st
        ON st.strand_id = s.strand_id
        AND st.date_deleted IS NULL
    WHERE s.student_id = ?
      AND s.date_deleted IS NULL
    LIMIT 1
";

$stmt = $conn->prepare($userSql);
$stmt->bind_param("i", $studentId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    http_response_code(404);
    echo json_encode([
        "success" => false,
        "message" => "Student not found."
    ]);
    exit();
}

$visible = intval($user['leaderboard_visible']) === 1;

if (!$visible) {
    echo json_encode([
        "success" => true,
        "participating" => false,
        "scope" => $scope,
        "students" => [],
        "current_student" => [
            "student_id" => $studentId,
            "xp" => intval($user['points'] ?? 0),
            "name" => trim(($user['firstName'] ?? '') . ' ' . ($user['lastName'] ?? ''))
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

$where = "
    s.date_deleted IS NULL
    AND COALESCE(s.is_verified, 0) = 1
    AND COALESCE(s.leaderboard_visible, 1) = 1
";

$params = [];
$types = "";

if ($scope === 'strand') {
    $where .= " AND s.strand_id = ? AND s.grade_level = ?";
    $params[] = intval($user['strand_id']);
    $params[] = intval($user['grade_level']);
    $types .= "ii";
} elseif ($scope === 'grade') {
    $where .= " AND s.grade_level = ?";
    $params[] = intval($user['grade_level']);
    $types .= "i";
}

$sql = "
    SELECT
        s.student_id,
        CONCAT(
            s.firstName,
            CASE
                WHEN s.m_initial IS NOT NULL AND TRIM(s.m_initial) <> ''
                THEN CONCAT(' ', s.m_initial, '.')
                ELSE ''
            END,
            ' ',
            s.lastName
        ) AS name,
        COALESCE(st.strand_name, 'N/A') AS strand,
        COALESCE(s.grade_level, 0) AS grade_level,
        COALESCE(s.points, 0) AS xp
    FROM student s
    LEFT JOIN strand st
        ON st.strand_id = s.strand_id
        AND st.date_deleted IS NULL
    WHERE $where
    ORDER BY COALESCE(s.points, 0) DESC,
             s.lastName ASC,
             s.firstName ASC
";

$stmt = $conn->prepare($sql);

if ($types !== "") {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

$rows = [];
$rank = 0;
$previousXp = null;
$position = 0;

while ($row = $result->fetch_assoc()) {
    $position++;
    $xp = intval($row['xp']);

    if ($previousXp === null || $xp < $previousXp) {
        $rank = $position;
    }

    $previousXp = $xp;

    $rows[] = [
        "rank" => $rank,
        "student_id" => intval($row['student_id']),
        "name" => trim($row['name']),
        "strand" => $row['strand'],
        "grade_level" => intval($row['grade_level']),
        "xp" => $xp,
        "level" => min(4, max(1, floor($xp / 100) + 1)),
        "medal" => $rank === 1 ? "🥇" : ($rank === 2 ? "🥈" : ($rank === 3 ? "🥉" : "")),
        "is_current_user" => intval($row['student_id']) === $studentId
    ];
}

$stmt->close();

$current = null;
foreach ($rows as $row) {
    if ($row['is_current_user']) {
        $current = $row;
        break;
    }
}

echo json_encode([
    "success" => true,
    "participating" => true,
    "scope" => $scope,
    "students" => $rows,
    "current_student" => $current
], JSON_UNESCAPED_UNICODE);

$conn->close();
?>
