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
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");


/*
|--------------------------------------------------------------------------
| RESPONSE HELPER
|--------------------------------------------------------------------------
*/

function responseJson(
    bool $success,
    string $message,
    array $data = [],
    int $statusCode = 200
): void {

    http_response_code($statusCode);

    echo json_encode(

        array_merge(

            [
                "success" => $success,
                "message" => $message
            ],

            $data

        ),

        JSON_UNESCAPED_UNICODE

    );

    exit;

}


/*
|--------------------------------------------------------------------------
| OPTIONS
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"] === "OPTIONS"
) {

    responseJson(
        true,
        "OK"
    );

}


/*
|--------------------------------------------------------------------------
| ONLY GET
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"] !== "GET"
) {

    responseJson(

        false,

        "Only GET requests are allowed.",

        [],

        405

    );

}


/*
|--------------------------------------------------------------------------
| DATABASE
|--------------------------------------------------------------------------
*/

$conn = brainpal_db();


if (
    $conn->connect_error
) {

    responseJson(

        false,

        "Database connection failed.",

        [
            "error" =>
                $conn->connect_error
        ],

        500

    );

}


$conn->set_charset("utf8mb4");


/*
|--------------------------------------------------------------------------
| GET PARAMETERS
|--------------------------------------------------------------------------
|
| Supports:
|
| result_id
| student_id
|
|--------------------------------------------------------------------------
*/

$result_id = intval(
    $_GET["result_id"] ?? 0
);

$student_id = intval(
    $_GET["student_id"] ?? 0
);


/*
|--------------------------------------------------------------------------
| VALIDATE ID
|--------------------------------------------------------------------------
*/

if (
    $result_id <= 0 &&
    $student_id <= 0
) {

    $conn->close();

    responseJson(

        false,

        "Please provide a valid result_id or student_id.",

        [

            "result_id" =>
                $result_id,

            "student_id" =>
                $student_id

        ],

        400

    );

}


/*
|--------------------------------------------------------------------------
| LOAD DIAGNOSTIC RESULT
|--------------------------------------------------------------------------
|
| Priority:
|
| 1. result_id if provided
| 2. latest result for student_id
|
|--------------------------------------------------------------------------
*/

$resultRow = null;


/*
|--------------------------------------------------------------------------
| CASE 1: RESULT ID
|--------------------------------------------------------------------------
*/

if (
    $result_id > 0
) {

    $resultStmt =
        $conn->prepare("

            SELECT

                dr.result_id,

                dr.student_id,

                dr.diagnostic_id,

                dr.academic_id,

                dr.total_score,

                dr.level_classification,

                dr.points_awarded,

                dr.date_taken,

                dr.date_created,

                s.firstName,

                s.m_initial,

                s.lastName,

                s.studentNo,

                s.email,

                s.grade_level,

                s.profile_photo

            FROM diagnostic_result dr

            LEFT JOIN student s

                ON s.student_id =
                   dr.student_id

            WHERE

                dr.result_id = ?

                AND dr.date_deleted IS NULL

            LIMIT 1

        ");


    if (!$resultStmt) {

        $error =
            $conn->error;

        $conn->close();

        responseJson(

            false,

            "Failed to prepare result query.",

            [
                "error" =>
                    $error
            ],

            500

        );

    }


    $resultStmt->bind_param(
        "i",
        $result_id
    );


    if (!$resultStmt->execute()) {

        $error =
            $resultStmt->error;

        $resultStmt->close();

        $conn->close();

        responseJson(

            false,

            "Failed to load diagnostic result.",

            [
                "error" =>
                    $error
            ],

            500

        );

    }


    $resultQuery =
        $resultStmt->get_result();


    $resultRow =
        $resultQuery->fetch_assoc();


    $resultStmt->close();

}


/*
|--------------------------------------------------------------------------
| CASE 2: STUDENT ID
|--------------------------------------------------------------------------
|
| Get the latest valid diagnostic result
| belonging to this student.
|
|--------------------------------------------------------------------------
*/

if (
    !$resultRow &&
    $student_id > 0
) {

    $resultStmt =
        $conn->prepare("

            SELECT

                dr.result_id,

                dr.student_id,

                dr.diagnostic_id,

                dr.academic_id,

                dr.total_score,

                dr.level_classification,

                dr.points_awarded,

                dr.date_taken,

                dr.date_created,

                s.firstName,

                s.m_initial,

                s.lastName,

                s.studentNo,

                s.email,

                s.grade_level,

                s.profile_photo

            FROM diagnostic_result dr

            LEFT JOIN student s

                ON s.student_id =
                   dr.student_id

            WHERE

                dr.student_id = ?

                AND dr.date_deleted IS NULL

            ORDER BY

                COALESCE(
                    dr.date_taken,
                    dr.date_created
                ) DESC,

                dr.result_id DESC

            LIMIT 1

        ");


    if (!$resultStmt) {

        $error =
            $conn->error;

        $conn->close();

        responseJson(

            false,

            "Failed to prepare student diagnostic query.",

            [
                "error" =>
                    $error
            ],

            500

        );

    }


    $resultStmt->bind_param(
        "i",
        $student_id
    );


    if (!$resultStmt->execute()) {

        $error =
            $resultStmt->error;

        $resultStmt->close();

        $conn->close();

        responseJson(

            false,

            "Failed to load student's diagnostic result.",

            [
                "error" =>
                    $error
            ],

            500

        );

    }


    $resultQuery =
        $resultStmt->get_result();


    $resultRow =
        $resultQuery->fetch_assoc();


    $resultStmt->close();

}


/*
|--------------------------------------------------------------------------
| RESULT NOT FOUND
|--------------------------------------------------------------------------
*/

if (!$resultRow) {

    $conn->close();

    responseJson(

        false,

        "No diagnostic result found for this student.",

        [

            "result_id" =>
                $result_id,

            "student_id" =>
                $student_id

        ],

        404

    );

}


/*
|--------------------------------------------------------------------------
| USE ACTUAL RESULT ID
|--------------------------------------------------------------------------
*/

$result_id =
    intval(
        $resultRow["result_id"]
    );


$student_id =
    intval(
        $resultRow["student_id"]
    );


/*
|--------------------------------------------------------------------------
| BUILD STUDENT NAME
|--------------------------------------------------------------------------
*/

$nameParts = [];


if (
    !empty(
        $resultRow["firstName"]
    )
) {

    $nameParts[] =
        trim(
            $resultRow["firstName"]
        );

}


if (
    !empty(
        $resultRow["m_initial"]
    )
) {

    $middleInitial =
        trim(
            $resultRow["m_initial"]
        );


    $middleInitial =
        rtrim(
            $middleInitial,
            "."
        );


    if (
        $middleInitial !== ""
    ) {

        $nameParts[] =
            $middleInitial . ".";

    }

}


if (
    !empty(
        $resultRow["lastName"]
    )
) {

    $nameParts[] =
        trim(
            $resultRow["lastName"]
        );

}


$studentName =
    trim(
        implode(
            " ",
            $nameParts
        )
    );


if (
    $studentName === ""
) {

    $studentName =
        "Student #" .
        $student_id;

}


/*
|--------------------------------------------------------------------------
| GET QUESTIONS / ANSWERS
|--------------------------------------------------------------------------
*/

$questions = [];


$detailStmt =
    $conn->prepare("

        SELECT

            dd.diagnostic_id,

            dd.result_id,

            dd.user_answer,

            d.question,

            d.correct_answer,

            d.subject_id,

            COALESCE(
                sub.subject_name,
                'Unknown Subject'
            ) AS subject_name

        FROM diagnostic_details dd

        INNER JOIN diagnostic d

            ON d.diagnostic_id =
               dd.diagnostic_id

        LEFT JOIN subject sub

            ON sub.subject_id =
               d.subject_id

        WHERE

            dd.result_id = ?

        ORDER BY

            dd.diagnostic_id ASC

    ");


if ($detailStmt) {

    $detailStmt->bind_param(
        "i",
        $result_id
    );


    if (
        $detailStmt->execute()
    ) {

        $detailResult =
            $detailStmt->get_result();


        while (
            $row =
                $detailResult->fetch_assoc()
        ) {

            $questions[] = [

                "diagnostic_id" =>
                    intval(
                        $row["diagnostic_id"]
                    ),

                "question" =>
                    $row["question"]
                    ?? "",

                "user_answer" =>
                    strtoupper(
                        trim(
                            $row["user_answer"]
                            ?? ""
                        )
                    ),

                "correct_answer" =>
                    strtoupper(
                        trim(
                            $row["correct_answer"]
                            ?? ""
                        )
                    ),

                "subject_id" =>
                    isset(
                        $row["subject_id"]
                    )
                        ? intval(
                            $row["subject_id"]
                        )
                        : null,

                "subject_name" =>
                    $row["subject_name"]
                    ?? "Unknown Subject"

            ];

        }

    }


    $detailStmt->close();

}


/*
|--------------------------------------------------------------------------
| TOTAL QUESTIONS
|--------------------------------------------------------------------------
*/

$totalItems =
    count(
        $questions
    );


/*
|--------------------------------------------------------------------------
| TOTAL SCORE
|--------------------------------------------------------------------------
*/

$totalScore =
    intval(
        $resultRow["total_score"]
        ?? 0
    );


/*
|--------------------------------------------------------------------------
| RECALCULATE SCORE IF DETAILS EXIST
|--------------------------------------------------------------------------
*/

$calculatedCorrect = 0;


foreach (
    $questions as $question
) {

    if (

        $question["user_answer"] !== "" &&

        $question["user_answer"] ===
        $question["correct_answer"]

    ) {

        $calculatedCorrect++;

    }

}


if (
    $totalItems > 0
) {

    $totalScore =
        $calculatedCorrect;

}


/*
|--------------------------------------------------------------------------
| PERCENTAGE
|--------------------------------------------------------------------------
*/

$percentage =

    $totalItems > 0

        ? round(

            (
                $totalScore /
                $totalItems
            ) * 100,

            2

        )

        : 0;


/*
|--------------------------------------------------------------------------
| XP POINTS
|--------------------------------------------------------------------------
*/

$pointsAwarded =
    intval(
        $resultRow["points_awarded"]
        ?? 0
    );


if (
    $pointsAwarded <= 0
) {

    $pointsAwarded =
        $totalScore * 10;

}


/*
|--------------------------------------------------------------------------
| SUBJECT PERFORMANCE
|--------------------------------------------------------------------------
*/

$subjectStats = [];


foreach (
    $questions as $question
) {

    $subjectId =
        $question["subject_id"];


    $subjectName =
        $question["subject_name"];


    $key =

        $subjectId !== null

            ? "id_" . $subjectId

            : "name_" . $subjectName;


    if (
        !isset(
            $subjectStats[$key]
        )
    ) {

        $subjectStats[$key] = [

            "subject_id" =>
                $subjectId,

            "subject_name" =>
                $subjectName,

            "total" =>
                0,

            "correct" =>
                0,

            "wrong" =>
                0,

            "percentage" =>
                0,

            "priority" =>
                "Low"

        ];

    }


    $subjectStats[$key]["total"]++;


    if (

        $question["user_answer"] !== "" &&

        $question["user_answer"] ===
        $question["correct_answer"]

    ) {

        $subjectStats[$key]["correct"]++;

    }

    else {

        $subjectStats[$key]["wrong"]++;

    }

}


/*
|--------------------------------------------------------------------------
| SUBJECT PERCENTAGE / PRIORITY
|--------------------------------------------------------------------------
*/

foreach (
    $subjectStats
    as &$subject
) {

    if (
        $subject["total"] > 0
    ) {

        $subject["percentage"] =
            round(

                (
                    $subject["correct"] /
                    $subject["total"]
                ) * 100,

                2

            );

    }


    if (
        $subject["percentage"] < 50
    ) {

        $subject["priority"] =
            "High";

    }

    elseif (
        $subject["percentage"] < 75
    ) {

        $subject["priority"] =
            "Medium";

    }

    else {

        $subject["priority"] =
            "Low";

    }

}


unset($subject);


$subjectPerformance =
    array_values(
        $subjectStats
    );


/*
|--------------------------------------------------------------------------
| RESPONSE
|--------------------------------------------------------------------------
*/

$response = [

    "result_id" =>
        $result_id,

    "student_id" =>
        $student_id,

    "student_name" =>
        $studentName,

    "student_no" =>
        $resultRow["studentNo"]
        ?? "",

    "student_email" =>
        $resultRow["email"]
        ?? "",

    "grade_level" =>
        $resultRow["grade_level"] !== null

            ? intval(
                $resultRow["grade_level"]
            )

            : null,

    "diagnostic_id" =>
        intval(
            $resultRow["diagnostic_id"]
        ),

    "academic_id" =>
        intval(
            $resultRow["academic_id"]
        ),

    "score" =>
        $totalScore,

    "correct" =>
        $totalScore,

    "total" =>
        $totalItems > 0
            ? $totalItems
            : 20,

    "percentage" =>
        $percentage,

    "points_awarded" =>
        $pointsAwarded,

    "total_score" =>
        $pointsAwarded,

    "level" =>
        $resultRow["level_classification"]
        ?? "",

    "level_classification" =>
        $resultRow["level_classification"]
        ?? "",

    "date_taken" =>
        $resultRow["date_taken"]
        ??
        $resultRow["date_created"]
        ??
        "",

    "date_created" =>
        $resultRow["date_created"]
        ?? "",

    "data" =>
        $questions,

    "subject_performance" =>
        $subjectPerformance

];


$conn->close();


responseJson(

    true,

    "Diagnostic result loaded successfully.",

    $response,

    200

);

?>