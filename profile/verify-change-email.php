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


$data = json_decode(
    file_get_contents("php://input"),
    true
);


$studentId = (int)(
    $data["student_id"] ?? 0
);

$newEmail =
    strtolower(
        trim(
            (string)($data["new_email"] ?? "")
        )
    );

$code =
    trim(
        (string)($data["code"] ?? "")
    );


/* =====================================================
   VALIDATION
===================================================== */

if ($studentId <= 0) {

    echo json_encode([
        "status" => "error",
        "message" => "Invalid student ID."
    ]);

    $conn->close();
    exit;
}


if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {

    echo json_encode([
        "status" => "error",
        "message" => "Invalid email address."
    ]);

    $conn->close();
    exit;
}


if (!preg_match('/^\d{6}$/', $code)) {

    echo json_encode([
        "status" => "error",
        "message" => "Verification code must be 6 digits."
    ]);

    $conn->close();
    exit;
}


/* =====================================================
   GET VERIFICATION DATA
===================================================== */

$stmt = $conn->prepare("
    SELECT
        student_id,
        email,
        email_verification_code,
        email_verification_expires
    FROM student
    WHERE student_id = ?
      AND date_deleted IS NULL
      AND account_status = 'active'
    LIMIT 1
");


$stmt->bind_param(
    "i",
    $studentId
);

$stmt->execute();

$row =
    $stmt
        ->get_result()
        ->fetch_assoc();

$stmt->close();


if (!$row) {

    echo json_encode([
        "status" => "error",
        "message" => "Student account not found."
    ]);

    $conn->close();
    exit;
}


/* =====================================================
   CHECK OTP
===================================================== */

if (
    empty($row["email_verification_code"])
) {

    echo json_encode([
        "status" => "error",
        "message" => "No verification code was requested."
    ]);

    $conn->close();
    exit;
}


if (
    empty($row["email_verification_expires"])
) {

    echo json_encode([
        "status" => "error",
        "message" => "Verification code has expired."
    ]);

    $conn->close();
    exit;
}


if (
    strtotime(
        $row["email_verification_expires"]
    ) < time()
) {

    echo json_encode([
        "status" => "error",
        "message" => "Verification code has expired. Please request a new code."
    ]);

    $conn->close();
    exit;
}


if (
    !hash_equals(
        (string)$row["email_verification_code"],
        $code
    )
) {

    echo json_encode([
        "status" => "error",
        "message" => "Incorrect verification code."
    ]);

    $conn->close();
    exit;
}


/* =====================================================
   CHECK EMAIL AGAIN
===================================================== */

$check = $conn->prepare("
    SELECT student_id
    FROM student
    WHERE LOWER(email) = LOWER(?)
      AND date_deleted IS NULL
      AND student_id <> ?
    LIMIT 1
");


$check->bind_param(
    "si",
    $newEmail,
    $studentId
);

$check->execute();

$exists =
    $check
        ->get_result()
        ->num_rows > 0;

$check->close();


if ($exists) {

    echo json_encode([
        "status" => "error",
        "message" => "Email address is already in use."
    ]);

    $conn->close();
    exit;
}


/* =====================================================
   UPDATE EMAIL
===================================================== */

$update = $conn->prepare("
    UPDATE student
    SET
        email = ?,
        email_verification_code = NULL,
        email_verification_expires = NULL
    WHERE student_id = ?
      AND date_deleted IS NULL
      AND account_status = 'active'
");


$update->bind_param(
    "si",
    $newEmail,
    $studentId
);


if (!$update->execute()) {

    $update->close();

    http_response_code(500);

    echo json_encode([
        "status" => "error",
        "message" => "Unable to update email address."
    ]);

    $conn->close();
    exit;
}


$update->close();


/* =====================================================
   SUCCESS
===================================================== */

echo json_encode([
    "status" => "success",
    "message" => "Email address updated successfully.",
    "email" => $newEmail
], JSON_UNESCAPED_UNICODE);


$conn->close();

?>