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

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "status" => "error",
        "message" => "Database connection failed."
    ]);

    exit;

}


$conn->set_charset("utf8mb4");


/* =========================================================
   GET ADMIN INFORMATION
========================================================= */

$admin_id = isset($_GET["admin_id"])
    ? intval($_GET["admin_id"])
    : 0;


/* =========================================================
   GET ROLE
========================================================= */

$requestedRole =
    strtolower(
        trim(
            $_GET["role"] ?? ""
        )
    );


/* =========================================================
   ALLOWED ROLES
========================================================= */

/*
 * Quiz History + Quiz Result:
 *
 * super_admin   = ALLOWED
 * student_admin = ALLOWED
 *
 * content_admin = NOT ALLOWED
 * admin         = NOT ALLOWED
 */

$allowedRoles = [

    "super_admin",

    "student_admin"

];


/* =========================================================
   VALIDATE ADMIN ID
========================================================= */

if ($admin_id <= 0) {

    http_response_code(401);

    echo json_encode([

        "success" => false,

        "status" => "error",

        "message" =>
            "Invalid admin account."

    ], JSON_UNESCAPED_UNICODE);

    $conn->close();

    exit;

}


/* =========================================================
   VALIDATE ADMIN ACCOUNT
========================================================= */

$adminCheck = $conn->prepare("

    SELECT

        admin_id,

        role,

        fullname,

        account_status

    FROM admin

    WHERE

        admin_id = ?

        AND date_deleted IS NULL

    LIMIT 1

");


if (!$adminCheck) {

    http_response_code(500);

    echo json_encode([

        "success" => false,

        "status" => "error",

        "message" =>
            "Failed to validate admin access."

    ], JSON_UNESCAPED_UNICODE);

    $conn->close();

    exit;

}


$adminCheck->bind_param(
    "i",
    $admin_id
);


if (!$adminCheck->execute()) {

    http_response_code(500);

    echo json_encode([

        "success" => false,

        "status" => "error",

        "message" =>
            "Failed to validate admin account."

    ], JSON_UNESCAPED_UNICODE);

    $adminCheck->close();

    $conn->close();

    exit;

}


$adminResult =
    $adminCheck->get_result();


$adminRow =
    $adminResult->fetch_assoc();


$adminCheck->close();


/* =========================================================
   ADMIN NOT FOUND
========================================================= */

if (!$adminRow) {

    http_response_code(403);

    echo json_encode([

        "success" => false,

        "status" => "error",

        "message" =>
            "Admin account not found or inactive."

    ], JSON_UNESCAPED_UNICODE);

    $conn->close();

    exit;

}


/* =========================================================
   ACTUAL DATABASE ROLE
========================================================= */

$dbRole =

    strtolower(

        trim(

            $adminRow["role"] ?? ""

        )

    );


/* =========================================================
   CHECK ROLE
========================================================= */

if (

    !in_array(
        $dbRole,
        $allowedRoles,
        true
    )

) {

    http_response_code(403);

    echo json_encode([

        "success" => false,

        "status" => "error",

        "message" =>
            "Your admin role does not have access to Quiz Result."

    ], JSON_UNESCAPED_UNICODE);

    $conn->close();

    exit;

}


/* =========================================================
   ACCOUNT STATUS
========================================================= */

$accountStatus =

    strtolower(

        trim(

            $adminRow["account_status"] ?? ""

        )

    );


if (

    $accountStatus !== "" &&

    !in_array(

        $accountStatus,

        [
            "active",
            "verified"
        ],

        true

    )

) {

    http_response_code(403);

    echo json_encode([

        "success" => false,

        "status" => "error",

        "message" =>
            "Admin account is not active."

    ], JSON_UNESCAPED_UNICODE);

    $conn->close();

    exit;

}


/* =========================================================
   GET ATTEMPT ID
========================================================= */

$attempt_id = isset($_GET["attempt_id"])

    ? intval($_GET["attempt_id"])

    : 0;


if ($attempt_id <= 0) {

    http_response_code(400);

    echo json_encode([

        "success" => false,

        "status" => "error",

        "message" =>
            "Invalid attempt_id."

    ], JSON_UNESCAPED_UNICODE);

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

        qa.earned_points,

        qa.total_points,

        qa.date_created,

        q.quiz_title,

        q.quiz_type,

        q.difficulty,

        q.material_id,

        lm.title AS material_title,

        s.subject_name,

        CONCAT_WS(

            ' ',

            st.firstName,

            NULLIF(st.m_initial, ''),

            st.lastName,

            NULLIF(st.extension, '')

        ) AS student_name,

        COALESCE(

            st.points,

            0

        ) AS student_total_xp,

        COALESCE(

            (

                SELECT AVG(
                    qa2.score
                )

                FROM quiz_attempt qa2

                INNER JOIN quiz q2

                    ON q2.quiz_id =
                       qa2.quiz_id

                INNER JOIN learning_material lm2

                    ON lm2.material_id =
                       q2.material_id

                WHERE

                    qa2.student_id =
                    qa.student_id

                    AND

                    lm2.subject_id =
                    lm.subject_id

            ),

            0

        ) AS progress_percentage


    FROM quiz_attempt qa


    INNER JOIN quiz q

        ON q.quiz_id =
           qa.quiz_id


    LEFT JOIN learning_material lm

        ON lm.material_id =
           q.material_id


    LEFT JOIN subject s

        ON s.subject_id =
           lm.subject_id


    INNER JOIN student st

        ON st.student_id =
           qa.student_id


    WHERE

        qa.attempt_id = ?


    LIMIT 1

";


$stmt =
    $conn->prepare(
        $attemptSql
    );


if (!$stmt) {

    http_response_code(500);

    echo json_encode([

        "success" => false,

        "status" => "error",

        "message" =>
            "Failed to prepare attempt query.",

        "error" =>
            $conn->error

    ], JSON_UNESCAPED_UNICODE);

    $conn->close();

    exit;

}


$stmt->bind_param(
    "i",
    $attempt_id
);


if (!$stmt->execute()) {

    http_response_code(500);

    echo json_encode([

        "success" => false,

        "status" => "error",

        "message" =>
            "Failed to load quiz attempt.",

        "error" =>
            $stmt->error

    ], JSON_UNESCAPED_UNICODE);

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

    http_response_code(404);

    echo json_encode([

        "success" => false,

        "status" => "error",

        "message" =>
            "Quiz attempt not found."

    ], JSON_UNESCAPED_UNICODE);

    $stmt->close();

    $conn->close();

    exit;

}


$attempt =
    $attemptResult->fetch_assoc();


$quizId =
    intval(
        $attempt["quiz_id"]
    );


$studentId =
    intval(
        $attempt["student_id"]
    );


$stmt->close();


/* =========================================================
   GET QUESTIONS
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

        ON qaa.question_id =
           qq.question_id

        AND qaa.attempt_id =
            ?


    LEFT JOIN quiz_answers qans

        ON qans.question_id =
           qq.question_id


    WHERE

        qq.quiz_id = ?


    ORDER BY

        qq.question_number ASC,

        qq.question_id ASC

";


$questionStmt =
    $conn->prepare(
        $questionSql
    );


if (!$questionStmt) {

    http_response_code(500);

    echo json_encode([

        "success" => false,

        "status" => "error",

        "message" =>
            "Failed to prepare question query.",

        "error" =>
            $conn->error

    ], JSON_UNESCAPED_UNICODE);

    $conn->close();

    exit;

}


$questionStmt->bind_param(

    "ii",

    $attempt_id,

    $quizId

);


if (!$questionStmt->execute()) {

    http_response_code(500);

    echo json_encode([

        "success" => false,

        "status" => "error",

        "message" =>
            "Failed to load quiz questions.",

        "error" =>
            $questionStmt->error

    ], JSON_UNESCAPED_UNICODE);

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


/* =========================================================
   QUESTIONS LOOP
========================================================= */

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

                $row["question_type"]
                ??
                "multiple_choice"

            )

        );


    $points =

        intval(

            $row["points"]
            ??
            0

        );


    $studentAnswer =

        $row["student_answer"]
        ??
        "";


    $correctAnswer =

        $row["correct_answer"]
        ??
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


    /* =====================================================
       PENDING ESSAY
    ===================================================== */

    $isPending = 0;


    if (

        $questionType === "essay"

        &&

        $row["is_correct"] === null

    ) {

        $isPending = 1;

    }


    /* =====================================================
       CORRECT COUNT
    ===================================================== */

    if (

        $isCorrect === 1

    ) {

        $correctQuestions++;

    }


    /* =====================================================
       POINTS
    ===================================================== */

    $pointsEarned +=
        $earned;


    /* =====================================================
       CHOICES
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

            ORDER BY

                choice_letter ASC

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
   PROGRESS
========================================================= */

$progressPercentage =

    floatval(

        $attempt[
            "progress_percentage"
        ]
        ??
        0

    );


$progressPercentage =

    round(

        min(

            100,

            max(

                0,

                $progressPercentage

            )

        )

    );


/* =========================================================
   FINAL RESPONSE
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
                $attempt["quiz_title"]
                ??
                "Quiz Assessment",


            "quiz_type" =>
                $attempt["quiz_type"]
                ??
                "",


            "difficulty" =>
                $attempt["difficulty"]
                ??
                "",


            "material_title" =>
                $attempt["material_title"]
                ??
                "",


            "score" =>
                $score,


            "correct" =>
                $correctQuestions,


            "total" =>
                $totalQuestions,


            "points_earned" =>
                $pointsEarned,


            "total_points" =>
                intval(
                    $attempt["total_points"]
                    ??
                    $pointsEarned
                ),


            "student_name" =>
                trim(

                    (string)

                    (

                        $attempt[
                            "student_name"
                        ]
                        ??
                        "Student"

                    )

                ),


            "student_total_xp" =>
                intval(

                    $attempt[
                        "student_total_xp"
                    ]
                    ??
                    0

                ),


            "progress_percentage" =>
                $progressPercentage,


            "subject_name" =>
                (string)

                (

                    $attempt[
                        "subject_name"
                    ]
                    ??
                    ""

                ),


            "date_created" =>
                $attempt[
                    "date_created"
                ],


            "questions" =>
                $questions

        ]

    ],

    JSON_UNESCAPED_UNICODE

);


$conn->close();

exit;

?>