<?php
require_once __DIR__ . '/../_shared/db.php';
brainpal_cors('GET, OPTIONS');
$conn = brainpal_db();

$studentId = (int)($_GET['student_id'] ?? 0);
if ($studentId <= 0) {
  echo json_encode(['status'=>'error','message'=>'Invalid student_id.']);
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

$pref = ['daily_reminder'=>0,'enable_reminder_time'=>0,'reminder_time'=>'8:00 AM'];
$s = $conn->prepare("SELECT daily_reminder, enable_reminder_time, reminder_time FROM notification_preferences WHERE user_id=? AND user_role='student' LIMIT 1");
if ($s) {
  $s->bind_param('i',$studentId); $s->execute();
  $r=$s->get_result()->fetch_assoc(); $s->close();
  if ($r) $pref=$r;
}
if (!(int)$pref['daily_reminder'] || !(int)$pref['enable_reminder_time']) {
  echo json_encode(['status'=>'success','enabled'=>false,'reminders'=>[]]);
  $conn->close(); exit;
}

$time = strtotime((string)$pref['reminder_time']);
$clock = $time === false ? '08:00' : date('H:i',$time);

$stmt=$conn->prepare("SELECT ss.scheduled_day, COUNT(*) AS session_count,
  GROUP_CONCAT(DISTINCT COALESCE(sub.subject_name,'Subject') ORDER BY ss.start_time SEPARATOR ', ') AS subjects
  FROM study_schedule ss
  LEFT JOIN subject sub ON sub.subject_id=ss.subject_id
  WHERE ss.student_id=? AND ss.status='scheduled' AND ss.date_deleted IS NULL
    AND ss.scheduled_day BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
  GROUP BY ss.scheduled_day ORDER BY ss.scheduled_day");
$stmt->bind_param('i',$studentId); $stmt->execute(); $result=$stmt->get_result();

$items=[];
while($row=$result->fetch_assoc()){
  $date=$row['scheduled_day'];
  $items[]=[
    'date'=>$date,
    'at'=>$date.' '.$clock.':00',
    'session_count'=>(int)$row['session_count'],
    'subjects'=>$row['subjects'] ?: 'your scheduled subjects'
  ];
}
$stmt->close(); $conn->close();
echo json_encode(['status'=>'success','enabled'=>true,'reminder_time'=>$clock,'reminders'=>$items],JSON_UNESCAPED_UNICODE);
?>
