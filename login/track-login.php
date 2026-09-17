<?php
require_once __DIR__ . '/../_shared/db.php';

// BrainPal: daily successful-login streak endpoint
require_once __DIR__ . '/../_shared/notification-helper.php';

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

$data = json_decode(file_get_contents("php://input"), true);
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

$conn->begin_transaction();

try {

    // Lock the student row so simultaneous login requests cannot
    // increment the streak twice.
    $stmt = $conn->prepare("
        SELECT
            current_streak,
            longest_streak,
            total_opens,
            last_open_date
        FROM student
        WHERE student_id = ?
          AND date_deleted IS NULL
          AND account_status = 'active'
        LIMIT 1
        FOR UPDATE
    ");

    if (!$stmt) {
        throw new Exception("Unable to prepare streak query.");
    }

    $stmt->bind_param("i", $student_id);

    if (!$stmt->execute()) {
        throw new Exception("Unable to read streak data.");
    }

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        throw new Exception("Student account not found.");
    }

    $today = date("Y-m-d");

    $lastDate = !empty($row["last_open_date"])
        ? substr((string)$row["last_open_date"], 0, 10)
        : null;

    $current = (int)($row["current_streak"] ?? 0);
    $longest = (int)($row["longest_streak"] ?? 0);
    $total = (int)($row["total_opens"] ?? 0);

    // Save the value BEFORE changing it. Dashboard uses this to decide
    // which animation should be shown.
    $previousStreak = $current;

    $alreadyLoggedInToday = ($lastDate === $today);
    $streakIncreased = false;
    $streakReset = false;

    if ($alreadyLoggedInToday) {

        // Repeated login on the same day: no streak change.
        $newCurrent = max(1, $current);
        $newTotal = $total;

    } elseif ($lastDate === null) {

        // First successful login ever.
        $newCurrent = 1;
        $newTotal = $total + 1;

        // Do not show +1 animation for the very first day.
        $streakIncreased = false;

    } else {

        try {
            $lastDateObj = new DateTime($lastDate);
            $todayObj = new DateTime($today);
            $daysSinceLast = (int)$lastDateObj->diff($todayObj)->days;
        } catch (Throwable $e) {
            $daysSinceLast = 0;
        }

        if ($daysSinceLast === 1) {

            // Logged in yesterday: continue streak.
            $newCurrent = max(1, $current) + 1;
            $streakIncreased = ($newCurrent > $previousStreak);

        } else {

            // Missed one or more calendar days: reset streak to Day 1.
            $newCurrent = 1;
            $streakReset = ($previousStreak > 1);
        }

        $newTotal = $total + 1;
    }

    $newLongest = max($longest, $newCurrent);

    $update = $conn->prepare("
        UPDATE student
        SET
            current_streak = ?,
            longest_streak = ?,
            total_opens = ?,
            last_open_date = ?
        WHERE student_id = ?
          AND date_deleted IS NULL
          AND account_status = 'active'
    ");

    if (!$update) {
        throw new Exception("Unable to prepare streak update.");
    }

    $update->bind_param(
        "iiisi",
        $newCurrent,
        $newLongest,
        $newTotal,
        $today,
        $student_id
    );

    if (!$update->execute()) {
        $update->close();
        throw new Exception("Unable to update streak.");
    }

    $update->close();
    $conn->commit();

    $studentName = getStudentName($conn, $student_id);

    if ($streakIncreased) {
        notifyAllAdmins(
            $conn,
            'streak_increased',
            'Student Streak Increased',
            $studentName . ' increased their login streak to ' . $newCurrent . ' day(s).',
            $student_id
        );
    } elseif ($streakReset) {
        notifyAllAdmins(
            $conn,
            'streak_reset',
            'Student Streak Reset',
            $studentName . ' missed a day and their login streak reset to Day 1.',
            $student_id
        );
    }

    echo json_encode([
        "status" => "success",
        "message" => "Daily login recorded.",
        "current_streak" => $newCurrent,
        "streak" => $newCurrent,
        "longest_streak" => $newLongest,
        "total_opens" => $newTotal,
        "last_open_date" => $today,
        "already_logged_in_today" => $alreadyLoggedInToday,
        "previous_streak" => $previousStreak,
        "streak_increased" => $streakIncreased,
        "streak_reset" => $streakReset
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {

    $conn->rollback();
    http_response_code(500);

    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

$conn->close();
?>
