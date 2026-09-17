<?php

error_reporting(E_ALL);
ini_set('display_errors', 0);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");


/*
|--------------------------------------------------------------------------
| OPTIONS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {

    http_response_code(200);

    exit();

}


/*
|--------------------------------------------------------------------------
| DATABASE
|--------------------------------------------------------------------------
*/

require_once("../backend/database.php");


if ($conn->connect_error) {

    echo json_encode([
        "status" => "error",
        "message" => "Database connection failed."
    ]);

    exit();

}


/*
|--------------------------------------------------------------------------
| GET JSON
|--------------------------------------------------------------------------
*/

$data =
    json_decode(
        file_get_contents("php://input"),
        true
    );


$email =
    trim(
        $data['email'] ?? ''
    );


$otp =
    trim(
        $data['code'] ?? ''
    );


$mode =
    trim(
        $data['mode'] ?? 'forgot'
    );


/*
|--------------------------------------------------------------------------
| VALIDATE
|--------------------------------------------------------------------------
*/

if (
    empty($email) ||
    empty($otp)
) {

    echo json_encode([
        "status" => "error",
        "message" => "Email and OTP are required."
    ]);

    exit();

}


/*
|--------------------------------------------------------------------------
| FIND STUDENT
|--------------------------------------------------------------------------
*/

$stmt =
    $conn->prepare("
        SELECT
            student_id,
            reset_otp,
            otp_expiry,
            is_verified
        FROM student
        WHERE email = ?
        LIMIT 1
    ");


if (!$stmt) {

    echo json_encode([
        "status" => "error",
        "message" => "Database query failed."
    ]);

    exit();

}


$stmt->bind_param(
    "s",
    $email
);

$stmt->execute();

$result =
    $stmt->get_result();


/*
|--------------------------------------------------------------------------
| EMAIL NOT FOUND
|--------------------------------------------------------------------------
*/

if (
    $result->num_rows === 0
) {

    echo json_encode([
        "status" => "error",
        "message" => "Email not found."
    ]);

    $stmt->close();

    $conn->close();

    exit();

}


$row =
    $result->fetch_assoc();


$stmt->close();


/*
|--------------------------------------------------------------------------
| CHECK OTP
|--------------------------------------------------------------------------
*/

if (
    empty($row['reset_otp'])
) {

    echo json_encode([
        "status" => "error",
        "message" => "No active verification code."
    ]);

    $conn->close();

    exit();

}


if (
    $row['reset_otp'] !== $otp
) {

    echo json_encode([
        "status" => "error",
        "message" => "Invalid verification code."
    ]);

    $conn->close();

    exit();

}


/*
|--------------------------------------------------------------------------
| CHECK EXPIRY
|--------------------------------------------------------------------------
*/

if (
    empty($row['otp_expiry'])
) {

    echo json_encode([
        "status" => "error",
        "message" => "Verification code expired."
    ]);

    $conn->close();

    exit();

}


if (
    strtotime(
        $row['otp_expiry']
    ) < time()
) {

    echo json_encode([
        "status" => "error",
        "message" => "Verification code expired."
    ]);

    $conn->close();

    exit();

}


/*
|--------------------------------------------------------------------------
| REGISTRATION VERIFICATION
|--------------------------------------------------------------------------
*/

if (
    $mode === 'registration'
) {

    /*
     * Mark student as verified.
     */

    $verify =
        $conn->prepare("
            UPDATE student
            SET
                is_verified = 1,
                reset_otp = NULL,
                otp_expiry = NULL
            WHERE email = ?
        ");


    if (!$verify) {

        echo json_encode([
            "status" => "error",
            "message" => "Unable to verify account."
        ]);

        $conn->close();

        exit();

    }


    $verify->bind_param(
        "s",
        $email
    );


    if (
        !$verify->execute()
    ) {

        echo json_encode([
            "status" => "error",
            "message" => "Failed to verify account."
        ]);

        $verify->close();

        $conn->close();

        exit();

    }


    $verify->close();


    // Return the verified student record so the app can create
    // the active user session immediately without going through login.
    $userStmt = $conn->prepare("
        SELECT
            student_id,
            studentNo,
            firstName,
            m_initial,
            lastName,
            extension,
            birthdate,
            age,
            email,
            gender,
            strand_id,
            specialization_id,
            academic_id,
            grade_level,
            account_status,
            is_verified,
            points,
            leaderboard_visible,
            profile_photo,
            diagnostic_completed,
            current_streak,
            longest_streak,
            total_opens,
            last_open_date
        FROM student
        WHERE email = ?
          AND date_deleted IS NULL
        LIMIT 1
    ");

    $userStmt->bind_param("s", $email);
    $userStmt->execute();
    $userResult = $userStmt->get_result();
    $verifiedUser = $userResult->fetch_assoc();
    $userStmt->close();

    if (!$verifiedUser) {
        echo json_encode([
            "status" => "error",
            "message" => "Account was verified, but the user record could not be loaded."
        ]);
        $conn->close();
        exit();
    }

    echo json_encode([
        "status" => "success",
        "message" => "Account verified successfully.",
        "mode" => "registration",
        "user" => $verifiedUser
    ], JSON_UNESCAPED_UNICODE);

    $conn->close();

    exit();

}


/*
|--------------------------------------------------------------------------
| FORGOT PASSWORD VERIFICATION
|--------------------------------------------------------------------------
*/

$clear =
    $conn->prepare("
        UPDATE student
        SET
            reset_otp = NULL,
            otp_expiry = NULL
        WHERE email = ?
    ");


if ($clear) {

    $clear->bind_param(
        "s",
        $email
    );

    $clear->execute();

    $clear->close();

}


/*
|--------------------------------------------------------------------------
| SUCCESS
|--------------------------------------------------------------------------
*/

echo json_encode([
    "status" => "success",
    "message" => "OTP verified successfully.",
    "mode" => "forgot"
]);


$conn->close();

?>