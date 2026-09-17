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

header(
    "Access-Control-Allow-Origin: http://localhost:8100"
);

header(
    "Access-Control-Allow-Headers: Content-Type"
);

header(
    "Access-Control-Allow-Methods: GET, OPTIONS"
);


// =========================================================
// PREFLIGHT
// =========================================================

if (
    $_SERVER["REQUEST_METHOD"] === "OPTIONS"
) {

    http_response_code(200);

    echo json_encode([
        "status" => "success",
        "message" => "OK"
    ]);

    exit;

}


// =========================================================
// ONLY GET
// =========================================================

if (
    $_SERVER["REQUEST_METHOD"] !== "GET"
) {

    http_response_code(405);

    echo json_encode([

        "status" => "error",

        "message" =>
            "Only GET requests are allowed."

    ]);

    exit;

}


// =========================================================
// DATABASE
// =========================================================

$conn = brainpal_db();


if (
    $conn->connect_error
) {

    http_response_code(500);

    echo json_encode([

        "status" => "error",

        "message" =>
            "Database connection failed."

    ]);

    exit;

}


$conn->set_charset("utf8mb4");


// =========================================================
// GET USER ID
// =========================================================

$user_id = intval(

    $_GET["user_id"] ??
    $_GET["student_id"] ??
    0

);


// =========================================================
// VALIDATE USER
// =========================================================

if (
    $user_id <= 0
) {

    echo json_encode([

        "status" => "error",

        "message" =>
            "Invalid student ID."

    ]);

    $conn->close();

    exit;

}


// =========================================================
// GET STUDENT
// =========================================================

$studentStmt = $conn->prepare("

    SELECT

        student_id,

        firstName,

        m_initial,

        lastName,

        points,

        strand_id,

        specialization_id,

        academic_id,

        grade_level,

        current_streak,

        longest_streak,

        total_opens,

        last_open_date

    FROM student

    WHERE

        student_id = ?

        AND

        date_deleted IS NULL

    LIMIT 1

");


if (
    !$studentStmt
) {

    http_response_code(500);

    echo json_encode([

        "status" => "error",

        "message" =>
            "Failed to prepare student query.",

        "error" =>
            $conn->error

    ]);

    $conn->close();

    exit;

}


$studentStmt->bind_param(

    "i",

    $user_id

);


if (
    !$studentStmt->execute()
) {

    http_response_code(500);

    echo json_encode([

        "status" => "error",

        "message" =>
            "Failed to load student."

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

        "status" => "error",

        "message" =>
            "Student account not found."

    ]);

    $studentStmt->close();

    $conn->close();

    exit;

}


$student =
    $studentResult->fetch_assoc();


$studentStmt->close();


// =========================================================
// STUDENT VALUES
// =========================================================

$xp =
    intval(
        $student["points"] ?? 0
    );

$student_strand_id =
    intval(
        $student["strand_id"] ?? 0
    );

$current_streak =
    intval(
        $student["current_streak"] ?? 0
    );

$longest_streak =
    intval(
        $student["longest_streak"] ?? 0
    );

$total_opens =
    intval(
        $student["total_opens"] ?? 0
    );

$last_open_date =
    $student["last_open_date"] ?? null;


// =========================================================
// UPDATE DAILY STREAK
//
// Uses student.last_open_date.
//
// Same day:
//   no change
//
// Yesterday:
//   +1 streak
//
// Older date / no date:
//   reset to 1
// =========================================================

$today =
    date("Y-m-d");


if (
    empty($last_open_date)
) {

    $current_streak = 1;

    $total_opens++;

    $updateOpen =
        $conn->prepare("

            UPDATE student

            SET

                current_streak = ?,

                longest_streak =
                    GREATEST(
                        longest_streak,
                        ?
                    ),

                total_opens = ?,

                last_open_date = ?

            WHERE
                student_id = ?

        ");

    if ($updateOpen) {

        $updateOpen->bind_param(

            "iiisi",

            $current_streak,

            $current_streak,

            $total_opens,

            $today,

            $user_id

        );

        $updateOpen->execute();

        $updateOpen->close();

    }

}

else {

    $lastDate =
        new DateTime(
            $last_open_date
        );

    $todayDate =
        new DateTime(
            $today
        );

    $difference =
        (int)$lastDate->diff(
            $todayDate
        )->format("%r%a");


    if (
        $difference === 0
    ) {

        // Same day.
        // Do not increase streak.

    }

    elseif (
        $difference === 1
    ) {

        $current_streak++;

        $total_opens++;

        if (
            $current_streak >
            $longest_streak
        ) {

            $longest_streak =
                $current_streak;

        }


        $updateOpen =
            $conn->prepare("

                UPDATE student

                SET

                    current_streak = ?,

                    longest_streak = ?,

                    total_opens = ?,

                    last_open_date = ?

                WHERE
                    student_id = ?

            ");

        if ($updateOpen) {

            $updateOpen->bind_param(

                "iiisi",

                $current_streak,

                $longest_streak,

                $total_opens,

                $today,

                $user_id

            );

            $updateOpen->execute();

            $updateOpen->close();

        }

    }

    else {

        // Missed one or more days.

        $current_streak = 1;

        $total_opens++;


        $updateOpen =
            $conn->prepare("

                UPDATE student

                SET

                    current_streak = ?,

                    longest_streak =
                        GREATEST(
                            longest_streak,
                            ?
                        ),

                    total_opens = ?,

                    last_open_date = ?

                WHERE
                    student_id = ?

            ");

        if ($updateOpen) {

            $updateOpen->bind_param(

                "iiisi",

                $current_streak,

                $longest_streak,

                $total_opens,

                $today,

                $user_id

            );

            $updateOpen->execute();

            $updateOpen->close();

        }

    }

}


// =========================================================
// LEVEL SYSTEM
//
// 0 - 199    = Level 1 Beginner
// 200 - 499  = Level 2 Intermediate
// 500 - 999  = Level 3 Advanced
// 1000+      = Level 4 Master
// =========================================================

if (
    $xp < 200
) {

    $level = 1;

    $level_name =
        "Beginner";

    $previous_level_xp = 0;

    $next_level_xp = 200;

}

elseif (
    $xp < 500
) {

    $level = 2;

    $level_name =
        "Intermediate";

    $previous_level_xp = 200;

    $next_level_xp = 500;

}

elseif (
    $xp < 1000
) {

    $level = 3;

    $level_name =
        "Advanced";

    $previous_level_xp = 500;

    $next_level_xp = 1000;

}

else {

    $level = 4;

    $level_name =
        "Master";

    $previous_level_xp = 1000;

    /*
     * Master has no next level.
     *
     * Keep 100% progress.
     */

    $next_level_xp = 1000;

}


// =========================================================
// XP PROGRESS
// =========================================================

if (
    $level >= 4
) {

    $xp_progress = 1;

}

else {

    $level_range =
        $next_level_xp -
        $previous_level_xp;

    $earned_in_level =
        $xp -
        $previous_level_xp;

    if (
        $level_range > 0
    ) {

        $xp_progress =
            $earned_in_level /
            $level_range;

    }

    else {

        $xp_progress = 0;

    }


    $xp_progress =
        max(
            0,
            min(
                1,
                $xp_progress
            )
        );

}


// =========================================================
// QUIZ STATISTICS
// =========================================================

$quiz_count = 0;

$highest_score = 0;

$average_score = 0;


$quizStmt =
    $conn->prepare("

        SELECT

            COUNT(*) AS quiz_count,

            COALESCE(
                MAX(score),
                0
            ) AS highest_score,

            COALESCE(
                AVG(score),
                0
            ) AS average_score

        FROM quiz_attempt

        WHERE
            student_id = ?

    ");


if (
    $quizStmt
) {

    $quizStmt->bind_param(
        "i",
        $user_id
    );

    if (
        $quizStmt->execute()
    ) {

        $quizResult =
            $quizStmt->get_result();

        if (
            $quizRow =
                $quizResult->fetch_assoc()
        ) {

            $quiz_count =
                intval(
                    $quizRow["quiz_count"] ??
                    0
                );

            $highest_score =
                floatval(
                    $quizRow["highest_score"] ??
                    0
                );

            $average_score =
                round(
                    floatval(
                        $quizRow["average_score"] ??
                        0
                    ),
                    2
                );

        }

    }

    $quizStmt->close();

}


// =========================================================
// STRAND NAME
// =========================================================

$strand_name =
    "Your Strand";


if (
    $student_strand_id > 0
) {

    $strandStmt =
        $conn->prepare("

            SELECT
                strand_name

            FROM strand

            WHERE
                strand_id = ?

                AND

                date_deleted IS NULL

            LIMIT 1

        ");


    if ($strandStmt) {

        $strandStmt->bind_param(
            "i",
            $student_strand_id
        );

        if (
            $strandStmt->execute()
        ) {

            $strandResult =
                $strandStmt->get_result();

            if (
                $strandRow =
                    $strandResult->fetch_assoc()
            ) {

                $strand_name =
                    $strandRow["strand_name"] ??
                    "Your Strand";

            }

        }

        $strandStmt->close();

    }

}


// =========================================================
// STRAND STAR
//
// Student earns Strand Star when:
//
// 1. Student has assigned learning materials.
// 2. Those materials belong to the student's strand.
// 3. Every assigned material has at least
//    one completed quiz attempt.
// =========================================================

$strand_total_materials = 0;

$strand_completed_materials = 0;

$strand_progress = 0;


$strandStmt =
    $conn->prepare("

        SELECT

            COUNT(
                DISTINCT lm.material_id
            ) AS total_materials,

            COUNT(
                DISTINCT
                CASE

                    WHEN qa.attempt_id IS NOT NULL

                    THEN lm.material_id

                END
            ) AS completed_materials

        FROM learning_material lm

        INNER JOIN study_schedule ss

            ON ss.schedule_id =
               lm.schedule_id

            AND ss.student_id = ?

            AND ss.date_deleted IS NULL

        INNER JOIN subject sub

            ON sub.subject_id =
               lm.subject_id

            AND sub.strand_id = ?

            AND sub.date_deleted IS NULL

        LEFT JOIN quiz q

            ON q.material_id =
               lm.material_id

        LEFT JOIN quiz_attempt qa

            ON qa.quiz_id =
               q.quiz_id

            AND qa.student_id = ?

        WHERE

            lm.date_deleted IS NULL

    ");


if ($strandStmt) {

    $strandStmt->bind_param(

        "iii",

        $user_id,

        $student_strand_id,

        $user_id

    );


    if (
        $strandStmt->execute()
    ) {

        $strandResult =
            $strandStmt->get_result();

        if (
            $strandRow =
                $strandResult->fetch_assoc()
        ) {

            $strand_total_materials =
                intval(
                    $strandRow[
                        "total_materials"
                    ] ?? 0
                );

            $strand_completed_materials =
                intval(
                    $strandRow[
                        "completed_materials"
                    ] ?? 0
                );

        }

    }

    $strandStmt->close();

}


if (
    $strand_total_materials > 0
) {

    $strand_progress =
        round(

            (
                $strand_completed_materials /
                $strand_total_materials
            ) * 100

        );

}


$strand_badge_earned =

    $strand_total_materials > 0 &&

    $strand_completed_materials >=
    $strand_total_materials;


// =========================================================
// MASTERY
//
// Mastery:
//
// All assigned learning materials that have
// quizzes must have an attempt.
//
// AND average quiz score must be >= 80.
// =========================================================

$mastery_total_materials = 0;

$mastery_completed_materials = 0;

$mastery_average_score = 0;


$masteryStmt =
    $conn->prepare("

        SELECT

            COUNT(
                DISTINCT lm.material_id
            ) AS total_materials,

            COUNT(
                DISTINCT
                CASE

                    WHEN qa.attempt_id IS NOT NULL

                    THEN lm.material_id

                END
            ) AS completed_materials,

            COALESCE(
                AVG(
                    qa.score
                ),
                0
            ) AS average_score

        FROM learning_material lm

        INNER JOIN study_schedule ss

            ON ss.schedule_id =
               lm.schedule_id

            AND ss.student_id = ?

            AND ss.date_deleted IS NULL

        INNER JOIN quiz q

            ON q.material_id =
               lm.material_id

        LEFT JOIN quiz_attempt qa

            ON qa.quiz_id =
               q.quiz_id

            AND qa.student_id = ?

        WHERE

            lm.date_deleted IS NULL

    ");


if ($masteryStmt) {

    $masteryStmt->bind_param(

        "ii",

        $user_id,

        $user_id

    );


    if (
        $masteryStmt->execute()
    ) {

        $masteryResult =
            $masteryStmt->get_result();

        if (
            $masteryRow =
                $masteryResult->fetch_assoc()
        ) {

            $mastery_total_materials =
                intval(
                    $masteryRow[
                        "total_materials"
                    ] ?? 0
                );

            $mastery_completed_materials =
                intval(
                    $masteryRow[
                        "completed_materials"
                    ] ?? 0
                );

            $mastery_average_score =
                round(
                    floatval(
                        $masteryRow[
                            "average_score"
                        ] ?? 0
                    ),
                    2
                );

        }

    }

    $masteryStmt->close();

}


$mastery_badge_earned =

    $mastery_total_materials > 0 &&

    $mastery_completed_materials >=
    $mastery_total_materials &&

    $mastery_average_score >= 80;


// =========================================================
// OTHER BADGES
// =========================================================

$first_quiz_earned =
    $quiz_count >= 1;


$top_scorer_earned =
    $highest_score >= 90;


$streak_master_earned =
    $longest_streak >= 7;


$advanced_earned =
    $longest_streak >= 30;


// =========================================================
// BADGES
// =========================================================

$badges = [

    [

        "key" =>
            "streak_master",

        "icon" =>
            "🔥",

        "title" =>
            "Streak Master",

        "description" =>
            "Maintain a 7-day study streak.",

        "earned" =>
            $streak_master_earned

    ],

    [

        "key" =>
            "first_quiz",

        "icon" =>
            "⭐",

        "title" =>
            "First Quiz",

        "description" =>
            "Complete your first quiz.",

        "earned" =>
            $first_quiz_earned

    ],

    [

        "key" =>
            "strand_star",

        "icon" =>
            "🌟",

        "title" =>
            (
                $strand_name !==
                "Your Strand"

                    ?

                strtoupper(
                    $strand_name
                ) . " STAR"

                    :

                "Strand Star"
            ),

        "description" =>
            "Complete all assigned learning materials in your strand.",

        "earned" =>
            $strand_badge_earned

    ],

    [

        "key" =>
            "top_scorer",

        "icon" =>
            "🏆",

        "title" =>
            "Top Scorer",

        "description" =>
            "Score 90% or higher on a quiz.",

        "earned" =>
            $top_scorer_earned

    ],

    [

        "key" =>
            "mastery",

        "icon" =>
            "💎",

        "title" =>
            "Mastery",

        "description" =>
            "Complete all assigned quizzes with an average score of at least 80%.",

        "earned" =>
            $mastery_badge_earned

    ],

    [

        "key" =>
            "advanced",

        "icon" =>
            "🚀",

        "title" =>
            "Advanced",

        "description" =>
            "Maintain a 30-day study streak.",

        "earned" =>
            $advanced_earned

    ]

];


// =========================================================
// BADGE COUNT
// =========================================================

$total_badges =
    count($badges);


$earned_badges = 0;


foreach (
    $badges as $badge
) {

    if (
        $badge["earned"]
    ) {

        $earned_badges++;

    }

}


$badge_percentage =

    $total_badges > 0

        ?

    round(

        (
            $earned_badges /
            $total_badges
        ) * 100

    )

        :

    0;


// =========================================================
// RANK / MEDAL
// =========================================================

$rank_badge =
    "No Badge";

$rank_medal =
    "";


if (
    $xp >= 1000
) {

    $rank_badge =
        "Master";

    $rank_medal =
        "🏆";

}

elseif (
    $xp >= 500
) {

    $rank_badge =
        "Advanced";

    $rank_medal =
        "🥇";

}

elseif (
    $xp >= 200
) {

    $rank_badge =
        "Intermediate";

    $rank_medal =
        "🥈";

}

elseif (
    $xp > 0
) {

    $rank_badge =
        "Beginner";

    $rank_medal =
        "🥉";

}


// =========================================================
// RESPONSE
// =========================================================

echo json_encode([

    "status" =>
        "success",

    "user_id" =>
        $user_id,

    // -------------------------
    // XP
    // -------------------------

    "xp" =>
        $xp,

    "level" =>
        $level,

    "level_name" =>
        $level_name,

    "current_xp" =>
        $xp,

    "previous_level_xp" =>
        $previous_level_xp,

    "next_level_xp" =>
        $next_level_xp,

    "xp_progress" =>
        round(
            $xp_progress,
            4
        ),

    // -------------------------
    // STREAK
    // -------------------------

    "streak" =>
        $current_streak,

    "current_streak" =>
        $current_streak,

    "longest_streak" =>
        $longest_streak,

    "total_opens" =>
        $total_opens,

    "last_open_date" =>
        $today,

    // -------------------------
    // QUIZ
    // -------------------------

    "quiz_count" =>
        $quiz_count,

    "quizzes" =>
        $quiz_count,

    "highest_quiz_score" =>
        $highest_score,

    "average_quiz_score" =>
        $average_score,

    // -------------------------
    // STRAND
    // -------------------------

    "strand_id" =>
        $student_strand_id,

    "strand_name" =>
        $strand_name,

    "strand_total_materials" =>
        $strand_total_materials,

    "strand_completed_materials" =>
        $strand_completed_materials,

    "strand_progress" =>
        $strand_progress,

    // -------------------------
    // MASTERY
    // -------------------------

    "mastery_total_materials" =>
        $mastery_total_materials,

    "mastery_completed_materials" =>
        $mastery_completed_materials,

    "mastery_average_score" =>
        $mastery_average_score,

    // -------------------------
    // BADGES
    // -------------------------

    "earned_badges" =>
        $earned_badges,

    "total_badges" =>
        $total_badges,

    "badge_percentage" =>
        $badge_percentage,

    "badges" =>
        $badges,

    // -------------------------
    // RANK
    // -------------------------

    "badge" =>
        $rank_badge,

    "medal" =>
        $rank_medal,

    // -------------------------
    // SOURCES
    // -------------------------

    "badge_sources" => [

        "first_quiz" =>
            "quiz_attempt",

        "top_scorer" =>
            "quiz_attempt.score",

        "streak_master" =>
            "student.current_streak / longest_streak",

        "advanced" =>
            "student.longest_streak",

        "strand_star" =>
            "learning_material + study_schedule + quiz_attempt",

        "mastery" =>
            "learning_material + quiz + quiz_attempt"

    ]

], JSON_UNESCAPED_UNICODE);

$conn->close();

exit;

?>