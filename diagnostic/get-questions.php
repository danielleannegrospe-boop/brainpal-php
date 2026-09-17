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
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");


/*
|--------------------------------------------------------------------------
| HELPER
|--------------------------------------------------------------------------
*/

function jsonResponse(
    bool $success,
    string $message,
    array $data = [],
    int $status = 200
): void {

    http_response_code($status);

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

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {

    jsonResponse(
        true,
        "OK"
    );

}


/*
|--------------------------------------------------------------------------
| DATABASE
|--------------------------------------------------------------------------
*/

$conn = brainpal_db();


if ($conn->connect_error) {

    jsonResponse(
        false,
        "Database connection failed.",
        [
            "error" => $conn->connect_error,
            "data" => []
        ],
        500
    );

}


$conn->set_charset("utf8mb4");


/*
|--------------------------------------------------------------------------
| READ REQUEST
|--------------------------------------------------------------------------
*/

$data = [];


/*
|--------------------------------------------------------------------------
| POST
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $rawInput = file_get_contents("php://input");

    if (
        $rawInput !== false &&
        trim($rawInput) !== ""
    ) {

        $decoded = json_decode(
            $rawInput,
            true
        );

        if (is_array($decoded)) {

            $data = $decoded;

        }

    }

}


/*
|--------------------------------------------------------------------------
| GET
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "GET") {

    $data = $_GET;

}


/*
|--------------------------------------------------------------------------
| REQUEST VALUES
|--------------------------------------------------------------------------
*/

$strand_id = intval(
    $data["strand_id"] ?? 0
);

$specialization_id = intval(
    $data["specialization_id"] ?? 0
);

$grade_level = intval(
    $data["grade_level"] ?? 11
);

$semester = trim(
    (string)(
        $data["semester"] ?? "1st"
    )
);

$student_id = intval(
    $data["student_id"] ?? 0
);

/*
|--------------------------------------------------------------------------
| VERIFY STUDENT ACCESS
|--------------------------------------------------------------------------
|
| Diagnostic questions are only available to verified students who
| have not completed the diagnostic yet.
|
|--------------------------------------------------------------------------
*/

if ($student_id <= 0) {

    $conn->close();

    jsonResponse(
        false,
        "Student session is required.",
        ["data" => []],
        401
    );

}

$studentStmt = $conn->prepare("
    SELECT
        is_verified,
        diagnostic_completed,
        account_status
    FROM student
    WHERE
        student_id = ?
        AND date_deleted IS NULL
    LIMIT 1
");

if (!$studentStmt) {

    $conn->close();

    jsonResponse(
        false,
        "Failed to verify student access.",
        ["data" => []],
        500
    );

}

$studentStmt->bind_param(
    "i",
    $student_id
);

$studentStmt->execute();

$studentResult = $studentStmt->get_result();

$studentAccess = $studentResult->fetch_assoc();

$studentStmt->close();

if (!$studentAccess) {

    $conn->close();

    jsonResponse(
        false,
        "Student not found.",
        ["data" => []],
        404
    );

}

if (
    (int)$studentAccess["account_status"] !== 1
    && strtolower((string)$studentAccess["account_status"]) !== "active"
) {

    $conn->close();

    jsonResponse(
        false,
        "Student account is not active.",
        ["data" => []],
        403
    );

}

if ((int)$studentAccess["is_verified"] !== 1) {

    $conn->close();

    jsonResponse(
        false,
        "Email verification is required before taking the diagnostic.",
        ["data" => []],
        403
    );

}

if ((int)$studentAccess["diagnostic_completed"] === 1) {

    $conn->close();

    jsonResponse(
        false,
        "Diagnostic has already been completed. Please proceed to the dashboard.",
        ["data" => []],
        409
    );

}


/*
|--------------------------------------------------------------------------
| NORMALIZE SPECIALIZATION
|--------------------------------------------------------------------------
*/

if ($specialization_id <= 0) {

    $specialization_id = null;

}


/*
|--------------------------------------------------------------------------
| VALIDATE STRAND
|--------------------------------------------------------------------------
*/

if ($strand_id <= 0) {

    $conn->close();

    jsonResponse(
        false,
        "Invalid strand_id.",
        [
            "received_data" => $data,
            "data" => []
        ],
        400
    );

}


/*
|--------------------------------------------------------------------------
| NORMALIZE GRADE
|--------------------------------------------------------------------------
*/

if ($grade_level <= 0) {

    $grade_level = 11;

}


/*
|--------------------------------------------------------------------------
| NORMALIZE SEMESTER
|--------------------------------------------------------------------------
*/

if ($semester === "") {

    $semester = "1st";

}


/*
|--------------------------------------------------------------------------
| SETTINGS
|--------------------------------------------------------------------------
*/

$TARGET_QUESTIONS = 20;

$MAJOR_PERCENTAGE = 0.60;

$SHARED_PERCENTAGE = 0.40;

$MAJOR_TARGET = (int) round(
    $TARGET_QUESTIONS *
    $MAJOR_PERCENTAGE
);

$SHARED_TARGET =
    $TARGET_QUESTIONS -
    $MAJOR_TARGET;


/*
|--------------------------------------------------------------------------
| STEP 1
| GET CURRENT SUBJECTS
|--------------------------------------------------------------------------
|
| These are the subjects currently assigned to the selected strand.
|
|--------------------------------------------------------------------------
*/

$subjects = [];


if ($specialization_id !== null) {

    $subjectSql = "

        SELECT
            subject_id,
            subject_name,
            strand_id,
            specialization_id,
            grade_level,
            semester

        FROM subject

        WHERE
            strand_id = ?

        AND
            (
                grade_level IS NULL
                OR
                grade_level = ?
            )

        AND
            semester = ?

        AND
            (
                specialization_id IS NULL
                OR
                specialization_id = 0
                OR
                specialization_id = ?
            )

        AND
            date_deleted IS NULL

        ORDER BY

            CASE
                WHEN specialization_id IS NULL THEN 1
                WHEN specialization_id = 0 THEN 1
                ELSE 2
            END,

            subject_id ASC

    ";


    $subjectStmt = $conn->prepare(
        $subjectSql
    );


    if (!$subjectStmt) {

        $error = $conn->error;

        $conn->close();

        jsonResponse(
            false,
            "Failed to prepare subject query.",
            [
                "error" => $error,
                "data" => []
            ],
            500
        );

    }


    $subjectStmt->bind_param(
        "iisi",
        $strand_id,
        $grade_level,
        $semester,
        $specialization_id
    );

} else {

    /*
    |--------------------------------------------------------------------------
    | SHARED / GENERAL SUBJECTS
    |--------------------------------------------------------------------------
    */

    $subjectSql = "

        SELECT
            subject_id,
            subject_name,
            strand_id,
            specialization_id,
            grade_level,
            semester

        FROM subject

        WHERE
            strand_id = ?

        AND
            (
                grade_level IS NULL
                OR
                grade_level = ?
            )

        AND
            semester = ?

        AND
            (
                specialization_id IS NULL
                OR
                specialization_id = 0
            )

        AND
            date_deleted IS NULL

        ORDER BY
            subject_id ASC

    ";


    $subjectStmt = $conn->prepare(
        $subjectSql
    );


    if (!$subjectStmt) {

        $error = $conn->error;

        $conn->close();

        jsonResponse(
            false,
            "Failed to prepare shared subject query.",
            [
                "error" => $error,
                "data" => []
            ],
            500
        );

    }


    $subjectStmt->bind_param(
        "iis",
        $strand_id,
        $grade_level,
        $semester
    );

}


/*
|--------------------------------------------------------------------------
| EXECUTE SUBJECT QUERY
|--------------------------------------------------------------------------
*/

if (!$subjectStmt->execute()) {

    $error = $subjectStmt->error;

    $subjectStmt->close();

    $conn->close();

    jsonResponse(
        false,
        "Failed to execute subject query.",
        [
            "error" => $error,
            "data" => []
        ],
        500
    );

}


$subjectResult = $subjectStmt->get_result();


while ($row = $subjectResult->fetch_assoc()) {

    $subjects[] = $row;

}


$subjectStmt->close();


/*
|--------------------------------------------------------------------------
| NO SUBJECTS
|--------------------------------------------------------------------------
*/

if (count($subjects) === 0) {

    $conn->close();

    jsonResponse(
        false,
        "No subjects found for this strand, grade level, specialization, and semester.",
        [
            "filters" => [
                "strand_id" => $strand_id,
                "specialization_id" => $specialization_id,
                "grade_level" => $grade_level,
                "semester" => $semester
            ],

            "subject_count" => 0,
            "question_count" => 0,
            "available_questions" => 0,
            "data" => []
        ],
        200
    );

}


/*
|--------------------------------------------------------------------------
| STEP 2
| CURRENT SUBJECT IDS
|--------------------------------------------------------------------------
*/

$subjectIds = [];

$sharedSubjectIds = [];

$majorSubjectIds = [];


/*
|--------------------------------------------------------------------------
| SUBJECT NAME MAP
|--------------------------------------------------------------------------
|
| This is important.
|
| Diagnostic questions may be linked to an OLD subject_id.
| We therefore also remember the subject name.
|
|--------------------------------------------------------------------------
*/

$sharedSubjectNames = [];

$majorSubjectNames = [];


foreach ($subjects as $subject) {

    $subjectId = intval(
        $subject["subject_id"] ?? 0
    );

    $subjectName = trim(
        (string)(
            $subject["subject_name"] ?? ""
        )
    );


    if ($subjectId <= 0) {
        continue;
    }


    if ($subjectName === "") {
        continue;
    }


    $subjectIds[] = $subjectId;


    /*
    |--------------------------------------------------------------------------
    | NORMALIZED SUBJECT NAME
    |--------------------------------------------------------------------------
    */

    $normalizedName = strtolower(
        preg_replace(
            '/\s+/',
            ' ',
            trim($subjectName)
        )
    );


    $subjectSpecialization =
        $subject["specialization_id"];


    /*
    |--------------------------------------------------------------------------
    | SHARED
    |--------------------------------------------------------------------------
    */

    if (
        $subjectSpecialization === null ||
        intval($subjectSpecialization) === 0
    ) {

        $sharedSubjectIds[] = $subjectId;

        $sharedSubjectNames[$normalizedName] =
            $subjectName;

    }


    /*
    |--------------------------------------------------------------------------
    | MAJOR
    |--------------------------------------------------------------------------
    */

    elseif (
        $specialization_id !== null &&
        intval($subjectSpecialization) ===
        intval($specialization_id)
    ) {

        $majorSubjectIds[] = $subjectId;

        $majorSubjectNames[$normalizedName] =
            $subjectName;

    }

}


/*
|--------------------------------------------------------------------------
| REMOVE DUPLICATES
|--------------------------------------------------------------------------
*/

$subjectIds = array_values(
    array_unique($subjectIds)
);

$sharedSubjectIds = array_values(
    array_unique($sharedSubjectIds)
);

$majorSubjectIds = array_values(
    array_unique($majorSubjectIds)
);


/*
|--------------------------------------------------------------------------
| STEP 3
| GET DIAGNOSTIC QUESTIONS
|--------------------------------------------------------------------------
|
| FIRST:
|   Match current subject IDs.
|
| SECOND:
|   Match subject names.
|
| This solves the problem where the subject record was recreated
| and received a new subject_id.
|
|--------------------------------------------------------------------------
*/

$allQuestions = [];


/*
|--------------------------------------------------------------------------
| 3A. DIRECT SUBJECT ID MATCH
|--------------------------------------------------------------------------
*/

if (count($subjectIds) > 0) {

    $placeholders = implode(
        ",",
        array_fill(
            0,
            count($subjectIds),
            "?"
        )
    );


    $sql = "

        SELECT

            d.diagnostic_id,
            d.subject_id,

            s.subject_name,
            s.strand_id,
            s.specialization_id,
            s.grade_level,
            s.semester,

            d.question,
            d.choice_a,
            d.choice_b,
            d.choice_c,
            d.choice_d,
            d.difficulty,
            d.academic_id

        FROM diagnostic d

        INNER JOIN subject s
            ON s.subject_id = d.subject_id

        WHERE

            d.subject_id IN (
                $placeholders
            )

        AND
            d.date_deleted IS NULL

        AND
            s.date_deleted IS NULL

        ORDER BY RAND()

    ";


    $stmt = $conn->prepare($sql);


    if (!$stmt) {

        $error = $conn->error;

        $conn->close();

        jsonResponse(
            false,
            "Failed to prepare diagnostic question query.",
            [
                "error" => $error,
                "subject_ids" => $subjectIds,
                "data" => []
            ],
            500
        );

    }


    $types = str_repeat(
        "i",
        count($subjectIds)
    );


    $params = [];

    $params[] = &$types;


    foreach ($subjectIds as $key => $subjectId) {

        $params[] = &$subjectIds[$key];

    }


    call_user_func_array(
        [
            $stmt,
            "bind_param"
        ],
        $params
    );


    if ($stmt->execute()) {

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {

            $allQuestions[] = $row;

        }

    }


    $stmt->close();

}


/*
|--------------------------------------------------------------------------
| 3B. SUBJECT NAME FALLBACK
|--------------------------------------------------------------------------
|
| IMPORTANT:
|
| We do NOT require the diagnostic subject to have the same subject_id.
|
| We compare normalized subject names instead.
|
|--------------------------------------------------------------------------
*/

if (count($allQuestions) === 0) {

    $nameList = array_unique(
        array_merge(
            array_keys($sharedSubjectNames),
            array_keys($majorSubjectNames)
        )
    );


    if (count($nameList) > 0) {

        /*
        |--------------------------------------------------------------------------
        | Build LOWER(TRIM()) comparison values.
        |--------------------------------------------------------------------------
        */

        $nameConditions = [];

        $nameParams = [];

        foreach ($nameList as $normalizedName) {

            $nameConditions[] =
                "LOWER(TRIM(s.subject_name)) = ?";

            $nameParams[] =
                $normalizedName;

        }


        $nameWhere = implode(
            " OR ",
            $nameConditions
        );


        /*
        |--------------------------------------------------------------------------
        | Diagnostic subject attributes
        |--------------------------------------------------------------------------
        |
        | We intentionally do NOT require s.strand_id to equal the selected
        | strand here.
        |
        | Why?
        |
        | Because the old diagnostic question may belong to an old/recreated
        | subject record whose strand_id is outdated.
        |
        | The CURRENT subject name is what identifies the subject.
        |
        |--------------------------------------------------------------------------
        */

        $nameSql = "

            SELECT

                d.diagnostic_id,
                d.subject_id,

                s.subject_name,
                s.strand_id,
                s.specialization_id,
                s.grade_level,
                s.semester,

                d.question,
                d.choice_a,
                d.choice_b,
                d.choice_c,
                d.choice_d,
                d.difficulty,
                d.academic_id

            FROM diagnostic d

            INNER JOIN subject s
                ON s.subject_id = d.subject_id

            WHERE

                (
                    $nameWhere
                )

            AND
                d.date_deleted IS NULL

            AND
                s.date_deleted IS NULL

            AND
                (
                    s.grade_level IS NULL
                    OR
                    s.grade_level = ?
                )

            AND
                s.semester = ?

            ORDER BY RAND()

        ";


        $nameStmt = $conn->prepare(
            $nameSql
        );


        if ($nameStmt) {

            /*
            |--------------------------------------------------------------------------
            | Dynamic parameter types
            |--------------------------------------------------------------------------
            */

            $types =
                str_repeat(
                    "s",
                    count($nameParams)
                ) .
                "is";


            $params = [];

            $params[] = &$types;


            foreach (
                $nameParams
                as $key => $name
            ) {

                $params[] =
                    &$nameParams[$key];

            }


            $gradeParam = $grade_level;

            $semesterParam = $semester;


            $params[] =
                &$gradeParam;

            $params[] =
                &$semesterParam;


            call_user_func_array(
                [
                    $nameStmt,
                    "bind_param"
                ],
                $params
            );


            if ($nameStmt->execute()) {

                $nameResult =
                    $nameStmt->get_result();


                while (
                    $row =
                    $nameResult->fetch_assoc()
                ) {

                    $allQuestions[] =
                        $row;

                }

            }


            $nameStmt->close();

        }

    }

}


/*
|--------------------------------------------------------------------------
| REMOVE DUPLICATE DIAGNOSTIC QUESTIONS
|--------------------------------------------------------------------------
*/

$uniqueQuestions = [];

$seenDiagnosticIds = [];


foreach ($allQuestions as $question) {

    $diagnosticId = intval(
        $question["diagnostic_id"] ?? 0
    );


    if ($diagnosticId <= 0) {
        continue;
    }


    if (
        isset(
            $seenDiagnosticIds[
                $diagnosticId
            ]
        )
    ) {

        continue;

    }


    $seenDiagnosticIds[
        $diagnosticId
    ] = true;


    $uniqueQuestions[] =
        $question;

}


$allQuestions =
    $uniqueQuestions;


$availableQuestions =
    count($allQuestions);


/*
|--------------------------------------------------------------------------
| NO QUESTIONS
|--------------------------------------------------------------------------
*/

if ($availableQuestions === 0) {

    $conn->close();

    jsonResponse(
        false,
        "Subjects were found, but no diagnostic questions were found for the selected subjects.",
        [
            "filters" => [
                "strand_id" =>
                    $strand_id,

                "specialization_id" =>
                    $specialization_id,

                "grade_level" =>
                    $grade_level,

                "semester" =>
                    $semester
            ],

            "subject_count" =>
                count($subjects),

            "subject_ids" =>
                $subjectIds,

            "shared_subject_ids" =>
                $sharedSubjectIds,

            "major_subject_ids" =>
                $majorSubjectIds,

            "shared_subject_names" =>
                array_values(
                    $sharedSubjectNames
                ),

            "major_subject_names" =>
                array_values(
                    $majorSubjectNames
                ),

            "available_questions" =>
                0,

            "question_count" =>
                0,

            "data" =>
                []
        ],
        200
    );

}


/*
|--------------------------------------------------------------------------
| STEP 4
| CLASSIFY QUESTIONS
|--------------------------------------------------------------------------
|
| IMPORTANT:
|
| Because diagnostic questions may use old subject IDs,
| classification is now also based on SUBJECT NAME.
|
|--------------------------------------------------------------------------
*/

$majorQuestions = [];

$sharedQuestions = [];


foreach ($allQuestions as $question) {

    $questionSubjectId =
        intval(
            $question["subject_id"] ?? 0
        );


    $questionSubjectName =
        trim(
            (string)(
                $question["subject_name"] ?? ""
            )
        );


    $normalizedQuestionName =
        strtolower(
            preg_replace(
                '/\s+/',
                ' ',
                $questionSubjectName
            )
        );


    /*
    |--------------------------------------------------------------------------
    | MAJOR CHECK
    |--------------------------------------------------------------------------
    */

    $isMajor =
        in_array(
            $questionSubjectId,
            $majorSubjectIds,
            true
        )
        ||
        isset(
            $majorSubjectNames[
                $normalizedQuestionName
            ]
        );


    /*
    |--------------------------------------------------------------------------
    | SHARED CHECK
    |--------------------------------------------------------------------------
    */

    $isShared =
        in_array(
            $questionSubjectId,
            $sharedSubjectIds,
            true
        )
        ||
        isset(
            $sharedSubjectNames[
                $normalizedQuestionName
            ]
        );


    /*
    |--------------------------------------------------------------------------
    | PRIORITY
    |--------------------------------------------------------------------------
    |
    | If it is explicitly a major subject, classify as major.
    |
    |--------------------------------------------------------------------------
    */

    if ($isMajor) {

        $majorQuestions[] =
            $question;

    }

    elseif ($isShared) {

        $sharedQuestions[] =
            $question;

    }

}


/*
|--------------------------------------------------------------------------
| DIFFICULTY GROUPS
|--------------------------------------------------------------------------
*/

function splitByDifficulty(
    array $questions
): array {

    $pools = [

        "easy" => [],

        "medium" => [],

        "hard" => [],

        "other" => []

    ];


    foreach ($questions as $question) {

        $difficulty =
            strtolower(
                trim(
                    (string)(
                        $question["difficulty"]
                        ?? ""
                    )
                )
            );


        if (
            in_array(
                $difficulty,
                [
                    "ver_easy",
                    "easy"
                ],
                true
            )
        ) {

            $pools["easy"][] =
                $question;

        }

        elseif (
            in_array(
                $difficulty,
                [
                    "easy_medium",
                    "medium"
                ],
                true
            )
        ) {

            $pools["medium"][] =
                $question;

        }

        elseif (
            $difficulty === "hard"
        ) {

            $pools["hard"][] =
                $question;

        }

        else {

            $pools["other"][] =
                $question;

        }

    }


    foreach ($pools as &$pool) {

        shuffle($pool);

    }

    unset($pool);


    return $pools;

}


/*
|--------------------------------------------------------------------------
| CREATE POOLS
|--------------------------------------------------------------------------
*/

$majorPools =
    splitByDifficulty(
        $majorQuestions
    );


$sharedPools =
    splitByDifficulty(
        $sharedQuestions
    );


/*
|--------------------------------------------------------------------------
| QUESTION HELPERS
|--------------------------------------------------------------------------
*/

$selectedQuestions = [];

$selectedIds = [];


function addQuestion(
    array &$selectedQuestions,
    array &$selectedIds,
    array $question,
    int $target
): bool {

    if (
        count($selectedQuestions)
        >=
        $target
    ) {

        return false;

    }


    $questionId =
        intval(
            $question["diagnostic_id"]
            ?? 0
        );


    if ($questionId <= 0) {

        return false;

    }


    if (
        in_array(
            $questionId,
            $selectedIds,
            true
        )
    ) {

        return false;

    }


    $selectedQuestions[] =
        $question;

    $selectedIds[] =
        $questionId;


    return true;

}


/*
|--------------------------------------------------------------------------
| PICK FROM DIFFICULTY POOL
|--------------------------------------------------------------------------
*/

function pickFromPool(
    array &$pool,
    int $target,
    array &$selectedQuestions,
    array &$selectedIds
): void {

    foreach (
        [
            "easy",
            "medium",
            "hard",
            "other"
        ]
        as $difficulty
    ) {

        foreach (
            $pool[$difficulty]
            as $question
        ) {

            if (
                count(
                    $selectedQuestions
                )
                >=
                $target
            ) {

                return;

            }


            addQuestion(
                $selectedQuestions,
                $selectedIds,
                $question,
                $target
            );

        }

    }

}


/*
|--------------------------------------------------------------------------
| STEP 5
| MAJOR
|--------------------------------------------------------------------------
*/

$majorBefore =
    count(
        $selectedQuestions
    );


pickFromPool(
    $majorPools,
    $MAJOR_TARGET,
    $selectedQuestions,
    $selectedIds
);


$majorSelected =
    count(
        $selectedQuestions
    )
    -
    $majorBefore;


/*
|--------------------------------------------------------------------------
| STEP 6
| SHARED
|--------------------------------------------------------------------------
*/

$sharedBefore =
    count(
        $selectedQuestions
    );


pickFromPool(
    $sharedPools,
    $SHARED_TARGET,
    $selectedQuestions,
    $selectedIds
);


$sharedSelected =
    count(
        $selectedQuestions
    )
    -
    $sharedBefore;


/*
|--------------------------------------------------------------------------
| STEP 7
| FILL SHORTAGE
|--------------------------------------------------------------------------
|
| If there aren't enough major questions, use remaining shared questions.
| If there aren't enough shared questions, use remaining major questions.
|
|--------------------------------------------------------------------------
*/

if (
    count($selectedQuestions)
    <
    $TARGET_QUESTIONS
) {

    $remainingQuestions = [];


    foreach (
        [
            $majorPools["easy"],
            $majorPools["medium"],
            $majorPools["hard"],
            $majorPools["other"],

            $sharedPools["easy"],
            $sharedPools["medium"],
            $sharedPools["hard"],
            $sharedPools["other"]
        ]
        as $pool
    ) {

        foreach ($pool as $question) {

            $remainingQuestions[] =
                $question;

        }

    }


    shuffle(
        $remainingQuestions
    );


    foreach (
        $remainingQuestions
        as $question
    ) {

        if (
            count($selectedQuestions)
            >=
            $TARGET_QUESTIONS
        ) {

            break;

        }


        addQuestion(
            $selectedQuestions,
            $selectedIds,
            $question,
            $TARGET_QUESTIONS
        );

    }

}


/*
|--------------------------------------------------------------------------
| FINAL SHUFFLE
|--------------------------------------------------------------------------
*/

shuffle(
    $selectedQuestions
);


/*
|--------------------------------------------------------------------------
| FINAL LIMIT
|--------------------------------------------------------------------------
*/

$selectedQuestions =
    array_slice(
        $selectedQuestions,
        0,
        $TARGET_QUESTIONS
    );


/*
|--------------------------------------------------------------------------
| STEP 8
| FINAL SUMMARY
|--------------------------------------------------------------------------
*/

$finalMajorCount = 0;

$finalSharedCount = 0;


$difficultySummary = [

    "ver_easy" => 0,

    "easy" => 0,

    "easy_medium" => 0,

    "medium" => 0,

    "hard" => 0

];


$subjectSummary = [];


foreach (
    $selectedQuestions
    as $question
) {

    $questionSubjectId =
        intval(
            $question["subject_id"]
            ?? 0
        );


    $questionSubjectName =
        trim(
            (string)(
                $question["subject_name"]
                ?? ""
            )
        );


    $normalizedQuestionName =
        strtolower(
            preg_replace(
                '/\s+/',
                ' ',
                $questionSubjectName
            )
        );


    /*
    |--------------------------------------------------------------------------
    | DETERMINE TYPE
    |--------------------------------------------------------------------------
    */

    $isMajor =
        in_array(
            $questionSubjectId,
            $majorSubjectIds,
            true
        )
        ||
        isset(
            $majorSubjectNames[
                $normalizedQuestionName
            ]
        );


    $isShared =
        in_array(
            $questionSubjectId,
            $sharedSubjectIds,
            true
        )
        ||
        isset(
            $sharedSubjectNames[
                $normalizedQuestionName
            ]
        );


    if ($isMajor) {

        $finalMajorCount++;

    }

    elseif ($isShared) {

        $finalSharedCount++;

    }


    /*
    |--------------------------------------------------------------------------
    | DIFFICULTY
    |--------------------------------------------------------------------------
    */

    $difficulty =
        strtolower(
            trim(
                (string)(
                    $question["difficulty"]
                    ?? ""
                )
            )
        );


    if (
        isset(
            $difficultySummary[
                $difficulty
            ]
        )
    ) {

        $difficultySummary[
            $difficulty
        ]++;

    }


    /*
    |--------------------------------------------------------------------------
    | SUBJECT SUMMARY
    |--------------------------------------------------------------------------
    */

    if (
        !isset(
            $subjectSummary[
                $questionSubjectId
            ]
        )
    ) {

        $subjectSummary[
            $questionSubjectId
        ] = [

            "subject_id" =>
                $questionSubjectId,

            "subject_name" =>
                $questionSubjectName,

            "type" =>
                $isMajor
                    ? "Major"
                    : "Shared",

            "question_count" =>
                0

        ];

    }


    $subjectSummary[
        $questionSubjectId
    ]["question_count"]++;

}


/*
|--------------------------------------------------------------------------
| STEP 9
| CLIENT DATA
|--------------------------------------------------------------------------
*/

$clientQuestions = [];


foreach (
    $selectedQuestions
    as $question
) {

    $questionSubjectId =
        intval(
            $question["subject_id"]
        );


    $questionSubjectName =
        trim(
            (string)(
                $question["subject_name"]
                ?? ""
            )
        );


    $normalizedQuestionName =
        strtolower(
            preg_replace(
                '/\s+/',
                ' ',
                $questionSubjectName
            )
        );


    $isMajor =
        in_array(
            $questionSubjectId,
            $majorSubjectIds,
            true
        )
        ||
        isset(
            $majorSubjectNames[
                $normalizedQuestionName
            ]
        );


    $clientQuestions[] = [

        "diagnostic_id" =>
            intval(
                $question[
                    "diagnostic_id"
                ]
            ),

        "subject_id" =>
            $questionSubjectId,

        "subject_name" =>
            $questionSubjectName,

        "subject_type" =>
            $isMajor
                ? "Major"
                : "Shared",

        "question" =>
            $question[
                "question"
            ],

        "choice_a" =>
            $question[
                "choice_a"
            ],

        "choice_b" =>
            $question[
                "choice_b"
            ],

        "choice_c" =>
            $question[
                "choice_c"
            ],

        "choice_d" =>
            $question[
                "choice_d"
            ],

        "difficulty" =>
            $question[
                "difficulty"
            ]

    ];

}


/*
|--------------------------------------------------------------------------
| STEP 10
| RESPONSE
|--------------------------------------------------------------------------
*/

$response = [

    "success" =>
        true,

    "message" =>
        "Diagnostic questions loaded successfully.",


    /*
    |--------------------------------------------------------------------------
    | FILTERS
    |--------------------------------------------------------------------------
    */

    "filters" => [

        "strand_id" =>
            $strand_id,

        "specialization_id" =>
            $specialization_id,

        "grade_level" =>
            $grade_level,

        "semester" =>
            $semester

    ],


    /*
    |--------------------------------------------------------------------------
    | DISTRIBUTION
    |--------------------------------------------------------------------------
    */

    "distribution" => [

        "major_target" =>
            $MAJOR_TARGET,

        "shared_target" =>
            $SHARED_TARGET,

        "major_selected" =>
            $finalMajorCount,

        "shared_selected" =>
            $finalSharedCount,

        "major_percentage" =>
            $TARGET_QUESTIONS > 0
                ? round(
                    (
                        $finalMajorCount /
                        $TARGET_QUESTIONS
                    ) * 100,
                    2
                )
                : 0,

        "shared_percentage" =>
            $TARGET_QUESTIONS > 0
                ? round(
                    (
                        $finalSharedCount /
                        $TARGET_QUESTIONS
                    ) * 100,
                    2
                )
                : 0

    ],


    /*
    |--------------------------------------------------------------------------
    | COUNTS
    |--------------------------------------------------------------------------
    */

    "target_questions" =>
        $TARGET_QUESTIONS,

    "question_count" =>
        count(
            $clientQuestions
        ),

    "available_questions" =>
        $availableQuestions,

    "subject_count" =>
        count(
            $subjects
        ),

    "major_subject_count" =>
        count(
            $majorSubjectIds
        ),

    "shared_subject_count" =>
        count(
            $sharedSubjectIds
        ),


    /*
    |--------------------------------------------------------------------------
    | CURRENT SUBJECT IDS
    |--------------------------------------------------------------------------
    */

    "subject_ids" =>
        $subjectIds,

    "major_subject_ids" =>
        $majorSubjectIds,

    "shared_subject_ids" =>
        $sharedSubjectIds,


    /*
    |--------------------------------------------------------------------------
    | SUBJECT NAMES
    |--------------------------------------------------------------------------
    |
    | Added for debugging so we can see exactly which subjects were resolved.
    |
    |--------------------------------------------------------------------------
    */

    "major_subject_names" =>
        array_values(
            $majorSubjectNames
        ),

    "shared_subject_names" =>
        array_values(
            $sharedSubjectNames
        ),


    /*
    |--------------------------------------------------------------------------
    | SUMMARY
    |--------------------------------------------------------------------------
    */

    "subject_summary" =>
        array_values(
            $subjectSummary
        ),

    "difficulty_summary" =>
        $difficultySummary,


    /*
    |--------------------------------------------------------------------------
    | QUESTIONS
    |--------------------------------------------------------------------------
    */

    "data" =>
        $clientQuestions

];


$conn->close();


jsonResponse(
    true,
    "Diagnostic questions loaded successfully.",
    $response,
    200
);

?>