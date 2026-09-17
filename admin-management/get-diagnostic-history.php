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
    "Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With"
);

header(
    "Access-Control-Allow-Credentials: true"
);


/*
|--------------------------------------------------------------------------
| JSON RESPONSE
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
        [
            "success" => $success,
            "message" => $message,
            "data" => $data
        ],
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

if ($_SERVER["REQUEST_METHOD"] !== "GET") {

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


if ($conn->connect_error) {

    responseJson(
        false,
        "Database connection failed.",
        [],
        500
    );

}


$conn->set_charset("utf8mb4");


/*
|--------------------------------------------------------------------------
| CHECK TABLES
|--------------------------------------------------------------------------
*/

$requiredTables = [
    "student",
    "diagnostic_result",
    "diagnostic",
    "diagnostic_details"
];


foreach ($requiredTables as $table) {

    $safeTable =
        $conn->real_escape_string($table);

    $checkTable =
        $conn->query(
            "SHOW TABLES LIKE '$safeTable'"
        );


    if (
        !$checkTable ||
        $checkTable->num_rows === 0
    ) {

        $conn->close();

        responseJson(
            false,
            "Required table '$table' was not found.",
            [],
            500
        );

    }

}


/*
|--------------------------------------------------------------------------
| GET COLUMNS
|--------------------------------------------------------------------------
*/

function getColumns(
    BrainPalDb $conn,
    string $table
): array {

    $columns = [];

    $safeTable =
        $conn->real_escape_string($table);

    $result =
        $conn->query(
            "SHOW COLUMNS FROM `$safeTable`"
        );


    if (!$result) {

        return $columns;

    }


    while ($row = $result->fetch_assoc()) {

        $columns[] =
            $row["Field"];

    }


    return $columns;

}


/*
|--------------------------------------------------------------------------
| COLUMNS
|--------------------------------------------------------------------------
*/

$studentColumns =
    getColumns(
        $conn,
        "student"
    );


$resultColumns =
    getColumns(
        $conn,
        "diagnostic_result"
    );


$diagnosticColumns =
    getColumns(
        $conn,
        "diagnostic"
    );


/*
|--------------------------------------------------------------------------
| FIND COLUMN
|--------------------------------------------------------------------------
*/

function findColumn(
    array $columns,
    array $possible
): ?string {

    foreach ($possible as $column) {

        if (
            in_array(
                $column,
                $columns,
                true
            )
        ) {

            return $column;

        }

    }


    return null;

}


/*
|--------------------------------------------------------------------------
| STUDENT NAME
|--------------------------------------------------------------------------
*/

$firstNameColumn =
    findColumn(
        $studentColumns,
        [
            "first_name",
            "firstname",
            "given_name"
        ]
    );


$lastNameColumn =
    findColumn(
        $studentColumns,
        [
            "last_name",
            "lastname",
            "surname",
            "family_name"
        ]
    );


$fullNameColumn =
    findColumn(
        $studentColumns,
        [
            "name",
            "full_name",
            "student_name"
        ]
    );


/*
|--------------------------------------------------------------------------
| STUDENT NUMBER
|--------------------------------------------------------------------------
*/

$studentNoColumn =
    findColumn(
        $studentColumns,
        [
            "student_no",
            "student_number",
            "studentNo",
            "school_id",
            "student_code"
        ]
    );


/*
|--------------------------------------------------------------------------
| EMAIL
|--------------------------------------------------------------------------
*/

$emailColumn =
    findColumn(
        $studentColumns,
        [
            "email",
            "student_email",
            "email_address"
        ]
    );


/*
|--------------------------------------------------------------------------
| PHOTO
|--------------------------------------------------------------------------
*/

$photoColumn =
    findColumn(
        $studentColumns,
        [
            "profile_photo",
            "profile_picture",
            "photo",
            "image",
            "avatar"
        ]
    );


/*
|--------------------------------------------------------------------------
| GRADE
|--------------------------------------------------------------------------
*/

$studentGradeColumn =
    findColumn(
        $studentColumns,
        [
            "grade_level",
            "grade",
            "year_level",
            "level"
        ]
    );


$diagnosticGradeColumn =
    findColumn(
        $diagnosticColumns,
        [
            "grade_level",
            "grade",
            "year_level",
            "level"
        ]
    );


/*
|--------------------------------------------------------------------------
| DATE
|--------------------------------------------------------------------------
*/

$resultDateColumn =
    findColumn(
        $resultColumns,
        [
            "date_taken",
            "date_created",
            "created_at",
            "created"
        ]
    );


/*
|--------------------------------------------------------------------------
| NAME SQL
|--------------------------------------------------------------------------
*/

if (
    $firstNameColumn &&
    $lastNameColumn
) {

    $studentNameSQL = "
        TRIM(
            CONCAT(
                COALESCE(s.`$firstNameColumn`, ''),
                ' ',
                COALESCE(s.`$lastNameColumn`, '')
            )
        )
    ";

}
elseif ($fullNameColumn) {

    $studentNameSQL =
        "COALESCE(s.`$fullNameColumn`, '')";

}
elseif ($firstNameColumn) {

    $studentNameSQL =
        "COALESCE(s.`$firstNameColumn`, '')";

}
else {

    $studentNameSQL =
        "CONCAT('Student #', s.student_id)";

}


/*
|--------------------------------------------------------------------------
| STUDENT NO SQL
|--------------------------------------------------------------------------
*/

if ($studentNoColumn) {

    $studentNoSQL =
        "COALESCE(s.`$studentNoColumn`, '')";

}
else {

    $studentNoSQL =
        "''";

}


/*
|--------------------------------------------------------------------------
| EMAIL SQL
|--------------------------------------------------------------------------
*/

if ($emailColumn) {

    $emailSQL =
        "COALESCE(s.`$emailColumn`, '')";

}
else {

    $emailSQL =
        "''";

}


/*
|--------------------------------------------------------------------------
| PHOTO SQL
|--------------------------------------------------------------------------
*/

if ($photoColumn) {

    $photoSQL =
        "COALESCE(s.`$photoColumn`, '')";

}
else {

    $photoSQL =
        "NULL";

}


/*
|--------------------------------------------------------------------------
| GRADE SQL
|--------------------------------------------------------------------------
*/

if ($studentGradeColumn) {

    $gradeLevelSQL =
        "s.`$studentGradeColumn`";

}
elseif ($diagnosticGradeColumn) {

    $gradeLevelSQL =
        "d.`$diagnosticGradeColumn`";

}
else {

    $gradeLevelSQL =
        "NULL";

}


/*
|--------------------------------------------------------------------------
| DATE SQL
|--------------------------------------------------------------------------
*/

if ($resultDateColumn) {

    $dateSQL =
        "dr.`$resultDateColumn`";

}
else {

    $dateSQL =
        "NULL";

}


/*
|--------------------------------------------------------------------------
| TOTAL QUESTIONS
|--------------------------------------------------------------------------
*/

$totalQuestionsSQL = "

    (
        SELECT COUNT(*)
        FROM diagnostic_details dd
        WHERE dd.result_id = dr.result_id
    )

";


/*
|--------------------------------------------------------------------------
| MAIN QUERY
|--------------------------------------------------------------------------
|
| IMPORTANT:
| points_awarded comes directly from diagnostic_result.
|
|--------------------------------------------------------------------------
*/

$sql = "

    SELECT

        dr.result_id,

        dr.student_id,

        $studentNameSQL AS student_name,

        $studentNoSQL AS student_no,

        $emailSQL AS student_email,

        dr.diagnostic_id,

        dr.academic_id,

        $dateSQL AS date_taken,

        dr.total_score,

        $totalQuestionsSQL AS total_questions,

        dr.level_classification,

        $dateSQL AS date_created,

        COALESCE(
            dr.points_awarded,
            0
        ) AS points_awarded,

        $gradeLevelSQL AS grade_level,

        $photoSQL AS profile_photo

    FROM diagnostic_result dr

    LEFT JOIN student s
        ON s.student_id = dr.student_id

    LEFT JOIN diagnostic d
        ON d.diagnostic_id = dr.diagnostic_id

    ORDER BY
        dr.result_id DESC

";


$result =
    $conn->query($sql);


if (!$result) {

    $error =
        $conn->error;

    $conn->close();

    responseJson(
        false,
        "Failed to load diagnostic history.",
        [
            "error" => $error
        ],
        500
    );

}


/*
|--------------------------------------------------------------------------
| BUILD DATA
|--------------------------------------------------------------------------
*/

$records = [];


while ($row = $result->fetch_assoc()) {

    /*
    |--------------------------------------------------------------------------
    | STUDENT NAME
    |--------------------------------------------------------------------------
    */

    $studentName =
        trim(
            $row["student_name"] ?? ""
        );


    if ($studentName === "") {

        $studentName =
            "Student #" .
            intval(
                $row["student_id"] ?? 0
            );

    }


    /*
    |--------------------------------------------------------------------------
    | EMAIL
    |--------------------------------------------------------------------------
    */

    $studentEmail =
        trim(
            $row["student_email"] ?? ""
        );


    if ($studentEmail === "") {

        $studentEmail =
            "No email";

    }


    /*
    |--------------------------------------------------------------------------
    | GRADE
    |--------------------------------------------------------------------------
    */

    $gradeLevel = null;


    if (
        isset($row["grade_level"]) &&
        $row["grade_level"] !== null &&
        $row["grade_level"] !== ""
    ) {

        $gradeLevel =
            intval(
                $row["grade_level"]
            );

    }


    /*
    |--------------------------------------------------------------------------
    | TOTAL QUESTIONS
    |--------------------------------------------------------------------------
    */

    $totalQuestions =
        intval(
            $row["total_questions"] ?? 0
        );


    /*
    |--------------------------------------------------------------------------
    | SCORE
    |--------------------------------------------------------------------------
    */

    $totalScore =
        intval(
            $row["total_score"] ?? 0
        );


    /*
    |--------------------------------------------------------------------------
    | POINTS
    |--------------------------------------------------------------------------
    */

    $pointsAwarded =
        intval(
            $row["points_awarded"] ?? 0
        );


    /*
    |--------------------------------------------------------------------------
    | RECORD
    |--------------------------------------------------------------------------
    */

    $records[] = [

        "result_id" =>
            intval(
                $row["result_id"] ?? 0
            ),

        "student_id" =>
            intval(
                $row["student_id"] ?? 0
            ),

        "student_name" =>
            $studentName,

        "student_no" =>
            $row["student_no"] ?? "",

        "student_email" =>
            $studentEmail,

        "diagnostic_id" =>
            intval(
                $row["diagnostic_id"] ?? 0
            ),

        "academic_id" =>
            intval(
                $row["academic_id"] ?? 0
            ),

        "date_taken" =>
            $row["date_taken"] ?? "",

        "total_score" =>
            $totalScore,

        "total_questions" =>
            $totalQuestions,

        "level_classification" =>
            $row["level_classification"]
            ??
            "Not Classified",

        "date_created" =>
            $row["date_created"] ?? "",

        "points_awarded" =>
            $pointsAwarded,

        "grade_level" =>
            $gradeLevel,

        "profile_photo" =>
            $row["profile_photo"] ?? null

    ];

}


$conn->close();


/*
|--------------------------------------------------------------------------
| SUCCESS
|--------------------------------------------------------------------------
*/

responseJson(
    true,
    "Diagnostic history loaded successfully.",
    $records,
    200
);

?>