<?php
require_once __DIR__ . '/../_shared/db.php';
require_once __DIR__ . '/../_shared/notification-helper.php';

header(
    "Content-Type: application/json; charset=UTF-8"
);

header(
    "Access-Control-Allow-Origin: http://localhost:8100"
);

header(
    "Access-Control-Allow-Methods: POST, OPTIONS"
);

header(
    "Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With"
);

header(
    "Access-Control-Allow-Credentials: true"
);


/*
|--------------------------------------------------------------------------
| JSON RESPONSE
|--------------------------------------------------------------------------
*/

function responseJson(
    bool $success,
    string $message,
    array $data = [],
    int $statusCode = 200
): void {

    http_response_code(
        $statusCode
    );

    echo json_encode(

        array_merge(

            [
                "success" =>
                    $success,

                "message" =>
                    $message
            ],

            $data

        ),

        JSON_UNESCAPED_UNICODE

    );

    exit;

}


/*
|--------------------------------------------------------------------------
| CORS PREFLIGHT
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"] === "OPTIONS"
) {

    responseJson(
        true,
        "OK"
    );

}


/*
|--------------------------------------------------------------------------
| ONLY POST
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"] !== "POST"
) {

    responseJson(

        false,

        "Only POST requests are allowed.",

        [
            "data" => []
        ],

        405

    );

}


/*
|--------------------------------------------------------------------------
| DATABASE
|--------------------------------------------------------------------------
*/

$conn = brainpal_db();


if (
    $conn->connect_error
) {

    responseJson(

        false,

        "Database connection failed.",

        [
            "error" =>
                $conn->connect_error,

            "data" => []
        ],

        500

    );

}


$conn->set_charset(
    "utf8mb4"
);


/*
|--------------------------------------------------------------------------
| READ JSON
|--------------------------------------------------------------------------
*/

$rawInput =
    file_get_contents(
        "php://input"
    );


if (
    $rawInput === false ||
    trim($rawInput) === ""
) {

    $conn->close();

    responseJson(

        false,

        "Empty request body.",

        [
            "data" => []
        ],

        400

    );

}


$data =
    json_decode(
        $rawInput,
        true
    );


if (
    json_last_error() !== JSON_ERROR_NONE ||
    !is_array($data)
) {

    $jsonError =
        json_last_error_msg();

    $conn->close();

    responseJson(

        false,

        "Invalid JSON payload.",

        [
            "json_error" =>
                $jsonError,

            "data" => []
        ],

        400

    );

}


/*
|--------------------------------------------------------------------------
| GET VALUES
|--------------------------------------------------------------------------
*/

$student_id =
    intval(
        $data["student_id"] ?? 0
    );


$diagnostic_id =
    intval(
        $data["diagnostic_id"] ?? 0
    );


/*
|--------------------------------------------------------------------------
| ACADEMIC ID
|--------------------------------------------------------------------------
|
| We still accept academic_id from the frontend for compatibility,
| but DO NOT TRUST IT.
|
| The actual academic_id will be retrieved from the student table.
|
|--------------------------------------------------------------------------
*/

$requested_academic_id =
    intval(
        $data["academic_id"] ?? 0
    );


$answers =

    isset(
        $data["answers"]
    ) &&

    is_array(
        $data["answers"]
    )

        ? $data["answers"]

        : [];


/*
|--------------------------------------------------------------------------
| BASIC VALIDATION
|--------------------------------------------------------------------------
*/

if (
    $student_id <= 0
) {

    $conn->close();

    responseJson(

        false,

        "Invalid student_id.",

        [
            "student_id" =>
                $student_id
        ],

        400

    );

}


if (
    $diagnostic_id <= 0
) {

    $conn->close();

    responseJson(

        false,

        "Invalid diagnostic_id.",

        [
            "diagnostic_id" =>
                $diagnostic_id
        ],

        400

    );

}


if (
    count($answers) === 0
) {

    $conn->close();

    responseJson(

        false,

        "No answers submitted.",

        [
            "data" => []
        ],

        400

    );

}


/*
|--------------------------------------------------------------------------
| VERIFY STUDENT
|--------------------------------------------------------------------------
*/

$studentCheck =
    $conn->prepare("

        SELECT

            student_id,

            academic_id,

            points,

            diagnostic_completed,

            is_verified,

            account_status

        FROM student

        WHERE

            student_id = ?

            AND date_deleted IS NULL

        LIMIT 1

    ");


if (
    !$studentCheck
) {

    $error =
        $conn->error;

    $conn->close();

    responseJson(

        false,

        "Failed to prepare student verification.",

        [
            "error" =>
                $error
        ],

        500

    );

}


$studentCheck->bind_param(

    "i",

    $student_id

);


if (
    !$studentCheck->execute()
) {

    $error =
        $studentCheck->error;

    $studentCheck->close();

    $conn->close();

    responseJson(

        false,

        "Failed to verify student.",

        [
            "error" =>
                $error
        ],

        500

    );

}


$studentResult =
    $studentCheck->get_result();


$studentRow =
    $studentResult->fetch_assoc();


$studentCheck->close();


if (
    !$studentRow
) {

    $conn->close();

    responseJson(

        false,

        "Student not found.",

        [
            "student_id" =>
                $student_id
        ],

        404

    );

}

if (
    strtolower((string)($studentRow["account_status"] ?? "")) !== "active"
) {

    $conn->close();

    responseJson(
        false,
        "Student account is not active.",
        [],
        403
    );

}

if (
    (int)($studentRow["is_verified"] ?? 0) !== 1
) {

    $conn->close();

    responseJson(
        false,
        "Email verification is required before submitting the diagnostic.",
        [],
        403
    );

}

if (
    (int)($studentRow["diagnostic_completed"] ?? 0) === 1
) {

    $conn->close();

    responseJson(
        false,
        "Diagnostic has already been completed.",
        [],
        409
    );

}


/*
|--------------------------------------------------------------------------
| GET VERIFIED ACADEMIC ID
|--------------------------------------------------------------------------
|
| THIS IS THE IMPORTANT FIX.
|
| The backend gets academic_id directly from the student's
| database record.
|
|--------------------------------------------------------------------------
*/

$academic_id =
    intval(
        $studentRow["academic_id"] ?? 0
    );


if (
    $academic_id <= 0
) {

    $conn->close();

    responseJson(

        false,

        "Student has no valid academic record.",

        [
            "student_id" =>
                $student_id,

            "academic_id" =>
                $academic_id
        ],

        400

    );

}


/*
|--------------------------------------------------------------------------
| VALIDATE ANSWERS
|--------------------------------------------------------------------------
*/

foreach (
    $answers
    as $index => $answer
) {

    $questionId =
        intval(
            $answer["diagnostic_id"] ?? 0
        );


    $userAnswer =
        strtoupper(

            trim(

                $answer["user_answer"] ?? ""

            )

        );


    if (
        $questionId <= 0
    ) {

        $conn->close();

        responseJson(

            false,

            "Invalid diagnostic question ID.",

            [
                "index" =>
                    $index,

                "diagnostic_id" =>
                    $questionId
            ],

            400

        );

    }


    if (
        !in_array(

            $userAnswer,

            [
                "A",
                "B",
                "C",
                "D"
            ],

            true

        )
    ) {

        $conn->close();

        responseJson(

            false,

            "Invalid answer choice.",

            [
                "index" =>
                    $index,

                "answer" =>
                    $userAnswer
            ],

            400

        );

    }

}


/*
|--------------------------------------------------------------------------
| TRANSACTION
|--------------------------------------------------------------------------
*/

$conn->begin_transaction();


try {


    /*
    |--------------------------------------------------------------------------
    | SCORE
    |--------------------------------------------------------------------------
    */

    $correct = 0;

    $total =
        count(
            $answers
        );


    /*
    |--------------------------------------------------------------------------
    | QUESTION QUERY
    |--------------------------------------------------------------------------
    */

    $questionStmt =
        $conn->prepare("

            SELECT

                correct_answer

            FROM diagnostic

            WHERE

                diagnostic_id = ?

                AND date_deleted IS NULL

            LIMIT 1

        ");


    if (
        !$questionStmt
    ) {

        throw new Exception(

            "Failed to prepare question lookup: " .

            $conn->error

        );

    }


    /*
    |--------------------------------------------------------------------------
    | CHECK ANSWERS
    |--------------------------------------------------------------------------
    */

    foreach (
        $answers
        as $answer
    ) {

        $questionId =
            intval(
                $answer["diagnostic_id"]
            );


        $userAnswer =
            strtoupper(

                trim(
                    $answer["user_answer"]
                )

            );


        $questionStmt->bind_param(

            "i",

            $questionId

        );


        if (
            !$questionStmt->execute()
        ) {

            throw new Exception(

                "Failed to execute question lookup: " .

                $questionStmt->error

            );

        }


        $questionResult =
            $questionStmt->get_result();


        $questionRow =
            $questionResult->fetch_assoc();


        if (
            !$questionRow
        ) {

            throw new Exception(

                "Diagnostic question not found: " .

                $questionId

            );

        }


        $correctAnswer =
            strtoupper(

                trim(

                    $questionRow[
                        "correct_answer"
                    ] ?? ""

                )

            );


        if (
            $userAnswer ===
            $correctAnswer
        ) {

            $correct++;

        }

    }


    $questionStmt->close();


    /*
    |--------------------------------------------------------------------------
    | SCORE
    |--------------------------------------------------------------------------
    */

    $totalScore =
        $correct;


    /*
    |--------------------------------------------------------------------------
    | XP
    |--------------------------------------------------------------------------
    |
    | 1 correct = 10 XP
    |
    |--------------------------------------------------------------------------
    */

    $pointsAwarded =
        $correct * 10;


    /*
    |--------------------------------------------------------------------------
    | PERCENTAGE
    |--------------------------------------------------------------------------
    */

    $percentage =

        $total > 0

            ? round(

                (
                    $correct /
                    $total
                ) * 100,

                2

            )

            : 0;


    /*
    |--------------------------------------------------------------------------
    | LEVEL CLASSIFICATION
    |--------------------------------------------------------------------------
    */

    if (
        $percentage >= 90
    ) {

        $level =
            "Excellent";

    }

    elseif (
        $percentage >= 75
    ) {

        $level =
            "Very Good";

    }

    elseif (
        $percentage >= 50
    ) {

        $level =
            "Needs Improvement";

    }

    else {

        $level =
            "Needs More Practice";

    }


    /*
    |--------------------------------------------------------------------------
    | INSERT DIAGNOSTIC RESULT
    |--------------------------------------------------------------------------
    */

    $insertResult =
        $conn->prepare("

            INSERT INTO diagnostic_result

            (

                student_id,

                diagnostic_id,

                academic_id,

                total_score,

                total_questions,

                level_classification,

                points_awarded

            )

            VALUES

            (

                ?,

                ?,

                ?,

                ?,

                ?,

                ?,

                ?

            )

        ");


    if (
        !$insertResult
    ) {

        throw new Exception(

            "Failed to prepare diagnostic_result insert: " .

            $conn->error

        );

    }


    $insertResult->bind_param(

        "iiiiisi",

        $student_id,

        $diagnostic_id,

        $academic_id,

        $totalScore,

        $total,

        $level,

        $pointsAwarded

    );


    if (
        !$insertResult->execute()
    ) {

        throw new Exception(

            "Failed to insert diagnostic_result: " .

            $insertResult->error

        );

    }


    $result_id =
        intval(
            $conn->insert_id
        );


    $insertResult->close();


    if (
        $result_id <= 0
    ) {

        throw new Exception(

            "Invalid result_id after inserting diagnostic_result."

        );

    }


    /*
    |--------------------------------------------------------------------------
    | INSERT DIAGNOSTIC DETAILS
    |--------------------------------------------------------------------------
    */

    $insertDetail =
        $conn->prepare("

            INSERT INTO diagnostic_details

            (

                diagnostic_id,

                result_id,

                user_answer

            )

            VALUES

            (

                ?,

                ?,

                ?

            )

        ");


    if (
        !$insertDetail
    ) {

        throw new Exception(

            "Failed to prepare diagnostic_details insert: " .

            $conn->error

        );

    }


    foreach (
        $answers
        as $answer
    ) {

        $questionId =
            intval(
                $answer["diagnostic_id"]
            );


        $userAnswer =
            strtoupper(

                trim(
                    $answer["user_answer"]
                )

            );


        $insertDetail->bind_param(

            "iis",

            $questionId,

            $result_id,

            $userAnswer

        );


        if (
            !$insertDetail->execute()
        ) {

            throw new Exception(

                "Failed to insert diagnostic_details: " .

                $insertDetail->error

            );

        }

    }


    $insertDetail->close();


    /*
    |--------------------------------------------------------------------------
    | UPDATE STUDENT POINTS
    |--------------------------------------------------------------------------
    */

    $updateStudent =
        $conn->prepare("

            UPDATE student

            SET

                points =
                    COALESCE(
                        points,
                        0
                    ) + ?,

                diagnostic_completed = 1

            WHERE

                student_id = ?

                AND date_deleted IS NULL

        ");


    if (
        !$updateStudent
    ) {

        throw new Exception(

            "Failed to prepare student points update: " .

            $conn->error

        );

    }


    $updateStudent->bind_param(

        "ii",

        $pointsAwarded,

        $student_id

    );


    if (
        !$updateStudent->execute()
    ) {

        throw new Exception(

            "Failed to update student points: " .

            $updateStudent->error

        );

    }


    $updateStudent->close();


    /*
    |--------------------------------------------------------------------------
    | GET UPDATED STUDENT POINTS
    |--------------------------------------------------------------------------
    */

    $pointsCheck =
        $conn->prepare("

            SELECT

                points,

                diagnostic_completed

            FROM student

            WHERE

                student_id = ?

            LIMIT 1

        ");


    if (
        !$pointsCheck
    ) {

        throw new Exception(

            "Failed to prepare updated points query: " .

            $conn->error

        );

    }


    $pointsCheck->bind_param(

        "i",

        $student_id

    );


    if (
        !$pointsCheck->execute()
    ) {

        throw new Exception(

            "Failed to execute updated points query: " .

            $pointsCheck->error

        );

    }


    $pointsResult =
        $pointsCheck->get_result();


    $updatedStudent =
        $pointsResult->fetch_assoc();


    $pointsCheck->close();


    if (
        !$updatedStudent
    ) {

        throw new Exception(

            "Unable to retrieve updated student points."

        );

    }


    $updatedTotalPoints =
        intval(

            $updatedStudent[
                "points"
            ] ?? 0

        );


    /*
    |--------------------------------------------------------------------------
    | COMMIT
    |--------------------------------------------------------------------------
    */

    $conn->commit();

    // Notify every active admin that this student completed the diagnostic.
    if (function_exists('notifyAllAdmins')) {
        $studentName = trim(
            preg_replace('/\\s+/', ' ', (string)(
                ($studentRow['firstName'] ?? '') . ' ' .
                ($studentRow['lastName'] ?? '')
            ))
        );
        notifyAllAdmins(
            $conn,
            'diagnostic_taken',
            'Diagnostic Completed',
            ($studentName !== '' ? $studentName : 'A student') . ' completed a diagnostic assessment.',
            $result_id
        );
    }


    /*
    |--------------------------------------------------------------------------
    | CLOSE DATABASE
    |--------------------------------------------------------------------------
    */

    $conn->close();


    /*
    |--------------------------------------------------------------------------
    | SUCCESS RESPONSE
    |--------------------------------------------------------------------------
    */

    responseJson(

        true,

        "Diagnostic submitted successfully.",

        [

            "result_id" =>
                $result_id,

            "student_id" =>
                $student_id,

            "diagnostic_id" =>
                $diagnostic_id,

            /*
             * This is now the VERIFIED value from
             * the student table.
             */

            "academic_id" =>
                $academic_id,

            "score" =>
                $totalScore,

            "correct" =>
                $correct,

            "total" =>
                $total,

            "percentage" =>
                $percentage,

            "level" =>
                $level,

            "points_awarded" =>
                $pointsAwarded,

            "total_points" =>
                $updatedTotalPoints

        ],

        200

    );

}


/*
|--------------------------------------------------------------------------
| ERROR / ROLLBACK
|--------------------------------------------------------------------------
*/

catch (
    Throwable $e
) {

    $conn->rollback();


    $errorMessage =
        $e->getMessage();


    $conn->close();


    responseJson(

        false,

        "Failed to save diagnostic.",

        [

            "error" =>
                $errorMessage,

            "data" =>
                []

        ],

        500

    );

}

?>