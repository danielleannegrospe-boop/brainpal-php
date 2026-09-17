<?php
declare(strict_types=1);

require_once __DIR__ . '/../_shared/admin-auth.php';
bp_cors('POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
$data = bp_json();
$actor = bp_require_role($data, ['super_admin', 'content_admin']);
$quizId = (int)($data['quiz_id'] ?? 0);
if ($quizId <= 0) { echo json_encode(['status'=>'error','message'=>'Invalid quiz ID.']); exit; }
$conn = bp_admin_conn();
$conn->begin_transaction();
try {
    $stmt=$conn->prepare('SELECT quiz_title, admin_id FROM quiz WHERE quiz_id=? LIMIT 1');
    if(!$stmt) throw new Exception('Unable to load quiz.');
    $stmt->bind_param('i',$quizId); $stmt->execute(); $quiz=$stmt->get_result()->fetch_assoc(); $stmt->close();
    if(!$quiz) throw new Exception('Quiz not found.');
    $actorRole=strtolower(trim((string)$actor['role']));
    if($actorRole!=='super_admin' && (int)$quiz['admin_id']!==(int)$actor['admin_id']) throw new Exception('You can only delete your own quizzes.');

    $stmt=$conn->prepare('SELECT question_id FROM quiz_questions WHERE quiz_id=?');
    if(!$stmt) throw new Exception('Unable to load quiz questions.');
    $stmt->bind_param('i',$quizId); $stmt->execute(); $result=$stmt->get_result(); $questionIds=[];
    while($row=$result->fetch_assoc()) $questionIds[]=(int)$row['question_id'];
    $stmt->close();

    foreach($questionIds as $questionId){
        foreach(['quiz_attempt_answers','quiz_choices','quiz_answers'] as $table){
            $stmt=$conn->prepare("DELETE FROM {$table} WHERE question_id=?");
            if(!$stmt) throw new Exception('Unable to delete quiz question data.');
            $stmt->bind_param('i',$questionId); if(!$stmt->execute()) throw new Exception($stmt->error); $stmt->close();
        }
    }
    $stmt=$conn->prepare('DELETE FROM quiz_questions WHERE quiz_id=?'); if(!$stmt) throw new Exception('Unable to delete quiz questions.');
    $stmt->bind_param('i',$quizId); if(!$stmt->execute()) throw new Exception($stmt->error); $stmt->close();
    $stmt=$conn->prepare('DELETE FROM quiz_attempt WHERE quiz_id=?'); if(!$stmt) throw new Exception('Unable to delete quiz attempts.');
    $stmt->bind_param('i',$quizId); if(!$stmt->execute()) throw new Exception($stmt->error); $stmt->close();
    $stmt=$conn->prepare('DELETE FROM quiz WHERE quiz_id=?'); if(!$stmt) throw new Exception('Unable to delete quiz.');
    $stmt->bind_param('i',$quizId); if(!$stmt->execute()) throw new Exception($stmt->error); $stmt->close();

    bp_log($conn,(int)$actor['admin_id'],'Deleted Quiz','Deleted quiz: '.trim((string)$quiz['quiz_title']).' (#'.$quizId.')');
    $conn->commit(); $conn->close();
    echo json_encode(['status'=>'success','message'=>'Quiz deleted successfully.']);
} catch(Throwable $e) {
    $conn->rollback(); $conn->close(); http_response_code(400);
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
