<?php

require_once __DIR__ . '/../_shared/db.php';

error_reporting(0);
ini_set('display_errors', '0');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Content-Type: application/json; charset=UTF-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $conn = brainpal_db();

    $data = json_decode(
        file_get_contents('php://input'),
        true
    );

    if (!is_array($data)) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid request body.'
        ]);
        exit;
    }

    $email = trim((string)($data['email'] ?? ''));
    $password = (string)($data['password'] ?? '');

    if ($email === '' || $password === '') {
        echo json_encode([
            'status' => 'error',
            'message' => 'Missing email or password'
        ]);
        exit;
    }

    /*
     * PostgreSQL:
     * firstName and lastName were created with quoted/case-sensitive
     * column names, so they must remain quoted here.
     */
    $sql = '
        SELECT
            "student_id",
            "firstName",
            "m_initial",
            "lastName",
            "extension",
            "email",
            "age",
            "gender",
            "grade_level",
            "strand_id",
            "specialization_id",
            "points",
            "diagnostic_completed",
            "is_verified",
            "academic_id",
            "leaderboard_visible",
            "profile_photo",
            "current_streak",
            "longest_streak",
            "total_opens",
            "last_open_date",
            "account_status",
            "hashed_password"
        FROM "student"
        WHERE "email" = ?
          AND "date_deleted" IS NULL
          AND "account_status" = \'active\'
        LIMIT 1
    ';

    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Database query preparation failed.',
            'debug' => $conn->error
        ]);
        exit;
    }

    if (!$stmt->bind_param('s', $email)) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to bind login parameters.',
            'debug' => $stmt->error
        ]);
        exit;
    }

    if (!$stmt->execute()) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to execute login query.',
            'debug' => $stmt->error
        ]);
        exit;
    }

    $result = $stmt->get_result();

    if (!$result || $result->num_rows === 0) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid email or password'
        ]);
        exit;
    }

    $student = $result->fetch_assoc();

    if (!isset($student['hashed_password'])) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Student password hash is missing.'
        ]);
        exit;
    }

    /*
     * Check the existing PHP password_hash() / bcrypt hash.
     */
    if (!password_verify($password, $student['hashed_password'])) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid email or password'
        ]);
        exit;
    }

    /*
     * PostgreSQL returns actual boolean values for BOOLEAN columns.
     */
    $isVerified = filter_var(
        $student['is_verified'],
        FILTER_VALIDATE_BOOLEAN
    );

    $leaderboardVisible = filter_var(
        $student['leaderboard_visible'],
        FILTER_VALIDATE_BOOLEAN
    );

    /*
     * Correct credentials but account is not email-verified.
     */
    if (!$isVerified) {
        echo json_encode([
            'status' => 'unverified',
            'message' => 'Account is not verified. Please verify your email first.',
            'role' => 'student',
            'user' => [
                'student_id' => (int)$student['student_id'],
                'firstName' => $student['firstName'],
                'm_initial' => $student['m_initial'],
                'lastName' => $student['lastName'],
                'extension' => $student['extension'],
                'email' => $student['email'],
                'age' => $student['age'] !== null
                    ? (int)$student['age']
                    : null,
                'gender' => $student['gender'],
                'grade_level' => $student['grade_level'] !== null
                    ? (int)$student['grade_level']
                    : null,
                'strand_id' => $student['strand_id'] !== null
                    ? (int)$student['strand_id']
                    : null,
                'specialization_id' => $student['specialization_id'] !== null
                    ? (int)$student['specialization_id']
                    : null,
                'academic_id' => $student['academic_id'] !== null
                    ? (int)$student['academic_id']
                    : null,
                'points' => $student['points'] !== null
                    ? (int)$student['points']
                    : 0,
                'diagnostic_completed' => (bool)$student['diagnostic_completed'],
                'is_verified' => false,
                'role' => 'student'
            ]
        ]);
        exit;
    }

    /*
     * Successful student login.
     */
    echo json_encode([
        'status' => 'success',
        'role' => 'student',
        'user' => [
            'student_id' => (int)$student['student_id'],
            'firstName' => $student['firstName'],
            'm_initial' => $student['m_initial'],
            'lastName' => $student['lastName'],
            'extension' => $student['extension'],
            'email' => $student['email'],

            'age' => $student['age'] !== null
                ? (int)$student['age']
                : null,

            'gender' => $student['gender'],

            'grade_level' => $student['grade_level'] !== null
                ? (int)$student['grade_level']
                : null,

            'strand_id' => $student['strand_id'] !== null
                ? (int)$student['strand_id']
                : null,

            'specialization_id' => $student['specialization_id'] !== null
                ? (int)$student['specialization_id']
                : null,

            'points' => $student['points'] !== null
                ? (int)$student['points']
                : 0,

            'diagnostic_completed' => (bool)$student['diagnostic_completed'],
            'is_verified' => true,

            'academic_id' => $student['academic_id'] !== null
                ? (int)$student['academic_id']
                : null,

            'leaderboard_visible' => $leaderboardVisible,

            'profile_photo' => $student['profile_photo'],

            'current_streak' => $student['current_streak'] !== null
                ? (int)$student['current_streak']
                : 0,

            'longest_streak' => $student['longest_streak'] !== null
                ? (int)$student['longest_streak']
                : 0,

            'total_opens' => $student['total_opens'] !== null
                ? (int)$student['total_opens']
                : 0,

            'last_open_date' => $student['last_open_date'],

            'role' => 'student'
        ]
    ]);

    $stmt->close();

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'status' => 'error',
        'message' => 'Student login failed.',
        'debug' => $e->getMessage()
    ]);

}
?>