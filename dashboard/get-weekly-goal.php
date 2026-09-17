<?php
require_once __DIR__ . '/../_shared/db.php';

header("Content-Type: application/json; charset=UTF-8");

header(
    "Access-Control-Allow-Origin: http://localhost:8100"
);

header(
    "Access-Control-Allow-Methods: GET, OPTIONS"
);

header(
    "Access-Control-Allow-Headers: Content-Type, Authorization"
);

header(
    "Access-Control-Allow-Credentials: true"
);


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

        "message" =>
            "Only GET requests are allowed.",

        "quiz_count" =>
            0,

        "target" =>
            5,

        "weekly_goal" =>
            0

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

        "message" =>
            "Database connection failed.",

        "error" =>
            $conn->connect_error,

        "quiz_count" =>
            0,

        "target" =>
            5,

        "weekly_goal" =>
            0

    ]);

    exit;
}


$conn->set_charset("utf8mb4");


/*
|--------------------------------------------------------------------------
| STUDENT ID
|--------------------------------------------------------------------------
*/

$student_id = intval(

    $_GET["student_id"] ?? 0

);


if ($student_id <= 0) {

    http_response_code(400);

    echo json_encode([

        "success" => false,

        "message" =>
            "Invalid student_id.",

        "quiz_count" =>
            0,

        "target" =>
            5,

        "weekly_goal" =>
            0

    ]);

    $conn->close();

    exit;
}


/*
|--------------------------------------------------------------------------
| CHECK STUDENT
|--------------------------------------------------------------------------
*/

$studentStmt = $conn->prepare("

    SELECT
        student_id

    FROM student

    WHERE
        student_id = ?

        AND date_deleted IS NULL

    LIMIT 1

");


if (!$studentStmt) {

    http_response_code(500);

    echo json_encode([

        "success" => false,

        "message" =>
            "Failed to prepare student query.",

        "error" =>
            $conn->error,

        "quiz_count" =>
            0,

        "target" =>
            5,

        "weekly_goal" =>
            0

    ]);

    $conn->close();

    exit;
}


$studentStmt->bind_param(

    "i",

    $student_id

);


if (!$studentStmt->execute()) {

    http_response_code(500);

    echo json_encode([

        "success" => false,

        "message" =>
            "Failed to execute student query.",

        "error" =>
            $studentStmt->error,

        "quiz_count" =>
            0,

        "target" =>
            5,

        "weekly_goal" =>
            0

    ]);

    $studentStmt->close();

    $conn->close();

    exit;
}


$studentResult =
    $studentStmt->get_result();


if (
    !$studentResult ||
    $studentResult->num_rows === 0
) {

    http_response_code(404);

    echo json_encode([

        "success" => false,

        "message" =>
            "Student not found.",

        "quiz_count" =>
            0,

        "target" =>
            5,

        "weekly_goal" =>
            0

    ]);

    $studentStmt->close();

    $conn->close();

    exit;
}


$studentStmt->close();


/*
|--------------------------------------------------------------------------
| WEEKLY QUIZ COUNT
|--------------------------------------------------------------------------
|
| IMPORTANT:
|
| Count UNIQUE quiz_id only.
|
| If a student takes the same quiz multiple times:
|
| Quiz #1 attempt 1
| Quiz #1 attempt 2
| Quiz #1 attempt 3
|
| = 1 quiz for Weekly Goal.
|
| Different quizzes:
|
| Quiz #1 = 1
| Quiz #2 = 1
| Quiz #3 = 1
|
| = 3 quizzes.
|
| Weekly period:
|
| Today + previous 6 days = 7 days.
|
*/

$quizStmt = $conn->prepare("

    SELECT

        COUNT(
            DISTINCT quiz_id
        ) AS quiz_count

    FROM quiz_attempt

    WHERE

        student_id = ?

        AND date_created >=
            DATE_SUB(
                CURDATE(),
                INTERVAL 6 DAY
            )

        AND date_created <
            DATE_ADD(
                CURDATE(),
                INTERVAL 1 DAY
            )

");


if (!$quizStmt) {

    http_response_code(500);

    echo json_encode([

        "success" => false,

        "message" =>
            "Failed to prepare weekly quiz query.",

        "error" =>
            $conn->error,

        "quiz_count" =>
            0,

        "target" =>
            5,

        "weekly_goal" =>
            0

    ]);

    $conn->close();

    exit;
}


$quizStmt->bind_param(

    "i",

    $student_id

);


if (!$quizStmt->execute()) {

    http_response_code(500);

    echo json_encode([

        "success" => false,

        "message" =>
            "Failed to execute weekly quiz query.",

        "error" =>
            $quizStmt->error,

        "quiz_count" =>
            0,

        "target" =>
            5,

        "weekly_goal" =>
            0

    ]);

    $quizStmt->close();

    $conn->close();

    exit;
}


$quizResult =
    $quizStmt->get_result();


$quizRow =
    $quizResult->fetch_assoc();


$quizCount =
    intval(

        $quizRow["quiz_count"] ?? 0

    );


$quizStmt->close();


/*
|--------------------------------------------------------------------------
| WEEKLY GOAL
|--------------------------------------------------------------------------
|
| Target = 5 UNIQUE QUIZZES.
|
| 0 unique quizzes = 0%
| 1 unique quiz    = 20%
| 2 unique quizzes = 40%
| 3 unique quizzes = 60%
| 4 unique quizzes = 80%
| 5 unique quizzes = 100%
| 6+ unique quizzes = 100%
|
*/

$target = 5;


$weeklyGoal = 0;


if ($target > 0) {

    $weeklyGoal = min(

        100,

        round(

            (
                $quizCount /
                $target
            ) * 100

        )

    );

}


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
        "Weekly goal loaded successfully.",

    "student_id" =>
        $student_id,

    "quiz_count" =>
        $quizCount,

    "target" =>
        $target,

    "weekly_goal" =>
        $weeklyGoal,

    "days" =>
        7

], JSON_UNESCAPED_UNICODE);


$conn->close();

exit;

?>