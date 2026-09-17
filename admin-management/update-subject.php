<?php
require_once __DIR__ . '/../_shared/db.php';
require_once __DIR__.'/../_shared/admin-auth.php';


error_reporting(E_ALL);
ini_set('display_errors', 0);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] == "OPTIONS") {
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

$subject_id = intval($data['subject_id'] ?? 0);
$subject_name = trim($data['subject_name'] ?? '');
$strand_id = intval($data['strand_id'] ?? 0);
$semester = trim($data['semester'] ?? '');
$grade_level = intval($data['grade_level'] ?? 0);
$specialization_id = $data['specialization_id'] ?? null;
$admin_id = intval($data['admin_id'] ?? 0);
$actor = bp_require_role(['admin_id'=>$admin_id], ['super_admin','student_admin']);

if (
    $subject_id <= 0 ||
    $subject_name == '' ||
    $strand_id <= 0 ||
    $semester == '' ||
    $grade_level <= 0 ||
    $admin_id <= 0
) {

    echo json_encode([
        "status" => "error",
        "message" => "Missing required fields."
    ]);

    exit();
}

if ($specialization_id == '' || $specialization_id == 0) {
    $specialization_id = null;
}

/* ==========================
   UPDATE SUBJECT
========================== */

$sql = "
UPDATE subject
SET
    subject_name = ?,
    strand_id = ?,
    semester = ?,
    grade_level = ?,
    specialization_id = ?
WHERE subject_id = ?
";

$stmt = $conn->prepare($sql);

$stmt->bind_param(
    "sissii",
    $subject_name,
    $strand_id,
    $semester,
    $grade_level,
    $specialization_id,
    $subject_id
);

if ($stmt->execute()) {

    // Log activity and notify Super Admin.
    bp_log(
        $conn,
        $admin_id,
        "Updated Subject",
        "Updated subject: " . $subject_name
    );

    echo json_encode([
        "status" => "success",
        "message" => "Subject updated successfully."
    ]);

} else {

    echo json_encode([
        "status" => "error",
        "message" => "Update failed."
    ]);

}

$stmt->close();
$conn->close();

?>