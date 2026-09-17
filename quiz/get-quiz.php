<?php
require_once __DIR__ . '/../_shared/db.php';

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}


/* =====================================================
   DATABASE
===================================================== */

$conn = brainpal_db();


if ($conn->connect_error) {

    echo json_encode([
        "success" => false,
        "status" => "error",
        "message" => "Database connection failed.",
        "quizzes" => [],
        "quiz" => null,
        "questions" => []
    ]);

    exit;
}


$conn->set_charset("utf8mb4");


/* =====================================================
   GET QUIZ ID
===================================================== */

$quiz_id = isset($_GET['quiz_id'])
    ? intval($_GET['quiz_id'])
    : 0;

/* =====================================================
   STUDENT ACCESS

   Quiz visibility is controlled by the learning material
   schedule. The schedule must belong to this student.
===================================================== */

$student_id = isset($_GET['student_id'])
    ? intval($_GET['student_id'])
    : intval($_GET['studentId'] ?? 0);

if ($student_id <= 0) {

    echo json_encode([
        "success" => false,
        "status" => "error",
        "message" => "Invalid student_id.",
        "quiz" => null,
        "quizzes" => [],
        "questions" => []
    ]);

    $conn->close();
    exit;
}


/* =====================================================
   MODE 1
   NO quiz_id = GET ALL ADMIN-CREATED QUIZZES
===================================================== */

if ($quiz_id <= 0) {

    $sql = "

        SELECT

            q.quiz_id,

            q.material_id,

            q.quiz_title,

            q.quiz_type,

            q.difficulty,

            q.date_created,

            q.approval_status,
            lm.approval_status AS material_approval_status,
            ss.scheduled_day,
            CASE WHEN ss.scheduled_day = CURDATE() THEN 1 ELSE 0 END AS scheduled_today,

            lm.title AS material_title,

            lm.description AS material_description,

            COUNT(
                DISTINCT qq.question_id
            ) AS question_count,

            CASE WHEN MAX(sms.status = 'completed') = 1 THEN 1 ELSE 0 END AS study_completed,
            COALESCE(MAX(sms.total_seconds), 0) AS study_seconds

        FROM quiz q

        INNER JOIN learning_material lm
            ON lm.material_id = q.material_id

        INNER JOIN study_schedule ss
            ON ss.schedule_id = lm.schedule_id
            AND ss.student_id = ?
            AND ss.date_deleted IS NULL

        LEFT JOIN study_material_sessions sms
            ON sms.material_id = lm.material_id
            AND sms.student_id = ?

        LEFT JOIN quiz_questions qq
            ON qq.quiz_id = q.quiz_id

        WHERE
            lm.date_deleted IS NULL
            AND lm.approval_status = 'approved'
            AND q.approval_status = 'approved'
            AND ss.status = 'scheduled'

        GROUP BY

            q.quiz_id,
            q.material_id,
            q.quiz_title,
            q.quiz_type,
            q.difficulty,
            q.date_created,
            lm.title,
            lm.description

        ORDER BY
            q.date_created DESC

    ";


    $stmt = $conn->prepare($sql);


    if (!$stmt) {

        echo json_encode([
            "success" => false,
            "status" => "error",
            "message" =>
                "Failed to prepare quiz list query: " .
                $conn->error,
            "quizzes" => []
        ]);

        $conn->close();

        exit;
    }


    $stmt->bind_param("ii", $student_id, $student_id);

    if (!$stmt->execute()) {

        echo json_encode([
            "success" => false,
            "status" => "error",
            "message" =>
                "Failed to load quizzes: " .
                $stmt->error,
            "quizzes" => []
        ]);

        $stmt->close();

        $conn->close();

        exit;
    }


    $result = $stmt->get_result();

    $quizzes = [];


    while (
        $row = $result->fetch_assoc()
    ) {

        $quizzes[] = [

            "quiz_id" =>
                intval(
                    $row["quiz_id"]
                ),

            "material_id" =>
                intval(
                    $row["material_id"]
                ),

            "quiz_title" =>
                $row["quiz_title"] ??
                "Practice Quiz",

            "quiz_type" =>
                $row["quiz_type"] ??
                "mixed",

            "difficulty" =>
                $row["difficulty"] ??
                "",

            "material_title" =>
                $row["material_title"] ??
                "",

            "material_description" =>
                $row["material_description"] ??
                "",

            "question_count" =>
                intval(
                    $row["question_count"]
                ),

            "study_completed" =>
                intval($row["study_completed"] ?? 0),

            "study_seconds" =>
                intval($row["study_seconds"] ?? 0),

            "scheduled_today" => intval($row["scheduled_today"] ?? 0),

            "can_take_today" => (intval($row["scheduled_today"] ?? 0) === 1 && intval($row["study_completed"] ?? 0) === 1) ? 1 : 0,

            "date_created" =>
                $row["date_created"] ??
                null

        ];

    }


    echo json_encode([

        "success" => true,

        "status" => "success",

        "message" =>
            "Available quizzes loaded successfully.",

        "quizzes" =>
            $quizzes,

        "count" =>
            count($quizzes)

    ], JSON_UNESCAPED_UNICODE);


    $stmt->close();

    $conn->close();

    exit;
}


/* =====================================================
   MODE 2
   quiz_id EXISTS = GET SPECIFIC QUIZ
===================================================== */


/* =====================================================
   GET QUIZ
===================================================== */

$quizSql = "

    SELECT

        q.quiz_id,

        q.material_id,

        q.quiz_title,

        q.quiz_type,

        q.difficulty,

        q.date_created,

        q.approval_status,
        lm.approval_status AS material_approval_status,
        ss.scheduled_day,
        CASE WHEN ss.scheduled_day = CURDATE() THEN 1 ELSE 0 END AS scheduled_today,

        lm.title AS material_title,
        COALESCE(sms.status, 'not_started') AS study_status,
        COALESCE(sms.total_seconds, 0) AS study_seconds

    FROM quiz q

    INNER JOIN learning_material lm
        ON lm.material_id = q.material_id

    INNER JOIN study_schedule ss
        ON ss.schedule_id = lm.schedule_id
        AND ss.student_id = ?
        AND ss.date_deleted IS NULL

    LEFT JOIN study_material_sessions sms
        ON sms.material_id = lm.material_id
        AND sms.student_id = ?

    WHERE q.quiz_id = ?
      AND q.approval_status = 'approved'
      AND lm.approval_status = 'approved'
      AND ss.status = 'scheduled'
      AND ss.scheduled_day = CURDATE()

    LIMIT 1

";


$stmt = $conn->prepare($quizSql);


if (!$stmt) {

    echo json_encode([
        "success" => false,
        "status" => "error",
        "message" =>
            "Failed to prepare quiz query: " .
            $conn->error,
        "quiz" => null,
        "questions" => []
    ]);

    $conn->close();

    exit;
}


$stmt->bind_param(
    "iii",
    $student_id,
    $student_id,
    $quiz_id
);


if (!$stmt->execute()) {

    echo json_encode([
        "success" => false,
        "status" => "error",
        "message" =>
            "Failed to load quiz: " .
            $stmt->error,
        "quiz" => null,
        "questions" => []
    ]);

    $stmt->close();

    $conn->close();

    exit;
}


$quizResult = $stmt->get_result();


if (
    !$quizResult ||
    $quizResult->num_rows === 0
) {

    echo json_encode([
        "success" => false,
        "status" => "error",
        "message" => "Quiz not found.",
        "quiz" => null,
        "questions" => []
    ]);

    $stmt->close();

    $conn->close();

    exit;
}


$quizRow = $quizResult->fetch_assoc();

/* A quiz cannot be opened until its learning material is marked completed. */
if (($quizRow['study_status'] ?? 'not_started') !== 'completed') {
    $stmt->close();
    $conn->close();
    echo json_encode([
        "success" => false,
        "status" => "study_required",
        "message" => "Please study the learning material before taking this quiz.",
        "study_completed" => false,
        "study_seconds" => intval($quizRow['study_seconds'] ?? 0),
        "quiz" => null,
        "questions" => []
    ], JSON_UNESCAPED_UNICODE);
    exit;
}


/* =====================================================
   CHECK IF STUDENT ALREADY COMPLETED THIS QUIZ
===================================================== */

$attemptCheck = $conn->prepare("
    SELECT attempt_id
    FROM quiz_attempt
    WHERE quiz_id = ?
      AND student_id = ?
    ORDER BY attempt_id ASC
    LIMIT 1
");

if (!$attemptCheck) {
    echo json_encode([
        "success" => false,
        "status" => "error",
        "message" => "Unable to check quiz completion.",
        "quiz" => null,
        "questions" => []
    ]);
    $stmt->close();
    $conn->close();
    exit;
}

$attemptCheck->bind_param(
    "ii",
    $quiz_id,
    $student_id
);

if (!$attemptCheck->execute()) {
    echo json_encode([
        "success" => false,
        "status" => "error",
        "message" => "Unable to check quiz completion.",
        "quiz" => null,
        "questions" => []
    ]);
    $attemptCheck->close();
    $stmt->close();
    $conn->close();
    exit;
}

$attemptResult = $attemptCheck->get_result();

if ($attemptResult && $attemptResult->num_rows > 0) {

    $attemptRow = $attemptResult->fetch_assoc();

    $completedAttemptId =
        intval($attemptRow["attempt_id"]);

    $attemptCheck->close();
    $stmt->close();
    $conn->close();

    echo json_encode([
        "success" => false,
        "status" => "completed",
        "message" =>
            "You have already completed this quiz. You cannot take it again.",
        "completed" => true,
        "attempt_id" => $completedAttemptId,
        "quiz" => null,
        "questions" => []
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

$attemptCheck->close();


$quiz = [

    "quiz_id" =>
        intval(
            $quizRow["quiz_id"]
        ),

    "material_id" =>
        $quizRow["material_id"] !== null
            ? intval(
                $quizRow["material_id"]
            )
            : null,

    "quiz_title" =>
        $quizRow["quiz_title"] ??
        "Practice Quiz",

    "quiz_type" =>
        $quizRow["quiz_type"] ??
        "mixed",

    "difficulty" =>
        $quizRow["difficulty"] ??
        "",

    "material_title" =>
        $quizRow["material_title"] ??
        "",

    "study_completed" =>
        (($quizRow["study_status"] ?? "") === "completed") ? 1 : 0,

    "study_seconds" =>
        intval($quizRow["study_seconds"] ?? 0),

    "scheduled_today" => intval($quizRow["scheduled_today"] ?? 0),

    "date_created" =>
        $quizRow["date_created"] ??
        null

];


$stmt->close();


/* =====================================================
   GET QUESTIONS
===================================================== */

$questionSql = "

    SELECT

        question_id,

        quiz_id,

        question_number,

        question_text,

        question_type,

        points

    FROM quiz_questions

    WHERE quiz_id = ?

    ORDER BY

        question_number ASC,

        question_id ASC

";


$stmt = $conn->prepare($questionSql);


if (!$stmt) {

    echo json_encode([
        "success" => false,
        "status" => "error",
        "message" =>
            "Failed to prepare questions query: " .
            $conn->error,
        "quiz" => $quiz,
        "questions" => []
    ]);

    $conn->close();

    exit;
}


$stmt->bind_param(
    "i",
    $quiz_id
);


if (!$stmt->execute()) {

    echo json_encode([
        "success" => false,
        "status" => "error",
        "message" =>
            "Failed to load questions: " .
            $stmt->error,
        "quiz" => $quiz,
        "questions" => []
    ]);

    $stmt->close();

    $conn->close();

    exit;
}


$questionResult = $stmt->get_result();


$questions = [];


while (
    $row = $questionResult->fetch_assoc()
) {

    $questions[] = [

        "question_id" =>
            intval(
                $row["question_id"]
            ),

        "quiz_id" =>
            intval(
                $row["quiz_id"]
            ),

        "question_number" =>
            intval(
                $row["question_number"]
            ),

        "question_text" =>
            $row["question_text"],

        "question_type" =>
            strtolower(
                trim(
                    $row["question_type"] ??
                    "multiple_choice"
                )
            ),

        "points" =>
            intval(
                $row["points"]
            ),

        "choices" => [],

        "selectedAnswer" => ""

    ];

}


$stmt->close();


/* =====================================================
   GET CHOICES
===================================================== */

$choiceSql = "

    SELECT

        choice_id,

        question_id,

        choice_letter,

        choice_text

    FROM quiz_choices

    WHERE question_id = ?

    ORDER BY choice_letter ASC

";


$choiceStmt = $conn->prepare($choiceSql);


if ($choiceStmt) {

    foreach (
        $questions as &$question
    ) {

        if (
            $question["question_type"] !==
            "multiple_choice"
        ) {

            continue;
        }


        $questionId =
            $question["question_id"];


        $choiceStmt->bind_param(
            "i",
            $questionId
        );


        if (!$choiceStmt->execute()) {

            continue;
        }


        $choiceResult =
            $choiceStmt->get_result();


        $choices = [];


        while (
            $choiceRow =
            $choiceResult->fetch_assoc()
        ) {

            $choices[] = [

                "choice_id" =>
                    intval(
                        $choiceRow["choice_id"]
                    ),

                "question_id" =>
                    intval(
                        $choiceRow["question_id"]
                    ),

                "choice_letter" =>
                    strtoupper(
                        trim(
                            $choiceRow["choice_letter"]
                        )
                    ),

                "choice_text" =>
                    $choiceRow["choice_text"]

            ];

        }


        $question["choices"] =
            $choices;

    }


    unset($question);


    $choiceStmt->close();

}


/* =====================================================
   RESPONSE
===================================================== */

echo json_encode([

    "success" => true,

    "status" => "success",

    "message" =>
        "Quiz loaded successfully.",

    "quiz" =>
        $quiz,

    "questions" =>
        $questions,

    "count" =>
        count($questions)

], JSON_UNESCAPED_UNICODE);


$conn->close();

exit;

?>