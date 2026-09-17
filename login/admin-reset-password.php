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

// Database Connection
$conn = brainpal_db();

if ($conn->connect_error) {
    echo json_encode([
        "status" => "error",
        "message" => "Database connection failed."
    ]);
    exit();
}

$conn->set_charset("utf8mb4");

// Get JSON data
$data = json_decode(file_get_contents("php://input"), true);

$email = trim($data['email'] ?? '');
$password = trim($data['password'] ?? '');

if (empty($email) || empty($password)) {
    echo json_encode([
        "status" => "error",
        "message" => "Email and password are required."
    ]);
    exit();
}

// Minimum password length
if (strlen($password) < 8) {
    echo json_encode([
        "status" => "error",
        "message" => "Password must be at least 8 characters."
    ]);
    exit();
}

// Check if admin exists
$stmt = $conn->prepare("
    SELECT admin_id
    FROM admin
    WHERE email = ?
      AND date_deleted IS NULL
");

if (!$stmt) {
    echo json_encode([
        "status" => "error",
        "message" => $conn->error
    ]);
    exit();
}

$stmt->bind_param("s", $email);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode([
        "status" => "error",
        "message" => "Admin account not found."
    ]);
    exit();
}

$stmt->close();

// Hash password
$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

// Update password
$update = $conn->prepare("
    UPDATE admin
    SET
        hashed_password = ?,
        reset_otp = NULL,
        otp_expiry = NULL,
        reset_token = NULL,
        token_expiry = NULL
    WHERE email = ?
");

if (!$update) {
    echo json_encode([
        "status" => "error",
        "message" => $conn->error
    ]);
    exit();
}

$update->bind_param("ss", $hashedPassword, $email);

if ($update->execute()) {

    echo json_encode([
        "status" => "success",
        "message" => "Password updated successfully."
    ]);

} else {

    echo json_encode([
        "status" => "error",
        "message" => $update->error
    ]);

}

$update->close();
$conn->close(); 