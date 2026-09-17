<?php
require_once __DIR__ . '/../_shared/db.php';
require_once __DIR__.'/../_shared/admin-auth.php';

error_reporting(E_ALL);
ini_set('display_errors', 0);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$conn = brainpal_db();

if ($conn->connect_error) {
    echo json_encode([
        "status" => "error",
        "message" => "Database connection failed."
    ]);
    exit();
}

$conn->set_charset("utf8mb4");

$data = json_decode(file_get_contents("php://input"), true);

$subject_name = trim($data['subject_name'] ?? '');
$strand_id = intval($data['strand_id'] ?? 0);
$specialization_id = $data['specialization_id'] ?? null;
$semester = trim($data['semester'] ?? '');
$grade_level = intval($data['grade_level'] ?? 0);
$admin_id = intval($data['admin_id'] ?? 0);
$actor = bp_require_role(['admin_id'=>$admin_id], ['super_admin','student_admin']);

if (
    $subject_name == '' ||
    $strand_id == 0 ||
    $semester == '' ||
    $grade_level == 0 ||
    $admin_id == 0
) {
    echo json_encode([
        "status" => "error",
        "message" => "Please fill all required fields."
    ]);
    exit();
}

/* Check duplicate */

$check = $conn->prepare("
SELECT subject_id
FROM subject
WHERE subject_name = ?
AND strand_id = ?
AND semester = ?
AND grade_level = ?
AND date_deleted IS NULL
");

$check->bind_param(
    "sisi",
    $subject_name,
    $strand_id,
    $semester,
    $grade_level
);

$check->execute();
$result = $check->get_result();

if ($result->num_rows > 0) {

    echo json_encode([
        "status" => "error",
        "message" => "Subject already exists."
    ]);
    exit();

}

if ($specialization_id == '' || $specialization_id == 0) {
    $specialization_id = NULL;
}

$sql = $conn->prepare("
INSERT INTO subject
(
    strand_id,
    specialization_id,
    admin_id,
    subject_name,
    semester,
    grade_level
)
VALUES
(
    ?, ?, ?, ?, ?, ?
)
");

$sql->bind_param(
    "iiissi",
    $strand_id,
    $specialization_id,
    $admin_id,
    $subject_name,
    $semester,
    $grade_level
);

if ($sql->execute()) {

    // Log activity and notify Super Admin.
    bp_log(
        $conn,
        $admin_id,
        "Added Subject",
        "Added subject: " . $subject_name
    );

    echo json_encode([
        "status" => "success",
        "message" => "Subject added successfully."
    ]);

} else {

    echo json_encode([
        "status" => "error",
        "message" => $conn->error
    ]);

}