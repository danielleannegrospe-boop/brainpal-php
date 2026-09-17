<?php

error_reporting(E_ALL);
ini_set('display_errors', 0);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");


/*
|--------------------------------------------------------------------------
| OPTIONS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {

    http_response_code(200);

    exit();

}


/*
|--------------------------------------------------------------------------
| DATABASE
|--------------------------------------------------------------------------
*/

require_once("../backend/database.php");
require_once("../email/mailer.php");


if ($conn->connect_error) {

    echo json_encode([
        "status" => "error",
        "message" => "Database connection failed."
    ]);

    exit();

}


/*
|--------------------------------------------------------------------------
| GET JSON
|--------------------------------------------------------------------------
*/

$data =
    json_decode(
        file_get_contents("php://input"),
        true
    );


$email =
    trim(
        $data['email'] ?? ''
    );


/*
|--------------------------------------------------------------------------
| VALIDATE EMAIL
|--------------------------------------------------------------------------
*/

if (empty($email)) {

    echo json_encode([
        "status" => "error",
        "message" => "Email is required."
    ]);

    exit();

}


$email =
    strtolower($email);

$purpose = strtolower(trim((string)($data['purpose'] ?? 'forgot')));

$allowedPurposes = [
    'registration',
    'forgot',
    'login_verification'
];

if (!in_array($purpose, $allowedPurposes, true)) {
    $purpose = 'forgot';
}


/*
|--------------------------------------------------------------------------
| FIND STUDENT
|--------------------------------------------------------------------------
*/

$stmt =
    $conn->prepare("
        SELECT
            student_id,
            is_verified,
            account_status,
            date_deleted
        FROM student
        WHERE email = ?
          AND date_deleted IS NULL
        LIMIT 1
    ");


if (!$stmt) {

    echo json_encode([
        "status" => "error",
        "message" => "Database query failed."
    ]);

    exit();

}


$stmt->bind_param(
    "s",
    $email
);

$stmt->execute();

$result =
    $stmt->get_result();


/*
|--------------------------------------------------------------------------
| EMAIL NOT FOUND
|--------------------------------------------------------------------------
*/

if (
    $result->num_rows === 0
) {

    echo json_encode([
        "status" => "error",
        "message" => "Email not found."
    ]);

    $stmt->close();

    $conn->close();

    exit();

}


$student =
    $result->fetch_assoc();


$stmt->close();


/*
|--------------------------------------------------------------------------
| GENERATE OTP
|--------------------------------------------------------------------------
*/

$otp =
    (string) rand(
        100000,
        999999
    );


/*
|--------------------------------------------------------------------------
| OTP EXPIRY
|--------------------------------------------------------------------------
*/

$expiry =
    date(
        "Y-m-d H:i:s",
        strtotime("+5 minutes")
    );


/*
|--------------------------------------------------------------------------
| SAVE OTP
|--------------------------------------------------------------------------
*/

$update =
    $conn->prepare("
        UPDATE student
        SET
            reset_otp = ?,
            otp_expiry = ?
        WHERE email = ?
    ");


if (!$update) {

    echo json_encode([
        "status" => "error",
        "message" => "Unable to prepare OTP update."
    ]);

    $conn->close();

    exit();

}


$update->bind_param(
    "sss",
    $otp,
    $expiry,
    $email
);


if (
    $update->execute()
) {

    try {
        if ($purpose === 'registration') {
            $subject = 'BrainPal Registration OTP';
            $heading = 'BrainPal Registration Verification';
            $intro = 'Your verification code for completing your BrainPal registration is:';
            $ignoreText = 'If you did not request this registration, you can safely ignore this email.';
        } elseif ($purpose === 'login_verification') {
            $subject = 'BrainPal Login Verification OTP';
            $heading = 'BrainPal Login Verification';
            $intro = 'Your verification code for signing in to BrainPal is:';
            $ignoreText = 'If you did not request this login verification, you can safely ignore this email.';
        } else {
            $subject = 'BrainPal Password Reset OTP';
            $heading = 'BrainPal Password Reset';
            $intro = 'Your verification code for resetting your BrainPal password is:';
            $ignoreText = 'If you did not request this password reset, you can safely ignore this email.';
        }

        $html = '<div style="font-family:Arial,sans-serif;line-height:1.6">'
              . '<h2 style="color:#654db5">'.htmlspecialchars($heading, ENT_QUOTES, 'UTF-8').'</h2>'
              . '<p>'.htmlspecialchars($intro, ENT_QUOTES, 'UTF-8').'</p>'
              . '<p style="font-size:30px;font-weight:bold;letter-spacing:6px">'.$otp.'</p>'
              . '<p>This code expires in 5 minutes. '.htmlspecialchars($ignoreText, ENT_QUOTES, 'UTF-8').'</p>'
              . '<p>— BrainPal Team</p>'
              . '</div>';

        $sent = sendBrainPalEmail(
            $email,
            'BrainPal Student',
            $subject,
            $html
        );
    } catch (Throwable $e) {
        error_log('BrainPal student OTP email failed: '.$e->getMessage());
        $sent = false;
    }

    if ($sent) {
        echo json_encode([
            "status" => "success",
            "message" => "Verification code sent to your email."
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

        "message" =>
            "Failed to save verification code."

    ]);

}


$update->close();

$conn->close();

?>