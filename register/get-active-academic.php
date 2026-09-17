<?php
require_once __DIR__ . '/../_shared/db.php';

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}


/* =====================================================
   DATABASE CONNECTION
===================================================== */

$conn = brainpal_db();

if ($conn->connect_error) {

    echo json_encode([
        "status" => "error",
        "message" => "Database connection failed."
    ]);

    exit;
}

$conn->set_charset("utf8mb4");


/* =====================================================
   GET ACTIVE ACADEMIC YEARS
===================================================== */

$sql = "
    SELECT
        academic_id,
        academic_year,
        date_start,
        date_end,
        status
    FROM academic
    WHERE status = 'active'
      AND date_deleted IS NULL
    ORDER BY date_start DESC
";

$result = $conn->query($sql);

if (!$result) {

    echo json_encode([
        "status" => "error",
        "message" => $conn->error
    ]);

    $conn->close();

    exit;
}


$data = [];


/* =====================================================
   BUILD RESPONSE
===================================================== */

while ($row = $result->fetch_assoc()) {

    $data[] = [

        "academic_id" =>
            (int)$row["academic_id"],

        "academic_year" =>
            $row["academic_year"],

        "date_start" =>
            $row["date_start"],

        "date_end" =>
            $row["date_end"],

        "status" =>
            $row["status"]

    ];

}


/* =====================================================
   RESPONSE
===================================================== */

echo json_encode([

    "status" => "success",

    "data" => $data

]);


$conn->close();

?>