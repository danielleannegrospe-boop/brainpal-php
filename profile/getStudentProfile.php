<?php
require_once __DIR__ . '/../_shared/db.php';

error_reporting(E_ALL);
ini_set('display_errors', 0);

/* =====================================================
   CORS
===================================================== */

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
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");


/* =====================================================
   PREFLIGHT
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

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "DB connection failed: " .
                     $conn->connect_error
    ]);

    exit();

}


$conn->set_charset("utf8mb4");


/* =====================================================
   STUDENT ID
===================================================== */

$student_id =
    isset($_GET['student_id'])
        ? intval($_GET['student_id'])
        : 0;


if ($student_id <= 0) {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Invalid or missing student_id"
    ]);

    $conn->close();

    exit();

}


/* =====================================================
   GET STUDENT PROFILE
===================================================== */

$stmt = $conn->prepare("

    SELECT

        s.student_id,

        s.firstName,

        s.m_initial,

        s.lastName,

        s.email,

        s.strand_id,

        s.specialization_id,

        st.strand_name,

        sp.specialization_name

    FROM student s

    LEFT JOIN strand st

        ON st.strand_id = s.strand_id

    LEFT JOIN specialization sp

        ON sp.specialization_id = s.specialization_id

        AND sp.strand_id = s.strand_id

    WHERE s.student_id = ?

    AND s.date_deleted IS NULL

    LIMIT 1

");


if (!$stmt) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Prepare failed: " .
                     $conn->error
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


/* =====================================================
   CHECK STUDENT
===================================================== */

if (!$row = $result->fetch_assoc()) {

    http_response_code(404);

    echo json_encode([
        "success" => false,
        "message" => "Student not found"
    ]);

    $stmt->close();

    $conn->close();

    exit();

}


/* =====================================================
   OUTPUT
===================================================== */

echo json_encode([

    "success" => true,

    "data" => [

        "student_id" =>
            (int)$row['student_id'],

        "firstName" =>
            $row['firstName'],

        "m_initial" =>
            $row['m_initial'],

        "lastName" =>
            $row['lastName'],

        "email" =>
            $row['email'],

        "strand_id" =>
            (int)$row['strand_id'],

        "strand_name" =>
            $row['strand_name'],

        "specialization_id" =>
            $row['specialization_id'] !== null
                ? (int)$row['specialization_id']
                : null,

        "specialization_name" =>
            $row['specialization_name'] ?? null

    ]

]);


$stmt->close();

$conn->close();

exit();

?>  