<?php
require_once __DIR__ . '/../_shared/db.php';

$brainpalMailer = __DIR__ . '/../email/mailer.php';
if (file_exists($brainpalMailer)) {
    require_once $brainpalMailer;
}

// =====================================================
// BRAINPAL SECURITY API
// =====================================================

error_reporting(E_ALL);
ini_set('display_errors', '0');
// =====================================================
// HEADERS
// =====================================================

header("Content-Type: application/json; charset=UTF-8");

header(
    "Access-Control-Allow-Origin: http://localhost:8100"
);

header(
    "Access-Control-Allow-Headers: Content-Type, Authorization, Accept, X-Requested-With"
);

header(
    "Access-Control-Allow-Methods: POST, OPTIONS"
);

header(
    "Access-Control-Allow-Credentials: true"
);


// =====================================================
// PREFLIGHT
// =====================================================

if (
    $_SERVER['REQUEST_METHOD'] === 'OPTIONS'
) {

    http_response_code(200);

    exit;

}


// =====================================================
// ONLY POST
// =====================================================

if (
    $_SERVER['REQUEST_METHOD'] !== 'POST'
) {

    http_response_code(405);

    echo json_encode([
        "status" => "error",
        "message" => "Only POST requests are allowed."
    ]);

    exit;

}


// =====================================================
// TIMEZONE
// =====================================================

date_default_timezone_set(
    'Asia/Manila'
);


// =====================================================
// DATABASE
// =====================================================

$conn = brainpal_db();


if (
    $conn->connect_error
) {

    http_response_code(500);

    echo json_encode([
        "status" => "error",
        "message" => "Database connection failed."
    ]);

    exit;

}


$conn->set_charset(
    "utf8mb4"
);


// =====================================================
// READ JSON
// =====================================================

$rawData =
    file_get_contents(
        "php://input"
    );


$data =
    json_decode(
        $rawData,
        true
    );


if (
    !is_array($data)
) {

    http_response_code(400);

    echo json_encode([
        "status" => "error",
        "message" => "Invalid JSON data."
    ]);

    $conn->close();

    exit;

}


// =====================================================
// REQUEST DATA
// =====================================================

$action =
    strtolower(
        trim(
            (string)(
                $data['action'] ?? ''
            )
        )
    );


$userId =
    (int)(
        $data['user_id'] ?? 0
    );


$role =
    strtolower(
        trim(
            (string)(
                $data['role'] ?? ''
            )
        )
    );


$currentPassword =
    (string)(
        $data['current_password'] ?? ''
    );


$newPassword =
    (string)(
        $data['new_password'] ?? ''
    );


$newEmail =
    strtolower(
        trim(
            (string)(
                $data['new_email'] ?? ''
            )
        )
    );


$verificationCode =
    trim(
        (string)(
            $data['verification_code'] ?? ''
        )
    );


// =====================================================
// VALIDATE ACCOUNT
// =====================================================

if (
    $userId <= 0 ||
    !in_array(
        $role,
        ['student', 'admin'],
        true
    )
) {

    http_response_code(400);

    echo json_encode([
        "status" => "error",
        "message" => "Invalid account information."
    ]);

    $conn->close();

    exit;

}


// =====================================================
// TABLE
// =====================================================

$table =
    $role === 'admin'
        ? 'admin'
        : 'student';


$idColumn =
    $role === 'admin'
        ? 'admin_id'
        : 'student_id';


// =====================================================
// TRY
// =====================================================

try {


    // =================================================
    // CHANGE PASSWORD
    // =================================================

    if (
        $action === 'change_password'
    ) {


        if (
            $currentPassword === '' ||
            $newPassword === ''
        ) {

            throw new Exception(
                "Current and new password are required."
            );

        }


        if (
            strlen($newPassword) < 8
        ) {

            throw new Exception(
                "New password must be at least 8 characters."
            );

        }


        $stmt =
            $conn->prepare(
                "
                SELECT
                    hashed_password
                FROM {$table}
                WHERE {$idColumn} = ?
                  AND date_deleted IS NULL
                  AND account_status = 'active'
                LIMIT 1
                "
            );


        if (!$stmt) {

            throw new Exception(
                "Unable to prepare password verification."
            );

        }


        $stmt->bind_param(
            "i",
            $userId
        );


        if (
            !$stmt->execute()
        ) {

            $stmt->close();

            throw new Exception(
                "Unable to verify current password."
            );

        }


        $result =
            $stmt->get_result();


        $row =
            $result->fetch_assoc();


        $stmt->close();


        if (
            !$row ||
            !password_verify(
                $currentPassword,
                $row['hashed_password']
            )
        ) {

            throw new Exception(
                "Current password is incorrect."
            );

        }


        if (
            password_verify(
                $newPassword,
                $row['hashed_password']
            )
        ) {

            throw new Exception(
                "New password must be different from your current password."
            );

        }


        $hash =
            password_hash(
                $newPassword,
                PASSWORD_DEFAULT
            );


        $update =
            $conn->prepare(
                "
                UPDATE {$table}
                SET hashed_password = ?
                WHERE {$idColumn} = ?
                  AND date_deleted IS NULL
                  AND account_status = 'active'
                "
            );


        if (!$update) {

            throw new Exception(
                "Unable to prepare password update."
            );

        }


        $update->bind_param(
            "si",
            $hash,
            $userId
        );


        if (
            !$update->execute()
        ) {

            $update->close();

            throw new Exception(
                "Unable to update password."
            );

        }


        $update->close();


        echo json_encode([

            "status" =>
                "success",

            "message" =>
                "Password changed successfully."

        ]);

        $conn->close();

        exit;

    }



    // =================================================
    // REQUEST EMAIL VERIFICATION
    // =================================================

    if (
        $action === 'request_email_verification'
    ) {


        // ---------------------------------------------
        // REQUIRED
        // ---------------------------------------------

        if (
            $currentPassword === '' ||
            $newEmail === ''
        ) {

            throw new Exception(
                "Current password and new email are required."
            );

        }


        // ---------------------------------------------
        // EMAIL FORMAT
        // ---------------------------------------------

        if (
            !filter_var(
                $newEmail,
                FILTER_VALIDATE_EMAIL
            )
        ) {

            throw new Exception(
                "Invalid email address."
            );

        }


        // ---------------------------------------------
        // GET CURRENT ACCOUNT
        // ---------------------------------------------

       $stmt =
            $conn->prepare(
                "
                SELECT
                    email,
                    hashed_password
                FROM {$table}
                WHERE {$idColumn} = ?
                  AND date_deleted IS NULL
                  AND account_status = 'active'
                LIMIT 1
                "
            );


        if (!$stmt) {

            throw new Exception(
                "Unable to prepare account verification."
            );

        }


        $stmt->bind_param(
            "i",
            $userId
        );


        if (
            !$stmt->execute()
        ) {

            $stmt->close();

            throw new Exception(
                "Unable to verify account."
            );

        }


        $current =
            $stmt
                ->get_result()
                ->fetch_assoc();


        $stmt->close();


        if (!$current) {

            throw new Exception(
                "Account not found."
            );

        }


        // ---------------------------------------------
        // CURRENT PASSWORD
        // ---------------------------------------------

        if (
            !password_verify(
                $currentPassword,
                $current['hashed_password']
            )
        ) {

            throw new Exception(
                "Current password is incorrect."
            );

        }


        // ---------------------------------------------
        // SAME EMAIL
        // ---------------------------------------------

        if (
            strcasecmp(
                $current['email'],
                $newEmail
            ) === 0
        ) {

            throw new Exception(
                "New email must be different from your current email."
            );

        }


        // ---------------------------------------------
        // CHECK EMAIL DUPLICATE
        // ---------------------------------------------

        $dup =
            $conn->prepare(
                "
                SELECT email
                FROM student
                WHERE email = ?
                  AND date_deleted IS NULL

                UNION ALL

                SELECT email
                FROM admin
                WHERE email = ?
                  AND date_deleted IS NULL

                LIMIT 1
                "
            );


        if (!$dup) {

            throw new Exception(
                "Unable to verify email availability."
            );

        }


        $dup->bind_param(
            "ss",
            $newEmail,
            $newEmail
        );


        if (
            !$dup->execute()
        ) {

            $dup->close();

            throw new Exception(
                "Unable to check email availability."
            );

        }


        $exists =
            $dup
                ->get_result()
                ->num_rows > 0;


        $dup->close();


        if ($exists) {

            throw new Exception(
                "Email address is already in use."
            );

        }


        // ---------------------------------------------
        // GENERATE 6 DIGIT OTP
        // ---------------------------------------------

        $otp =
            (string)random_int(
                100000,
                999999
            );


        // ---------------------------------------------
        // OTP EXPIRY
        // 10 MINUTES
        // ---------------------------------------------

        $expiry =
            date(
                'Y-m-d H:i:s',
                time() + (10 * 60)
            );


        // ---------------------------------------------
        // SAVE OTP
        // ---------------------------------------------

        $otpUpdate =
            $conn->prepare(
                "
                UPDATE {$table}
                SET
                    reset_otp = ?,
                    otp_expiry = ?
                WHERE {$idColumn} = ?
                  AND date_deleted IS NULL
                  AND account_status = 'active'
                "
            );


        if (!$otpUpdate) {

            throw new Exception(
                "Unable to prepare verification code."
            );

        }


        $otpUpdate->bind_param(
            "ssi",
            $otp,
            $expiry,
            $userId
        );


        if (
            !$otpUpdate->execute()
        ) {

            $otpUpdate->close();

            throw new Exception(
                "Unable to save verification code."
            );

        }


        $otpUpdate->close();


        // ---------------------------------------------
        // EMAIL SUBJECT
        // ---------------------------------------------

        $subject =
            "BrainPal Email Verification Code";


        // ---------------------------------------------
        // EMAIL MESSAGE
        // ---------------------------------------------

        $message =

            "Hello,\n\n" .

            "You requested to " .

            (
                $current['email']
                    ? "change the email address"
                    : "add an email address"
            ) .

            " for your BrainPal account.\n\n" .

            "Your verification code is:\n\n" .

            $otp .

            "\n\n" .

            "This code will expire in 10 minutes.\n\n" .

            "If you did not request this change, " .
            "please ignore this email.\n\n" .

            "BrainPal Team";


        // ---------------------------------------------
        // EMAIL HEADERS
        // ---------------------------------------------

        $headers =
            "From: BrainPal <no-reply@brainpal.local>\r\n" .

            "Reply-To: no-reply@brainpal.local\r\n" .

            "Content-Type: text/plain; charset=UTF-8\r\n";


        // ---------------------------------------------
        // SEND EMAIL
        // ---------------------------------------------

        $mailSent = false;
        try {
            if (function_exists('sendBrainPalEmail')) {
                $mailSent = sendBrainPalEmail(
                    $newEmail,
                    'BrainPal Administrator',
                    $subject,
                    '<p>Hello,</p><p>You requested to change the email address for your BrainPal administrator account.</p><p>Your verification code is:</p><h2 style="letter-spacing:4px;">' .
                    htmlspecialchars($otp, ENT_QUOTES, 'UTF-8') .
                    '</h2><p>This code will expire in 10 minutes.</p><p>If you did not request this change, please ignore this email.</p><p>BrainPal Team</p>'
                );
            } else {
                $mailSent = @mail($newEmail, $subject, $message, $headers);
            }
        } catch (Throwable $mailError) {
            error_log('BrainPal admin email-change mail error: ' . $mailError->getMessage());
        }


        /*
         * IMPORTANT:
         *
         * XAMPP/localhost usually does not have
         * a mail server configured.
         *
         * Therefore we return the OTP in development
         * mode so you can test the entire flow.
         *
         * Remove the "otp" field before deployment.
         */


        echo json_encode([

            "status" =>
                "success",

            "message" =>
                $mailSent
                    ? "Verification code sent to your new email."
                    : "Verification code generated. Local mail server is not configured.",

            "email" =>
                $newEmail,

            "expires_in" =>
                600,

            "mail_sent" =>
                $mailSent,

            "otp" =>
                $otp

        ], JSON_UNESCAPED_UNICODE);

        $conn->close();

        exit;

    }



    // =================================================
    // VERIFY EMAIL CHANGE
    // =================================================

    if (
        $action === 'verify_email_change'
    ) {


        // ---------------------------------------------
        // REQUIRED
        // ---------------------------------------------

        if (
            $currentPassword === '' ||
            $newEmail === '' ||
            $verificationCode === ''
        ) {

            throw new Exception(
                "Current password, new email and verification code are required."
            );

        }


        // ---------------------------------------------
        // EMAIL
        // ---------------------------------------------

        if (
            !filter_var(
                $newEmail,
                FILTER_VALIDATE_EMAIL
            )
        ) {

            throw new Exception(
                "Invalid email address."
            );

        }


        // ---------------------------------------------
        // CODE
        // ---------------------------------------------

        if (
            !preg_match(
                '/^\d{6}$/',
                $verificationCode
            )
        ) {

            throw new Exception(
                "Verification code must be 6 digits."
            );

        }


        // ---------------------------------------------
        // GET ACCOUNT
        // ---------------------------------------------

        $stmt =
            $conn->prepare(
                "
                SELECT
                    email,
                    hashed_password,
                    reset_otp,
                    otp_expiry
                FROM {$table}
                WHERE {$idColumn} = ?
                  AND date_deleted IS NULL
                  AND account_status = 'active'
                LIMIT 1
                "
            );


        if (!$stmt) {

            throw new Exception(
                "Unable to prepare verification."
            );

        }


        $stmt->bind_param(
            "i",
            $userId
        );


        if (
            !$stmt->execute()
        ) {

            $stmt->close();

            throw new Exception(
                "Unable to verify account."
            );

        }


        $account =
            $stmt
                ->get_result()
                ->fetch_assoc();


        $stmt->close();


        if (!$account) {

            throw new Exception(
                "Account not found."
            );

        }


        // ---------------------------------------------
        // CURRENT PASSWORD
        // ---------------------------------------------

        if (
            !password_verify(
                $currentPassword,
                $account['hashed_password']
            )
        ) {

            throw new Exception(
                "Current password is incorrect."
            );

        }


        // ---------------------------------------------
        // OTP EXISTS
        // ---------------------------------------------

        if (
            empty($account['reset_otp'])
        ) {

            throw new Exception(
                "No verification code was requested."
            );

        }


        // ---------------------------------------------
        // OTP EXPIRY
        // ---------------------------------------------

        if (
            empty($account['otp_expiry'])
        ) {

            throw new Exception(
                "Verification code has expired."
            );

        }


        $now =
            new DateTime();


        $expiry =
            new DateTime(
                $account['otp_expiry']
            );


        if (
            $now > $expiry
        ) {

            // Clear expired OTP

            $clear =
                $conn->prepare(
                    "
                    UPDATE {$table}
                    SET
                        reset_otp = NULL,
                        otp_expiry = NULL
                    WHERE {$idColumn} = ?
                    "
                );


            if ($clear) {

                $clear->bind_param(
                    "i",
                    $userId
                );

                $clear->execute();

                $clear->close();

            }


            throw new Exception(
                "Verification code has expired. Please request a new code."
            );

        }


        // ---------------------------------------------
        // VERIFY OTP
        // ---------------------------------------------

        if (
            !hash_equals(
                (string)$account['reset_otp'],
                (string)$verificationCode
            )
        ) {

            throw new Exception(
                "Invalid verification code."
            );

        }


        // ---------------------------------------------
        // CHECK EMAIL AGAIN
        // ---------------------------------------------

        $dup =
            $conn->prepare(
                "
                SELECT email
                FROM student
                WHERE email = ?
                  AND date_deleted IS NULL
                  AND NOT (
                      student_id = ?
                  )

                UNION ALL

                SELECT email
                FROM admin
                WHERE email = ?
                  AND date_deleted IS NULL
                  AND NOT (
                      admin_id = ?
                  )

                LIMIT 1
                "
            );


        if (!$dup) {

            throw new Exception(
                "Unable to verify email availability."
            );

        }


        $dup->bind_param(
            "sisi",
            $newEmail,
            $userId,
            $newEmail,
            $userId
        );


        if (
            !$dup->execute()
        ) {

            $dup->close();

            throw new Exception(
                "Unable to check email availability."
            );

        }


        $exists =
            $dup
                ->get_result()
                ->num_rows > 0;


        $dup->close();


        if ($exists) {

            throw new Exception(
                "Email address is already in use."
            );

        }


        // ---------------------------------------------
        // UPDATE EMAIL
        // ---------------------------------------------

        $update =
            $conn->prepare(
                "
                UPDATE {$table}
                SET
                    email = ?,
                    reset_otp = NULL,
                    otp_expiry = NULL
                WHERE {$idColumn} = ?
                  AND date_deleted IS NULL
                  AND account_status = 'active'
                "
            );


        if (!$update) {

            throw new Exception(
                "Unable to prepare email update."
            );

        }


        $update->bind_param(
            "si",
            $newEmail,
            $userId
        );


        if (
            !$update->execute()
        ) {

            $update->close();

            throw new Exception(
                "Unable to update email."
            );

        }


        $update->close();


        // ---------------------------------------------
        // SUCCESS
        // ---------------------------------------------

        echo json_encode([

            "status" =>
                "success",

            "message" =>
                "Email address verified and updated successfully.",

            "email" =>
                $newEmail

        ], JSON_UNESCAPED_UNICODE);

        $conn->close();

        exit;

    }


    // =================================================
    // OLD CHANGE EMAIL ACTION
    // =================================================
    /*
     * We intentionally DO NOT allow the old
     * change_email action anymore.
     *
     * This prevents bypassing verification.
     */

    if (
        $action === 'change_email'
    ) {

        throw new Exception(
            "Email changes now require verification. Please request a verification code first."
        );

    }


    // =================================================
    // UNSUPPORTED ACTION
    // =================================================

    throw new Exception(
        "Unsupported security action."
    );


} catch (
    Throwable $e
) {


    http_response_code(400);


    echo json_encode([

        "status" =>
            "error",

        "message" =>
            $e->getMessage()

    ], JSON_UNESCAPED_UNICODE);

}


$conn->close();

?>