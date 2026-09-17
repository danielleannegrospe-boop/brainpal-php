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

$result = $conn->query("SELECT strand_id, strand_name FROM strand");

$strands = [];

while ($row = $result->fetch_assoc()) {
    $strands[] = $row;
}

echo json_encode($strands);

$conn->close();
?>