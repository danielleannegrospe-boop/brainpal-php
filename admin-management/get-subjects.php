<?php
require_once __DIR__ . '/../_shared/db.php';

error_reporting(E_ALL);
ini_set('display_errors', 0);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");


/* =====================================================
   OPTIONS
===================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {

    http_response_code(200);
    exit();

}


/* =====================================================
   DATABASE
===================================================== */

$conn = brainpal_db();


if ($conn->connect_error) {

    echo json_encode([
        "status" => "error",
        "message" => "Database connection failed.",
        "error" => $conn->connect_error
    ]);

    exit();

}


$conn->set_charset("utf8mb4");


/* =====================================================
   HELPER - CHECK TABLE
===================================================== */

function tableExists(BrainPalDb $conn, string $table): bool
{
    $tableSafe = $conn->real_escape_string($table);

    $result = $conn->query(
        "SHOW TABLES LIKE '{$tableSafe}'"
    );

    return $result && $result->num_rows > 0;
}


/* =====================================================
   HELPER - CHECK COLUMN
===================================================== */

function columnExists(
    BrainPalDb $conn,
    string $table,
    string $column
): bool {

    $tableSafe =
        $conn->real_escape_string($table);

    $columnSafe =
        $conn->real_escape_string($column);

    $result = $conn->query(
        "SHOW COLUMNS FROM `{$tableSafe}` LIKE '{$columnSafe}'"
    );

    return $result && $result->num_rows > 0;
}


/* =====================================================
   CHECK REQUIRED TABLES
===================================================== */

if (!tableExists($conn, 'subject')) {

    echo json_encode([
        "status" => "error",
        "message" => "Subject table does not exist."
    ]);

    $conn->close();
    exit();

}


if (!tableExists($conn, 'strand')) {

    echo json_encode([
        "status" => "error",
        "message" => "Strand table does not exist."
    ]);

    $conn->close();
    exit();

}


/* =====================================================
   CHECK SUBJECT COLUMNS
===================================================== */

$requiredSubjectColumns = [

    'subject_id',
    'subject_name',
    'strand_id',
    'semester',
    'grade_level',
    'date_deleted'

];


foreach (
    $requiredSubjectColumns
    as $column
) {

    if (
        !columnExists(
            $conn,
            'subject',
            $column
        )
    ) {

        echo json_encode([
            "status" => "error",
            "message" =>
                "Missing subject column: " . $column
        ]);

        $conn->close();
        exit();

    }

}


/* =====================================================
   CHECK STRAND COLUMNS
===================================================== */

if (
    !columnExists(
        $conn,
        'strand',
        'strand_id'
    )
    ||
    !columnExists(
        $conn,
        'strand',
        'strand_name'
    )
) {

    echo json_encode([
        "status" => "error",
        "message" => "Invalid strand table structure."
    ]);

    $conn->close();
    exit();

}


/* =====================================================
   CHECK SPECIALIZATION TABLE
===================================================== */

$hasSpecialization =
    tableExists(
        $conn,
        'specialization'
    );


$hasSpecializationId = false;

$hasSpecializationName = false;


if ($hasSpecialization) {

    $hasSpecializationId =
        columnExists(
            $conn,
            'specialization',
            'specialization_id'
        );

    $hasSpecializationName =
        columnExists(
            $conn,
            'specialization',
            'specialization_name'
        );

}


/* =====================================================
   BASE SUBJECT QUERY
===================================================== */

$sql = "

SELECT

    s.subject_id,

    s.subject_name,

    s.strand_id,

    st.strand_name,

    s.semester,

    s.grade_level

FROM subject AS s

INNER JOIN strand AS st

    ON s.strand_id = st.strand_id

WHERE s.date_deleted IS NULL

ORDER BY

    st.strand_name ASC,

    s.grade_level ASC,

    s.semester ASC,

    s.subject_name ASC

";


$result = $conn->query($sql);


if (!$result) {

    echo json_encode([

        "status" => "error",

        "message" =>
            "Failed to load subjects.",

        "error" =>
            $conn->error

    ], JSON_UNESCAPED_UNICODE);

    $conn->close();

    exit();

}


/* =====================================================
   GET SPECIALIZATIONS
===================================================== */

$specializations = [];


if (
    $hasSpecialization &&
    $hasSpecializationId &&
    $hasSpecializationName
) {

    $specializationQuery = "

        SELECT

            specialization_id,

            strand_id,

            specialization_name

        FROM specialization

        ORDER BY specialization_id ASC

    ";


    $specializationResult =
        $conn->query(
            $specializationQuery
        );


    if ($specializationResult) {

        while (
            $sp =
            $specializationResult->fetch_assoc()
        ) {

            $specializations[
                (int)$sp["specialization_id"]
            ] = [

                "specialization_id" =>
                    (int)$sp["specialization_id"],

                "strand_id" =>
                    (int)$sp["strand_id"],

                "specialization_name" =>
                    $sp["specialization_name"]

            ];

        }

    }

}


/* =====================================================
   SUBJECT ARRAY
===================================================== */

$subjects = [];


while (
    $row =
    $result->fetch_assoc()
) {

    $subjectId =
        (int)$row["subject_id"];


    $strandId =
        (int)$row["strand_id"];


    /*
     * specialization_id is read separately
     * because the current database contains
     * some old subject records using 0.
     */

    $specializationId = null;

    $specializationName = "";


    /*
     * Get specialization_id directly from
     * the subject table using a small query.
     */

    if (
        columnExists(
            $conn,
            'subject',
            'specialization_id'
        )
    ) {

        $idQuery = "

            SELECT specialization_id

            FROM subject

            WHERE subject_id = {$subjectId}

            LIMIT 1

        ";


        $idResult =
            $conn->query(
                $idQuery
            );


        if (
            $idResult &&
            $idRow =
            $idResult->fetch_assoc()
        ) {

            $rawSpecializationId =
                $idRow["specialization_id"];


            if (
                $rawSpecializationId !== null &&
                (int)$rawSpecializationId > 0
            ) {

                $specializationId =
                    (int)$rawSpecializationId;


                if (
                    isset(
                        $specializations[
                            $specializationId
                        ]
                    )
                ) {

                    $specializationName =
                        $specializations[
                            $specializationId
                        ]["specialization_name"];

                }

            }

        }

    }


    $subjects[] = [

        /* =========================
           SUBJECT
        ========================= */

        "subject_id" =>
            $subjectId,

        "subject_name" =>
            $row["subject_name"],


        /* =========================
           STRAND
        ========================= */

        "strand_id" =>
            $strandId,

        "strand_name" =>
            $row["strand_name"],

        /* Old frontend compatibility */

        "strand" =>
            $row["strand_name"],


        /* =========================
           SPECIALIZATION
        ========================= */

        "specialization_id" =>
            $specializationId,

        "specialization_name" =>
            $specializationName,

        /* Old frontend compatibility */

        "specialization" =>
            $specializationName,


        /* =========================
           OTHER DETAILS
        ========================= */

        "semester" =>
            $row["semester"],

        "grade_level" =>
            (int)$row["grade_level"]

    ];

}


/* =====================================================
   RESPONSE
===================================================== */

echo json_encode(

    [

        "status" =>
            "success",

        "subjects" =>
            $subjects,

        "strands" => [

            [
                "id" => 1,
                "name" => "STEM"
            ],

            [
                "id" => 2,
                "name" => "ABM"
            ],

            [
                "id" => 3,
                "name" => "HUMSS"
            ],

            [
                "id" => 4,
                "name" => "GAS"
            ],

            [
                "id" => 5,
                "name" => "TVL"
            ]

        ],

        "specializations" =>
            array_values(
                $specializations
            )

    ],

    JSON_UNESCAPED_UNICODE

);


$conn->close();

?>