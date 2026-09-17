<?php
require_once __DIR__.'/../_shared/admin-auth.php';
require_once __DIR__.'/../_shared/notification-helper.php';
bp_cors('POST, OPTIONS');$d=bp_json();$actor=bp_require_role($d,['super_admin','content_admin']);
$quizId=(int)($d['quiz_id']??0);$materialId=(int)($d['material_id']??0);$title=trim((string)($d['quiz_title']??''));$type=trim((string)($d['quiz_type']??'mixed'));$difficulty=trim((string)($d['difficulty']??''));$questions=$d['questions']??[];
if($materialId<=0||$title===''||!is_array($questions)||count($questions)<1){echo json_encode(['status'=>'error','message'=>'Quiz title, learning material and at least one question are required.']);exit;}
$c=bp_admin_conn();$c->begin_transaction();
try{
 if($quizId>0){$s=$c->prepare('SELECT admin_id FROM quiz WHERE quiz_id=? LIMIT 1');$s->bind_param('i',$quizId);$s->execute();$old=$s->get_result()->fetch_assoc();$s->close();if(!$old)throw new Exception('Quiz not found.');if(strtolower($actor['role'])!=='super_admin'&&(int)$old['admin_id']!==(int)$actor['admin_id'])throw new Exception('You can only edit your own quizzes.');$s=$c->prepare('UPDATE quiz SET material_id=?,quiz_title=?,quiz_type=?,difficulty=?,approval_status=? WHERE quiz_id=?');$status='pending';$s->bind_param('issssi',$materialId,$title,$type,$difficulty,$status,$quizId);$s->execute();$s->close();$s=$c->prepare('DELETE FROM quiz_questions WHERE quiz_id=?');$s->bind_param('i',$quizId);$s->execute();$s->close();}
 else{$status='pending';$s=$c->prepare('INSERT INTO quiz(material_id,quiz_title,quiz_type,difficulty,admin_id,approval_status) VALUES(?,?,?,?,?,?)');$s->bind_param('isssis',$materialId,$title,$type,$difficulty,$actor['admin_id'],$status);$s->execute();$quizId=$c->insert_id;$s->close();}
$q=$c->prepare('INSERT INTO quiz_questions(quiz_id,question_number,question_text,question_type,points) VALUES(?,?,?,?,?)');$ch=$c->prepare('INSERT INTO quiz_choices(question_id,choice_letter,choice_text,is_correct) VALUES(?,?,?,?)');$an=$c->prepare('INSERT INTO quiz_answers(question_id,correct_answer,answer_type) VALUES(?,?,?)');
foreach($questions as $i=>$row){$num=$i+1;$qt=trim((string)($row['question_text']??''));$qtype=trim((string)($row['question_type']??'multiple_choice'));$points=max(1,(int)($row['points']??1));if($qt==='')continue;$q->bind_param('iissi',$quizId,$num,$qt,$qtype,$points);$q->execute();$qid=$c->insert_id;$choices=is_array($row['choices']??null)?$row['choices']:[];$correct=(string)($row['correct_choice']??$row['correct_answer']??'');foreach($choices as $choice){$letter=(string)($choice['choice_letter']??'');$text=trim((string)($choice['choice_text']??''));$ok=(int)($choice['is_correct']??($letter===$correct?1:0));$ch->bind_param('issi',$qid,$letter,$text,$ok);$ch->execute();}$atype='multiple_choice';$an->bind_param('iss',$qid,$correct,$atype);$an->execute();}
$q->close();$ch->close();$an->close();/*
 * A pending/re-submitted quiz must not remain visible through an old
 * student notification. A fresh notification is created only after
 * Super Admin approval.
 */
$cleanupNotify = $c->prepare(
    'DELETE FROM notifications
     WHERE user_role = "student"
       AND type = "new_quiz"
       AND reference_id = ?'
);
if ($cleanupNotify) {
    $cleanupNotify->bind_param('i', $quizId);
    $cleanupNotify->execute();
    $cleanupNotify->close();
}

$action=$quizId>0?'Updated Quiz':'Added Quiz';bp_log($c,(int)$actor['admin_id'],$action,$title.' submitted for Super Admin approval.');$c->commit();


$c->close();echo json_encode(['status'=>'success','message'=>'Quiz saved and submitted for approval.','quiz_id'=>$quizId,'approval_status'=>'pending']);
}catch(Throwable $e){$c->rollback();$c->close();http_response_code(400);echo json_encode(['status'=>'error','message'=>$e->getMessage()]);}
