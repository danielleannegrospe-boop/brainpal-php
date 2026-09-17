<?php
require_once __DIR__ . '/../_shared/db.php';
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

// Database
$conn = brainpal_db();

if ($conn->connect_error) {
    echo json_encode([
        "status" => "error",
        "message" => "Database connection failed."
    ]);
    exit();
}

$conn->set_charset("utf8mb4");

// Get JSON
$data = json_decode(file_get_contents("php://input"), true);

$email = trim($data['email'] ?? '');
$otp   = trim($data['otp'] ?? '');

if ($email == '' || $otp == '') {
    echo json_encode([
        "status" => "error",
        "message" => "Email and OTP are required."
    ]);
    exit();
}

// Check OTP
$stmt = $conn->prepare("
    SELECT admin_id, otp_expiry
    FROM admin
    WHERE email = ?
      AND reset_otp = ?
      AND date_deleted IS NULL
");

$stmt->bind_param("ss", $email, $otp);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows == 0) {

    echo json_encode([
        "status" => "error",
        "message" => "Invalid OTP."
    ]);

    exit();
}

$row = $result->fetch_assoc();

// Check expiration
if (strtotime($row['otp_expiry']) < time()) {

    echo json_encode([
        "status" => "error",
        "message" => "OTP has expired."
    ]);

    exit();
}

// Success
echo json_encode([
    "status" => "success",
    "message" => "OTP verified successfully."
]);

$stmt->close();
$conn->close();