<?php
require_once __DIR__ . '/../_shared/db.php';

header("Content-Type: application/json; charset=UTF-8");

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
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");


/*
|--------------------------------------------------------------------------
| OPTIONS
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {

    http_response_code(200);

    echo json_encode([
        "success" => true
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| ONLY GET
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] !== "GET") {

    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Only GET requests are allowed.",
        "points" => 0
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| DATABASE
|--------------------------------------------------------------------------
*/

$conn = brainpal_db();


if ($conn->connect_error) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database connection failed.",
        "error" => $conn->connect_error,
        "points" => 0
    ]);

    exit;
}


$conn->set_charset("utf8mb4");


/*
|--------------------------------------------------------------------------
| GET PARAMETERS
|--------------------------------------------------------------------------
*/

$student_id = intval(
    $_GET["student_id"] ?? 0
);

$studentNo = trim(
    (string)(
        $_GET["studentNo"] ??
        $_GET["student_no"] ??
        ""
    )
);


/*
|--------------------------------------------------------------------------
| FIND STUDENT
|--------------------------------------------------------------------------
*/

$user = null;


/*
|--------------------------------------------------------------------------
| OPTION 1: STUDENT ID
|--------------------------------------------------------------------------
*/

if ($student_id > 0) {

    $stmt = $conn->prepare("
        SELECT *
        FROM student
        WHERE student_id = ?
          AND date_deleted IS NULL
        LIMIT 1
    ");

    if (!$stmt) {

        http_response_code(500);

        echo json_encode([
            "success" => false,
            "message" => "Failed to prepare student query.",
            "error" => $conn->error,
            "points" => 0
        ]);

        $conn->close();
        exit;
    }

    $stmt->bind_param(
        "i",
        $student_id
    );

    if (!$stmt->execute()) {

        http_response_code(500);

        echo json_encode([
            "success" => false,
            "message" => "Failed to execute student query.",
            "error" => $stmt->error,
            "points" => 0
        ]);

        $stmt->close();
        $conn->close();
        exit;
    }

    $result = $stmt->get_result();

    $user = $result->fetch_assoc();

    $stmt->close();
}


/*
|--------------------------------------------------------------------------
| OPTION 2: STUDENT NUMBER
|--------------------------------------------------------------------------
*/

if (!$user && $studentNo !== "") {

    $stmt = $conn->prepare("
        SELECT *
        FROM student
        WHERE studentNo = ?
          AND date_deleted IS NULL
        LIMIT 1
    ");

    if (!$stmt) {

        http_response_code(500);

        echo json_encode([
            "success" => false,
            "message" => "Failed to prepare student number query.",
            "error" => $conn->error,
            "points" => 0
        ]);

        $conn->close();
        exit;
    }

    $stmt->bind_param(
        "s",
        $studentNo
    );

    if (!$stmt->execute()) {

        http_response_code(500);

        echo json_encode([
            "success" => false,
            "message" => "Failed to execute student number query.",
            "error" => $stmt->error,
            "points" => 0
        ]);

        $stmt->close();
        $conn->close();
        exit;
    }

    $result = $stmt->get_result();

    $user = $result->fetch_assoc();

    $stmt->close();
}


/*
|--------------------------------------------------------------------------
| NO STUDENT FOUND
|--------------------------------------------------------------------------
*/

if (!$user) {

    if (
        $student_id <= 0 &&
        $studentNo === ""
    ) {

        http_response_code(400);

        echo json_encode([
            "success" => false,
            "message" =>
                "No valid student_id or studentNo was provided.",
            "received" => [
                "student_id" => $student_id,
                "studentNo" => $studentNo
            ],
            "points" => 0
        ]);

        $conn->close();
        exit;
    }


    http_response_code(404);

    echo json_encode([
        "success" => false,
        "message" => "Student not found.",
        "received" => [
            "student_id" => $student_id,
            "studentNo" => $studentNo
        ],
        "points" => 0
    ]);

    $conn->close();
    exit;
}


/*
|--------------------------------------------------------------------------
| ACTUAL STUDENT ID
|--------------------------------------------------------------------------
*/

$actualStudentId = intval(
    $user["student_id"]
);


/*
|--------------------------------------------------------------------------
| DIAGNOSTIC POINTS
|--------------------------------------------------------------------------
|
| These are the points already awarded by the diagnostic.
|
*/

$diagnosticPoints = 0;

$pointsStmt = $conn->prepare("
    SELECT
        COALESCE(
            SUM(points_awarded),
            0
        ) AS total_points
    FROM diagnostic_result
    WHERE student_id = ?
      AND date_deleted IS NULL
");


if (!$pointsStmt) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare diagnostic points query.",
        "error" => $conn->error,
        "points" => 0
    ]);

    $conn->close();
    exit;
}


$pointsStmt->bind_param(
    "i",
    $actualStudentId
);


if (!$pointsStmt->execute()) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to calculate diagnostic points.",
        "error" => $pointsStmt->error,
        "points" => 0
    ]);

    $pointsStmt->close();
    $conn->close();
    exit;
}


$pointsResult =
    $pointsStmt->get_result();


$pointsRow =
    $pointsResult->fetch_assoc();


$diagnosticPoints =
    intval(
        $pointsRow["total_points"] ?? 0
    );


$pointsStmt->close();


/*
|--------------------------------------------------------------------------
| QUIZ POINTS
|--------------------------------------------------------------------------
|
| IMPORTANT:
|
| quiz_attempt.score is a PERCENTAGE.
|
| Example:
|   10/10 points = score 100
|
| Therefore DO NOT add quiz_attempt.score
| to the student's points.
|
| The actual XP/points earned by each answer is stored in:
|
|   quiz_attempt_answers.points_earned
|
| Example:
|   10-point question answered correctly
|   = +10 points
|
| Two perfect 10-point quizzes
|   = +20 points
|
*/

$quizPoints = 0;


$quizPointsStmt = $conn->prepare("
    SELECT
        COALESCE(
            SUM(qaa.points_earned),
            0
        ) AS total_points
    FROM quiz_attempt_answers qaa

    INNER JOIN quiz_attempt qa
        ON qa.attempt_id = qaa.attempt_id

    WHERE qa.student_id = ?
");


if (!$quizPointsStmt) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare quiz points query.",
        "error" => $conn->error,
        "points" => $diagnosticPoints
    ]);

    $conn->close();
    exit;
}


$quizPointsStmt->bind_param(
    "i",
    $actualStudentId
);


if (!$quizPointsStmt->execute()) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to calculate quiz points.",
        "error" => $quizPointsStmt->error,
        "points" => $diagnosticPoints
    ]);

    $quizPointsStmt->close();
    $conn->close();
    exit;
}


$quizPointsResult =
    $quizPointsStmt->get_result();


$quizPointsRow =
    $quizPointsResult->fetch_assoc();


$quizPoints =
    intval(
        $quizPointsRow["total_points"] ?? 0
    );


$quizPointsStmt->close();


/*
|--------------------------------------------------------------------------
| TOTAL POINTS / XP
|--------------------------------------------------------------------------
|
| Total Points =
|
| Diagnostic Points
| +
| Quiz Points
|
*/

$totalPoints =
    $diagnosticPoints +
    $quizPoints;


/*
|--------------------------------------------------------------------------
| UPDATE USER OBJECT
|--------------------------------------------------------------------------
*/

$user["points"] =
    $totalPoints;


/*
|--------------------------------------------------------------------------
| RESPONSE
|--------------------------------------------------------------------------
*/

http_response_code(200);

echo json_encode([

    "success" =>
        true,

    "message" =>
        "User loaded successfully.",

    "student_id" =>
        $actualStudentId,

    "points" =>
        $totalPoints,

    "diagnostic_points" =>
        $diagnosticPoints,

    "quiz_points" =>
        $quizPoints,

    "user" =>
        $user

], JSON_UNESCAPED_UNICODE);


$conn->close();

exit;

?>