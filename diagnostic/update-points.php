<?php

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

require_once __DIR__ . '/../_shared/db.php';
$brainpalConn = brainpal_db();
$pdo = $brainpalConn->rawPdo();

/*
|--------------------------------------------------------------------------
| READ JSON
|--------------------------------------------------------------------------
*/

$data =
    json_decode(
        file_get_contents("php://input"),
        true
    );

$student_id =
    intval(
        $data['student_id'] ?? 0
    );

$result_id =
    intval(
        $data['result_id'] ?? 0
    );

$points =
    intval(
        $data['points'] ?? 0
    );

/*
|--------------------------------------------------------------------------
| VALIDATE
|--------------------------------------------------------------------------
*/

if (
    $student_id <= 0 ||
    $result_id <= 0
) {

    echo json_encode([
        "status" => "error",
        "message" => "Invalid student_id or result_id."
    ]);

    exit;
}

try {

    $pdo->beginTransaction();

    /*
    |--------------------------------------------------------------------------
    | LOCK RESULT
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            result_id,
            student_id,
            points_awarded
        FROM diagnostic_result
        WHERE result_id = ?
          AND student_id = ?
        FOR UPDATE
    ");

    $stmt->execute([
        $result_id,
        $student_id
    ]);

    $result = $stmt->fetch();

    if (!$result) {

        throw new Exception(
            "Diagnostic result not found."
        );
    }

    /*
    |--------------------------------------------------------------------------
    | ALREADY AWARDED
    |--------------------------------------------------------------------------
    */

    if (
        intval(
            $result['points_awarded']
        ) === 1
    ) {

        $userStmt = $pdo->prepare("
            SELECT points
            FROM student
            WHERE student_id = ?
            LIMIT 1
        ");

        $userStmt->execute([
            $student_id
        ]);

        $user = $userStmt->fetch();

        $pdo->commit();

        echo json_encode([

            "status" => "success",

            "message" =>
                "Points already awarded.",

            "points" =>
                intval(
                    $user['points'] ?? 0
                )

        ]);

        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDATE POINTS
    |--------------------------------------------------------------------------
    */

    if ($points < 0) {
        $points = 0;
    }

    /*
    |--------------------------------------------------------------------------
    | ADD POINTS
    |--------------------------------------------------------------------------
    */

    $updateStudent = $pdo->prepare("
        UPDATE student
        SET points = COALESCE(points, 0) + ?
        WHERE student_id = ?
    ");

    $updateStudent->execute([
        $points,
        $student_id
    ]);

    /*
    |--------------------------------------------------------------------------
    | MARK RESULT AS AWARDED
    |--------------------------------------------------------------------------
    */

    $updateResult = $pdo->prepare("
        UPDATE diagnostic_result
        SET points_awarded = 1
        WHERE result_id = ?
    ");

    $updateResult->execute([
        $result_id
    ]);

    /*
    |--------------------------------------------------------------------------
    | GET NEW POINTS
    |--------------------------------------------------------------------------
    */

    $pointsStmt = $pdo->prepare("
        SELECT points
        FROM student
        WHERE student_id = ?
        LIMIT 1
    ");

    $pointsStmt->execute([
        $student_id
    ]);

    $student = $pointsStmt->fetch();

    /*
    |--------------------------------------------------------------------------
    | COMMIT
    |--------------------------------------------------------------------------
    */

    $pdo->commit();

    echo json_encode([

        "status" => "success",

        "message" =>
            "Points awarded successfully.",

        "points" =>
            intval(
                $student['points'] ?? 0
            )

    ]);

} catch (Exception $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo json_encode([

        "status" => "error",

        "message" =>
            "Failed to update points.",

        "error" =>
            $e->getMessage()

    ]);
}