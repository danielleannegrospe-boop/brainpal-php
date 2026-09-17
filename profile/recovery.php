<?php
require_once __DIR__ . '/../_shared/db.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");


if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {

    http_response_code(200);

    exit;
}


/* =====================================================
   DATABASE
===================================================== */

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


/* =====================================================
   INPUT
===================================================== */

$rawInput = file_get_contents("php://input");

$data = json_decode(
    $rawInput,
    true
);


if (!is_array($data)) {

    echo json_encode([
        "status" => "error",
        "message" => "Invalid request."
    ]);

    $conn->close();

    exit;
}


$action = strtolower(
    trim(
        (string)($data['action'] ?? '')
    )
);

$email = strtolower(
    trim(
        (string)($data['email'] ?? '')
    )
);

$code = trim(
    (string)($data['code'] ?? '')
);


/* =====================================================
   VALIDATE EMAIL
===================================================== */

if (
    $email === '' ||
    !filter_var(
        $email,
        FILTER_VALIDATE_EMAIL
    )
) {

    echo json_encode([
        "status" => "error",
        "message" => "Enter a valid email address."
    ]);

    $conn->close();

    exit;
}


/* =====================================================
   FIND DEACTIVATED ACCOUNT AUTOMATICALLY
=====================================================

   We identify the account using the email only.

   date_deleted IS NOT NULL is intentionally checked
   because the current BrainPal deleteUser procedure
   sets date_deleted but older rows may still have
   account_status = 'active'.

   account_status <> 'deleted' prevents permanently
   deleted records from being recoverable.
===================================================== */

function findRecoveryAccount(
    BrainPalDb $conn,
    string $email
): array {

    $sql = "

        SELECT
            'student' AS account_role,
            student_id AS account_id,
            email
        FROM student
        WHERE email = ?
          AND date_deleted IS NOT NULL
          AND account_status <> 'deleted'

        UNION ALL

        SELECT
            'admin' AS account_role,
            admin_id AS account_id,
            email
        FROM admin
        WHERE email = ?
          AND date_deleted IS NOT NULL
          AND account_status <> 'deleted'

        LIMIT 2

    ";


    $stmt = $conn->prepare($sql);


    if (!$stmt) {

        return [
            "error" => "Unable to prepare account lookup."
        ];

    }


    $stmt->bind_param(
        "ss",
        $email,
        $email
    );

    $stmt->execute();


    $result =
        $stmt->get_result();


    $rows = [];


    while (
        $row =
        $result->fetch_assoc()
    ) {

        $rows[] = $row;

    }


    $stmt->close();


    if (count($rows) === 0) {

        return [];

    }


    if (count($rows) > 1) {

        return [
            "multiple" => true
        ];

    }


    return $rows[0];

}


/* =====================================================
   SEND RECOVERY OTP
===================================================== */

if ($action === 'send_recovery_otp') {


    $account =
        findRecoveryAccount(
            $conn,
            $email
        );


    if (isset($account['error'])) {

        http_response_code(500);

        echo json_encode([
            "status" => "error",
            "message" =>
                $account['error']
        ]);

        $conn->close();

        exit;

    }


    if (
        isset($account['multiple']) &&
        $account['multiple'] === true
    ) {

        echo json_encode([
            "status" => "error",
            "message" =>
                "More than one deactivated account uses this email. Please contact the administrator."
        ]);

        $conn->close();

        exit;

    }


    if (!$account) {

        echo json_encode([
            "status" => "error",
            "message" =>
                "No deactivated account was found for this email."
        ]);

        $conn->close();

        exit;

    }


    $otp =
        (string)random_int(
            100000,
            999999
        );


    $expiry =
        date(
            "Y-m-d H:i:s",
            strtotime("+5 minutes")
        );


    if (
        $account['account_role'] === 'student'
    ) {

        $update = $conn->prepare("

            UPDATE student

            SET
                reset_otp = ?,
                otp_expiry = ?

            WHERE student_id = ?
              AND date_deleted IS NOT NULL
              AND account_status <> 'deleted'

        ");


        if (!$update) {

            http_response_code(500);

            echo json_encode([
                "status" => "error",
                "message" =>
                    "Unable to prepare recovery code."
            ]);

            $conn->close();

            exit;

        }


        $accountId =
            (int)$account['account_id'];


        $update->bind_param(
            "ssi",
            $otp,
            $expiry,
            $accountId
        );


    } else {

        $update = $conn->prepare("

            UPDATE admin

            SET
                reset_otp = ?,
                otp_expiry = ?

            WHERE admin_id = ?
              AND date_deleted IS NOT NULL
              AND account_status <> 'deleted'

        ");


        if (!$update) {

            http_response_code(500);

            echo json_encode([
                "status" => "error",
                "message" =>
                    "Unable to prepare recovery code."
            ]);

            $conn->close();

            exit;

        }


        $accountId =
            (int)$account['account_id'];


        $update->bind_param(
            "ssi",
            $otp,
            $expiry,
            $accountId
        );

    }


    if (!$update->execute()) {

        http_response_code(500);

        echo json_encode([
            "status" => "error",
            "message" =>
                "Unable to create recovery code."
        ]);

        $update->close();

        $conn->close();

        exit;

    }


    $update->close();


    /*
       Local development only.

       Replace this with actual email sending
       before production deployment.
    */

    echo json_encode([
        "status" => "success",
        "message" =>
            "Recovery code generated successfully.",
        "otp" => $otp
    ], JSON_UNESCAPED_UNICODE);


    $conn->close();

    exit;
}


/* =====================================================
   RECOVER ACCOUNT
===================================================== */

if ($action === 'recover_account') {


    if (!preg_match(
        '/^\d{6}$/',
        $code
    )) {

        echo json_encode([
            "status" => "error",
            "message" =>
                "Recovery code must be 6 digits."
        ]);

        $conn->close();

        exit;

    }


    $account =
        findRecoveryAccount(
            $conn,
            $email
        );


    if (!$account) {

        echo json_encode([
            "status" => "error",
            "message" =>
                "Deactivated account not found."
        ]);

        $conn->close();

        exit;

    }


    if (
        isset($account['multiple']) &&
        $account['multiple'] === true
    ) {

        echo json_encode([
            "status" => "error",
            "message" =>
                "More than one deactivated account uses this email. Please contact the administrator."
        ]);

        $conn->close();

        exit;

    }


    if (
        $account['account_role'] === 'student'
    ) {

        $stmt = $conn->prepare("

            SELECT
                reset_otp,
                otp_expiry

            FROM student

            WHERE student_id = ?
              AND date_deleted IS NOT NULL
              AND account_status <> 'deleted'

            LIMIT 1

        ");

    } else {

        $stmt = $conn->prepare("

            SELECT
                reset_otp,
                otp_expiry

            FROM admin

            WHERE admin_id = ?
              AND date_deleted IS NOT NULL
              AND account_status <> 'deleted'

            LIMIT 1

        ");

    }


    if (!$stmt) {

        http_response_code(500);

        echo json_encode([
            "status" => "error",
            "message" =>
                "Unable to prepare recovery verification."
        ]);

        $conn->close();

        exit;

    }


    $accountId =
        (int)$account['account_id'];


    $stmt->bind_param(
        "i",
        $accountId
    );

    $stmt->execute();


    $row =
        $stmt
            ->get_result()
            ->fetch_assoc();


    $stmt->close();


    if (!$row) {

        echo json_encode([
            "status" => "error",
            "message" =>
                "Recovery record not found."
        ]);

        $conn->close();

        exit;

    }


    if (
        empty($row['reset_otp']) ||
        !hash_equals(
            (string)$row['reset_otp'],
            $code
        )
    ) {

        echo json_encode([
            "status" => "error",
            "message" =>
                "Invalid recovery code."
        ]);

        $conn->close();

        exit;

    }


    if (
        empty($row['otp_expiry']) ||
        strtotime(
            $row['otp_expiry']
        ) < time()
    ) {

        echo json_encode([
            "status" => "error",
            "message" =>
                "Recovery code expired."
        ]);

        $conn->close();

        exit;

    }


    if (
        $account['account_role'] === 'student'
    ) {

        $update = $conn->prepare("

            UPDATE student

            SET
                account_status = 'active',
                date_deleted = NULL,
                reset_otp = NULL,
                otp_expiry = NULL

            WHERE student_id = ?

        ");

    } else {

        $update = $conn->prepare("

            UPDATE admin

            SET
                account_status = 'active',
                date_deleted = NULL,
                reset_otp = NULL,
                otp_expiry = NULL

            WHERE admin_id = ?

        ");

    }


    if (!$update) {

        http_response_code(500);

        echo json_encode([
            "status" => "error",
            "message" =>
                "Unable to prepare account recovery."
        ]);

        $conn->close();

        exit;

    }


    $update->bind_param(
        "i",
        $accountId
    );


    if (!$update->execute()) {

        http_response_code(500);

        echo json_encode([
            "status" => "error",
            "message" =>
                "Unable to recover account."
        ]);

        $update->close();

        $conn->close();

        exit;

    }


    $update->close();


    echo json_encode([
        "status" => "success",
        "message" =>
            "Account recovered successfully."
    ]);


    $conn->close();

    exit;
}


/* =====================================================
   UNSUPPORTED ACTION
===================================================== */

echo json_encode([
    "status" => "error",
    "message" =>
        "Unsupported recovery action."
]);


$conn->close();

?>
