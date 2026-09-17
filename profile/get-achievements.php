<?php
require_once __DIR__ . '/../_shared/db.php';
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    echo json_encode(['status' => 'success', 'message' => 'OK']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Only GET requests are allowed.']);
    exit;
}

$conn = brainpal_db();
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed.']);
    exit;
}
$conn->set_charset('utf8mb4');

$userId = (int)($_GET['user_id'] ?? $_GET['student_id'] ?? 0);
if ($userId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid student ID.']);
    $conn->close();
    exit;
}

$studentStmt = $conn->prepare("SELECT student_id, firstName, m_initial, lastName, extension, points, current_streak, longest_streak FROM student WHERE student_id = ? AND date_deleted IS NULL LIMIT 1");
if (!$studentStmt) {
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'Failed to prepare student query.']);
    $conn->close(); exit;
}
$studentStmt->bind_param('i', $userId);
$studentStmt->execute();
$student = $studentStmt->get_result()->fetch_assoc();
$studentStmt->close();
if (!$student) {
    echo json_encode(['status'=>'error','message'=>'Student account not found.']);
    $conn->close(); exit;
}

$studentName = trim(preg_replace('/\s+/', ' ', implode(' ', array_filter([
    trim((string)($student['firstName'] ?? '')),
    trim((string)($student['m_initial'] ?? '')),
    trim((string)($student['lastName'] ?? '')),
    trim((string)($student['extension'] ?? ''))
]))));
if ($studentName === '') $studentName = 'Student';

$xp = (int)($student['points'] ?? 0);
$currentStreak = (int)($student['current_streak'] ?? 0);
$longestStreak = (int)($student['longest_streak'] ?? 0);

if ($xp < 200) { $level=1; $levelName='Beginner'; }
elseif ($xp < 500) { $level=2; $levelName='Intermediate'; }
elseif ($xp < 1000) { $level=3; $levelName='Advanced'; }
else { $level=4; $levelName='Master'; }

$quizCount=0; $highestScore=0;
$stmt=$conn->prepare('SELECT COUNT(*) quiz_count, COALESCE(MAX(score),0) highest_score FROM quiz_attempt WHERE student_id = ?');
if ($stmt) { $stmt->bind_param('i',$userId); $stmt->execute(); $r=$stmt->get_result()->fetch_assoc(); $quizCount=(int)($r['quiz_count']??0); $highestScore=(float)($r['highest_score']??0); $stmt->close(); }

$strandTotal=0; $strandCompleted=0;
$stmt=$conn->prepare("SELECT COUNT(DISTINCT lm.material_id) total_materials, COUNT(DISTINCT CASE WHEN qa.attempt_id IS NOT NULL THEN lm.material_id END) completed_materials FROM learning_material lm INNER JOIN study_schedule ss ON ss.schedule_id=lm.schedule_id AND ss.student_id=? AND ss.date_deleted IS NULL INNER JOIN subject sub ON sub.subject_id=lm.subject_id AND sub.strand_id=(SELECT strand_id FROM student WHERE student_id=?) AND sub.date_deleted IS NULL LEFT JOIN quiz q ON q.material_id=lm.material_id LEFT JOIN quiz_attempt qa ON qa.quiz_id=q.quiz_id AND qa.student_id=? WHERE lm.date_deleted IS NULL");
if ($stmt) { $stmt->bind_param('iii',$userId,$userId,$userId); $stmt->execute(); $r=$stmt->get_result()->fetch_assoc(); $strandTotal=(int)($r['total_materials']??0); $strandCompleted=(int)($r['completed_materials']??0); $stmt->close(); }
$strandEarned=$strandTotal>0 && $strandCompleted >= $strandTotal;

$masteryTotal=0; $masteryCompleted=0; $masteryAverage=0;
$stmt=$conn->prepare("SELECT COUNT(DISTINCT lm.material_id) total_materials, COUNT(DISTINCT CASE WHEN qa.attempt_id IS NOT NULL THEN lm.material_id END) completed_materials, COALESCE(AVG(qa.score),0) average_score FROM learning_material lm INNER JOIN study_schedule ss ON ss.schedule_id=lm.schedule_id AND ss.student_id=? AND ss.date_deleted IS NULL INNER JOIN quiz q ON q.material_id=lm.material_id LEFT JOIN quiz_attempt qa ON qa.quiz_id=q.quiz_id AND qa.student_id=? WHERE lm.date_deleted IS NULL");
if ($stmt) { $stmt->bind_param('ii',$userId,$userId); $stmt->execute(); $r=$stmt->get_result()->fetch_assoc(); $masteryTotal=(int)($r['total_materials']??0); $masteryCompleted=(int)($r['completed_materials']??0); $masteryAverage=(float)($r['average_score']??0); $stmt->close(); }
$masteryEarned=$masteryTotal>0 && $masteryCompleted >= $masteryTotal && $masteryAverage >= 80;

$strandName='Your Strand';
$stmt=$conn->prepare('SELECT st.strand_name FROM student s LEFT JOIN strand st ON st.strand_id=s.strand_id AND st.date_deleted IS NULL WHERE s.student_id=? LIMIT 1');
if ($stmt) { $stmt->bind_param('i',$userId); $stmt->execute(); $r=$stmt->get_result()->fetch_assoc(); $strandName=trim((string)($r['strand_name']??'')) ?: 'Your Strand'; $stmt->close(); }

$badges=[
 ['key'=>'first_quiz','icon'=>'⭐','title'=>'First Quiz','description'=>'Complete your first quiz.','earned'=>$quizCount>=1,'earned_reason'=>$quizCount>=1?'You completed your first quiz.':'Complete at least one quiz.','earned_at'=>null],
 ['key'=>'top_scorer','icon'=>'🏆','title'=>'Top Scorer','description'=>'Score 90% or higher on a quiz.','earned'=>$highestScore>=90,'earned_reason'=>$highestScore>=90?'You achieved a quiz score of 90% or higher.':'Reach 90% or higher on any quiz.','earned_at'=>null],
 ['key'=>'streak_master','icon'=>'🔥','title'=>'Streak Master','description'=>'Maintain a 7-day study streak.','earned'=>$longestStreak>=7,'earned_reason'=>$longestStreak>=7?'You maintained a study streak of at least 7 days.':'Maintain a 7-day streak.','earned_at'=>null],
 ['key'=>'advanced','icon'=>'🚀','title'=>'Advanced','description'=>'Maintain a 30-day study streak.','earned'=>$longestStreak>=30,'earned_reason'=>$longestStreak>=30?'You maintained a study streak of at least 30 days.':'Maintain a 30-day streak.','earned_at'=>null],
 ['key'=>'strand_star','icon'=>'🌟','title'=>$strandName==='Your Strand'?'Strand Star':strtoupper($strandName).' STAR','description'=>'Complete all assigned learning materials in your strand.','earned'=>$strandEarned,'earned_reason'=>$strandEarned?'You completed all assigned learning materials in your strand.':'Complete all assigned learning materials in your strand.','earned_at'=>null],
 ['key'=>'mastery','icon'=>'💎','title'=>'Mastery','description'=>'Complete all assigned quizzes with an average score of at least 80%.','earned'=>$masteryEarned,'earned_reason'=>$masteryEarned?'You completed all assigned quizzes and maintained an average score of at least 80%.':'Complete all assigned quizzes and reach at least an 80% average score.','earned_at'=>null]
];

$earned=0; foreach($badges as $b) if($b['earned']) $earned++;
$badgePercentage=count($badges)>0 ? (int)round(($earned/count($badges))*100) : 0;

$history=[]; $totalPointsEarned=0;
$stmt=$conn->prepare("SELECT qa.attempt_id, qa.quiz_id, q.quiz_title, COALESCE(sub.subject_name,'') subject_name, qa.earned_points, qa.total_points, qa.score, qa.date_created FROM quiz_attempt qa INNER JOIN quiz q ON q.quiz_id=qa.quiz_id LEFT JOIN learning_material lm ON lm.material_id=q.material_id LEFT JOIN subject sub ON sub.subject_id=lm.subject_id WHERE qa.student_id=? ORDER BY qa.date_created DESC, qa.attempt_id DESC LIMIT 50");
if ($stmt) {
    $stmt->bind_param('i',$userId); $stmt->execute(); $res=$stmt->get_result();
    while($row=$res->fetch_assoc()) { $row['attempt_id']=(int)$row['attempt_id']; $row['quiz_id']=(int)$row['quiz_id']; $row['earned_points']=(int)$row['earned_points']; $row['total_points']=(int)$row['total_points']; $row['score']=(int)$row['score']; $history[]=$row; $totalPointsEarned += $row['earned_points']; }
    $stmt->close();
}

// Set badge earned date from the earliest supporting quiz/streak evidence where it is directly available.
foreach ($badges as &$badge) {
    if (!$badge['earned']) continue;
    if ($badge['key']==='first_quiz') {
        $stmt=$conn->prepare('SELECT date_created FROM quiz_attempt WHERE student_id=? ORDER BY date_created ASC, attempt_id ASC LIMIT 1');
    } elseif ($badge['key']==='top_scorer') {
        $stmt=$conn->prepare('SELECT date_created FROM quiz_attempt WHERE student_id=? AND score>=90 ORDER BY date_created ASC, attempt_id ASC LIMIT 1');
    } else {
        $stmt=null;
    }
    if ($stmt) { $stmt->bind_param('i',$userId); $stmt->execute(); $r=$stmt->get_result()->fetch_assoc(); $badge['earned_at']=$r['date_created']??null; $stmt->close(); }
}
unset($badge);

$conn->close();

echo json_encode([
 'status'=>'success',
 'student_id'=>$userId,
 'student_name'=>$studentName,
 'xp'=>$xp,
 'level'=>$level,
 'level_name'=>$levelName,
 'badge_percentage'=>$badgePercentage,
 'earned_badges'=>$earned,
 'total_badges'=>count($badges),
 'badges'=>$badges,
 'point_history'=>$history,
 'total_points_earned'=>$totalPointsEarned,
 'quiz_count'=>$quizCount,
 'current_streak'=>$currentStreak,
 'longest_streak'=>$longestStreak
], JSON_UNESCAPED_UNICODE);
