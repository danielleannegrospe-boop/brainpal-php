<?php
require_once __DIR__ . '/../_shared/db.php';

error_reporting(E_ALL);
ini_set('display_errors', 0);
/* =====================================================
   CORS
===================================================== */

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if ($origin === 'http://localhost:8100') {
    header(
        'Access-Control-Allow-Origin: http://localhost:8100'
    );
}

header(
    'Access-Control-Allow-Methods: POST, OPTIONS'
);

header(
    'Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With'
);

header(
    'Access-Control-Max-Age: 86400'
);

header(
    'Content-Type: application/json; charset=UTF-8'
);


/* =====================================================
   PREFLIGHT
===================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {

    http_response_code(200);

    echo json_encode([
        'status' => 'success'
    ]);

    exit;
}


/* =====================================================
   DATABASE
===================================================== */

$conn = brainpal_db();

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database connection failed.'
    ]);
    exit;
}

$conn->set_charset('utf8mb4');

/* =====================================================
   GET CURRENT PREFERENCES
===================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $userId = (int)($_GET['user_id'] ?? 0);
    $role = strtolower(trim((string)($_GET['role'] ?? 'student')));

    if ($userId <= 0 || !in_array($role, ['student','admin'], true)) {
        http_response_code(400);
        echo json_encode(['status'=>'error','message'=>'Invalid user information.']);
        exit;
    }

    $create = $conn->query("CREATE TABLE IF NOT EXISTS notification_preferences (
        preference_id INT NOT NULL AUTO_INCREMENT,
        user_id INT NOT NULL,
        user_role ENUM('admin','student') NOT NULL,
        daily_reminder TINYINT(1) NOT NULL DEFAULT 0,
        quiz_reminder TINYINT(1) NOT NULL DEFAULT 0,
        streak_alert TINYINT(1) NOT NULL DEFAULT 0,
        weekly_report TINYINT(1) NOT NULL DEFAULT 0,
        badge_unlocked TINYINT(1) NOT NULL DEFAULT 0,
        level_up TINYINT(1) NOT NULL DEFAULT 0,
        xp_milestones TINYINT(1) NOT NULL DEFAULT 0,
        enable_reminder_time TINYINT(1) NOT NULL DEFAULT 0,
        reminder_time VARCHAR(20) NOT NULL DEFAULT '8:00 AM',
        date_created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        date_updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (preference_id),
        UNIQUE KEY uq_notification_preferences_user (user_id,user_role)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    if (!$create) {
        http_response_code(500);
        echo json_encode(['status'=>'error','message'=>'Unable to prepare notification preferences.']);
        exit;
    }

    $stmt = $conn->prepare("SELECT daily_reminder, quiz_reminder, streak_alert, weekly_report,
        badge_unlocked, level_up, xp_milestones, enable_reminder_time, reminder_time
        FROM notification_preferences WHERE user_id=? AND user_role=? LIMIT 1");
    $stmt->bind_param('is',$userId,$role);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $defaults = [
      'dailyReminder'=>false,'quizReminder'=>false,'streakAlert'=>false,'weeklyReport'=>false,
      'badgeUnlocked'=>false,'levelUp'=>false,'xpMilestones'=>false,
      'enableReminderTime'=>false,'reminderTime'=>'8:00 AM'
    ];

    if ($row) {
      $defaults = [
        'dailyReminder'=>(bool)$row['daily_reminder'],
        'quizReminder'=>(bool)$row['quiz_reminder'],
        'streakAlert'=>(bool)$row['streak_alert'],
        'weeklyReport'=>(bool)$row['weekly_report'],
        'badgeUnlocked'=>(bool)$row['badge_unlocked'],
        'levelUp'=>(bool)$row['level_up'],
        'xpMilestones'=>(bool)$row['xp_milestones'],
        'enableReminderTime'=>(bool)$row['enable_reminder_time'],
        'reminderTime'=>$row['reminder_time'] ?: '8:00 AM'
      ];
    }

    echo json_encode(['status'=>'success','settings'=>$defaults], JSON_UNESCAPED_UNICODE);
    $conn->close();
    exit;
}

/* =====================================================
   POST ONLY
===================================================== */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    http_response_code(405);

    echo json_encode([
        'status' => 'error',
        'message' => 'Only POST requests are allowed.'
    ]);

    exit;
}


/* =====================================================
   READ JSON
===================================================== */

$data = json_decode(
    file_get_contents('php://input'),
    true
);


if (!is_array($data)) {

    http_response_code(400);

    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid JSON request.'
    ]);

    $conn->close();

    exit;
}


/* =====================================================
   REQUEST VALUES
===================================================== */

$action = strtolower(
    trim(
        (string)($data['action'] ?? '')
    )
);


$userId = (int)(
    $data['user_id'] ?? 0
);


$role = strtolower(
    trim(
        (string)($data['role'] ?? '')
    )
);


/* =====================================================
   VALIDATION
===================================================== */

if (
    $userId <= 0 ||
    !in_array($role, ['student', 'admin'], true)
) {

    http_response_code(400);

    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid user information.'
    ]);

    $conn->close();

    exit;
}


/* =====================================================
   CREATE PREFERENCES TABLE
===================================================== */

$create = $conn->query("
    CREATE TABLE IF NOT EXISTS notification_preferences (

        preference_id INT NOT NULL AUTO_INCREMENT,

        user_id INT NOT NULL,

        user_role ENUM('admin','student') NOT NULL,

        daily_reminder TINYINT(1) NOT NULL DEFAULT 0,

        quiz_reminder TINYINT(1) NOT NULL DEFAULT 0,

        streak_alert TINYINT(1) NOT NULL DEFAULT 0,

        weekly_report TINYINT(1) NOT NULL DEFAULT 0,

        badge_unlocked TINYINT(1) NOT NULL DEFAULT 0,

        level_up TINYINT(1) NOT NULL DEFAULT 0,

        xp_milestones TINYINT(1) NOT NULL DEFAULT 0,

        enable_reminder_time TINYINT(1) NOT NULL DEFAULT 0,

        reminder_time VARCHAR(20)
            NOT NULL DEFAULT '8:00 AM',

        date_created TIMESTAMP
            NOT NULL DEFAULT CURRENT_TIMESTAMP,

        date_updated TIMESTAMP
            NOT NULL DEFAULT CURRENT_TIMESTAMP
            ON UPDATE CURRENT_TIMESTAMP,

        PRIMARY KEY (preference_id),

        UNIQUE KEY uq_notification_preferences_user
        (
            user_id,
            user_role
        )

    )
    ENGINE=InnoDB
    DEFAULT CHARSET=utf8mb4
    COLLATE=utf8mb4_general_ci
");


if (!$create) {

    http_response_code(500);

    echo json_encode([
        'status' => 'error',
        'message' =>
            'Unable to prepare notification preferences.',
        'details' => $conn->error
    ]);

    $conn->close();

    exit;
}


/* =====================================================
   RESET
===================================================== */

if ($action === 'reset') {

    $stmt = $conn->prepare("
        DELETE FROM notification_preferences

        WHERE user_id = ?

        AND user_role = ?
    ");


    if (!$stmt) {

        http_response_code(500);

        echo json_encode([
            'status' => 'error',
            'message' =>
                'Unable to reset notification preferences.'
        ]);

        $conn->close();

        exit;
    }


    $stmt->bind_param(
        'is',
        $userId,
        $role
    );


    if (!$stmt->execute()) {

        http_response_code(500);

        echo json_encode([
            'status' => 'error',
            'message' =>
                'Unable to reset notification preferences.'
        ]);

        $stmt->close();

        $conn->close();

        exit;
    }


    $stmt->close();


    echo json_encode([
        'status' => 'success',
        'message' =>
            'Notification preferences reset.'
    ]);

    $conn->close();

    exit;
}


/* =====================================================
   SAVE
===================================================== */

if ($action === 'save') {


    $bool = static function ($value): int {

        if (
            $value === true ||
            $value === 1 ||
            $value === '1' ||
            $value === 'true' ||
            $value === 'on'
        ) {
            return 1;
        }

        return 0;
    };


    $dailyReminder = $bool(
        $data['dailyReminder'] ?? false
    );


    $quizReminder = $bool(
        $data['quizReminder'] ?? false
    );


    $streakAlert = $bool(
        $data['streakAlert'] ?? false
    );


    $weeklyReport = $bool(
        $data['weeklyReport'] ?? false
    );


    $badgeUnlocked = $bool(
        $data['badgeUnlocked'] ?? false
    );


    $levelUp = $bool(
        $data['levelUp'] ?? false
    );


    $xpMilestones = $bool(
        $data['xpMilestones'] ?? false
    );


    $enableReminderTime = $bool(
        $data['enableReminderTime'] ?? false
    );


    $reminderTime = trim(
        (string)(
            $data['reminderTime']
            ?? '8:00 AM'
        )
    );


    if ($reminderTime === '') {
        $reminderTime = '8:00 AM';
    }


    $stmt = $conn->prepare("
        INSERT INTO notification_preferences
        (
            user_id,
            user_role,
            daily_reminder,
            quiz_reminder,
            streak_alert,
            weekly_report,
            badge_unlocked,
            level_up,
            xp_milestones,
            enable_reminder_time,
            reminder_time
        )

        VALUES
        (
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?
        )

        ON DUPLICATE KEY UPDATE

            daily_reminder =
                VALUES(daily_reminder),

            quiz_reminder =
                VALUES(quiz_reminder),

            streak_alert =
                VALUES(streak_alert),

            weekly_report =
                VALUES(weekly_report),

            badge_unlocked =
                VALUES(badge_unlocked),

            level_up =
                VALUES(level_up),

            xp_milestones =
                VALUES(xp_milestones),

            enable_reminder_time =
                VALUES(enable_reminder_time),

            reminder_time =
                VALUES(reminder_time)
    ");


    if (!$stmt) {

        http_response_code(500);

        echo json_encode([
            'status' => 'error',
            'message' =>
                'Unable to prepare notification preferences save.'
        ]);

        $conn->close();

        exit;
    }


    $stmt->bind_param(
        'isiiiiiiiis',

        $userId,
        $role,

        $dailyReminder,
        $quizReminder,
        $streakAlert,
        $weeklyReport,

        $badgeUnlocked,
        $levelUp,
        $xpMilestones,

        $enableReminderTime,

        $reminderTime
    );


    if (!$stmt->execute()) {

        http_response_code(500);

        echo json_encode([
            'status' => 'error',
            'message' =>
                'Unable to save notification preferences.',
            'details' => $stmt->error
        ]);

        $stmt->close();

        $conn->close();

        exit;
    }


    $stmt->close();


    echo json_encode([
        'status' => 'success',
        'message' =>
            'Notification preferences saved.'
    ]);

    $conn->close();

    exit;
}


/* =====================================================
   INVALID ACTION
===================================================== */

http_response_code(400);

echo json_encode([
    'status' => 'error',
    'message' => 'Unsupported action.'
]);


$conn->close();

?>