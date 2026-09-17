<?php
require_once __DIR__ . '/../_shared/db.php';

// =====================================================
// ERROR REPORTING
// =====================================================

error_reporting(E_ALL);
ini_set('display_errors', 0);


// =====================================================
// HEADERS
// =====================================================

header(
    "Access-Control-Allow-Origin: http://localhost:8100"
);

header(
    "Access-Control-Allow-Headers: Content-Type, Authorization"
);

header(
    "Access-Control-Allow-Methods: GET, POST, OPTIONS"
);

header(
    "Content-Type: application/json; charset=UTF-8"
);


// =====================================================
// PREFLIGHT
// =====================================================

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {

    http_response_code(200);

    echo json_encode([
        "status" => "success",
        "message" => "OK"
    ]);

    exit();

}


// =====================================================
// DATABASE CONNECTION
// =====================================================

$conn = brainpal_db();


// =====================================================
// DATABASE CHECK
// =====================================================

if ($conn->connect_error) {

    http_response_code(500);

    echo json_encode([

        "status" => "error",

        "message" =>
            "DB connection failed: " .
            $conn->connect_error,

        "xp" => 0,
        "diagnostic_points" => 0,
        "quiz_points" => 0

    ]);

    exit();

}


// =====================================================
// CHARACTER SET
// =====================================================

$conn->set_charset("utf8mb4");


// =====================================================
// USER ID
// =====================================================

$user_id = intval(
    $_GET['user_id'] ?? 0
);


// =====================================================
// VALIDATE USER ID
// =====================================================

if ($user_id <= 0) {

    http_response_code(400);

    echo json_encode([

        "status" => "error",

        "message" =>
            "Invalid or missing user_id.",

        "xp" => 0,
        "diagnostic_points" => 0,
        "quiz_points" => 0

    ]);

    $conn->close();

    exit();

}


// =====================================================
// DEFAULT VALUES
// =====================================================

$diagnostic_points = 0;
$quiz_points = 0;
$xp = 0;

$streak = 0;
$longest_streak = 0;
$total_opens = 0;

$quizzes = 0;
$badges = 0;

$highest_quiz_score = 0;

$quiz_completed = false;
$has_ninety_score = false;


// =====================================================
// GET STUDENT
// =====================================================

$stmt = $conn->prepare("

SELECT
    student_id,
    current_streak,
    longest_streak,
    total_opens,
    last_open_date
FROM student

    WHERE

        student_id = ?

        AND date_deleted IS NULL

    LIMIT 1

");


if (!$stmt) {

    http_response_code(500);

    echo json_encode([

        "status" => "error",

        "message" =>
            "Failed to prepare student query.",

        "error" =>
            $conn->error,

        "xp" => 0

    ]);

    $conn->close();

    exit();

}


$stmt->bind_param(
    "i",
    $user_id
);


if (!$stmt->execute()) {

    http_response_code(500);

    echo json_encode([

        "status" => "error",

        "message" =>
            "Failed to load student.",

        "error" =>
            $stmt->error,

        "xp" => 0

    ]);

    $stmt->close();

    $conn->close();

    exit();

}


$res = $stmt->get_result();


$student = $res->fetch_assoc();


$stmt->close();


// =====================================================
// STUDENT NOT FOUND
// =====================================================

if (!$student) {

    http_response_code(404);

    echo json_encode([

        "status" => "error",

        "message" =>
            "Student not found.",

        "xp" => 0

    ]);

    $conn->close();

    exit();

}


// =====================================================
// STUDENT ACTIVITY
// =====================================================

$streak = intval(
    $student["current_streak"] ?? 0
);


$longest_streak = intval(
    $student["longest_streak"] ?? 0
);


$total_opens = intval(
    $student["total_opens"] ?? 0
);

$last_open_date =
    $student["last_open_date"] ?? null;


// =====================================================
// DIAGNOSTIC XP
//
// SOURCE:
// diagnostic_result.points_awarded
// =====================================================

$diagnosticStmt = $conn->prepare("

    SELECT

        COALESCE(
            SUM(points_awarded),
            0
        ) AS diagnostic_points

    FROM diagnostic_result

    WHERE

        student_id = ?

        AND date_deleted IS NULL

");


if (!$diagnosticStmt) {

    http_response_code(500);

    echo json_encode([

        "status" => "error",

        "message" =>
            "Failed to prepare diagnostic query.",

        "error" =>
            $conn->error,

        "xp" => 0

    ]);

    $conn->close();

    exit();

}


$diagnosticStmt->bind_param(
    "i",
    $user_id
);


if (!$diagnosticStmt->execute()) {

    http_response_code(500);

    echo json_encode([

        "status" => "error",

        "message" =>
            "Failed to calculate diagnostic XP.",

        "error" =>
            $diagnosticStmt->error,

        "xp" => 0

    ]);

    $diagnosticStmt->close();

    $conn->close();

    exit();

}


$diagnosticResult =
    $diagnosticStmt->get_result();


$diagnosticRow =
    $diagnosticResult->fetch_assoc();


$diagnostic_points = intval(
    $diagnosticRow["diagnostic_points"] ?? 0
);


$diagnosticStmt->close();


// =====================================================
// QUIZ XP
//
// IMPORTANT:
//
// DO NOT USE:
// quiz_attempt.score
//
// score = percentage
//
// ACTUAL XP COMES FROM:
// quiz_attempt_answers.points_earned
// =====================================================

$quizPointsStmt = $conn->prepare("

    SELECT

        COALESCE(
            SUM(qaa.points_earned),
            0
        ) AS quiz_points

    FROM quiz_attempt_answers qaa

    INNER JOIN quiz_attempt qa

        ON qa.attempt_id =
           qaa.attempt_id

    WHERE

        qa.student_id = ?

");


if (!$quizPointsStmt) {

    http_response_code(500);

    echo json_encode([

        "status" => "error",

        "message" =>
            "Failed to prepare quiz points query.",

        "error" =>
            $conn->error,

        "xp" =>
            $diagnostic_points,

        "diagnostic_points" =>
            $diagnostic_points,

        "quiz_points" =>
            0

    ]);

    $conn->close();

    exit();

}


$quizPointsStmt->bind_param(
    "i",
    $user_id
);


if (!$quizPointsStmt->execute()) {

    http_response_code(500);

    echo json_encode([

        "status" => "error",

        "message" =>
            "Failed to calculate quiz XP.",

        "error" =>
            $quizPointsStmt->error,

        "xp" =>
            $diagnostic_points,

        "diagnostic_points" =>
            $diagnostic_points,

        "quiz_points" =>
            0

    ]);

    $quizPointsStmt->close();

    $conn->close();

    exit();

}


$quizPointsResult =
    $quizPointsStmt->get_result();


$quizPointsRow =
    $quizPointsResult->fetch_assoc();


$quiz_points = intval(
    $quizPointsRow["quiz_points"] ?? 0
);


$quizPointsStmt->close();


// =====================================================
// FINAL XP
//
// Diagnostic XP
// +
// Quiz XP
//
// = TOTAL XP
// =====================================================

$xp =
    $diagnostic_points +
    $quiz_points;


// =====================================================
// QUIZ STATISTICS
// =====================================================

$q = $conn->prepare("

    SELECT

        COUNT(*) AS total,

        COALESCE(
            MAX(score),
            0
        ) AS highest_score

    FROM quiz_attempt

    WHERE

        student_id = ?

");


if ($q) {

    $q->bind_param(
        "i",
        $user_id
    );


    if ($q->execute()) {

        $quizResult =
            $q
                ->get_result()
                ->fetch_assoc();


        $quizzes = intval(
            $quizResult["total"] ?? 0
        );


        $highest_quiz_score = floatval(
            $quizResult["highest_score"] ?? 0
        );


        $quiz_completed =
            $quizzes > 0;


        $has_ninety_score =
            $highest_quiz_score >= 90;

    }


    $q->close();

}


// =====================================================
// BADGES
// =====================================================

$badges = 0;


// =====================================================
// BADGE 1
// FIRST QUIZ
// =====================================================

if ($quiz_completed) {

    $badges++;

}


// =====================================================
// BADGE 2
// TOP SCORER
// =====================================================

if ($has_ninety_score) {

    $badges++;

}


// =====================================================
// BADGE 3
// 7-DAY STREAK
// =====================================================

if ($longest_streak >= 7) {

    $badges++;

}


// =====================================================
// BADGE 4
// 30-DAY STREAK
// =====================================================

if ($longest_streak >= 30) {

    $badges++;

}


// =====================================================
// RANK FUNCTION
// =====================================================

function getRank($xp)
{

    // -------------------------------------------------
    // NO XP
    // -------------------------------------------------

    if ($xp <= 0) {

        return [

            "level" => 0,

            "badge" =>
                "No Badge",

            "medal" =>
                "",

            "next_xp" =>
                200

        ];

    }


    // -------------------------------------------------
    // LEVEL 1
    // 1 - 199 XP
    // -------------------------------------------------

    if ($xp < 200) {

        return [

            "level" => 1,

            "badge" =>
                "Beginner",

            "medal" =>
                "🥉",

            "next_xp" =>
                200

        ];

    }


    // -------------------------------------------------
    // LEVEL 2
    // 200 - 499 XP
    // -------------------------------------------------

    if ($xp < 500) {

        return [

            "level" => 2,

            "badge" =>
                "Intermediate",

            "medal" =>
                "🥈",

            "next_xp" =>
                500

        ];

    }


    // -------------------------------------------------
    // LEVEL 3
    // 500 - 999 XP
    // -------------------------------------------------

    if ($xp < 1000) {

        return [

            "level" => 3,

            "badge" =>
                "Advanced",

            "medal" =>
                "🥇",

            "next_xp" =>
                1000

        ];

    }


    // -------------------------------------------------
    // LEVEL 4
    // 1000+ XP
    // -------------------------------------------------

    return [

        "level" => 4,

        "badge" =>
            "Master",

        "medal" =>
            "🏆",

        "next_xp" =>
            null

    ];

}


// =====================================================
// GET RANK
// =====================================================

$rank =
    getRank($xp);


// =====================================================
// RESPONSE
// =====================================================

echo json_encode([

    "status" => "success",

    "xp" => $xp,

    "diagnostic_points" => $diagnostic_points,

    "quiz_points" => $quiz_points,

    "level" => $rank["level"],

    "badge" => $rank["badge"],

    "medal" => $rank["medal"],

    "next_xp" => $rank["next_xp"],

    "streak" => $streak,

    "current_streak" => $streak,

    "longest_streak" => $longest_streak,

    "total_opens" => $total_opens,

    "last_open_date" => $last_open_date,

    "quizzes" => $quizzes,

    "highest_quiz_score" => $highest_quiz_score,

    "quiz_completed" => $quiz_completed,

    "has_ninety_score" => $has_ninety_score,

    "badges" => $badges

], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);


// =====================================================
// CLOSE
// =====================================================

$conn->close();

exit();

?>