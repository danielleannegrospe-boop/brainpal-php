<?php
require_once __DIR__.'/../_shared/admin-auth.php';
bp_cors('GET, OPTIONS');

$adminId=(int)($_GET['admin_id']??0);
$actor=bp_require_role(['admin_id'=>$adminId],['super_admin','student_admin']);
$isStudentAdmin=strtolower($actor['role'])==='student_admin';
$c=bp_admin_conn();
$users=[];

$sql="SELECT s.student_id AS id,s.studentNo,s.firstName,s.m_initial,s.lastName,s.extension,s.age,s.email,s.gender,
             s.strand_id,st.strand_name AS strand,s.specialization_id,s.academic_id,a.academic_year,
             s.grade_level,s.date_created,s.is_verified,s.points,s.diagnostic_completed,
             s.current_streak,s.longest_streak,s.total_opens,s.last_open_date,
             s.account_status,
             (
               SELECT MAX(qa.date_created)
               FROM quiz_attempt qa
               WHERE qa.student_id=s.student_id
             ) AS last_quiz_date,
             (
               SELECT COUNT(*)
               FROM quiz_attempt qa2
               WHERE qa2.student_id=s.student_id
                 AND qa2.date_created >= CURDATE() - INTERVAL WEEKDAY(CURDATE()) DAY
             ) AS weekly_quiz_attempts,
             (
               SELECT ROUND(AVG(qa3.score),2)
               FROM quiz_attempt qa3
               WHERE qa3.student_id=s.student_id
                 AND qa3.date_created >= CURDATE() - INTERVAL WEEKDAY(CURDATE()) DAY
             ) AS weekly_average_quiz_score
      FROM student s
      LEFT JOIN strand st ON st.strand_id=s.strand_id AND st.date_deleted IS NULL
      LEFT JOIN academic a ON a.academic_id=s.academic_id AND a.date_deleted IS NULL
      WHERE s.date_deleted IS NULL ORDER BY s.student_id DESC";
$r=$c->query($sql);
while($row=$r->fetch_assoc()){
  $middleInitial = trim((string)($row['m_initial'] ?? ''));
  $middleInitial = $middleInitial !== '' ? rtrim($middleInitial, '.') . '.' : '';
  $name = trim(preg_replace('/\s+/', ' ', ($row['firstName'] ?? '') . ' ' . $middleInitial . ' ' . ($row['lastName'] ?? '') . ' ' . ($row['extension'] ?? '')));
  $users[]=['id'=>(int)$row['id'],'name'=>$name,'email'=>$row['email'],'role'=>'student',
    'student_id'=>(int)$row['id'],'studentNo'=>$row['studentNo'],'firstName'=>$row['firstName'],
    'm_initial'=>$row['m_initial'],'lastName'=>$row['lastName'],'extension'=>$row['extension'],
    'age'=>$row['age']===null?null:(int)$row['age'],'gender'=>$row['gender'],'strand_id'=>$row['strand_id'],
    'strand'=>$row['strand'],'specialization_id'=>$row['specialization_id'],'academic_id'=>$row['academic_id'],
    'academic_year'=>$row['academic_year'],'grade_level'=>$row['grade_level'],'date_created'=>$row['date_created'],
    'is_verified'=>(int)$row['is_verified'],'points'=>(int)($row['points']??0),
    'diagnostic_completed'=>(int)($row['diagnostic_completed']??0),
    'current_streak'=>(int)($row['current_streak']??0),'longest_streak'=>(int)($row['longest_streak']??0),
    'total_opens'=>(int)($row['total_opens']??0),'last_open_date'=>$row['last_open_date'] ?? null,
    'account_status'=>$row['account_status'] ?? 'active',
    'last_quiz_date'=>$row['last_quiz_date'] ?? null,
    'weekly_quiz_attempts'=>(int)($row['weekly_quiz_attempts']??0),
    'weekly_average_quiz_score'=>$row['weekly_average_quiz_score']===null?null:(float)$row['weekly_average_quiz_score']];
}
if(!$isStudentAdmin){
  $r=$c->query("SELECT admin_id AS id,fullname AS name,email,role,date_created,account_status FROM admin WHERE date_deleted IS NULL ORDER BY admin_id DESC");
  while($row=$r->fetch_assoc()){
    $users[]=['id'=>(int)$row['id'],'name'=>$row['name'],'email'=>$row['email'],
      'role'=>strtolower(trim($row['role'])),'admin_id'=>(int)$row['id'],'date_created'=>$row['date_created'],
      'account_status'=>$row['account_status']];
  }
}
$c->close();
echo json_encode(['status'=>'success','users'=>$users],JSON_UNESCAPED_UNICODE);
