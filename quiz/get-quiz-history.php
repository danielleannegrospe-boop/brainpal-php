<?php
require_once __DIR__ . '/../_shared/db.php';

header("Content-Type: application/json; charset=UTF-8");

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");


/* =====================================
   OPTIONS
===================================== */

if (
    $_SERVER["REQUEST_METHOD"] === "OPTIONS"
) {

    http_response_code(200);

    echo json_encode([
        "success" => true,
        "message" => "OK",
        "data" => []
    ]);

    exit;

}


/* =====================================
   DATABASE
===================================== */

$conn = brainpal_db();


if ($conn->connect_error) {

    http_response_code(500);

    echo json_encode([

        "success" => false,

        "message" =>
            "Database connection failed.",

        "error" =>
            $conn->connect_error,

        "data" => []

    ]);

    exit;

}


$conn->set_charset("utf8mb4");


/* =====================================
   STUDENT ID
===================================== */

$student_id =

    isset($_GET["student_id"])

        ? intval($_GET["student_id"])

        : 0;


if ($student_id <= 0) {

    http_response_code(400);

    echo json_encode([

        "success" => false,

        "message" =>
            "Invalid student_id.",

        "student_id" =>
            $student_id,

        "data" => []

    ]);

    $conn->close();

    exit;

}


/* =====================================
   QUIZ HISTORY
===================================== */

$sql = "

    SELECT

        qa.attempt_id,

        qa.quiz_id,

        qa.student_id,

        qa.score,

        qa.date_created,

        q.quiz_title

    FROM quiz_attempt qa

    LEFT JOIN quiz q

        ON q.quiz_id = qa.quiz_id

    WHERE

        qa.student_id = ?

    ORDER BY

        qa.date_created DESC,

        qa.attempt_id DESC

";


$stmt =
    $conn->prepare($sql);


if (!$stmt) {

    http_response_code(500);

    echo json_encode([

        "success" => false,

        "message" =>
            "Failed to prepare query.",

        "error" =>
            $conn->error,

        "data" => []

    ]);

    $conn->close();

    exit;

}


$stmt->bind_param(

    "i",

    $student_id

);


if (!$stmt->execute()) {

    http_response_code(500);

    echo json_encode([

        "success" => false,

        "message" =>
            "Failed to execute query.",

        "error" =>
            $stmt->error,

        "data" => []

    ]);

    $stmt->close();

    $conn->close();

    exit;

}


$result =
    $stmt->get_result();


$data = [];


/* =====================================
   BUILD DATA
===================================== */

while (
    $row =
    $result->fetch_assoc()
) {

    $data[] = [

        "attempt_id" =>

            intval(
                $row["attempt_id"]
            ),


        "quiz_id" =>

            $row["quiz_id"] !== null

                ? intval(
                    $row["quiz_id"]
                )

                : 0,


        "student_id" =>

            $row["student_id"] !== null

                ? intval(
                    $row["student_id"]
                )

                : 0,


        "score" =>

            $row["score"] !== null

                ? (float)
                    $row["score"]

                : 0,


        "date_created" =>

            $row["date_created"]
            ?? "",


        "quiz_title" =>

            $row["quiz_title"]
            ?? ""

    ];

}


/* =====================================
   RESPONSE
===================================== */

echo json_encode([

    "success" => true,

    "message" =>

        count($data) > 0

            ? "Quiz history loaded successfully."

            : "No quiz history found.",


    "student_id" =>
        $student_id,


    "count" =>
        count($data),


    "data" =>
        $data

],
JSON_UNESCAPED_UNICODE
);


/* =====================================
   CLOSE
===================================== */

$stmt->close();

$conn->close();

?>