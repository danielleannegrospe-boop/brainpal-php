<?php
require_once __DIR__ . '/../_shared/db.php';
error_reporting(E_ALL);
ini_set('display_errors', 0);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once("../email/mailer.php");

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

if (empty($email)) {
    echo json_encode([
        "status" => "error",
        "message" => "Email is required."
    ]);
    exit();
}

// Check if admin email exists
$stmt = $conn->prepare("
    SELECT admin_id
    FROM admin
    WHERE email = ? AND date_deleted IS NULL
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
        "message" => "Email not found."
    ]);
    exit();
}

$stmt->close();

// Generate OTP
$otp = rand(100000, 999999);
$expiry = date("Y-m-d H:i:s", strtotime("+5 minutes"));

// Save OTP
$update = $conn->prepare("
    UPDATE admin
    SET reset_otp = ?, otp_expiry = ?
    WHERE email = ?
");

if (!$update) {
    echo json_encode([
        "status" => "error",
        "message" => $conn->error
    ]);
    exit();
}

$update->bind_param("sss", $otp, $expiry, $email);

if ($update->execute()) {

    try {
        $sent = sendBrainPalEmail(
            $email,
            'BrainPal Administrator',
            'BrainPal Password Reset OTP',
            '<div style="font-family:Arial,sans-serif"><h2>BrainPal Password Reset</h2><p>Your administrator verification code is:</p><p style="font-size:30px;font-weight:bold;letter-spacing:6px">'.$otp.'</p><p>This code expires in 5 minutes. If you did not request this, ignore this email.</p></div>'
        );
    } catch (Throwable $e) {
        error_log('BrainPal admin OTP email failed: '.$e->getMessage());
        $sent = false;
    }

    if ($sent) {
        echo json_encode([
            "status" => "success",
            "message" => "OTP sent to your email."
        ]);
    } else {
        echo json_encode([
            "status" => "error",
            "message" => "Unable to send the verification email. Please check the SMTP configuration."
        ]);
    }

} else {

    echo json_encode([
        "status" => "error",
        "message" => $update->error
    ]);

}

$update->close();
$conn->close(); 