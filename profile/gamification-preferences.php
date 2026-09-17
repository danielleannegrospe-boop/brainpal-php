<?php
require_once __DIR__ . '/../_shared/db.php';
brainpal_cors('GET, POST, OPTIONS');
$conn=brainpal_db();

$conn->query("CREATE TABLE IF NOT EXISTS gamification_preferences (
  preference_id INT NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  user_role ENUM('student') NOT NULL DEFAULT 'student',
  show_xp TINYINT(1) NOT NULL DEFAULT 1,
  show_streak TINYINT(1) NOT NULL DEFAULT 1,
  show_badges TINYINT(1) NOT NULL DEFAULT 1,
  leaderboard TINYINT(1) NOT NULL DEFAULT 1,
  xp_animation TINYINT(1) NOT NULL DEFAULT 1,
  confetti TINYINT(1) NOT NULL DEFAULT 1,
  date_created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  date_updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(preference_id),
  UNIQUE KEY uq_gamification_preferences_user(user_id,user_role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

if($_SERVER['REQUEST_METHOD']==='GET'){
  $id=(int)($_GET['user_id']??$_GET['student_id']??0);
  if($id<=0){echo json_encode(['status'=>'error','message'=>'Invalid student ID.']);exit;}
  $s=$conn->prepare("SELECT show_xp,show_streak,show_badges,leaderboard,xp_animation,confetti FROM gamification_preferences WHERE user_id=? AND user_role='student' LIMIT 1");
  $s->bind_param('i',$id);$s->execute();$r=$s->get_result()->fetch_assoc();$s->close();
  $settings=$r?[
    'showXP'=>(bool)$r['show_xp'],'showStreak'=>(bool)$r['show_streak'],'showBadges'=>(bool)$r['show_badges'],
    'leaderboard'=>(bool)$r['leaderboard'],'xpAnimation'=>(bool)$r['xp_animation'],'confetti'=>(bool)$r['confetti']
  ]:[
    'showXP'=>true,'showStreak'=>true,'showBadges'=>true,'leaderboard'=>true,'xpAnimation'=>true,'confetti'=>true
  ];
  echo json_encode(['status'=>'success','settings'=>$settings]);$conn->close();exit;
}
$data=json_decode(file_get_contents('php://input'),true)?:[];
$id=(int)($data['user_id']??$data['student_id']??0);
if($id<=0){echo json_encode(['status'=>'error','message'=>'Invalid student ID.']);exit;}
$bool=fn($v)=>(int)($v===true||$v===1||$v==='1'||$v==='true'||$v==='on');
$vals=[
 $bool($data['showXP']??true),$bool($data['showStreak']??true),$bool($data['showBadges']??true),
 $bool($data['leaderboard']??true),$bool($data['xpAnimation']??true),$bool($data['confetti']??true)
];
$s=$conn->prepare("INSERT INTO gamification_preferences(user_id,user_role,show_xp,show_streak,show_badges,leaderboard,xp_animation,confetti) VALUES(?,'student',?,?,?,?,?,?) ON DUPLICATE KEY UPDATE show_xp=VALUES(show_xp),show_streak=VALUES(show_streak),show_badges=VALUES(show_badges),leaderboard=VALUES(leaderboard),xp_animation=VALUES(xp_animation),confetti=VALUES(confetti)");
$showXP=$vals[0]; $showStreak=$vals[1]; $showBadges=$vals[2]; $leaderboard=$vals[3]; $xpAnimation=$vals[4]; $confetti=$vals[5];
$s->bind_param('iiiiiii',$id,$showXP,$showStreak,$showBadges,$leaderboard,$xpAnimation,$confetti);
$ok=$s->execute();$s->close();$conn->close();
echo json_encode(['status'=>$ok?'success':'error','message'=>$ok?'Gamification preferences saved.':'Unable to save preferences.']);
?>
