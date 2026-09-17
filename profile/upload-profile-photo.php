<?php
require_once __DIR__ . '/../_shared/db.php';

// =====================================================
// CORS
// =====================================================

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
header("Access-Control-Allow-Headers: Content-Type, Accept, X-Requested-With");
header("Access-Control-Allow-Credentials: true");

header("Content-Type: application/json; charset=UTF-8");

// Handle browser preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}


// =====================================================
// ERROR HANDLING
// =====================================================

ini_set('display_errors', '0');
error_reporting(E_ALL);


// =====================================================
// DATABASE
// =====================================================

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
// STUDENT ID
// =====================================================

$studentId = isset($_POST['student_id'])
    ? (int)$_POST['student_id']
    : 0;


if ($studentId <= 0) {

    echo json_encode([
        "status" => "error",
        "message" => "Invalid student ID."
    ]);

    $conn->close();
    exit;
}


// =====================================================
// CHECK PHOTO
// =====================================================

if (
    !isset($_FILES['photo']) ||
    !is_array($_FILES['photo'])
) {

    echo json_encode([
        "status" => "error",
        "message" => "No photo was uploaded."
    ]);

    $conn->close();
    exit;
}


$file = $_FILES['photo'];


// =====================================================
// UPLOAD ERROR
// =====================================================

if ($file['error'] !== UPLOAD_ERR_OK) {

    echo json_encode([
        "status" => "error",
        "message" => "Photo upload failed. Error code: " . $file['error']
    ]);

    $conn->close();
    exit;
}


// =====================================================
// FILE SIZE
// =====================================================

if ($file['size'] > 5 * 1024 * 1024) {

    echo json_encode([
        "status" => "error",
        "message" => "Image must be smaller than 5MB."
    ]);

    $conn->close();
    exit;
}


// =====================================================
// VALIDATE IMAGE
// =====================================================

$imageInfo = @getimagesize(
    $file['tmp_name']
);


if ($imageInfo === false) {

    echo json_encode([
        "status" => "error",
        "message" => "Invalid image file."
    ]);

    $conn->close();
    exit;
}


// =====================================================
// ALLOWED IMAGE TYPES
// =====================================================

$allowedMime = [

    'image/jpeg' => 'jpg',

    'image/png' => 'png',

    'image/webp' => 'webp'

];


$mime = $imageInfo['mime'] ?? '';


if (!isset($allowedMime[$mime])) {

    echo json_encode([
        "status" => "error",
        "message" => "Only JPG, PNG, and WEBP images are allowed."
    ]);

    $conn->close();
    exit;
}


$extension =
    $allowedMime[$mime];


// =====================================================
// UPLOAD DIRECTORY
// =====================================================

$uploadDir =
    __DIR__ . DIRECTORY_SEPARATOR . 'uploads';


if (!is_dir($uploadDir)) {

    if (!mkdir(
        $uploadDir,
        0755,
        true
    )) {

        http_response_code(500);

        echo json_encode([
            "status" => "error",
            "message" => "Unable to create upload directory."
        ]);

        $conn->close();
        exit;
    }
}


// =====================================================
// GENERATE SAFE FILE NAME
// =====================================================

try {

    $random =
        bin2hex(
            random_bytes(12)
        );

} catch (Throwable $e) {

    $random =
        uniqid(
            '',
            true
        );
}


$filename =
    'student_' .
    $studentId .
    '_' .
    $random .
    '.' .
    $extension;


$target =
    $uploadDir .
    DIRECTORY_SEPARATOR .
    $filename;


// =====================================================
// MOVE FILE
// =====================================================

if (!move_uploaded_file(
    $file['tmp_name'],
    $target
)) {

    http_response_code(500);

    echo json_encode([
        "status" => "error",
        "message" => "Unable to save uploaded photo."
    ]);

    $conn->close();
    exit;
}


// =====================================================
// GET OLD PHOTO
// =====================================================

$oldPhoto = '';


$oldStmt = $conn->prepare(
    "SELECT profile_photo
     FROM student
     WHERE student_id = ?
       AND date_deleted IS NULL
     LIMIT 1"
);


if ($oldStmt) {

    $oldStmt->bind_param(
        "i",
        $studentId
    );

    $oldStmt->execute();

    $result =
        $oldStmt->get_result();


    if ($row = $result->fetch_assoc()) {

        $oldPhoto =
            trim(
                (string)(
                    $row['profile_photo']
                    ?? ''
                )
            );
    }

    $oldStmt->close();
}


// =====================================================
// SAVE PHOTO NAME TO DATABASE
// =====================================================

$update = $conn->prepare(
    "UPDATE student
     SET profile_photo = ?
     WHERE student_id = ?
       AND date_deleted IS NULL"
);


if (!$update) {

    @unlink($target);

    http_response_code(500);

    echo json_encode([
        "status" => "error",
        "message" => "Unable to prepare photo update."
    ]);

    $conn->close();
    exit;
}


$update->bind_param(
    "si",
    $filename,
    $studentId
);


if (!$update->execute()) {

    @unlink($target);

    http_response_code(500);

    echo json_encode([
        "status" => "error",
        "message" => "Unable to save profile photo to database."
    ]);

    $update->close();
    $conn->close();
    exit;
}


$update->close();


// =====================================================
// DELETE OLD UPLOADED PHOTO
// =====================================================

if (
    $oldPhoto !== '' &&
    preg_match(
        '/^student_[0-9]+_[A-Za-z0-9]+\.(jpg|jpeg|png|webp)$/i',
        $oldPhoto
    )
) {

    $oldPath =
        $uploadDir .
        DIRECTORY_SEPARATOR .
        basename($oldPhoto);


    if (is_file($oldPath)) {

        @unlink($oldPath);

    }
}


// =====================================================
// SUCCESS
// =====================================================

echo json_encode([

    "status" => "success",

    "message" =>
        "Profile photo uploaded successfully.",

    "filename" =>
        $filename

], JSON_UNESCAPED_UNICODE);


$conn->close();

exit;

?>