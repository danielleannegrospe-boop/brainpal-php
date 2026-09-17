<?php
require_once __DIR__ . '/../_shared/db.php';

date_default_timezone_set('Asia/Manila');

error_reporting(0);
ini_set('display_errors', '0');

header("Content-Type: application/json; charset=UTF-8");
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
header("Access-Control-Allow-Headers: Content-Type, Accept, X-Requested-With");
header("Access-Control-Allow-Methods: POST, OPTIONS");


// =====================================================
// CORS / OPTIONS
// =====================================================

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {

    http_response_code(200);
    exit;

}


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    http_response_code(405);

    echo json_encode([
        "status" => "error",
        "message" => "Only POST requests are allowed."
    ]);

    exit;

}


// =====================================================
// DATABASE CONNECTION
// =====================================================

require_once __DIR__ . "/../email/mailer.php";

$conn = brainpal_db();


if ($conn->connect_error) {

    http_response_code(500);

    echo json_encode([
        "status" => "error",
        "message" => "Database connection failed."
    ]);

    exit;

}


$conn->set_charset("utf8mb4");


// =====================================================
// READ JSON REQUEST
// =====================================================

$rawInput =
    file_get_contents("php://input");


$data =
    json_decode(
        $rawInput,
        true
    );


if (!is_array($data)) {

    echo json_encode([
        "status" => "error",
        "message" => "Invalid request data."
    ]);

    $conn->close();
    exit;

}


// =====================================================
// GET INPUTS
// =====================================================

$studentId =
    (int)(
        $data["student_id"] ?? 0
    );


$isResend =
    !empty(
        $data["resend"]
    );


$currentPassword =
    (string)(
        $data["current_password"] ?? ""
    );


$newEmail =
    strtolower(
        trim(
            (string)(
                $data["new_email"] ?? ""
            )
        )
    );


// =====================================================
// VALIDATE STUDENT ID
// =====================================================

if ($studentId <= 0) {

    echo json_encode([
        "status" => "error",
        "message" => "Invalid student ID."
    ]);

    $conn->close();
    exit;

}


// =====================================================
// VALIDATE EMAIL
// =====================================================

if (
    !filter_var(
        $newEmail,
        FILTER_VALIDATE_EMAIL
    )
) {

    echo json_encode([
        "status" => "error",
        "message" => "Please enter a valid email address."
    ]);

    $conn->close();
    exit;

}


// =====================================================
// PASSWORD VALIDATION
//
// INITIAL REQUEST:
// Password is REQUIRED.
//
// RESEND:
// Password is NOT REQUIRED.
//
// This is the important part.
// =====================================================

if (
    !$isResend &&
    trim($currentPassword) === ''
) {

    echo json_encode([
        "status" => "error",
        "message" => "Current password is required."
    ]);

    $conn->close();
    exit;

}


// =====================================================
// GET STUDENT ACCOUNT
// =====================================================

$stmt =
    $conn->prepare("
        SELECT
            student_id,
            email,
            hashed_password
        FROM student
        WHERE student_id = ?
          AND date_deleted IS NULL
          AND account_status = 'active'
        LIMIT 1
    ");


if (!$stmt) {

    http_response_code(500);

    echo json_encode([
        "status" => "error",
        "message" => "Unable to prepare account query."
    ]);

    $conn->close();
    exit;

}


$stmt->bind_param(
    "i",
    $studentId
);


if (!$stmt->execute()) {

    $stmt->close();

    http_response_code(500);

    echo json_encode([
        "status" => "error",
        "message" => "Unable to retrieve account."
    ]);

    $conn->close();
    exit;

}


// =====================================================
// GET ACCOUNT DATA
//
// bind_result() is used so this does not depend
// on BrainPalDb get_result()/mysqlnd.
// =====================================================

$dbStudentId = 0;

$dbCurrentEmail = '';

$dbHashedPassword = '';


$stmt->bind_result(

    $dbStudentId,

    $dbCurrentEmail,

    $dbHashedPassword

);


$studentFound =
    $stmt->fetch();


$stmt->close();


if (!$studentFound) {

    echo json_encode([
        "status" => "error",
        "message" => "Student account not found."
    ]);

    $conn->close();
    exit;

}


// =====================================================
// PASSWORD VERIFICATION
//
// ONLY INITIAL REQUEST.
//
// RESEND SKIPS THIS ENTIRE BLOCK.
// =====================================================

if (!$isResend) {


    if (
        empty($dbHashedPassword)
    ) {

        echo json_encode([
            "status" => "error",
            "message" => "Account password is not configured."
        ]);

        $conn->close();
        exit;

    }


    if (
        !password_verify(
            $currentPassword,
            $dbHashedPassword
        )
    ) {

        echo json_encode([
            "status" => "error",
            "message" => "Current password is incorrect."
        ]);

        $conn->close();
        exit;

    }

}


// =====================================================
// CHECK IF NEW EMAIL IS SAME AS CURRENT EMAIL
// =====================================================

if (
    strcasecmp(
        $dbCurrentEmail,
        $newEmail
    ) === 0
) {

    echo json_encode([
        "status" => "error",
        "message" => "New email must be different from your current email."
    ]);

    $conn->close();
    exit;

}


// =====================================================
// CHECK IF EMAIL IS ALREADY USED
// =====================================================

$checkStudent =
    $conn->prepare("
        SELECT
            student_id
        FROM student
        WHERE LOWER(email) = LOWER(?)
          AND date_deleted IS NULL
          AND student_id <> ?
        LIMIT 1
    ");


if (!$checkStudent) {

    http_response_code(500);

    echo json_encode([
        "status" => "error",
        "message" => "Unable to check email address."
    ]);

    $conn->close();
    exit;

}


$checkStudent->bind_param(
    "si",
    $newEmail,
    $studentId
);


if (!$checkStudent->execute()) {

    $checkStudent->close();

    http_response_code(500);

    echo json_encode([
        "status" => "error",
        "message" => "Unable to check email address."
    ]);

    $conn->close();
    exit;

}


// =====================================================
// CHECK RESULT
//
// bind_result() instead of get_result()
// =====================================================

$existingStudentId = 0;


$checkStudent->bind_result(
    $existingStudentId
);


$studentExists =
    $checkStudent->fetch();


$checkStudent->close();


if ($studentExists) {

    echo json_encode([
        "status" => "error",
        "message" => "Email address is already in use."
    ]);

    $conn->close();
    exit;

}


// =====================================================
// GENERATE OTP
//
// Generates a NEW OTP every time:
//
// Initial request -> NEW OTP
// Resend          -> NEW OTP
// =====================================================

try {

    $otp =
        str_pad(
            (string)random_int(
                0,
                999999
            ),
            6,
            "0",
            STR_PAD_LEFT
        );

}
catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        "status" => "error",
        "message" => "Unable to generate verification code."
    ]);

    $conn->close();
    exit;

}


// =====================================================
// OTP EXPIRATION
//
// 10 MINUTES FROM THE MOMENT OF REQUEST
// =====================================================

$expires =
    date(
        "Y-m-d H:i:s",
        time() + 600
    );


// =====================================================
// SAVE OTP
//
// This automatically replaces the old OTP.
//
// Therefore:
//
// Old OTP -> invalid
// New OTP -> valid
// =====================================================

$update =
    $conn->prepare("
        UPDATE student
        SET
            email_verification_code = ?,
            email_verification_expires = ?
        WHERE student_id = ?
          AND date_deleted IS NULL
          AND account_status = 'active'
    ");


if (!$update) {

    http_response_code(500);

    echo json_encode([
        "status" => "error",
        "message" => "Unable to prepare verification request."
    ]);

    $conn->close();
    exit;

}


$update->bind_param(
    "ssi",
    $otp,
    $expires,
    $studentId
);


if (!$update->execute()) {

    $update->close();

    http_response_code(500);

    echo json_encode([
        "status" => "error",
        "message" => "Unable to save verification code."
    ]);

    $conn->close();
    exit;

}


$update->close();


// =====================================================
// SEND VERIFICATION EMAIL
// =====================================================

try {
    $sent = sendBrainPalEmail(
        $newEmail,
        'BrainPal Student',
        'BrainPal Email Change Verification Code',
        '<div style="font-family:Arial,sans-serif"><h2>BrainPal Email Verification</h2><p>Your verification code for changing your BrainPal email address is:</p><p style="font-size:30px;font-weight:bold;letter-spacing:6px">'.$otp.'</p><p>This code expires in 10 minutes.</p></div>'
    );
} catch (Throwable $e) {
    error_log('BrainPal email-change OTP failed: '.$e->getMessage());
    $sent = false;
}

if ($sent) {
    echo json_encode([
        "status" => "success",
        "message" => "Verification code sent to the new email address.",
        "email" => $newEmail,
        "expires_at" => $expires
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode([
        "status" => "error",
        "message" => "Unable to send verification email. Please check SMTP configuration."
    ], JSON_UNESCAPED_UNICODE);
}

$conn->close();

?>