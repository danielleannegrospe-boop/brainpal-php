<?php
require_once __DIR__ . '/../_shared/db.php';

// =========================================================
// ERROR REPORTING
// =========================================================

error_reporting(E_ALL);
ini_set("display_errors", 0);
// =========================================================
// HEADERS
// =========================================================

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
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");

require_once __DIR__ . '/../notifications/notification-helper.php';


// =========================================================
// PREFLIGHT
// =========================================================

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {

    http_response_code(200);

    echo json_encode([
        "success" => true,
        "message" => "OK"
    ]);

    exit;
}


// =========================================================
// ONLY POST
// =========================================================

if ($_SERVER["REQUEST_METHOD"] !== "POST") {

    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Only POST requests are allowed."
    ]);

    exit;
}


// =========================================================
// DATABASE
// =========================================================

$conn = brainpal_db();


if ($conn->connect_error) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database connection failed."
    ]);

    exit;
}


$conn->set_charset("utf8mb4");


// =========================================================
// GET REQUEST BODY
// =========================================================

$rawInput = file_get_contents("php://input");


$input = json_decode(
    $rawInput,
    true
);


if (!is_array($input)) {

    echo json_encode([
        "success" => false,
        "message" => "Invalid request data."
    ]);

    $conn->close();

    exit;
}


// =========================================================
// GET INPUT VALUES
// =========================================================

$student_id = intval(
    $input["student_id"] ?? 0
);


$quiz_id = intval(
    $input["quiz_id"] ?? 0
);


$answers = $input["answers"] ?? [];


// =========================================================
// VALIDATE STUDENT ID
// =========================================================

if ($student_id <= 0) {

    echo json_encode([
        "success" => false,
        "message" => "Invalid student ID."
    ]);

    $conn->close();

    exit;
}


// =========================================================
// VALIDATE QUIZ ID
// =========================================================

if ($quiz_id <= 0) {

    echo json_encode([
        "success" => false,
        "message" => "Invalid quiz ID."
    ]);

    $conn->close();

    exit;
}


// =========================================================
// VALIDATE ANSWERS
// =========================================================

if (!is_array($answers)) {

    echo json_encode([
        "success" => false,
        "message" => "Invalid answers."
    ]);

    $conn->close();

    exit;
}


// =========================================================
// CHECK STUDENT
// =========================================================

$studentStmt = $conn->prepare("
    SELECT

        student_id,
        points,
        academic_id,
        strand_id,
        specialization_id,
        grade_level

    FROM student

    WHERE
        student_id = ?

        AND

        date_deleted IS NULL

    LIMIT 1
");


if (!$studentStmt) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" =>
            "Failed to prepare student query."
    ]);

    $conn->close();

    exit;
}


$studentStmt->bind_param(
    "i",
    $student_id
);


if (!$studentStmt->execute()) {

    echo json_encode([
        "success" => false,
        "message" =>
            "Failed to validate student."
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

    echo json_encode([
        "success" => false,
        "message" =>
            "Student account not found."
    ]);

    $studentStmt->close();
    $conn->close();

    exit;
}


$student =
    $studentResult->fetch_assoc();


$academic_id =
    intval(
        $student["academic_id"] ?? 0
    );


$studentStmt->close();


// =========================================================
// CHECK QUIZ OWNERSHIP
//
// IMPORTANT:
//
// quiz
//   ↓
// learning_material
//   ↓
// study_schedule
//   ↓
// student
//
// This prevents Student 2 from submitting
// Student 1's quiz.
// =========================================================

$quizStmt = $conn->prepare("
    SELECT

        q.quiz_id,
        q.material_id,
        q.quiz_title,
        q.quiz_type,
        q.approval_status,
        lm.approval_status AS material_approval_status,
        lm.schedule_id,
        lm.subject_id,

        ss.student_id AS assigned_student_id,
        ss.scheduled_day,
        ss.status AS schedule_status

    FROM quiz q

    INNER JOIN learning_material lm

        ON lm.material_id =
           q.material_id

        AND lm.date_deleted IS NULL

    INNER JOIN study_schedule ss

        ON ss.schedule_id =
           lm.schedule_id

        AND ss.student_id = ?

        AND ss.date_deleted IS NULL

    WHERE

        q.quiz_id = ?
        AND q.approval_status = 'approved'
        AND lm.approval_status = 'approved'
        AND ss.status = 'scheduled'
        AND ss.scheduled_day = CURDATE()

    LIMIT 1
");


if (!$quizStmt) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" =>
            "Failed to prepare quiz access validation."
    ]);

    $conn->close();

    exit;
}


$quizStmt->bind_param(
    "ii",
    $student_id,
    $quiz_id
);


if (!$quizStmt->execute()) {

    echo json_encode([
        "success" => false,
        "message" =>
            "Failed to validate quiz access."
    ]);

    $quizStmt->close();
    $conn->close();

    exit;
}


$quizResult =
    $quizStmt->get_result();


// =========================================================
// QUIZ NOT AVAILABLE FOR THIS STUDENT
// =========================================================

if (
    !$quizResult ||
    $quizResult->num_rows === 0
) {

    echo json_encode([
        "success" => false,
        "message" =>
            "You do not have access to this quiz."
    ]);

    $quizStmt->close();
    $conn->close();

    exit;
}


$quiz =
    $quizResult->fetch_assoc();


// =========================================================
// EXTRA OWNERSHIP CHECK
// =========================================================

$assigned_student_id =
    intval(
        $quiz["assigned_student_id"] ?? 0
    );


if (
    $assigned_student_id !==
    $student_id
) {

    echo json_encode([
        "success" => false,
        "message" =>
            "You do not have access to this quiz."
    ]);

    $quizStmt->close();
    $conn->close();

    exit;
}


$material_id =
    intval(
        $quiz["material_id"] ?? 0
    );


$subject_id =
    intval(
        $quiz["subject_id"] ?? 0
    );


$quizStmt->close();

/* Server-side enforcement: the learning material must be completed first. */
$studyStmt = $conn->prepare("
    SELECT status, total_seconds
    FROM study_material_sessions
    WHERE student_id = ? AND material_id = ?
    LIMIT 1
");
$studyStmt->bind_param("ii", $student_id, $material_id);
$studyStmt->execute();
$studyRow = $studyStmt->get_result()->fetch_assoc();
$studyStmt->close();

if (!$studyRow || ($studyRow["status"] ?? "") !== "completed") {
    echo json_encode([
        "success" => false,
        "status" => "study_required",
        "message" => "Please study the learning material before taking this quiz.",
        "study_completed" => false,
        "study_seconds" => intval($studyRow["total_seconds"] ?? 0)
    ], JSON_UNESCAPED_UNICODE);
    $conn->close();
    exit;
}


// =========================================================
// VALIDATE MATERIAL
// =========================================================

if ($material_id <= 0) {

    echo json_encode([
        "success" => false,
        "message" =>
            "This quiz is not connected to a valid learning material."
    ]);

    $conn->close();

    exit;
}


// =========================================================
// VALIDATE SUBJECT
// =========================================================

if ($subject_id <= 0) {

    echo json_encode([
        "success" => false,
        "message" =>
            "This quiz is not connected to a valid subject."
    ]);

    $conn->close();

    exit;
}


// =========================================================
// GET SUBJECT INFORMATION
// =========================================================

$subjectStmt = $conn->prepare("
    SELECT

        lm.material_id,
        lm.subject_id,
        lm.academic_id,

        s.subject_name,
        s.semester,
        s.grade_level

    FROM learning_material lm

    INNER JOIN subject s

        ON s.subject_id =
           lm.subject_id

        AND s.date_deleted IS NULL

    WHERE

        lm.material_id = ?

        AND

        lm.date_deleted IS NULL

    LIMIT 1
");


if (!$subjectStmt) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" =>
            "Failed to prepare subject query."
    ]);

    $conn->close();

    exit;
}


$subjectStmt->bind_param(
    "i",
    $material_id
);


if (!$subjectStmt->execute()) {

    echo json_encode([
        "success" => false,
        "message" =>
            "Failed to load subject information."
    ]);

    $subjectStmt->close();
    $conn->close();

    exit;
}


$subjectResult =
    $subjectStmt->get_result();


if (
    !$subjectResult ||
    $subjectResult->num_rows === 0
) {

    echo json_encode([
        "success" => false,
        "message" =>
            "This quiz is not connected to a valid subject."
    ]);

    $subjectStmt->close();
    $conn->close();

    exit;
}


$subjectData =
    $subjectResult->fetch_assoc();


$subject_id =
    intval(
        $subjectData["subject_id"] ?? 0
    );


if ($academic_id <= 0) {

    $academic_id =
        intval(
            $subjectData["academic_id"] ?? 0
        );
}


$subjectStmt->close();


// =========================================================
// CREATE ANSWER MAP
// =========================================================

$submittedAnswers = [];


foreach ($answers as $item) {

    if (!is_array($item)) {
        continue;
    }


    $questionId =
        intval(
            $item["question_id"] ?? 0
        );


    if ($questionId <= 0) {
        continue;
    }


    $submittedAnswers[$questionId] =
        trim(
            (string)(
                $item["answer"] ?? ""
            )
        );
}


// =========================================================
// TRANSACTION
// =========================================================

$conn->begin_transaction();


try {


    // =====================================================
    // GET QUIZ QUESTIONS
    // =====================================================

    $questionStmt = $conn->prepare("
        SELECT

            question_id,
            question_type,
            points

        FROM quiz_questions

        WHERE quiz_id = ?

        ORDER BY

            question_number ASC,
            question_id ASC
    ");


    if (!$questionStmt) {

        throw new Exception(
            "Failed to prepare question query."
        );
    }


    $questionStmt->bind_param(
        "i",
        $quiz_id
    );


    if (!$questionStmt->execute()) {

        throw new Exception(
            "Failed to load quiz questions."
        );
    }


    $questionResult =
        $questionStmt->get_result();


    $questions = [];


    while (
        $row =
        $questionResult->fetch_assoc()
    ) {

        $questions[] = [

            "question_id" =>
                intval(
                    $row["question_id"]
                ),

            "question_type" =>
                strtolower(
                    trim(
                        $row["question_type"] ?? ""
                    )
                ),

            "points" =>
                max(
                    0,
                    intval(
                        $row["points"] ?? 0
                    )
                )

        ];
    }


    $questionStmt->close();


    // =====================================================
    // NO QUESTIONS
    // =====================================================

    if (
        count($questions) === 0
    ) {

        throw new Exception(
            "This quiz has no questions."
        );
    }


    // =====================================================    
    // =====================================================
    // PREVENT RETAKING A COMPLETED QUIZ
    // =====================================================

    $existingAttemptStmt = $conn->prepare("
        SELECT attempt_id
        FROM quiz_attempt
        WHERE quiz_id = ?
          AND student_id = ?
        ORDER BY attempt_id ASC
        LIMIT 1
    ");

    if (!$existingAttemptStmt) {
        throw new Exception(
            "Failed to check existing quiz attempt."
        );
    }

    $existingAttemptStmt->bind_param(
        "ii",
        $quiz_id,
        $student_id
    );

    if (!$existingAttemptStmt->execute()) {
        $existingAttemptStmt->close();
        throw new Exception(
            "Failed to check existing quiz attempt."
        );
    }

    $existingAttemptResult =
        $existingAttemptStmt->get_result();

    if (
        $existingAttemptResult &&
        $existingAttemptResult->num_rows > 0
    ) {

        $existingAttempt =
            $existingAttemptResult->fetch_assoc();

        $existingAttemptId =
            intval($existingAttempt["attempt_id"]);

        $existingAttemptStmt->close();

        $conn->rollback();

        echo json_encode([
            "success" => false,
            "status" => "completed",
            "message" =>
                "You have already completed this quiz. You cannot take it again.",
            "completed" => true,
            "attempt_id" => $existingAttemptId
        ], JSON_UNESCAPED_UNICODE);

        $conn->close();
        exit;
    }

    $existingAttemptStmt->close();


    // =====================================================
    // CREATE QUIZ ATTEMPT
    // =====================================================

    $attemptStmt = $conn->prepare(" 
        INSERT INTO quiz_attempt
        (
            quiz_id,
            student_id,
            score,
            earned_points,
            total_points,
            date_created
        )

        VALUES
        (
            ?,
            ?,
            0,
            0,
            0,
            NOW()
        )
    ");


    if (!$attemptStmt) {

        throw new Exception(
            "Failed to prepare quiz attempt."
        );
    }


    $attemptStmt->bind_param(
        "ii",
        $quiz_id,
        $student_id
    );


    if (!$attemptStmt->execute()) {

        throw new Exception(
            "Failed to save quiz attempt: " .
            $attemptStmt->error
        );
    }


    $attempt_id =
        intval(
            $attemptStmt->insert_id
        );


    $attemptStmt->close();


    if ($attempt_id <= 0) {

        throw new Exception(
            "Invalid attempt ID."
        );
    }


    // =====================================================
    // PREPARE ANSWER INSERT
    // =====================================================

    $answerInsert = $conn->prepare("
        INSERT INTO quiz_attempt_answers
        (
            attempt_id,
            question_id,
            student_answer,
            is_correct,
            points_earned
        )

        VALUES
        (
            ?,
            ?,
            ?,
            ?,
            ?
        )
    ");


    if (!$answerInsert) {

        throw new Exception(
            "Failed to prepare answer insertion: " .
            $conn->error
        );
    }


    // =====================================================
    // SCORE VARIABLES
    // =====================================================

    $correctCount = 0;

    $totalQuestions =
        count($questions);

    $totalPoints = 0;

    $earnedPoints = 0;

    $autoGradableQuestions = 0;


    // =====================================================
    // PROCESS QUESTIONS
    // =====================================================

    foreach (
        $questions as $question
    ) {

        $questionId =
            intval(
                $question["question_id"]
            );


        $points =
            max(
                0,
                intval(
                    $question["points"]
                )
            );


        $questionType =
            strtolower(
                trim(
                    $question["question_type"]
                )
            );


        $studentAnswer =
            $submittedAnswers[
                $questionId
            ] ?? "";


        // =================================================
        // TOTAL POSSIBLE POINTS
        // =================================================

        $totalPoints +=
            $points;


        $isCorrect = 0;

        $pointsEarned = 0;


        // =================================================
        // MULTIPLE CHOICE
        // =================================================

        if (
            $questionType ===
            "multiple_choice"
        ) {

            $choiceStmt =
                $conn->prepare("
                    SELECT
                        choice_letter

                    FROM quiz_choices

                    WHERE
                        question_id = ?

                        AND

                        is_correct = 1

                    LIMIT 1
                ");


            if (!$choiceStmt) {

                throw new Exception(
                    "Failed to prepare multiple choice query."
                );
            }


            $choiceStmt->bind_param(
                "i",
                $questionId
            );


            if (!$choiceStmt->execute()) {

                throw new Exception(
                    "Failed to get correct answer."
                );
            }


            $choiceResult =
                $choiceStmt->get_result();


            $correctAnswer = "";


            if (
                $correctRow =
                $choiceResult->fetch_assoc()
            ) {

                $correctAnswer =
                    strtoupper(
                        trim(
                            $correctRow[
                                "choice_letter"
                            ] ?? ""
                        )
                    );
            }


            $choiceStmt->close();


            $studentChoice =
                strtoupper(
                    trim(
                        $studentAnswer
                    )
                );


            if (
                $studentChoice !== ""

                &&

                $correctAnswer !== ""

                &&

                $studentChoice ===
                $correctAnswer
            ) {

                $isCorrect = 1;

                $pointsEarned =
                    $points;
            }


            $autoGradableQuestions++;
        }


        // =================================================
        // IDENTIFICATION
        // =================================================

        elseif (
            $questionType ===
            "identification"
        ) {

            $answerStmt =
                $conn->prepare("
                    SELECT
                        correct_answer

                    FROM quiz_answers

                    WHERE
                        question_id = ?

                    LIMIT 1
                ");


            if (!$answerStmt) {

                throw new Exception(
                    "Failed to prepare identification query."
                );
            }


            $answerStmt->bind_param(
                "i",
                $questionId
            );


            if (!$answerStmt->execute()) {

                throw new Exception(
                    "Failed to get identification answer."
                );
            }


            $answerResult =
                $answerStmt->get_result();


            $correctAnswer = "";


            if (
                $answerRow =
                $answerResult->fetch_assoc()
            ) {

                $correctAnswer =
                    trim(
                        $answerRow[
                            "correct_answer"
                        ] ?? ""
                    );
            }


            $answerStmt->close();


            if (
                $studentAnswer !== ""

                &&

                $correctAnswer !== ""

                &&

                strcasecmp(
                    trim($studentAnswer),
                    trim($correctAnswer)
                ) === 0
            ) {

                $isCorrect = 1;

                $pointsEarned =
                    $points;
            }


            $autoGradableQuestions++;
        }


        // =================================================
        // ENUMERATION
        // =================================================

        elseif (
            $questionType ===
            "enumeration"
        ) {

            $answerStmt =
                $conn->prepare("
                    SELECT
                        correct_answer

                    FROM quiz_answers

                    WHERE
                        question_id = ?

                    LIMIT 1
                ");


            if (!$answerStmt) {

                throw new Exception(
                    "Failed to prepare enumeration query."
                );
            }


            $answerStmt->bind_param(
                "i",
                $questionId
            );


            if (!$answerStmt->execute()) {

                throw new Exception(
                    "Failed to get enumeration answer."
                );
            }


            $answerResult =
                $answerStmt->get_result();


            $correctAnswer = "";


            if (
                $answerRow =
                $answerResult->fetch_assoc()
            ) {

                $correctAnswer =
                    trim(
                        $answerRow[
                            "correct_answer"
                        ] ?? ""
                    );
            }


            $answerStmt->close();


            $correctItems =
                preg_split(
                    "/[,;\n]+/",
                    $correctAnswer
                );


            $studentItems =
                preg_split(
                    "/[,;\n]+/",
                    $studentAnswer
                );


            $correctItems =
                array_values(
                    array_filter(
                        array_map(
                            function ($value) {

                                return strtolower(
                                    trim($value)
                                );

                            },
                            $correctItems
                        )
                    )
                );


            $studentItems =
                array_values(
                    array_filter(
                        array_map(
                            function ($value) {

                                return strtolower(
                                    trim($value)
                                );

                            },
                            $studentItems
                        )
                    )
                );


            sort($correctItems);

            sort($studentItems);


            if (
                count($correctItems) > 0

                &&

                $correctItems ===
                $studentItems
            ) {

                $isCorrect = 1;

                $pointsEarned =
                    $points;
            }


            $autoGradableQuestions++;
        }


        // =================================================
        // ESSAY
        // =================================================

        elseif (
            $questionType ===
            "essay"
        ) {

            /*
             * Essay is not automatically graded.
             */

            $isCorrect = 0;

            $pointsEarned = 0;
        }


        // =================================================
        // SAVE ANSWER
        // =================================================

        $answerInsert->bind_param(
            "iisii",
            $attempt_id,
            $questionId,
            $studentAnswer,
            $isCorrect,
            $pointsEarned
        );


        if (
            !$answerInsert->execute()
        ) {

            throw new Exception(
                "Failed to save answer: " .
                $answerInsert->error
            );
        }


        // =================================================
        // UPDATE COUNTERS
        // =================================================

        if (
            $isCorrect === 1
        ) {

            $correctCount++;
        }


        $earnedPoints +=
            $pointsEarned;
    }


    $answerInsert->close();


    // =====================================================
    // CALCULATE SCORE
    // =====================================================

    $score = 0;


    if (
        $totalPoints > 0
    ) {

        $score =
            round(
                (
                    $earnedPoints /
                    $totalPoints
                ) * 100
            );
    }


    // =====================================================
    // UPDATE ATTEMPT
    // =====================================================

    $updateAttempt =
        $conn->prepare("
            UPDATE quiz_attempt

            SET

                score = ?,

                earned_points = ?,

                total_points = ?

            WHERE
                attempt_id = ?
        ");


    if (!$updateAttempt) {

        throw new Exception(
            "Failed to prepare attempt update: " .
            $conn->error
        );
    }


    $updateAttempt->bind_param(
        "iiii",
        $score,
        $earnedPoints,
        $totalPoints,
        $attempt_id
    );


    if (
        !$updateAttempt->execute()
    ) {

        throw new Exception(
            "Failed to update quiz attempt points: " .
            $updateAttempt->error
        );
    }


    $updateAttempt->close();


    // =====================================================
    // ADD POINTS TO STUDENT
    // =====================================================

    if (
        $earnedPoints > 0
    ) {

        $updateStudent =
            $conn->prepare("
                UPDATE student

                SET

                    points =
                        COALESCE(points, 0)
                        + ?

                WHERE
                    student_id = ?
            ");


        if (!$updateStudent) {

            throw new Exception(
                "Failed to prepare student points update."
            );
        }


        $updateStudent->bind_param(
            "ii",
            $earnedPoints,
            $student_id
        );


        if (
            !$updateStudent->execute()
        ) {

            throw new Exception(
                "Failed to update student points: " .
                $updateStudent->error
            );
        }


        $updateStudent->close();
    }


    // =====================================================
    // SUBJECT PROGRESS
    // =====================================================

    $subjectProgressStmt =
        $conn->prepare("
            SELECT

                COALESCE(
                    SUM(qaa.points_earned),
                    0
                ) AS earned_points,

                COUNT(
                    DISTINCT qa.attempt_id
                ) AS quiz_count,

                COALESCE(
                    SUM(
                        CASE

                            WHEN
                                qaa.is_correct = 1

                            THEN
                                1

                            ELSE
                                0

                        END
                    ),
                    0
                ) AS correct_answers,

                COUNT(
                    qaa.question_id
                ) AS total_questions

            FROM quiz_attempt qa

            INNER JOIN quiz q

                ON q.quiz_id =
                   qa.quiz_id

            INNER JOIN learning_material lm

                ON lm.material_id =
                   q.material_id

            INNER JOIN quiz_attempt_answers qaa

                ON qaa.attempt_id =
                   qa.attempt_id

            WHERE

                qa.student_id = ?

                AND

                lm.subject_id = ?
        ");


    if (!$subjectProgressStmt) {

        throw new Exception(
            "Failed to prepare subject progress query."
        );
    }


    $subjectProgressStmt->bind_param(
        "ii",
        $student_id,
        $subject_id
    );


    if (
        !$subjectProgressStmt->execute()
    ) {

        throw new Exception(
            "Failed to calculate subject progress."
        );
    }


    $subjectProgressResult =
        $subjectProgressStmt->get_result();


    $subjectProgressData =
        $subjectProgressResult->fetch_assoc();


    $subjectProgressStmt->close();


    $progressPoints =
        intval(
            $subjectProgressData[
                "earned_points"
            ] ?? 0
        );


    $progressQuizCount =
        intval(
            $subjectProgressData[
                "quiz_count"
            ] ?? 0
        );


    $progressCorrectAnswers =
        intval(
            $subjectProgressData[
                "correct_answers"
            ] ?? 0
        );


    $progressTotalQuestions =
        intval(
            $subjectProgressData[
                "total_questions"
            ] ?? 0
        );


    // =====================================================
    // PROGRESS PERCENTAGE
    // =====================================================

    $progressPercentage =
        min(
            100,
            max(
                0,
                $progressPoints
            )
        );


    // =====================================================
    // PROGRESS SUMMARY
    // =====================================================

    $summaryStmt =
        $conn->prepare("
            SELECT
                summary_id

            FROM progress_summary

            WHERE

                student_id = ?

                AND

                subject_id = ?

                AND

                academic_id = ?

                AND

                date_deleted IS NULL

            LIMIT 1
        ");


    if (!$summaryStmt) {

        throw new Exception(
            "Failed to prepare progress summary lookup."
        );
    }


    $summaryStmt->bind_param(
        "iii",
        $student_id,
        $subject_id,
        $academic_id
    );


    if (
        !$summaryStmt->execute()
    ) {

        throw new Exception(
            "Failed to check progress summary."
        );
    }


    $summaryResult =
        $summaryStmt->get_result();


    $existingSummary =
        $summaryResult->fetch_assoc();


    $summaryStmt->close();


    // =====================================================
    // UPDATE EXISTING SUMMARY
    // =====================================================

    if ($existingSummary) {

        $summary_id =
            intval(
                $existingSummary[
                    "summary_id"
                ]
            );


        $updateSummary =
            $conn->prepare("
                UPDATE progress_summary

                SET

                    average_score = ?,

                    total_attempts = ?,

                    improvement_rate = 0,

                    last_update = NOW(),

                    last_calculated = NOW()

                WHERE
                    summary_id = ?
            ");


        if (!$updateSummary) {

            throw new Exception(
                "Failed to prepare progress summary update."
            );
        }


        $updateSummary->bind_param(
            "dii",
            $progressPercentage,
            $progressQuizCount,
            $summary_id
        );


        if (
            !$updateSummary->execute()
        ) {

            throw new Exception(
                "Failed to update subject progress."
            );
        }


        $updateSummary->close();
    }


    // =====================================================
    // CREATE SUMMARY
    // =====================================================

    else {

        $insertSummary =
            $conn->prepare("
                INSERT INTO progress_summary
                (
                    student_id,
                    subject_id,
                    academic_id,
                    average_score,
                    total_attempts,
                    improvement_rate,
                    last_update,
                    last_calculated,
                    date_created
                )

                VALUES
                (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    0,
                    NOW(),
                    NOW(),
                    NOW()
                )
            ");


        if (!$insertSummary) {

            throw new Exception(
                "Failed to prepare progress summary insert."
            );
        }


        $insertSummary->bind_param(
            "iiidi",
            $student_id,
            $subject_id,
            $academic_id,
            $progressPercentage,
            $progressQuizCount
        );


        if (
            !$insertSummary->execute()
        ) {

            throw new Exception(
                "Failed to create subject progress."
            );
        }


        $insertSummary->close();
    }


    // =====================================================
    // GET UPDATED STUDENT POINTS
    // =====================================================

    $pointsStmt =
        $conn->prepare("
            SELECT
                points

            FROM student

            WHERE
                student_id = ?

            LIMIT 1
        ");


    if (!$pointsStmt) {

        throw new Exception(
            "Failed to prepare student points query."
        );
    }


    $pointsStmt->bind_param(
        "i",
        $student_id
    );


    if (
        !$pointsStmt->execute()
    ) {

        throw new Exception(
            "Failed to get updated student points."
        );
    }


    $pointsResult =
        $pointsStmt->get_result();


    $updatedStudentPoints = 0;


    if (
        $pointsRow =
        $pointsResult->fetch_assoc()
    ) {

        $updatedStudentPoints =
            intval(
                $pointsRow["points"]
            );
    }


    $pointsStmt->close();


    // =====================================================
    // COMMIT
    // =====================================================

    if (!$conn->commit()) {
        throw new Exception(
            "Failed to commit quiz submission."
        );
    }


    // =====================================================
    // ADMIN NOTIFICATION
    //
    // IMPORTANT:
    // The quiz is already committed at this point.
    // A notification failure must NOT change a successful
    // quiz submission into an HTTP 500 error.
    // =====================================================

    $notificationSent = false;
    $notificationMessage = null;

    try {

        if (
            function_exists('notifyAllAdmins') &&
            function_exists('getStudentName')
        ) {

            $studentName = getStudentName(
                $conn,
                $student_id
            );

            if (!$studentName) {
                $studentName = 'A student';
            }

            $quizTitle = 'Quiz';

            $quizTitleStmt = $conn->prepare(
                "SELECT quiz_title
                 FROM quiz
                 WHERE quiz_id = ?
                 LIMIT 1"
            );

            if ($quizTitleStmt) {

                $quizTitleStmt->bind_param(
                    'i',
                    $quiz_id
                );

                if ($quizTitleStmt->execute()) {

                    $quizTitleRow =
                        $quizTitleStmt
                            ->get_result()
                            ->fetch_assoc();

                    $quizTitle =
                        trim(
                            (string)(
                                $quizTitleRow['quiz_title']
                                ?? 'Quiz'
                            )
                        ) ?: 'Quiz';
                }

                $quizTitleStmt->close();
            }

            notifyAllAdmins(
                $conn,
                'quiz_taken',
                'Quiz Completed',
                $studentName .
                ' completed "' .
                $quizTitle .
                '" with a score of ' .
                $score .
                '%.',
                $attempt_id
            );

            $notificationSent = true;

        } else {

            $notificationMessage =
                'Notification helper functions are not available.';
        }

    } catch (Throwable $notificationError) {

        $notificationMessage =
            $notificationError->getMessage();

        error_log(
            'Quiz notification failed: ' .
            $notificationMessage
        );
    }

    // =====================================================
    // SUCCESS RESPONSE
    // =====================================================

    echo json_encode([

        "success" => true,

        "message" =>
            "Quiz submitted successfully.",

        "attempt_id" =>
            $attempt_id,

        "student_id" =>
            $student_id,

        "quiz_id" =>
            $quiz_id,

        "material_id" =>
            $material_id,

        "subject_id" =>
            $subject_id,

        // -----------------------------
        // QUIZ RESULT
        // -----------------------------

        "score" =>
            $score,

        "correct" =>
            $correctCount,

        "total" =>
            $totalQuestions,

        // -----------------------------
        // QUIZ POINTS
        // -----------------------------

        "total_points" =>
            $totalPoints,

        "points_earned" =>
            $earnedPoints,

        // -----------------------------
        // STUDENT TOTAL POINTS
        // -----------------------------

        "student_points" =>
            $updatedStudentPoints,

        // -----------------------------
        // SUBJECT PROGRESS
        // -----------------------------

        "subject_progress_points" =>
            $progressPoints,

        "subject_progress_percentage" =>
            $progressPercentage,

        "subject_quiz_count" =>
            $progressQuizCount,

        "subject_correct_answers" =>
            $progressCorrectAnswers,

        "subject_total_questions" =>
            $progressTotalQuestions,

        // -----------------------------
        // GRADING
        // -----------------------------

        "auto_graded_questions" =>
            $autoGradableQuestions,

        "auto_graded" =>
            true,

        // -----------------------------
        // NOTIFICATION
        // -----------------------------

        "notification_sent" =>
            $notificationSent,

        "notification_message" =>
            $notificationMessage

    ], JSON_UNESCAPED_UNICODE);

}


// =========================================================
// ERROR HANDLER
// =========================================================

catch (Throwable $e) {

    try {
        $conn->rollback();
    } catch (Throwable $rollbackError) {
        error_log(
            'Quiz submission rollback failed: ' .
            $rollbackError->getMessage()
        );
    }

    http_response_code(500);

    echo json_encode([

        "success" => false,

        "message" =>
            $e->getMessage()

    ], JSON_UNESCAPED_UNICODE);
}


// =========================================================
// CLOSE
// =========================================================

$conn->close();

exit;

?>