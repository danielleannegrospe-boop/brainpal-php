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

/* ==========================
   DATABASE CONNECTION
========================== */

$conn = brainpal_db();

if ($conn->connect_error) {

    echo json_encode([
        "status" => "error",
        "message" => "Database connection failed."
    ]);

    exit();
}

$conn->set_charset("utf8mb4");

/* ==========================
   GET JSON DATA
========================== */

$data = json_decode(file_get_contents("php://input"), true);

$subject_id = intval($data['subject_id'] ?? 0);
$admin_id = intval($data['admin_id'] ?? 0);
$actor = bp_require_role(['admin_id'=>$admin_id], ['super_admin','student_admin']);

if ($subject_id <= 0 || $admin_id <= 0) {

    echo json_encode([
        "status" => "error",
        "message" => "Invalid request."
    ]);

    exit();
}

/* ==========================
   GET SUBJECT NAME
========================== */

$get = $conn->prepare("
SELECT subject_name
FROM subject
WHERE subject_id = ?
");

$get->bind_param("i", $subject_id);
$get->execute();

$result = $get->get_result();

if ($result->num_rows == 0) {

    echo json_encode([
        "status" => "error",
        "message" => "Subject not found."
    ]);

    exit();
}

$row = $result->fetch_assoc();
$subject_name = $row["subject_name"];

$get->close();

/* ==========================
   DELETE SUBJECT
========================== */

$stmt = $conn->prepare("
UPDATE subject
SET date_deleted = NOW()
WHERE subject_id = ?
");

$stmt->bind_param(
    "i",
    $subject_id
);

if ($stmt->execute()) {

    // Log activity and notify Super Admin.
    bp_log(
        $conn,
        $admin_id,
        "Deleted Subject",
        "Deleted subject: " . $subject_name
    );

    echo json_encode([
        "status" => "success",
        "message" => "Subject deleted successfully."
    ]);

} else {

    echo json_encode([
        "status" => "error",
        "message" => "Delete failed."
    ]);

}

$stmt->close();
$conn->close();

?>