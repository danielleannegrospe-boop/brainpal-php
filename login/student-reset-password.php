<?php
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

require_once("../backend/database.php");

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

// Check if email exists
$stmt = $conn->prepare("SELECT student_id FROM student WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows == 0) {

    echo json_encode([
        "status" => "error",
        "message" => "Account not found."
    ]);
    exit();
}

$stmt->close();

// Hash password
$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

// Update password
$update = $conn->prepare("
    UPDATE student
    SET
        hashed_password = ?,
        reset_otp = NULL,
        otp_expiry = NULL
    WHERE email = ?
");

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