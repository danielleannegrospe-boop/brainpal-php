<?php
require_once __DIR__ . '/../_shared/db.php';
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$conn = brainpal_db();

if ($conn->connect_error) {
    echo json_encode(["error" => "DB connection failed"]);
    exit();
}

$sql = "SELECT specialization_id, strand_id, specialization_name FROM specialization";
$result = $conn->query($sql);

$data = [];

while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

echo json_encode($data);
$conn->close();
?>