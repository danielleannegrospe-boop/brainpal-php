<?php
require_once __DIR__ . '/../_shared/db.php';

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
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../notifications/notification-helper.php';

$conn = brainpal_db();

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database connection failed.'
    ]);
    exit;
}

$conn->set_charset('utf8mb4');

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid request data.'
    ]);
    $conn->close();
    exit;
}

/* =====================================================
   INPUT
===================================================== */

$firstName = trim((string)($input['firstName'] ?? ''));
$m_initial = trim((string)($input['m_initial'] ?? ''));

/*
 * Canonical middle initial:
 * Store letters only, without a trailing period.
 * This prevents display code such as "M." from becoming "M.."
 * when a student enters "M." or "M..".
 */
$m_initial = preg_replace('/[^\\p{L}]/u', '', $m_initial) ?? '';
$m_initial = strtoupper($m_initial);
$lastName = trim((string)($input['lastName'] ?? ''));
$extension = trim((string)($input['extension'] ?? ''));

$email = strtolower(trim((string)($input['email'] ?? '')));
$password = (string)($input['password'] ?? '');

$birthdate = trim((string)($input['birthdate'] ?? ''));

$gender = trim((string)($input['gender'] ?? ''));

$strand_id = isset($input['strand_id'])
    ? (int)$input['strand_id']
    : 0;

$specialization_id =
    array_key_exists('specialization_id', $input) &&
    $input['specialization_id'] !== null &&
    $input['specialization_id'] !== ''
        ? (int)$input['specialization_id']
        : null;

$academic_id =
    array_key_exists('academic_id', $input) &&
    $input['academic_id'] !== null &&
    $input['academic_id'] !== ''
        ? (int)$input['academic_id']
        : null;

$semester = trim((string)($input['semester'] ?? ''));

$grade_level = 11;

/* =====================================================
   REQUIRED FIELDS
===================================================== */

if (
    $firstName === '' ||
    $lastName === '' ||
    $email === '' ||
    $password === '' ||
    $birthdate === '' ||
    $gender === '' ||
    $strand_id <= 0 ||
    $academic_id === null ||
    $semester === ''
) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Please fill in all required registration information.'
    ]);

    $conn->close();
    exit;
}

/* =====================================================
   EMAIL VALIDATION
===================================================== */

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Please enter a valid email address.'
    ]);

    $conn->close();
    exit;
}

/* Gmail only */
if (!preg_match('/^[a-zA-Z0-9._%+\-]+@gmail\.com$/i', $email)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Please use a valid Gmail account.'
    ]);

    $conn->close();
    exit;
}

/* =====================================================
   BIRTHDATE VALIDATION
   EXACT AGE RANGE: 15–25
===================================================== */

$birth = DateTime::createFromFormat('!Y-m-d', $birthdate);

$birthErrors = DateTime::getLastErrors();

if (
    !$birth ||
    ($birthErrors !== false &&
     ($birthErrors['warning_count'] > 0 || $birthErrors['error_count'] > 0)) ||
    $birth->format('Y-m-d') !== $birthdate
) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Please enter a valid birthdate.'
    ]);

    $conn->close();
    exit;
}

$today = new DateTime('today');

if ($birth > $today) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Birthdate cannot be in the future.'
    ]);

    $conn->close();
    exit;
}

$age = $birth->diff($today)->y;

/*
 * IMPORTANT:
 * Only ages 15 through 25 are allowed.
 */
if ($age < 15 || $age > 25) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Only users who are 15 to 25 years old are allowed to register.'
    ]);

    $conn->close();
    exit;
}

/* =====================================================
   DUPLICATE EMAIL
===================================================== */

$emailCheck = $conn->prepare(
    "SELECT email
     FROM student
     WHERE email = ?
       AND date_deleted IS NULL

     UNION ALL

     SELECT email
     FROM admin
     WHERE email = ?
       AND date_deleted IS NULL

     LIMIT 1"
);

if (!$emailCheck) {
    http_response_code(500);

    echo json_encode([
        'status' => 'error',
        'message' => 'Unable to check email availability.'
    ]);

    $conn->close();
    exit;
}

$emailCheck->bind_param('ss', $email, $email);
$emailCheck->execute();

$emailExists =
    $emailCheck->get_result()->num_rows > 0;

$emailCheck->close();

if ($emailExists) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Email already exists.'
    ]);

    $conn->close();
    exit;
}

/* =====================================================
   PASSWORD
===================================================== */

$hashedPassword =
    password_hash($password, PASSWORD_DEFAULT);

/* =====================================================
   DEFAULT ACCOUNT VALUES
===================================================== */

$is_verified = false;
$points = 0;
$diagnostic_completed = false;

/* =====================================================
   INSERT STUDENT
   birthdate is saved directly.
   age comes from the validated birthdate.
===================================================== */

$stmt = $conn->prepare(
    'INSERT INTO "student"
    (
        "firstName",
        "m_initial",
        "lastName",
        "extension",
        "age",
        "birthdate",
        "email",
        "gender",
        "hashed_password",
        "strand_id",
        "specialization_id",
        "academic_id",
        "grade_level",
        "semester",
        "is_verified",
        "points",
        "diagnostic_completed"
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)' 
);

if (!$stmt) {
    http_response_code(500);

    echo json_encode([
        'status' => 'error',
        'message' => 'Unable to prepare registration query.'
    ]);

    $conn->close();
    exit;
}

/*
 * Types:
 * s = firstName
 * s = m_initial
 * s = lastName
 * s = extension
 * i = age
 * s = birthdate
 * s = email
 * s = gender
 * s = hashedPassword
 * i = strand_id
 * i = specialization_id
 * i = academic_id
 * i = grade_level
 * s = semester
 * i = is_verified
 * i = points
 * i = diagnostic_completed
 */

$stmt->bind_param(
    'ssssissssiiiisiii',
    $firstName,
    $m_initial,
    $lastName,
    $extension,
    $age,
    $birthdate,
    $email,
    $gender,
    $hashedPassword,
    $strand_id,
    $specialization_id,
    $academic_id,
    $grade_level,
    $semester,
    $is_verified,
    $points,
    $diagnostic_completed
);

if (!$stmt->execute()) {
    http_response_code(500);

    echo json_encode([
        'status' => 'error',
        'message' => 'Registration failed while saving the account.',
        'debug' => $stmt->error
    ]);

    $stmt->close();
    $conn->close();
    exit;
}

$student_id = (int)$conn->insert_id;

$stmt->close();

/* =====================================================
   ADMIN NOTIFICATION
===================================================== */

$displayName = trim(
    preg_replace(
        '/\s+/',
        ' ',
        "$firstName $m_initial $lastName $extension"
    )
);

notifyAllAdmins(
    $conn,
    'new_user',
    'New User Registered',
    "$displayName created a student account.",
    $student_id
);

/* =====================================================
   RESPONSE
===================================================== */

echo json_encode([
    'status' => 'created',
    'message' => 'Account created successfully.',

    'user' => [
        'student_id' => $student_id,
        'firstName' => $firstName,
        'm_initial' => $m_initial,
        'lastName' => $lastName,
        'extension' => $extension,
        'email' => $email,
        'birthdate' => $birthdate,
        'age' => $age,
        'gender' => $gender,
        'strand_id' => $strand_id,
        'specialization_id' => $specialization_id,
        'academic_id' => $academic_id,
        'semester' => $semester,
        'grade_level' => $grade_level,
        'points' => 0,
        'is_verified' => false,
        'diagnostic_completed' => false
    ]

], JSON_UNESCAPED_UNICODE);

$conn->close();

?>
