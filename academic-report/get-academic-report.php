<?php
require_once __DIR__ . '/../_shared/db.php';

error_reporting(E_ALL);
ini_set('display_errors', 0);

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
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");


/*
|--------------------------------------------------------------------------
| OPTIONS / CORS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {

    http_response_code(200);

    exit();

}


/*
|--------------------------------------------------------------------------
| DATABASE CONNECTION
|--------------------------------------------------------------------------
*/

$conn = brainpal_db();


if ($conn->connect_error) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database connection failed."
    ]);

    exit();

}


$conn->set_charset("utf8mb4");


/*
|--------------------------------------------------------------------------
| GET STUDENT ID
|--------------------------------------------------------------------------
*/

$student_id = isset($_GET['student_id'])
    ? intval($_GET['student_id'])
    : 0;


if ($student_id <= 0) {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Invalid student ID."
    ]);

    $conn->close();

    exit();

}


/*
|--------------------------------------------------------------------------
| GET STUDENT INFORMATION
|--------------------------------------------------------------------------
*/

$studentSql = "

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

        AND st.date_deleted IS NULL

    LEFT JOIN specialization sp

        ON sp.specialization_id =
           s.specialization_id

    LEFT JOIN academic a

        ON a.academic_id = s.academic_id

        AND a.date_deleted IS NULL

    WHERE

        s.student_id = ?

        AND

        s.date_deleted IS NULL

    LIMIT 1

";


$stmt = $conn->prepare(
    $studentSql
);


if (!$stmt) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Unable to prepare student query."
    ]);

    $conn->close();

    exit();

}


$stmt->bind_param(
    "i",
    $student_id
);


$stmt->execute();


$result =
    $stmt->get_result();


$student =
    $result->fetch_assoc();


$stmt->close();


if (!$student) {

    http_response_code(404);

    echo json_encode([
        "success" => false,
        "message" => "Student record not found."
    ]);

    $conn->close();

    exit();

}


/*
|--------------------------------------------------------------------------
| STUDENT DATA
|--------------------------------------------------------------------------
*/

$strandId =
    intval(
        $student['strand_id'] ?? 0
    );


$specializationId =
    intval(
        $student['specialization_id'] ?? 0
    );


$academicId =
    intval(
        $student['academic_id'] ?? 0
    );


$gradeLevel =
    intval(
        $student['grade_level'] ?? 0
    );


/*
|--------------------------------------------------------------------------
| FULL NAME
|--------------------------------------------------------------------------
*/

$middleInitial =
    trim(
        $student['m_initial'] ?? ''
    );


$fullName =
    trim(

        ($student['firstName'] ?? '') .

        ' ' .

        (
            $middleInitial !== ''
                ? $middleInitial . ' '
                : ''
        ) .

        ($student['lastName'] ?? '')

    );


/*
|--------------------------------------------------------------------------
| CURRENT SEMESTER
|--------------------------------------------------------------------------
|
| June - October = 1st Semester
| November - May = 2nd Semester
|
|--------------------------------------------------------------------------
*/

$currentMonth =
    intval(
        date('n')
    );


if (
    $currentMonth >= 6
    &&
    $currentMonth <= 10
) {

    $currentSemester = '1st';

} else {

    $currentSemester = '2nd';

}


/*
|--------------------------------------------------------------------------
| HELPER FUNCTIONS
|--------------------------------------------------------------------------
*/


function getLevel(
    ?float $score
): string {

    if ($score === null) {

        return "No Data";

    }


    if ($score >= 90) {

        return "Advanced";

    }


    if ($score >= 75) {

        return "Proficient";

    }


    if ($score >= 60) {

        return "Developing";

    }


    return "Beginner";

}


/*
|--------------------------------------------------------------------------
| DIAGNOSTIC FOCUS
|--------------------------------------------------------------------------
*/

function getDiagnosticFocus(
    ?float $diagnosticPercentage
): string {

    if ($diagnosticPercentage === null) {

        return "No Diagnostic";

    }


    if ($diagnosticPercentage < 60) {

        return "High Focus";

    }


    if ($diagnosticPercentage < 75) {

        return "Moderate Focus";

    }


    return "No Focus";

}


/*
|--------------------------------------------------------------------------
| STATUS
|--------------------------------------------------------------------------
*/

function getStatus(
    ?float $score
): string {

    if ($score === null) {

        return "No Activity Yet";

    }


    if ($score < 60) {

        return "Needs Improvement";

    }


    if ($score < 75) {

        return "Needs Review";

    }


    if ($score < 90) {

        return "Good Progress";

    }


    return "Excellent";

}


/*
|--------------------------------------------------------------------------
| COLOR
|--------------------------------------------------------------------------
*/

function getColor(
    ?float $score
): string {

    if ($score === null) {

        return "#9A9A9A";

    }


    if ($score < 60) {

        return "#D52F2F";

    }


    if ($score < 75) {

        return "#E6AA00";

    }


    return "#25C33F";

}


/*
|--------------------------------------------------------------------------
| GET SUBJECTS
|--------------------------------------------------------------------------
*/

$subjectSql = "

    SELECT

        sub.subject_id,

        sub.subject_name,

        sub.semester,

        sub.grade_level,

        sub.strand_id,

        sub.specialization_id

    FROM subject sub

    WHERE

        sub.date_deleted IS NULL

        AND

        sub.strand_id = ?

        AND

        (
            sub.grade_level IS NULL

            OR

            sub.grade_level = ?
        )

        AND

        (
            sub.specialization_id IS NULL

            OR

            sub.specialization_id = 0

            OR

            sub.specialization_id = ?
        )

        AND

        sub.semester = ?

    ORDER BY

        sub.subject_name ASC

";


$stmtSubjects =
    $conn->prepare(
        $subjectSql
    );


if (!$stmtSubjects) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Unable to prepare subject query."
    ]);

    $conn->close();

    exit();

}


$stmtSubjects->bind_param(

    "iiis",

    $strandId,

    $gradeLevel,

    $specializationId,

    $currentSemester

);


$stmtSubjects->execute();


$resultSubjects =
    $stmtSubjects->get_result();


$subjects = [];


/*
|--------------------------------------------------------------------------
| PROCESS EACH SUBJECT
|--------------------------------------------------------------------------
*/

while (
    $subject =
    $resultSubjects->fetch_assoc()
) {

    $subjectId =
        intval(
            $subject['subject_id']
        );


    /*
    |--------------------------------------------------------------------------
    | DIAGNOSTIC RESULT
    |--------------------------------------------------------------------------
    */

    $diagnosticSql = "

        SELECT

            dr.total_score,

            dr.total_questions,

            dr.date_taken

        FROM diagnostic_result dr

        INNER JOIN diagnostic d

            ON d.diagnostic_id =
               dr.diagnostic_id

        WHERE

            dr.student_id = ?

            AND

            dr.academic_id = ?

            AND

            d.subject_id = ?

            AND

            dr.date_deleted IS NULL

            AND

            d.date_deleted IS NULL

            AND

            dr.total_questions > 0

        ORDER BY

            dr.date_taken DESC

        LIMIT 1

    ";


    $stmtDiagnostic =
        $conn->prepare(
            $diagnosticSql
        );


    $diagnostic = null;


    if ($stmtDiagnostic) {

        $stmtDiagnostic->bind_param(

            "iii",

            $student_id,

            $academicId,

            $subjectId

        );


        $stmtDiagnostic->execute();


        $diagnosticResult =
            $stmtDiagnostic->get_result();


        $diagnostic =
            $diagnosticResult->fetch_assoc();


        $stmtDiagnostic->close();

    }


    /*
    |--------------------------------------------------------------------------
    | DIAGNOSTIC PERCENTAGE
    |--------------------------------------------------------------------------
    */

    $diagnosticPercentage =
        null;


    if (

        $diagnostic !== null

        &&

        intval(
            $diagnostic['total_questions']
        ) > 0

    ) {

        $diagnosticPercentage =

            (

                floatval(
                    $diagnostic['total_score']
                )

                /

                floatval(
                    $diagnostic['total_questions']
                )

            )

            * 100;


        $diagnosticPercentage =
            round(
                $diagnosticPercentage,
                2
            );

    }


    /*
    |--------------------------------------------------------------------------
    | QUIZ / XP PERFORMANCE
    |--------------------------------------------------------------------------
    |
    | IMPORTANT:
    |
    | qa.score
    |     = quiz percentage
    |
    | qa.earned_points
    |     = XP earned
    |
    | Academic Report percentage
    |     = XP progress
    |
    | 5 XP  -> 5%
    | 20 XP -> 20%
    | 100 XP -> 100%
    |
    |--------------------------------------------------------------------------
    */

    $quizSql = "

        SELECT

            COUNT(
                DISTINCT qa.attempt_id
            ) AS quiz_attempts,

            COALESCE(
                SUM(
                    qa.earned_points
                ),
                0
            ) AS earned_points,

            COALESCE(
                SUM(
                    qa.total_points
                ),
                0
            ) AS possible_points,

            COALESCE(
                AVG(
                    qa.score
                ),
                0
            ) AS average_quiz_score,

            COALESCE(
                MAX(
                    qa.score
                ),
                0
            ) AS highest_quiz_score

        FROM quiz_attempt qa

        INNER JOIN quiz q

            ON q.quiz_id =
               qa.quiz_id

        INNER JOIN learning_material lm

            ON lm.material_id =
               q.material_id

        WHERE

            qa.student_id = ?

            AND

            lm.subject_id = ?

            AND

            lm.academic_id = ?

    ";


    $stmtQuiz =
        $conn->prepare(
            $quizSql
        );


    $quiz = null;


    if ($stmtQuiz) {

        $stmtQuiz->bind_param(

            "iii",

            $student_id,

            $subjectId,

            $academicId

        );


        $stmtQuiz->execute();


        $quizResult =
            $stmtQuiz->get_result();


        $quiz =
            $quizResult->fetch_assoc();


        $stmtQuiz->close();

    }


    /*
    |--------------------------------------------------------------------------
    | QUIZ DATA
    |--------------------------------------------------------------------------
    */

    $quizAttempts =
        intval(
            $quiz['quiz_attempts'] ?? 0
        );


    $earnedPoints =
        floatval(
            $quiz['earned_points'] ?? 0
        );


    $possiblePoints =
        floatval(
            $quiz['possible_points'] ?? 0
        );


    $averageQuizScore =
        floatval(
            $quiz['average_quiz_score'] ?? 0
        );


    $highestQuizScore =
        floatval(
            $quiz['highest_quiz_score'] ?? 0
        );


    /*
    |--------------------------------------------------------------------------
    | XP PROGRESS
    |--------------------------------------------------------------------------
    |
    | THIS IS THE VALUE USED BY ACADEMIC REPORT.
    |
    | The project currently treats 100 XP as 100%.
    |
    |--------------------------------------------------------------------------
    */

    $xpPercentage = null;


    if (
        $quizAttempts > 0
    ) {

        $xpPercentage =

            min(
                100,
                max(
                    0,
                    round(
                        $earnedPoints,
                        2
                    )
                )
            );

    }


    /*
    |--------------------------------------------------------------------------
    | QUIZ SCORE PERCENTAGE
    |--------------------------------------------------------------------------
    |
    | This is kept separately.
    |
    | Example:
    |
    | earned_points = 5
    | total_points  = 5
    |
    | quiz_score = 100%
    | XP progress = 5%
    |
    |--------------------------------------------------------------------------
    */

    $quizScorePercentage =
        null;


    if (
        $possiblePoints > 0
        &&
        $quizAttempts > 0
    ) {

        $quizScorePercentage =

            (
                $earnedPoints
                /
                $possiblePoints
            )

            * 100;


        $quizScorePercentage =

            min(
                100,
                max(
                    0,
                    round(
                        $quizScorePercentage,
                        2
                    )
                )
            );

    }


    /*
    |--------------------------------------------------------------------------
    | FINAL SUBJECT PERCENTAGE
    |--------------------------------------------------------------------------
    |
    | Academic Report percentage is XP progress.
    |
    |--------------------------------------------------------------------------
    */

    $percentage =
        $xpPercentage;


    /*
    |--------------------------------------------------------------------------
    | IMPROVEMENT RATE
    |--------------------------------------------------------------------------
    |
    | Compare quiz score against diagnostic score.
    |
    | This is NOT used for XP percentage.
    |
    |--------------------------------------------------------------------------
    */

    $improvementRate =
        0;


    if (

        $diagnosticPercentage !== null

        &&

        $quizScorePercentage !== null

    ) {

        $improvementRate =

            round(

                $quizScorePercentage
                -
                $diagnosticPercentage,

                2

            );

    }


    /*
    |--------------------------------------------------------------------------
    | FOCUS
    |--------------------------------------------------------------------------
    */

    $focus =
        getDiagnosticFocus(
            $diagnosticPercentage
        );


    /*
    |--------------------------------------------------------------------------
    | SUBJECT DATA
    |--------------------------------------------------------------------------
    */

    $subjects[] = [

        "subject_id" =>
            $subjectId,

        "name" =>
            $subject['subject_name'],

        "semester" =>
            $subject['semester'],

        "grade_level" =>
            intval(
                $subject['grade_level']
            ),

        /*
        | Main Academic Report percentage.
        |
        | This is XP progress.
        */

        "percentage" =>
            $percentage,

        /*
        | XP information.
        */

        "earned_points" =>
            $earnedPoints,

        "xp" =>
            $earnedPoints,

        "xp_percentage" =>
            $xpPercentage,

        /*
        | Quiz information.
        */

        "quiz" =>
            $quizAttempts,

        "quiz_percentage" =>
            $quizScorePercentage,

        "average_quiz_score" =>
            round(
                $averageQuizScore,
                2
            ),

        "highest_quiz_score" =>
            round(
                $highestQuizScore,
                2
            ),

        "possible_points" =>
            $possiblePoints,

        /*
        | Diagnostic.
        */

        "diagnostic_percentage" =>
            $diagnosticPercentage,

        /*
        | Improvement.
        */

        "improvement_rate" =>
            $improvementRate,

        /*
        | Classification.
        */

        "level" =>
            getLevel(
                $percentage
            ),

        "focus" =>
            $focus,

        "status" =>
            getStatus(
                $percentage
            ),

        "color" =>
            getColor(
                $percentage
            )

    ];

}


$stmtSubjects->close();


/*
|--------------------------------------------------------------------------
| SUBJECTS WITH DATA
|--------------------------------------------------------------------------
*/

$subjectsWithData = [];


foreach (
    $subjects
    as $subject
) {

    if (
        $subject['percentage']
        !== null
    ) {

        $subjectsWithData[] =
            $subject;

    }

}


/*
|--------------------------------------------------------------------------
| OVERALL SCORE
|--------------------------------------------------------------------------
|
| Average XP progress across subjects.
|
|--------------------------------------------------------------------------
*/

$overallScore =
    null;


if (
    count($subjectsWithData) > 0
) {

    $totalScore =
        0;


    foreach (
        $subjectsWithData
        as $subject
    ) {

        $totalScore +=

            floatval(
                $subject['percentage']
            );

    }


    $overallScore =

        round(

            $totalScore
            /
            count($subjectsWithData),

            2

        );


    /*
    | Safety limit.
    */

    $overallScore =
        min(
            100,
            max(
                0,
                $overallScore
            )
        );

}


/*
|--------------------------------------------------------------------------
| TOTAL QUIZ ATTEMPTS
|--------------------------------------------------------------------------
*/

$quizCount =
    0;


foreach (
    $subjects
    as $subject
) {

    $quizCount +=

        intval(
            $subject['quiz']
        );

}


/*
|--------------------------------------------------------------------------
| FOCUS SUBJECTS
|--------------------------------------------------------------------------
*/

$focusSubjects = [];


foreach (
    $subjects
    as $subject
) {

    if (

        $subject['focus']
        === 'High Focus'

        ||

        $subject['focus']
        === 'Moderate Focus'

    ) {

        $focusSubjects[] =
            $subject;

    }

}


/*
|--------------------------------------------------------------------------
| SORT FOCUS SUBJECTS
|--------------------------------------------------------------------------
*/

usort(

    $focusSubjects,

    function (
        $a,
        $b
    ) {

        $aScore =
            $a['diagnostic_percentage']
            ?? 999;


        $bScore =
            $b['diagnostic_percentage']
            ?? 999;


        return
            $aScore
            <=>
            $bScore;

    }

);


/*
|--------------------------------------------------------------------------
| HIGHEST SUBJECT
|--------------------------------------------------------------------------
*/

$highestSubject =
    null;


foreach (
    $subjectsWithData
    as $subject
) {

    if (

        $highestSubject === null

        ||

        $subject['percentage']
        >
        $highestSubject['percentage']

    ) {

        $highestSubject =
            $subject;

    }

}


/*
|--------------------------------------------------------------------------
| LOWEST SUBJECT
|--------------------------------------------------------------------------
*/

$lowestSubject =
    null;


foreach (
    $subjectsWithData
    as $subject
) {

    if (

        $lowestSubject === null

        ||

        $subject['percentage']
        <
        $lowestSubject['percentage']

    ) {

        $lowestSubject =
            $subject;

    }

}


/*
|--------------------------------------------------------------------------
| RECOMMENDATIONS
|--------------------------------------------------------------------------
*/

$recommendations = [];


foreach (

    array_slice(
        $focusSubjects,
        0,
        3
    )

    as $subject

) {

    $diagnosticText =

        $subject['diagnostic_percentage']
        !== null

        ?

        $subject['diagnostic_percentage']
        . "%"

        :

        "No diagnostic result";


    $quizText =

        $subject['quiz_percentage']
        !== null

        ?

        $subject['quiz_percentage']
        . "%"

        :

        "No quiz taken";


    $xpText =

        $subject['earned_points']
        . " XP";


    $recommendations[] = [

        "icon" =>
            "🎯",

        "title" =>
            "Focus on " .
            $subject['name'],

        "text" =>

            "Your diagnostic result in this subject is "
            .

            $diagnosticText

            .

            ". Your quiz performance is "
            .

            $quizText

            .

            " and you have earned "
            .

            $xpText

            .

            ". Review the learning materials and practice this subject to improve your mastery."

    ];

}


/*
|--------------------------------------------------------------------------
| DEFAULT RECOMMENDATION
|--------------------------------------------------------------------------
*/

if (
    count($recommendations) === 0
) {

    if (
        $highestSubject !== null
    ) {

        $recommendations[] = [

            "icon" =>
                "🏆",

            "title" =>
                "Good Academic Progress",

            "text" =>
                "Based on your current XP progress and diagnostic results, there are no subjects requiring immediate focus. Continue completing quizzes and learning materials to earn more XP."

        ];

    }

    else {

        $recommendations[] = [

            "icon" =>
                "📚",

            "title" =>
                "Start Learning",

            "text" =>
                "Complete your learning materials and quizzes to earn XP and build your academic progress."

        ];

    }

}


/*
|--------------------------------------------------------------------------
| FINAL RESPONSE
|--------------------------------------------------------------------------
*/

$response = [

    "success" =>
        true,

    "student" => [

        "student_id" =>
            intval(
                $student['student_id']
            ),

        "student_no" =>
            $student['studentNo']
            ?? null,

        "name" =>
            $fullName,

        "strand_id" =>
            $strandId,

        "strand" =>
            $student['strand_name']
            ?? null,

        "specialization_id" =>
            $specializationId > 0
                ? $specializationId
                : null,

        "specialization" =>
            $student['specialization_name']
            ?? null,

        "grade_level" =>
            $gradeLevel,

        "academic_id" =>
            $academicId,

        "school_year" =>
            $student['academic_year']
            ?? null,

        /*
        | Overall student XP.
        */

        "points" =>
            intval(
                $student['points']
                ?? 0
            )

    ],

    "semester" =>
        $currentSemester,

    "summary" => [

        /*
        | Academic report overall XP progress.
        */

        "overall_score" =>
            $overallScore,

        "total_subjects" =>
            count($subjects),

        "subjects_with_data" =>
            count($subjectsWithData),

        "quiz_count" =>
            $quizCount,

        "focus_count" =>
            count($focusSubjects)

    ],

    "subjects" =>
        $subjects,

    "focus_subjects" =>
        $focusSubjects,

    "highest_subject" =>
        $highestSubject,

    "lowest_subject" =>
        $lowestSubject,

    "recommendations" =>
        $recommendations

];


echo json_encode(

    $response,

    JSON_UNESCAPED_UNICODE

);


$conn->close();

exit();

?>