<?php
require_once __DIR__ . '/../_shared/db.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");


/* =========================================================
   OPTIONS
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}


/* =========================================================
   RESPONSE
========================================================= */

function response(
    bool $success,
    string $message,
    $data = null
): void {

    echo json_encode(
        [
            "success" => $success,
            "message" => $message,
            "data" => $data
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


/* =========================================================
   STUDENT ID
========================================================= */

$student_id = 0;

if (isset($_GET["student_id"])) {
    $student_id = intval($_GET["student_id"]);
}

if (
    $student_id <= 0 &&
    isset($_GET["studentId"])
) {
    $student_id = intval(
        $_GET["studentId"]
    );
}

if ($student_id <= 0) {

    response(
        false,
        "Invalid student_id."
    );

}


/* =========================================================
   DATABASE
========================================================= */

$conn = brainpal_db();

if ($conn->connect_error) {

    response(
        false,
        "Database connection failed: " .
        $conn->connect_error
    );

}

$conn->set_charset("utf8mb4");


/* =========================================================
   ICON
========================================================= */

function getSubjectIcon(
    string $name
): string {

    $name = strtolower(
        trim($name)
    );

    if (
        strpos($name, "math") !== false ||
        strpos($name, "calculus") !== false
    ) {
        return "calculator-outline";
    }

    if (
        strpos($name, "science") !== false ||
        strpos($name, "chemistry") !== false ||
        strpos($name, "biology") !== false ||
        strpos($name, "physics") !== false
    ) {
        return "flask-outline";
    }

    if (
        strpos($name, "communication") !== false ||
        strpos($name, "komunikasyon") !== false ||
        strpos($name, "filipino") !== false ||
        strpos($name, "wika") !== false ||
        strpos($name, "english") !== false
    ) {
        return "chatbubble-ellipses-outline";
    }

    if (
        strpos($name, "computer") !== false ||
        strpos($name, "programming") !== false ||
        strpos($name, "technology") !== false ||
        strpos($name, "ict") !== false
    ) {
        return "code-slash-outline";
    }

    if (
        strpos($name, "physical education") !== false ||
        strpos($name, "health") !== false
    ) {
        return "fitness-outline";
    }

    if (
        strpos($name, "culture") !== false ||
        strpos($name, "society") !== false ||
        strpos($name, "politics") !== false
    ) {
        return "people-outline";
    }

    if (
        strpos($name, "empowerment") !== false
    ) {
        return "desktop-outline";
    }

    return "book-outline";
}


/* =========================================================
   COLOR
========================================================= */

function getScoreColor(
    ?float $score
): string {

    if ($score === null) {
        return "#8b8f9e";
    }

    if ($score >= 90) {
        return "#28C840";
    }

    if ($score >= 80) {
        return "#3B45D9";
    }

    if ($score >= 75) {
        return "#D9A300";
    }

    return "#D43737";
}


/* =========================================================
   STATUS
========================================================= */

function getStatus(
    ?float $score
): string {

    if ($score === null) {
        return "No Quiz Yet";
    }

    if ($score >= 90) {
        return "Excellent";
    }

    if ($score >= 80) {
        return "Good Progress";
    }

    if ($score >= 75) {
        return "On Track";
    }

    return "Needs Improvement";
}


/* =========================================================
   SEMESTER
========================================================= */

$semester =
    strtolower(
        trim(
            $_GET["semester"] ??
            "1st"
        )
    );


if (
    $semester === "1" ||
    $semester === "first" ||
    $semester === "first semester" ||
    $semester === "1st semester"
) {

    $semester = "1st";

}


if (
    $semester === "2" ||
    $semester === "second" ||
    $semester === "second semester" ||
    $semester === "2nd semester"
) {

    $semester = "2nd";

}


if (
    $semester !== "1st" &&
    $semester !== "2nd"
) {

    $semester = "1st";

}


/* =========================================================
   GET STUDENT
========================================================= */

$sql = "

    SELECT

        s.student_id,
        s.studentNo,
        s.firstName,
        s.m_initial,
        s.lastName,

        s.strand_id,
        s.specialization_id,
        s.academic_id,
        s.grade_level,
        s.points,

        st.strand_name,

        sp.specialization_name,

        a.academic_year

    FROM student s

    LEFT JOIN strand st
        ON st.strand_id = s.strand_id

    LEFT JOIN specialization sp
        ON sp.specialization_id =
           s.specialization_id

    LEFT JOIN academic a
        ON a.academic_id =
           s.academic_id

    WHERE
        s.student_id = ?

        AND

        s.date_deleted IS NULL

    LIMIT 1

";


$stmt =
    $conn->prepare(
        $sql
    );


if (!$stmt) {

    response(
        false,
        "Student query failed: " .
        $conn->error
    );

}


$stmt->bind_param(
    "i",
    $student_id
);


$stmt->execute();


$result =
    $stmt->get_result();


if (
    !$result ||
    $result->num_rows === 0
) {

    response(
        false,
        "Student account was not found."
    );

}


$student =
    $result->fetch_assoc();


$stmt->close();


/* =========================================================
   STUDENT DATA
========================================================= */

$strand_id =
    intval(
        $student["strand_id"] ?? 0
    );

$specialization_id =
    intval(
        $student["specialization_id"] ?? 0
    );

$grade_level =
    intval(
        $student["grade_level"] ?? 0
    );

$academic_id =
    intval(
        $student["academic_id"] ?? 0
    );


$student_name =
    trim(
        ($student["firstName"] ?? "") .
        " " .
        ($student["m_initial"] ?? "") .
        " " .
        ($student["lastName"] ?? "")
    );


$student_name =
    preg_replace(
        "/\s+/",
        " ",
        $student_name
    );


/* =========================================================
   XP / POINTS
=========================================================

   IMPORTANT:

   Do NOT use:
       $student["points"]

   because the value stored in student.points may be
   outdated (example: 170 XP).

   Analytics now computes XP from the same actual
   sources used by the system:

       Diagnostic XP
       +
       Quiz XP
       =
       Current XP

========================================================= */

$diagnosticPoints = 0;
$quizXpPoints = 0;


/* =========================================================
   DIAGNOSTIC XP
========================================================= */

$diagnosticStmt = $conn->prepare("
    SELECT
        COALESCE(
            SUM(points_awarded),
            0
        ) AS total_points

    FROM diagnostic_result

    WHERE
        student_id = ?

        AND

        date_deleted IS NULL
");

if ($diagnosticStmt) {

    $diagnosticStmt->bind_param(
        "i",
        $student_id
    );

    if ($diagnosticStmt->execute()) {

        $diagnosticResult =
            $diagnosticStmt->get_result();

        if ($diagnosticResult) {

            $diagnosticRow =
                $diagnosticResult->fetch_assoc();

            $diagnosticPoints =
                intval(
                    $diagnosticRow["total_points"] ?? 0
                );
        }
    }

    $diagnosticStmt->close();
}


/* =========================================================
   QUIZ XP
========================================================= */

$quizXpStmt = $conn->prepare("
    SELECT
        COALESCE(
            SUM(qaa.points_earned),
            0
        ) AS total_points

    FROM quiz_attempt_answers qaa

    INNER JOIN quiz_attempt qa
        ON qa.attempt_id =
           qaa.attempt_id

    WHERE
        qa.student_id = ?
");

if ($quizXpStmt) {

    $quizXpStmt->bind_param(
        "i",
        $student_id
    );

    if ($quizXpStmt->execute()) {

        $quizXpResult =
            $quizXpStmt->get_result();

        if ($quizXpResult) {

            $quizXpRow =
                $quizXpResult->fetch_assoc();

            $quizXpPoints =
                intval(
                    $quizXpRow["total_points"] ?? 0
                );
        }
    }

    $quizXpStmt->close();
}


/* =========================================================
   FINAL XP
========================================================= */

$xp =
    $diagnosticPoints +
    $quizXpPoints;


/* =========================================================
   GET STUDENT'S SUBJECTS
=========================================================

   Shared:
       specialization_id IS NULL
       OR specialization_id = 0

   Major:
       specialization_id =
       student's specialization_id

========================================================= */

$subjects = [];


$subjectSql = "

    SELECT

        subject_id,
        subject_name,
        strand_id,
        specialization_id,
        semester,
        grade_level

    FROM subject

    WHERE

        strand_id = ?

    AND

        grade_level = ?

    AND

        semester = ?

    AND

        date_deleted IS NULL

    AND

        (
            specialization_id IS NULL

            OR

            specialization_id = 0

            OR

            specialization_id = ?
        )

    ORDER BY

        CASE

            WHEN
                specialization_id IS NULL
                OR specialization_id = 0

            THEN 1

            ELSE 2

        END,

        subject_id ASC

";


$subjectStmt =
    $conn->prepare(
        $subjectSql
    );


if (!$subjectStmt) {

    response(
        false,
        "Subject query failed: " .
        $conn->error
    );

}


$subjectStmt->bind_param(
    "iisi",
    $strand_id,
    $grade_level,
    $semester,
    $specialization_id
);


$subjectStmt->execute();


$subjectResult =
    $subjectStmt->get_result();


while (
    $row =
    $subjectResult->fetch_assoc()
) {

    $id =
        intval(
            $row["subject_id"]
        );


    $subjectSpecialization =
        $row["specialization_id"];


    $subjects[$id] = [

        "subject_id" =>
            $id,

        "name" =>
            $row["subject_name"],

        "semester" =>
            $row["semester"],

        "grade_level" =>
            intval(
                $row["grade_level"]
            ),

        "subject_type" =>

            (
                $subjectSpecialization === null ||
                intval(
                    $subjectSpecialization
                ) === 0
            )

                ? "Shared"

                : "Major",

        "quiz_count" =>
            0,

        "first_score" =>
            null,

        "latest_score" =>
            null,

        "progress" =>
            0,

        "progress_points" =>
            0,

        "earned_points" =>
            0,

        "total_points" =>
            0,

        "correct_answers" =>
            0,

        "total_questions" =>
            0,

        "status" =>
            "No Quiz Yet",

        "score" =>
            null,

        "icon" =>
            getSubjectIcon(
                $row["subject_name"]
            ),

        "color" =>
            "#8b8f9e"

    ];

}


$subjectStmt->close();


/* =========================================================
   SUBJECT NAME MAP
========================================================= */

$subjectNameMap = [];


foreach (
    $subjects as $id =>
    $subject
) {

    $key =
        strtolower(
            trim(
                $subject["name"]
            )
        );


    $subjectNameMap[
        $key
    ] = $id;

}


/* =========================================================
   GET QUIZ ATTEMPTS
========================================================= */

$quizSql = "
    SELECT
        qa.attempt_id,
        qa.quiz_id,
        qa.student_id,
        qa.score,
        qa.date_created,
        q.quiz_title,
        q.material_id,
        lm.subject_id AS material_subject_id,
        source_subject.subject_name AS source_subject_name
    FROM quiz_attempt qa
    INNER JOIN quiz q
        ON q.quiz_id = qa.quiz_id
    LEFT JOIN learning_material lm
        ON lm.material_id = q.material_id
    LEFT JOIN subject source_subject
        ON source_subject.subject_id = lm.subject_id
    WHERE qa.student_id = ?
    ORDER BY qa.date_created ASC, qa.attempt_id ASC
";

$quizStmt = $conn->prepare($quizSql);

if (!$quizStmt) {
    response(false, "Quiz query failed: " . $conn->error);
}

$quizStmt->bind_param(
    "i",
    $student_id
);

$quizStmt->execute();

$quizResult =
    $quizStmt->get_result();

$quizAttempts = [];

while (
    $quiz =
    $quizResult->fetch_assoc()
) {

    $attemptId =
        intval(
            $quiz["attempt_id"]
        );

    $quizId =
        intval(
            $quiz["quiz_id"]
        );

    $sourceSubjectId =
        intval(
            $quiz["material_subject_id"] ?? 0
        );

    $sourceSubjectName =
        trim(
            $quiz["source_subject_name"] ?? ""
        );

    $targetSubjectId = 0;


    /* =====================================================
       SUBJECT MATCH
    ===================================================== */

    if (
        $sourceSubjectId > 0 &&
        isset(
            $subjects[$sourceSubjectId]
        )
    ) {

        $targetSubjectId =
            $sourceSubjectId;
    }


    /* =====================================================
       SUBJECT NAME FALLBACK
    ===================================================== */

    if (
        $targetSubjectId === 0 &&
        $sourceSubjectName !== ""
    ) {

        $nameKey =
            strtolower(
                trim(
                    $sourceSubjectName
                )
            );

        if (
            isset(
                $subjectNameMap[$nameKey]
            )
        ) {

            $targetSubjectId =
                $subjectNameMap[
                    $nameKey
                ];
        }
    }


    if (
        $targetSubjectId === 0
    ) {
        continue;
    }


    /* =====================================================
       GET ANSWERS / EARNED POINTS
    ===================================================== */

    $correctAnswers = 0;
    $totalQuestions = 0;
    $earnedPoints = 0;


    $answerSql = "
        SELECT
            COUNT(*) AS total_questions,

            COALESCE(
                SUM(
                    CASE
                        WHEN is_correct = 1
                        THEN 1
                        ELSE 0
                    END
                ),
                0
            ) AS correct_answers,

            COALESCE(
                SUM(points_earned),
                0
            ) AS earned_points

        FROM quiz_attempt_answers

        WHERE
            attempt_id = ?
    ";


    $answerStmt =
        $conn->prepare(
            $answerSql
        );


    if ($answerStmt) {

        $answerStmt->bind_param(
            "i",
            $attemptId
        );

        $answerStmt->execute();

        $answerResult =
            $answerStmt->get_result();

        if ($answerResult) {

            $answer =
                $answerResult->fetch_assoc();

            $totalQuestions =
                intval(
                    $answer["total_questions"] ?? 0
                );

            $correctAnswers =
                intval(
                    $answer["correct_answers"] ?? 0
                );

            $earnedPoints =
                intval(
                    $answer["earned_points"] ?? 0
                );
        }

        $answerStmt->close();
    }


    /* =====================================================
       TOTAL POSSIBLE POINTS
    ===================================================== */

    $totalPoints = 0;


    $pointsStmt =
        $conn->prepare("
            SELECT
                COALESCE(
                    SUM(points),
                    0
                ) AS total_points

            FROM quiz_questions

            WHERE
                quiz_id = ?
        ");


    if ($pointsStmt) {

        $pointsStmt->bind_param(
            "i",
            $quizId
        );

        $pointsStmt->execute();

        $pointsResult =
            $pointsStmt->get_result();

        if ($pointsResult) {

            $pointsData =
                $pointsResult->fetch_assoc();

            $totalPoints =
                intval(
                    $pointsData["total_points"] ?? 0
                );
        }

        $pointsStmt->close();
    }


    /* =====================================================
       STORED SCORE FALLBACK
    ===================================================== */

    $storedScore =
        floatval(
            $quiz["score"] ?? 0
        );


    if (
        $earnedPoints <= 0 &&
        $storedScore > 0 &&
        $totalPoints > 0
    ) {

        $earnedPoints =
            (int) round(
                ($storedScore / 100) *
                $totalPoints
            );
    }


    /* =====================================================
       QUIZ PERCENTAGE
    ===================================================== */

    if (
        $totalPoints > 0
    ) {

        $quizPercentage =
            round(
                ($earnedPoints / $totalPoints) * 100,
                2
            );

    } else {

        $quizPercentage =
            $storedScore;

    }


    /* =====================================================
       STORE ATTEMPT
    ===================================================== */

    $quizAttempts[] = [

        "attempt_id" =>
            $attemptId,

        "quiz_id" =>
            $quizId,

        "subject_id" =>
            $targetSubjectId,

        "score" =>
            $quizPercentage,

        "earned_points" =>
            $earnedPoints,

        "total_points" =>
            $totalPoints,

        "correct_answers" =>
            $correctAnswers,

        "total_questions" =>
            $totalQuestions,

        "date_created" =>
            $quiz["date_created"]

    ];
}


$quizStmt->close();


/* =========================================================
   APPLY QUIZ RESULTS TO SUBJECTS
========================================================= */

$totalQuizzes =
    count(
        $quizAttempts
    );


foreach (
    $quizAttempts as $quiz
) {

    $subjectId =
        intval(
            $quiz["subject_id"]
        );


    if (
        !isset(
            $subjects[$subjectId]
        )
    ) {
        continue;
    }


    $subject =
        &$subjects[$subjectId];


    $subject["quiz_count"]++;


    /* =====================================================
       ACCUMULATED POINTS
    ===================================================== */

    $subject["earned_points"] +=
        intval(
            $quiz["earned_points"]
        );


    $subject["total_points"] +=
        intval(
            $quiz["total_points"]
        );


    $subject["correct_answers"] +=
        intval(
            $quiz["correct_answers"]
        );


    $subject["total_questions"] +=
        intval(
            $quiz["total_questions"]
        );


    if (
        $subject["first_score"] === null
    ) {

        $subject["first_score"] =
            floatval(
                $quiz["score"]
            );
    }


    $subject["latest_score"] =
        floatval(
            $quiz["score"]
        );


    /* =====================================================
       POINT-BASED PROGRESS
    ===================================================== */

    $subject["progress_points"] =
        intval(
            $subject["earned_points"]
        );


    $subject["progress"] =
        min(
            100,
            intval(
                $subject["earned_points"]
            )
        );


    /* =====================================================
       QUIZ SCORE
    ===================================================== */

    $subject["score"] =
        $subject["latest_score"];


    $subject["status"] =
        getStatus(
            $subject["latest_score"]
        );


    $subject["color"] =
        getScoreColor(
            $subject["latest_score"]
        );


    unset(
        $subject
    );
}


/* =========================================================
   SUBJECT IMPROVEMENT
========================================================= */

$overallScores = [];
$firstScores = [];
$latestScores = [];


foreach (
    $subjects as &$subject
) {

    if (
        $subject["quiz_count"] > 0
    ) {

        $improvement =
            round(
                $subject["latest_score"] -
                $subject["first_score"],
                2
            );


        $subject["improvement_rate"] =
            $improvement;


        $overallScores[] =
            $subject["latest_score"];


        $firstScores[] =
            $subject["first_score"];


        $latestScores[] =
            $subject["latest_score"];
    }
}


unset(
    $subject
);


/* =========================================================
   OVERALL QUIZ SCORE
========================================================= */

$overallScore = null;


if (
    count($overallScores) > 0
) {

    $overallScore =
        round(
            array_sum(
                $overallScores
            ) /
            count(
                $overallScores
            ),
            2
        );
}


/* =========================================================
   OVERALL POINT-BASED PROGRESS
========================================================= */

$totalProgressPoints = 0;


foreach (
    $subjects as $subject
) {

    $totalProgressPoints +=
        intval(
            $subject["progress_points"]
        );
}


$overallProgress =
    min(
        100,
        $totalProgressPoints
    );


/* =========================================================
   STRONGEST / WEAKEST
========================================================= */

$strongestSubject = null;
$weakestSubject = null;


foreach (
    $subjects as $subject
) {

    if (
        $subject["quiz_count"] <= 0
    ) {
        continue;
    }


    if (
        $strongestSubject === null ||
        $subject["latest_score"] >
        $strongestSubject["latest_score"]
    ) {

        $strongestSubject =
            $subject;
    }


    if (
        $weakestSubject === null ||
        $subject["latest_score"] <
        $weakestSubject["latest_score"]
    ) {

        $weakestSubject =
            $subject;
    }
}


/* =========================================================
   IMPROVEMENT RATE
========================================================= */

$improvementRate = 0;


if (
    count($firstScores) > 0 &&
    count($latestScores) > 0
) {

    $firstAverage =
        array_sum(
            $firstScores
        ) /
        count(
            $firstScores
        );


    $latestAverage =
        array_sum(
            $latestScores
        ) /
        count(
            $latestScores
        );


    $improvementRate =
        round(
            $latestAverage -
            $firstAverage,
            2
        );
}


/* =========================================================
   XP / LEVEL
========================================================= */

$level =
    floor(
        $xp / 1000
    ) + 1;


$xpRequired =
    $level * 1000;


$levelLabels = [

    1 =>
        "BEGINNER LEARNER",

    2 =>
        "DEVELOPING LEARNER",

    3 =>
        "INTERMEDIATE LEARNER",

    4 =>
        "ADVANCED LEARNER",

    5 =>
        "EXPERT LEARNER"

];


$levelLabel =
    $levelLabels[
        min(
            5,
            $level
        )
    ] ??
    "EXPERT LEARNER";


/* =========================================================
   WEEKLY ACTIVITY
========================================================= */

$weeklyActivity = [

    "Mon" => 0,
    "Tue" => 0,
    "Wed" => 0,
    "Thu" => 0,
    "Fri" => 0,
    "Sat" => 0,
    "Sun" => 0

];


$activityStmt =
    $conn->prepare("
        SELECT

            DATE(date_created)
                AS activity_date,

            COUNT(*)
                AS quiz_count

        FROM quiz_attempt

        WHERE

            student_id = ?

            AND

            date_created >= DATE_SUB(
                CURDATE(),
                INTERVAL 6 DAY
            )

        GROUP BY
            DATE(date_created)

        ORDER BY
            activity_date ASC
    ");


if ($activityStmt) {

    $activityStmt->bind_param(
        "i",
        $student_id
    );


    $activityStmt->execute();


    $activityResult =
        $activityStmt->get_result();


    while (
        $row =
        $activityResult->fetch_assoc()
    ) {

        $day =
            date(
                "D",
                strtotime(
                    $row["activity_date"]
                )
            );


        if (
            isset(
                $weeklyActivity[$day]
            )
        ) {

            $weeklyActivity[$day] =
                intval(
                    $row["quiz_count"]
                );
        }
    }


    $activityStmt->close();
}


$maxActivity =
    max(
        $weeklyActivity
    );


$weekly = [];


foreach (
    $weeklyActivity as $day => $count
) {

    $value = 0;


    if (
        $maxActivity > 0
    ) {

        $value =
            round(
                ($count / $maxActivity) * 100
            );
    }


    $weekly[] = [

        "label" =>
            $day,

        "value" =>
            $value,

        "quizCount" =>
            $count

    ];
}


/* =========================================================
   FINAL RESPONSE
========================================================= */

$data = [

    "student_id" =>
        $student_id,

    "student_name" =>
        $student_name,

    "strand_id" =>
        $strand_id,

    "strand_name" =>
        $student["strand_name"] ?? "",

    "specialization_id" =>
        $specialization_id,

    "specialization_name" =>
        $student["specialization_name"] ?? "",

    "grade_level" =>
        $grade_level,

    "academic_id" =>
        $academic_id,

    "school_year" =>
        $student["academic_year"] ?? "",

    "semester" =>
        $semester,

    "overall_score" =>
        $overallScore,

    "overall_progress" =>
        $overallProgress,

    "total_progress_points" =>
        $totalProgressPoints,

    "improvement_rate" =>
        $improvementRate,

    "quizzes_done" =>
        $totalQuizzes,

    "total_quiz_attempts" =>
        count(
            $quizAttempts
        ),

    "total_subjects" =>
        count(
            $subjects
        ),

    "subjects_with_quiz" =>
        count(
            array_filter(
                $subjects,
                function ($subject) {

                    return
                        $subject["quiz_count"] > 0;

                }
            )
        ),

    "subjects_without_quiz" =>
        count(
            array_filter(
                $subjects,
                function ($subject) {

                    return
                        $subject["quiz_count"] === 0;

                }
            )
        ),

    /* =====================================================
       XP VALUES
    ===================================================== */

    "xp_current" =>
        $xp,

    "diagnostic_xp" =>
        $diagnosticPoints,

    "quiz_xp" =>
        $quizXpPoints,

    "xp_required" =>
        $xpRequired,

    "gamification_level" =>
        $level,

    "level_label" =>
        $levelLabel,

    "subjects" =>
        array_values(
            $subjects
        ),

    "strongest_subject" =>
        $strongestSubject,

    "weakest_subject" =>
        $weakestSubject,

    "weekly_activity" =>
        $weekly

];


$conn->close();


response(
    true,
    "Analytics loaded successfully.",
    $data
);

?>