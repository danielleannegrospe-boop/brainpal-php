<?php
require_once __DIR__ . '/../_shared/db.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

ini_set('display_errors', 0);
error_reporting(E_ALL);

$conn = brainpal_db();

if ($conn->connect_error) {

    echo json_encode([
        "status" => "error",
        "message" => "Database connection failed."
    ]);

    exit;
}

$conn->set_charset("utf8mb4");


$sql = "
    SELECT
        material_id,
        title
    FROM learning_material
    WHERE date_deleted IS NULL
    ORDER BY title ASC
";


$result = $conn->query($sql);


if (!$result) {

    echo json_encode([
        "status" => "error",
        "message" => "Unable to load learning materials."
    ]);

    exit;
}


$materials = [];


while ($row = $result->fetch_assoc()) {

    $materials[] = [
        "material_id" => intval($row["material_id"]),
        "title" => $row["title"]
    ];

}


echo json_encode([
    "status" => "success",
    "materials" => $materials
]);


$conn->close();

exit;

?>