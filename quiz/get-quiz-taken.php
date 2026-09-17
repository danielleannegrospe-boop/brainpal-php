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


/* =========================================================
   DATABASE
========================================================= */

$conn = brainpal_db();


if ($conn->connect_error) {

    echo json_encode([
        "success" => false,
        "status" => "error",
        "message" =>
            "Database connection failed: " .
            $conn->connect_error
    ]);

    exit;
}


$conn->set_charset("utf8mb4");


/* =========================================================
   GET ATTEMPT ID
========================================================= */

$attempt_id = isset($_GET["attempt_id"])
    ? intval($_GET["attempt_id"])
    : 0;


if ($attempt_id <= 0) {

    echo json_encode([
        "success" => false,
        "status" => "error",
        "message" => "Invalid attempt_id."
    ]);

    $conn->close();

    exit;
}


/* =========================================================
   GET ATTEMPT + QUIZ INFO
========================================================= */

$attemptSql = "

    SELECT

        qa.attempt_id,

        qa.quiz_id,

        qa.student_id,

        qa.score,

        qa.date_created,

        q.quiz_title,

        q.quiz_type,

        q.difficulty,

        q.material_id,

        lm.title AS material_title

    FROM quiz_attempt qa

    INNER JOIN quiz q
        ON q.quiz_id = qa.quiz_id

    LEFT JOIN learning_material lm
        ON lm.material_id = q.material_id

    WHERE qa.attempt_id = ?

    LIMIT 1

";


$stmt = $conn->prepare($attemptSql);


if (!$stmt) {

    echo json_encode([
        "success" => false,
        "status" => "error",
        "message" =>
            "Failed to prepare attempt query: " .
            $conn->error
    ]);

    $conn->close();

    exit;
}


$stmt->bind_param(
    "i",
    $attempt_id
);


if (!$stmt->execute()) {

    echo json_encode([
        "success" => false,
        "status" => "error",
        "message" =>
            "Failed to load quiz attempt: " .
            $stmt->error
    ]);

    $stmt->close();

    $conn->close();

    exit;
}


$attemptResult =
    $stmt->get_result();


if (
    !$attemptResult ||
    $attemptResult->num_rows === 0
) {

    echo json_encode([
        "success" => false,
        "status" => "error",
        "message" => "Quiz attempt not found."
    ]);

    $stmt->close();

    $conn->close();

    exit;
}


$attempt =
    $attemptResult->fetch_assoc();


$quizId =
    intval($attempt["quiz_id"]);

$studentId =
    intval($attempt["student_id"]);


$stmt->close();


/* =========================================================
   GET QUESTIONS + STUDENT ANSWERS + CORRECT ANSWERS
========================================================= */

$questionSql = "

    SELECT

        qq.question_id,

        qq.quiz_id,

        qq.question_number,

        qq.question_text,

        qq.question_type,

        qq.points,

        qaa.student_answer,

        qaa.is_correct,

        qaa.points_earned,

        qans.correct_answer

    FROM quiz_questions qq

    LEFT JOIN quiz_attempt_answers qaa

        ON qaa.question_id = qq.question_id

        AND qaa.attempt_id = ?

    LEFT JOIN quiz_answers qans

        ON qans.question_id = qq.question_id

    WHERE qq.quiz_id = ?

    ORDER BY

        qq.question_number ASC,

        qq.question_id ASC

";


$questionStmt =
    $conn->prepare($questionSql);


if (!$questionStmt) {

    echo json_encode([
        "success" => false,
        "status" => "error",
        "message" =>
            "Failed to prepare question query: " .
            $conn->error
    ]);

    $conn->close();

    exit;
}


$questionStmt->bind_param(
    "ii",
    $attempt_id,
    $quizId
);


if (!$questionStmt->execute()) {

    echo json_encode([
        "success" => false,
        "status" => "error",
        "message" =>
            "Failed to load quiz questions: " .
            $questionStmt->error
    ]);

    $questionStmt->close();

    $conn->close();

    exit;
}


$questionResult =
    $questionStmt->get_result();


$questions = [];

$totalQuestions = 0;
$correctQuestions = 0;
$pointsEarned = 0;


while (
    $row =
    $questionResult->fetch_assoc()
) {

    $questionId =
        intval(
            $row["question_id"]
        );


    $questionType =
        strtolower(
            trim(
                $row["question_type"] ??
                "multiple_choice"
            )
        );


    $points =
        intval(
            $row["points"] ?? 0
        );


    $studentAnswer =
        $row["student_answer"] ??
        "";


    $correctAnswer =
        $row["correct_answer"] ??
        "";


    $isCorrect =
        $row["is_correct"] !== null
            ? intval(
                $row["is_correct"]
            )
            : 0;


    $earned =
        $row["points_earned"] !== null
            ? intval(
                $row["points_earned"]
            )
            : 0;


    /*
     * Essay may need manual evaluation.
     */

    $isPending = 0;


    if (
        $questionType === "essay" &&
        $row["is_correct"] === null
    ) {

        $isPending = 1;

    }


    if (
        $isCorrect === 1
    ) {

        $correctQuestions++;

    }


    $pointsEarned +=
        $earned;


    /* =====================================================
       GET CHOICES
    ===================================================== */

    $choices = [];


    if (
        $questionType ===
        "multiple_choice"
    ) {

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


        $choiceStmt =
            $conn->prepare(
                $choiceSql
            );


        if ($choiceStmt) {

            $choiceStmt->bind_param(
                "i",
                $questionId
            );


            if (
                $choiceStmt->execute()
            ) {

                $choiceResult =
                    $choiceStmt->get_result();


                while (
                    $choiceRow =
                    $choiceResult->fetch_assoc()
                ) {

                    $choices[] = [

                        "choice_id" =>
                            intval(
                                $choiceRow[
                                    "choice_id"
                                ]
                            ),

                        "question_id" =>
                            intval(
                                $choiceRow[
                                    "question_id"
                                ]
                            ),

                        "choice_letter" =>
                            strtoupper(
                                trim(
                                    $choiceRow[
                                        "choice_letter"
                                    ]
                                )
                            ),

                        "choice_text" =>
                            $choiceRow[
                                "choice_text"
                            ]

                    ];

                }

            }


            $choiceStmt->close();

        }

    }


    /* =====================================================
       QUESTION OBJECT
    ===================================================== */

    $questions[] = [

        "question_id" =>
            $questionId,

        "question_number" =>
            intval(
                $row["question_number"]
            ),

        "question_text" =>
            $row["question_text"],

        "question_type" =>
            $questionType,

        "points" =>
            $points,

        "student_answer" =>
            $studentAnswer,

        "correct_answer" =>
            $correctAnswer,

        "points_earned" =>
            $earned,

        "is_correct" =>
            $isCorrect,

        "is_pending" =>
            $isPending,

        "choices" =>
            $choices

    ];


    $totalQuestions++;

}


$questionStmt->close();


/* =========================================================
   SCORE
========================================================= */

$score =
    $attempt["score"] !== null
        ? floatval(
            $attempt["score"]
        )
        : 0;


/* =========================================================
   RESPONSE
========================================================= */

echo json_encode(

    [

        "success" => true,

        "status" => "success",

        "message" =>
            "Quiz attempt loaded successfully.",

        "result" => [

            "attempt_id" =>
                $attempt_id,

            "quiz_id" =>
                $quizId,

            "student_id" =>
                $studentId,

            "quiz_title" =>
                $attempt["quiz_title"] ??
                "Quiz Assessment",

            "quiz_type" =>
                $attempt["quiz_type"] ??
                "",

            "difficulty" =>
                $attempt["difficulty"] ??
                "",

            "material_title" =>
                $attempt["material_title"] ??
                "",

            "score" =>
                $score,

            "correct" =>
                $correctQuestions,

            "total" =>
                $totalQuestions,

            "points_earned" =>
                $pointsEarned,

            "date_created" =>
                $attempt["date_created"],

            "questions" =>
                $questions

        ]

    ],

    JSON_UNESCAPED_UNICODE

);


$conn->close();

exit;

?>