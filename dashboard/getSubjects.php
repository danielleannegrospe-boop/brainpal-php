<?php
require_once __DIR__ . '/../_shared/db.php';

header("Content-Type: application/json; charset=UTF-8");

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");


/*
|--------------------------------------------------------------------------
| OPTIONS
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"] === "OPTIONS"
) {

    http_response_code(200);

    echo json_encode([
        "success" => true,
        "status" => "success"
    ]);

    exit;
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

    http_response_code(500);

    echo json_encode([

        "success" => false,

        "status" => "error",

        "message" =>
            "Database connection failed.",

        "subjects" =>
            []

    ]);

    exit;
}


$conn->set_charset(
    "utf8mb4"
);


/*
|--------------------------------------------------------------------------
| READ REQUEST
|--------------------------------------------------------------------------
*/

$data = [];


/*
|--------------------------------------------------------------------------
| POST JSON
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
) {

    $rawInput =
        file_get_contents(
            "php://input"
        );


    if (
        $rawInput !== false &&
        trim($rawInput) !== ""
    ) {

        $decoded =
            json_decode(
                $rawInput,
                true
            );


        if (
            is_array($decoded)
        ) {

            $data =
                $decoded;

        }

    }

}


/*
|--------------------------------------------------------------------------
| GET FALLBACK
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"] === "GET"
) {

    $data =
        $_GET;

}


/*
|--------------------------------------------------------------------------
| GET VALUES
|--------------------------------------------------------------------------
*/

$strand =
    trim(
        (string)(
            $data["strand"] ?? ""
        )
    );


$semester =
    trim(
        (string)(
            $data["semester"] ?? ""
        )
    );


$specialization_id =
    intval(
        $data["specialization_id"] ?? 0
    );


$grade_level =
    intval(
        $data["grade_level"] ?? 11
    );


/*
|--------------------------------------------------------------------------
| VALIDATE BASIC VALUES
|--------------------------------------------------------------------------
*/

if (
    $strand === ""
) {

    $conn->close();

    http_response_code(400);

    echo json_encode([

        "success" => false,

        "status" => "error",

        "message" =>
            "Missing strand.",

        "subjects" =>
            []

    ]);

    exit;
}


if (
    $semester === ""
) {

    $conn->close();

    http_response_code(400);

    echo json_encode([

        "success" => false,

        "status" => "error",

        "message" =>
            "Missing semester.",

        "subjects" =>
            []

    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| NORMALIZE STRAND
|--------------------------------------------------------------------------
*/

$strand =
    strtoupper(
        trim(
            $strand
        )
    );


/*
|--------------------------------------------------------------------------
| NORMALIZE SEMESTER
|--------------------------------------------------------------------------
*/

$semesterLower =
    strtolower(
        trim(
            $semester
        )
    );


if (
    $semesterLower === "1"
    ||
    $semesterLower === "first"
    ||
    $semesterLower === "first semester"
    ||
    $semesterLower === "1st semester"
) {

    $semester =
        "1st";

}

elseif (
    $semesterLower === "2"
    ||
    $semesterLower === "second"
    ||
    $semesterLower === "second semester"
    ||
    $semesterLower === "2nd semester"
) {

    $semester =
        "2nd";

}

else {

    $semester =
        trim(
            $semester
        );

}


/*
|--------------------------------------------------------------------------
| FIND STRAND ID
|--------------------------------------------------------------------------
*/

$getStrand =
    $conn->prepare("

        SELECT

            strand_id,

            strand_name

        FROM strand

        WHERE

            UPPER(
                TRIM(strand_name)
            ) = ?

        LIMIT 1

    ");


if (
    !$getStrand
) {

    $error =
        $conn->error;

    $conn->close();

    http_response_code(500);

    echo json_encode([

        "success" => false,

        "status" => "error",

        "message" =>
            "Failed to prepare strand query.",

        "error" =>
            $error,

        "subjects" =>
            []

    ]);

    exit;
}


$getStrand->bind_param(

    "s",

    $strand

);


if (
    !$getStrand->execute()
) {

    $error =
        $getStrand->error;

    $getStrand->close();

    $conn->close();

    http_response_code(500);

    echo json_encode([

        "success" => false,

        "status" => "error",

        "message" =>
            "Failed to execute strand query.",

        "error" =>
            $error,

        "subjects" =>
            []

    ]);

    exit;
}


$strandResult =
    $getStrand->get_result();


$strandRow =
    $strandResult->fetch_assoc();


$getStrand->close();


if (
    !$strandRow
) {

    $conn->close();

    http_response_code(400);

    echo json_encode([

        "success" => false,

        "status" => "error",

        "message" =>
            "Invalid strand.",

        "strand" =>
            $strand,

        "subjects" =>
            []

    ]);

    exit;
}


$strand_id =
    intval(
        $strandRow["strand_id"]
    );


/*
|--------------------------------------------------------------------------
| SUBJECT QUERY
|--------------------------------------------------------------------------
|
| NON-TVL:
|   exact strand
|   exact grade
|   exact semester
|   specialization must be NULL/0
|
| TVL:
|   exact strand
|   exact grade
|   exact semester
|   specialization = student's specialization
|
|--------------------------------------------------------------------------
*/

if (
    $strand === "TVL"
) {

    /*
    |--------------------------------------------------------------------------
    | TVL
    |--------------------------------------------------------------------------
    */

    if (
        $specialization_id <= 0
    ) {

        /*
        |----------------------------------------------------------------------
        | If TVL has no specialization, get shared TVL subjects only.
        |----------------------------------------------------------------------
        */

        $sql = "

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

                AND grade_level = ?

                AND semester = ?

                AND (
                    specialization_id IS NULL
                    OR specialization_id = 0
                )

                AND date_deleted IS NULL

            ORDER BY
                subject_id ASC

        ";


        $stmt =
            $conn->prepare(
                $sql
            );


        if (
            !$stmt
        ) {

            $error =
                $conn->error;

            $conn->close();

            http_response_code(500);

            echo json_encode([

                "success" => false,

                "status" => "error",

                "message" =>
                    "Failed to prepare TVL subject query.",

                "error" =>
                    $error,

                "subjects" =>
                    []

            ]);

            exit;
        }


        $stmt->bind_param(

            "iis",

            $strand_id,

            $grade_level,

            $semester

        );

    }

    else {

        /*
        |--------------------------------------------------------------------------
        | TVL WITH SPECIALIZATION
        |--------------------------------------------------------------------------
        */

        $sql = "

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

                AND grade_level = ?

                AND semester = ?

                AND (
                    specialization_id = ?
                    OR specialization_id IS NULL
                    OR specialization_id = 0
                )

                AND date_deleted IS NULL

            ORDER BY

                CASE

                    WHEN specialization_id = ?
                        THEN 0

                    WHEN specialization_id IS NULL
                        THEN 1

                    ELSE 2

                END,

                subject_id ASC

        ";


        $stmt =
            $conn->prepare(
                $sql
            );


        if (
            !$stmt
        ) {

            $error =
                $conn->error;

            $conn->close();

            http_response_code(500);

            echo json_encode([

                "success" => false,

                "status" => "error",

                "message" =>
                    "Failed to prepare TVL specialization query.",

                "error" =>
                    $error,

                "subjects" =>
                    []

            ]);

            exit;
        }


        $stmt->bind_param(

            "iisii",

            $strand_id,

            $grade_level,

            $semester,

            $specialization_id,

            $specialization_id

        );

    }

}

else {

    /*
    |--------------------------------------------------------------------------
    | NON-TVL
    |--------------------------------------------------------------------------
    */

    $sql = "

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

            AND grade_level = ?

            AND semester = ?

            AND (
                specialization_id IS NULL
                OR specialization_id = 0
            )

            AND date_deleted IS NULL

        ORDER BY
            subject_id ASC

    ";


    $stmt =
        $conn->prepare(
            $sql
        );


    if (
        !$stmt
    ) {

        $error =
            $conn->error;

        $conn->close();

        http_response_code(500);

        echo json_encode([

            "success" => false,

            "status" => "error",

            "message" =>
                "Failed to prepare strand subject query.",

            "error" =>
                $error,

            "subjects" =>
                []

        ]);

        exit;
    }


    $stmt->bind_param(

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

if (
    !$stmt->execute()
) {

    $error =
        $stmt->error;

    $stmt->close();

    $conn->close();

    http_response_code(500);

    echo json_encode([

        "success" => false,

        "status" => "error",

        "message" =>
            "Failed to execute subject query.",

        "error" =>
            $error,

        "subjects" =>
            []

    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| FETCH SUBJECTS
|--------------------------------------------------------------------------
*/

$result =
    $stmt->get_result();


$subjects = [];


while (
    $row =
    $result->fetch_assoc()
) {

    $subjects[] = [

        "subject_id" =>
            intval(
                $row["subject_id"]
            ),

        "subject_name" =>
            (string)(
                $row["subject_name"] ??
                ""
            ),

        "strand_id" =>
            intval(
                $row["strand_id"]
            ),

        "specialization_id" =>
            $row["specialization_id"] !== null

                ? intval(
                    $row["specialization_id"]
                )

                : null,

        "grade_level" =>
            intval(
                $row["grade_level"]
            ),

        "semester" =>
            (string)(
                $row["semester"] ??
                ""
            )

    ];

}


$stmt->close();


/*
|--------------------------------------------------------------------------
| REMOVE DUPLICATE SUBJECTS
|--------------------------------------------------------------------------
|
| This is important because duplicate subject names/records
| can exist for different semesters or configurations.
|
|--------------------------------------------------------------------------
*/

$uniqueSubjects = [];


foreach (
    $subjects as $subject
) {

    $key =
        intval(
            $subject["subject_id"]
        );


    if (
        $key <= 0
    ) {

        continue;

    }


    if (
        !isset(
            $uniqueSubjects[$key]
        )
    ) {

        $uniqueSubjects[$key] =
            $subject;

    }

}


$subjects =
    array_values(
        $uniqueSubjects
    );


/*
|--------------------------------------------------------------------------
| RESPONSE
|--------------------------------------------------------------------------
*/

http_response_code(200);

echo json_encode([

    "success" =>
        true,

    "status" =>
        "success",

    "message" =>
        "Subjects loaded successfully.",

    "filters" => [

        "strand_id" =>
            $strand_id,

        "strand" =>
            $strand,

        "specialization_id" =>
            $specialization_id > 0

                ? $specialization_id

                : null,

        "grade_level" =>
            $grade_level,

        "semester" =>
            $semester

    ],

    "subject_count" =>
        count($subjects),

    "subjects" =>
        $subjects

], JSON_UNESCAPED_UNICODE);


$conn->close();

exit;

?>