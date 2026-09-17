<?php
require_once __DIR__ . '/../_shared/db.php';

date_default_timezone_set('Asia/Manila');

error_reporting(0);
ini_set('display_errors', '0');

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
header("Access-Control-Allow-Headers: Content-Type, Authorization, Accept, X-Requested-With");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=UTF-8");

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

$student_id = (int)($data["student_id"] ?? 0);

if ($student_id <= 0) {
    http_response_code(400);

    echo json_encode([
        "status" => "error",
        "message" => "Invalid student ID."
    ]);

    $conn->close();
    exit;
}

/*
|--------------------------------------------------------------------------
| IMPORTANT
|--------------------------------------------------------------------------
| This endpoint NO LONGER modifies:
|
| current_streak
| longest_streak
| total_opens
| last_open_date
|
| Streak is controlled ONLY by:
|
| login/track-login.php
|
| This endpoint is kept only for compatibility with
| any existing Dashboard code that may still call it.
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        current_streak,
        longest_streak,
        total_opens,
        last_open_date
    FROM student
    WHERE student_id = ?
      AND date_deleted IS NULL
    LIMIT 1
");

if (!$stmt) {
    http_response_code(500);

    echo json_encode([
        "status" => "error",
        "message" => "Unable to prepare query."
    ]);

    $conn->close();
    exit;
}

$stmt->bind_param(
    "i",
    $student_id
);

$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 0) {

    http_response_code(404);

    echo json_encode([
        "status" => "error",
        "message" => "Student not found."
    ]);

    $stmt->close();
    $conn->close();

    exit;
}

$row = $result->fetch_assoc();

$stmt->close();

$currentStreak = (int)(
    $row["current_streak"] ?? 0
);

$longestStreak = (int)(
    $row["longest_streak"] ?? 0
);

$totalOpens = (int)(
    $row["total_opens"] ?? 0
);

$lastOpenDate =
    $row["last_open_date"] ?? null;

/*
|--------------------------------------------------------------------------
| READ ONLY RESPONSE
|--------------------------------------------------------------------------
*/

echo json_encode([

    "status" => "success",

    "message" =>
        "Current streak retrieved. No streak update performed.",

    "current_streak" =>
        $currentStreak,

    "streak" =>
        $currentStreak,

    "longest_streak" =>
        $longestStreak,

    "total_opens" =>
        $totalOpens,

    "last_open_date" =>
        $lastOpenDate,

    "already_opened_today" =>
        true

], JSON_UNESCAPED_UNICODE);

$conn->close();

?>