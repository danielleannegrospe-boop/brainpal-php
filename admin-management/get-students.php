<?php
require_once __DIR__ . '/../_shared/db.php';

error_reporting(E_ALL);
ini_set("display_errors", 0);

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");


/* =========================================================
   OPTIONS REQUEST
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {

    http_response_code(200);

    exit();

}


/* =========================================================
   DATABASE CONFIGURATION
========================================================= */

$host = "localhost";

$username = "root";

$password = "";

$database = "brainpal";

$port = 3307;


/* =========================================================
   DATABASE CONNECTION
========================================================= */

$conn = brainpal_db();


/* =========================================================
   CHECK CONNECTION
========================================================= */

if ($conn->connect_error) {

    http_response_code(500);

    echo json_encode([

        "success" => false,

        "message" =>
            "Database connection failed.",

        "database_error" =>
            $conn->connect_error,

        "data" => []

    ]);

    exit();

}


$conn->set_charset("utf8mb4");


/* =========================================================
   GET STUDENTS
========================================================= */

$sql = "

    SELECT

        student_id,

        studentNo,

        firstName,

        m_initial,

        lastName,

        extension,

        email,

        strand_id,

        specialization_id,

        academic_id,

        grade_level,
profile_photo,

        date_created

    FROM student

    WHERE date_deleted IS NULL

    ORDER BY

        lastName ASC,

        firstName ASC

";


/* =========================================================
   EXECUTE QUERY
========================================================= */

$result = $conn->query($sql);


/* =========================================================
   QUERY ERROR
========================================================= */

if (!$result) {

    http_response_code(500);

    echo json_encode([

        "success" => false,

        "message" =>
            "Failed to retrieve students.",

        "database_error" =>
            $conn->error,

        "data" => []

    ]);

    $conn->close();

    exit();

}


/* =========================================================
   STORE STUDENTS
========================================================= */

$students = [];


while (

    $row = $result->fetch_assoc()

) {


    /*
    =========================================================
    STRAND NAME
    =========================================================
    */

    $strandName = "No Strand";


    switch (
        intval(
            $row["strand_id"]
        )
    ) {

        case 1:

            $strandName = "STEM";

            break;


        case 2:

            $strandName = "ABM";

            break;


        case 3:

            $strandName = "HUMSS";

            break;


        case 4:

            $strandName = "GAS";

            break;


        case 5:

            $strandName = "TVL";

            break;


        default:

            $strandName = "No Strand";

            break;

    }


    /*
    =========================================================
    ADD STUDENT
    =========================================================
    */

    $students[] = [

        "student_id" =>

            intval(
                $row["student_id"]
            ),


        "studentNo" =>

            $row["studentNo"] ?? null,


        "firstName" =>

            $row["firstName"] ?? "",


        "m_initial" =>

            $row["m_initial"] ?? "",


        "lastName" =>

            $row["lastName"] ?? "",


        "extension" =>

            $row["extension"] ?? "",


        "email" =>

            $row["email"] ?? "",


        "strand_id" =>

            $row["strand_id"] !== null

                ? intval(
                    $row["strand_id"]
                )

                : null,


        "strand_name" =>

            $strandName,


        "strand" =>

            $strandName,


        "specialization_id" =>

            $row["specialization_id"] !== null

                ? intval(
                    $row["specialization_id"]
                )

                : null,


        "academic_id" =>

            $row["academic_id"] !== null

                ? intval(
                    $row["academic_id"]
                )

                : null,


        "grade_level" =>

            $row["grade_level"] ?? null,
"profile_photo" =>

            $row["profile_photo"] ?? null,


        "date_created" =>

            $row["date_created"] ?? null

    ];

}


/* =========================================================
   SUCCESS RESPONSE
========================================================= */

echo json_encode([

    "success" => true,

    "message" =>

        count($students) > 0

            ? "Students loaded successfully."

            : "No students found.",


    "count" =>

        count(
            $students
        ),


    "data" =>

        $students

], JSON_UNESCAPED_UNICODE);


/* =========================================================
   CLOSE CONNECTION
========================================================= */

$result->free();

$conn->close();

?>