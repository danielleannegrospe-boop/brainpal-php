<?php
require_once __DIR__ . '/../_shared/db.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

ini_set('display_errors', '0');
error_reporting(E_ALL);

$conn = brainpal_db();

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "DB connection failed"
    ]);
    exit;
}

$conn->set_charset("utf8mb4");

$data = json_decode(file_get_contents("php://input"), true);

if (!is_array($data)) {
    echo json_encode([
        "status" => "error",
        "message" => "Invalid request data"
    ]);
    $conn->close();
    exit;
}

$studentId = (int)($data['student_id'] ?? 0);

if ($studentId <= 0) {
    echo json_encode([
        "status" => "error",
        "message" => "Missing student_id"
    ]);
    $conn->close();
    exit;
}

/*
 * Email is intentionally NOT accepted from the edit-profile form.
 * It is read from the database and cannot be changed here.
 */
$currentStmt = $conn->prepare(
    "SELECT email, birthdate
     FROM student
     WHERE student_id = ?
       AND date_deleted IS NULL
     LIMIT 1"
);

if (!$currentStmt) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Unable to load current profile."
    ]);
    $conn->close();
    exit;
}

$currentStmt->bind_param("i", $studentId);
$currentStmt->execute();
$currentResult = $currentStmt->get_result();
$current = $currentResult->fetch_assoc();
$currentStmt->close();

if (!$current) {
    http_response_code(404);
    echo json_encode([
        "status" => "error",
        "message" => "Student not found."
    ]);
    $conn->close();
    exit;
}

$firstName = trim((string)($data['firstName'] ?? ''));
$mInitial = trim((string)($data['m_initial'] ?? ''));

/*
 * Canonical middle initial:
 * Store letters only, without periods/spaces.
 */
$mInitial = preg_replace('/[^\\p{L}]/u', '', $mInitial) ?? '';
$mInitial = strtoupper($mInitial);
$lastName = trim((string)($data['lastName'] ?? ''));
$gender = trim((string)($data['gender'] ?? ''));
$birthdate = trim((string)($data['birthdate'] ?? ''));
$profilePhoto = trim((string)($data['profile_photo'] ?? ''));

if ($firstName === '' || $lastName === '' || $birthdate === '') {
    echo json_encode([
        "status" => "error",
        "message" => "First name, last name and birthdate are required."
    ]);
    $conn->close();
    exit;
}

if (!in_array($gender, ['Male', 'Female'], true)) {
    echo json_encode([
        "status" => "error",
        "message" => "Please select a valid gender."
    ]);
    $conn->close();
    exit;
}

$birth = DateTime::createFromFormat('!Y-m-d', $birthdate);
$birthErrors = DateTime::getLastErrors();

if (
    !$birth ||
    ($birthErrors !== false &&
     ($birthErrors['warning_count'] > 0 || $birthErrors['error_count'] > 0)) ||
    $birth->format('Y-m-d') !== $birthdate
) {
    echo json_encode([
        "status" => "error",
        "message" => "Please enter a valid birthdate."
    ]);
    $conn->close();
    exit;
}

$today = new DateTime('today');

if ($birth > $today) {
    echo json_encode([
        "status" => "error",
        "message" => "Birthdate cannot be in the future."
    ]);
    $conn->close();
    exit;
}

$age = $birth->diff($today)->y;

if ($age < 15 || $age > 25) {
    echo json_encode([
        "status" => "error",
        "message" => "Only users aged 15 to 25 are allowed."
    ]);
    $conn->close();
    exit;
}

/* Allow preset avatar paths or a server-generated uploaded filename only. */
if ($profilePhoto !== '') {
    $isPreset = (bool)preg_match(
        '#^assets/avatars/[A-Za-z0-9._-]+\.(jpg|jpeg|png|webp)$#i',
        $profilePhoto
    );

    $isUploaded = (bool)preg_match(
        '#^student_[0-9]+_[A-Za-z0-9]+\.(jpg|jpeg|png|webp)$#i',
        $profilePhoto
    );

    if (!$isPreset && !$isUploaded) {
        echo json_encode([
            "status" => "error",
            "message" => "Invalid profile photo."
        ]);
        $conn->close();
        exit;
    }
}

/*
 * Keep the existing email from the database.
 * The frontend email field is disabled and the backend also prevents
 * changing it even if someone manually modifies the request.
 */
$email = (string)$current['email'];

$stmt = $conn->prepare(
    "UPDATE student
     SET firstName = ?,
         m_initial = ?,
         lastName = ?,
         birthdate = ?,
         age = ?,
         gender = ?,
         profile_photo = ?
     WHERE student_id = ?
       AND date_deleted IS NULL"
);

if (!$stmt) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Unable to prepare profile update."
    ]);
    $conn->close();
    exit;
}

$stmt->bind_param(
    "ssssissi",
    $firstName,
    $mInitial,
    $lastName,
    $birthdate,
    $age,
    $gender,
    $profilePhoto,
    $studentId
);

if ($stmt->execute()) {
    echo json_encode([
        "status" => "success",
        "message" => "Profile updated successfully.",
        "email" => $email,
        "birthdate" => $birthdate,
        "age" => $age,
        "gender" => $gender,
        "profile_photo" => $profilePhoto
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Profile update failed."
    ]);
}

$stmt->close();
$conn->close();
exit;
?>
