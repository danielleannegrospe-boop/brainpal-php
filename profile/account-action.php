<?php
require_once __DIR__ . '/../_shared/db.php';
error_reporting(E_ALL);
ini_set('display_errors', 0);
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Only POST requests are allowed."]);
    exit;
}

$conn = brainpal_db();

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Database connection failed."]);
    exit;
}

$conn->set_charset("utf8mb4");

$data = json_decode(file_get_contents("php://input"), true);

$userId = (int)($data['user_id'] ?? 0);
$role = strtolower(trim((string)($data['role'] ?? '')));
$action = strtolower(trim((string)($data['action'] ?? '')));
$currentPassword = (string)($data['current_password'] ?? '');

if (
    $userId <= 0 ||
    !in_array($role, ['student', 'admin'], true) ||
    !in_array($action, ['deactivate', 'delete'], true)
) {
    echo json_encode(["status" => "error", "message" => "Invalid account request."]);
    $conn->close();
    exit;
}

if ($currentPassword === '') {
    echo json_encode(["status" => "error", "message" => "Current password is required."]);
    $conn->close();
    exit;
}

$table = $role === 'admin' ? 'admin' : 'student';
$idColumn = $role === 'admin' ? 'admin_id' : 'student_id';

$stmt = $conn->prepare("
    SELECT hashed_password, account_status
    FROM {$table}
    WHERE {$idColumn} = ?
      AND date_deleted IS NULL
      AND account_status = 'active'
    LIMIT 1
");

if (!$stmt) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Unable to prepare account query."]);
    $conn->close();
    exit;
}

$stmt->bind_param("i", $userId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row || !password_verify($currentPassword, $row['hashed_password'])) {
    echo json_encode(["status" => "error", "message" => "Current password is incorrect."]);
    $conn->close();
    exit;
}

$newStatus = $action === 'delete' ? 'deleted' : 'deactivated';

$update = $conn->prepare("
    UPDATE {$table}
    SET
        account_status = ?,
        date_deleted = NOW()
    WHERE {$idColumn} = ?
      AND account_status = 'active'
      AND date_deleted IS NULL
");

if (!$update) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Unable to prepare account update."]);
    $conn->close();
    exit;
}

$update->bind_param("si", $newStatus, $userId);

if (!$update->execute()) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Unable to update account status."]);
    $update->close();
    $conn->close();
    exit;
}

$update->close();

echo json_encode([
    "status" => "success",
    "message" =>
        $action === 'delete'
            ? "Account deleted successfully."
            : "Account deactivated successfully."
]);

$conn->close();
?>
