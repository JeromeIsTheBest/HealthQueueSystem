<?php
require_once __DIR__.'/physician-accounts.php';
require_once __DIR__.'/demo-revenue.php';
/** Always resolve report permissions from current database membership. */
function reportScope(PDO $pdo,int $id,array $input):array {
 $s=$pdo->prepare("SELECT u.*,r.RoleName FROM Users u JOIN Roles r ON r.RoleID=u.RoleID WHERE u.UserID=? AND u.Status='Active' AND u.DeletedAt IS NULL");$s->execute([$id]);$u=$s->fetch();
 if(!$u||!in_array($u['RoleName'],['Admin','Staff','Physician'],true))throw new RuntimeException('Reports are not available for this account.');
 $main=$u['RoleName']==='Physician'&&physicianAccount($pdo,$id)['is_main'];
 $view=(string)($input['view']??'performance');if(!in_array($view,['performance','queue','ratings','ai'],true)||($view==='ai'&&$u['RoleName']==='Staff'))throw new RuntimeException('This report is not available.');
 $today=$pdo->query('SELECT CURDATE()')->fetchColumn();$from=(string)($input['from']??substr($today,0,8).'01');$to=(string)($input['to']??$today);
 foreach([$from,$to] as $d){$v=DateTimeImmutable::createFromFormat('!Y-m-d',$d);if(!$v||$v->format('Y-m-d')!==$d)throw new RuntimeException('Choose valid report dates.');}
 if($from>$to||(new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days>366)throw new RuntimeException('Choose a date range of up to 366 days.');
 foreach(['clinic','physician'] as $k)if(isset($input[$k])&&!preg_match('/^\d+$/D',(string)$input[$k]))throw new RuntimeException('Invalid report filter.');
 $clinic=(int)($input['clinic']??0);$phys=(int)($input['physician']??0);
 if($u['RoleName']!=='Admin'){if(!$u['ClinicID']||($clinic&&$clinic!=(int)$u['ClinicID']))throw new RuntimeException('This clinic is outside your report access.');$clinic=(int)$u['ClinicID'];}
 if($u['RoleName']==='Physician'&&(!$main||$view==='ai')){if($phys&&$phys!==$id)throw new RuntimeException('Only your own physician report is available.');$phys=$id;}
 if($phys){$s=$pdo->prepare("SELECT ClinicID FROM Users u JOIN Roles r ON r.RoleID=u.RoleID WHERE UserID=? AND r.RoleName='Physician'");$s->execute([$phys]);$pc=$s->fetchColumn();if(!$pc||($clinic&&(int)$pc!==$clinic))throw new RuntimeException('Choose a physician in the selected clinic.');}
 return ['id'=>$id,'role'=>$u['RoleName'],'main'=>$main,'money'=>$u['RoleName']==='Admin'||$main,'clinic'=>$clinic,'physician'=>$phys,'from'=>$from,'to'=>$to,'view'=>$view];
}
function reportWhere(array $scope,string $alias='a',string $date='AppointmentDate'):array {
 $sql="$alias.$date BETWEEN ? AND ?";$args=[$scope['from'],$scope['to']];foreach(['clinic'=>'ClinicID','physician'=>'PhysicianID'] as $k=>$col)if($scope[$k]){$sql.=" AND $alias.$col=?";$args[]=$scope[$k];}return [$sql,$args];
}
function reportRows(PDO $pdo,array $s):array {
 [$where,$args]=reportWhere($s);$run=static function($sql,$args)use($pdo){$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchAll(PDO::FETCH_ASSOC);};
 if($s['view']==='performance'){
  $group=$s['role']==='Admin'?'c.ClinicName':($s['role']==='Physician'&&!$s['main']?'a.AppointmentDate':"COALESCE(CONCAT('Dr. ',u.FirstName,' ',u.LastName),'Unassigned')");
  $groupBy=$s['role']==='Admin'?'c.ClinicID,'.$group:($s['role']==='Physician'&&!$s['main']?$group:'a.PhysicianID,'.$group);
  $money='';$join='';if($s['money']){$eligible=demoRevenueEligibility();if($s['role']==='Physician')$eligible.=' AND p.FinancialOwnerUserID='.(int)$s['id'];$join=' LEFT JOIN AppointmentPayments p ON p.AppointmentID=a.AppointmentID';$money=",COALESCE(SUM(CASE WHEN $eligible THEN p.GrossAmount ELSE 0 END),0) AS `Eligible demo revenue`,COALESCE(SUM(CASE WHEN $eligible THEN p.PhysicianRevenue ELSE 0 END),0) AS `Physician share`,COALESCE(SUM(CASE WHEN $eligible THEN p.PlatformCommission ELSE 0 END),0) AS `Platform commission`";}
  return $run("SELECT $group AS `Group`,COUNT(*) AS Bookings,SUM(a.Status='Completed') AS Completed,SUM(a.Status='Cancelled') AS Cancelled,SUM(a.Status='NoShow') AS `No-show`,ROUND(100*SUM(a.Status='Completed')/COUNT(*),1) AS `Completion %` $money FROM Appointments a JOIN Clinic c ON c.ClinicID=a.ClinicID LEFT JOIN Users u ON u.UserID=a.PhysicianID $join WHERE $where GROUP BY $groupBy ORDER BY $groupBy",$args);
 }
 if($s['view']==='queue')return $run("SELECT COALESCE(CONCAT('Dr. ',u.FirstName,' ',u.LastName),'Unassigned') AS Physician,COUNT(*) AS `Queue entries`,SUM(COALESCE(q.FirstCalledAt,q.CalledAt) IS NOT NULL) AS Called,COALESCE(SUM(q.ArrivedAt IS NOT NULL AND q.FirstCalledAt>=q.ArrivedAt),0) AS `Wait samples`,ROUND(AVG(CASE WHEN q.FirstCalledAt>=q.ArrivedAt THEN TIMESTAMPDIFF(SECOND,q.ArrivedAt,q.FirstCalledAt)/60 END),1) AS `Average wait (min)` FROM Appointments a JOIN Queue q ON q.QueueID=(SELECT MAX(q2.QueueID) FROM Queue q2 WHERE q2.AppointmentID=a.AppointmentID) LEFT JOIN Users u ON u.UserID=a.PhysicianID WHERE $where GROUP BY a.PhysicianID,u.FirstName,u.LastName ORDER BY Physician,a.PhysicianID",$args);
 if($s['view']==='ratings')return $run("SELECT CONCAT('Dr. ',u.FirstName,' ',u.LastName) AS Physician,COUNT(*) AS Responses,ROUND(AVG(f.Rating),2) AS `Average visit rating`,MIN(f.Rating) AS Lowest,MAX(f.Rating) AS Highest FROM Feedback f JOIN Appointments a ON a.AppointmentID=f.AppointmentID JOIN Users u ON u.UserID=a.PhysicianID WHERE $where GROUP BY a.PhysicianID,u.FirstName,u.LastName ORDER BY Physician,a.PhysicianID",$args);
 [$where,$args]=reportWhere($s,'e','EvaluatedAt');$args[1].=' 23:59:59';
 return $run("SELECT e.TranscriptVersionID AS Source,CONCAT('Dr. ',u.FirstName,' ',u.LastName) AS Physician,e.Language,e.ReferenceWords AS `Reference words`,e.Substitutions,e.Deletions,e.Insertions,e.WordErrorRate AS `WER %`,e.EvaluatedAt AS Evaluated FROM AITranscriptEvaluations e JOIN Users u ON u.UserID=e.PhysicianID WHERE $where ORDER BY e.EvaluatedAt DESC",$args);
}
function reportWords(string $text):array {$text=trim(preg_replace('/[^\p{L}\p{N}]+/u',' ',mb_strtolower($text,'UTF-8')));return $text===''?[]:preg_split('/\s+/u',$text);}
/** Word-level Levenshtein with rolling rows; ties favor substitution, then deletion. */
function reportWer(string $reference,string $hypothesis):array {
 $r=reportWords($reference);$h=reportWords($hypothesis);$n=count($r);$m=count($h);if(!$n||max($n,$m)>1500)throw new RuntimeException('Use a non-empty reference and recordings of up to 1,500 words.');
 $prev=[];for($j=0;$j<=$m;$j++)$prev[]=[$j,0,0,$j];
 for($i=1;$i<=$n;$i++){$row=[[$i,0,$i,0]];for($j=1;$j<=$m;$j++){$a=$prev[$j-1];if($r[$i-1]!==$h[$j-1]){$a[0]++;$a[1]++;}$d=$prev[$j];$d[0]++;$d[2]++;$ins=$row[$j-1];$ins[0]++;$ins[3]++;$row[$j]=$a;if($d[0]<$row[$j][0])$row[$j]=$d;if($ins[0]<$row[$j][0])$row[$j]=$ins;}$prev=$row;}
 return ['reference'=>$n,'hypothesis'=>$m,'substitutions'=>$prev[$m][1],'deletions'=>$prev[$m][2],'insertions'=>$prev[$m][3],'wer'=>round(100*$prev[$m][0]/$n,4)];
}
function saveReportEvaluation(PDO $pdo,int $id,array $input):void {
 if(($input['verified']??'')!=='1')throw new RuntimeException('Verify the reference against the recording before evaluating.');
 $lang=(string)($input['language']??'');if(!in_array($lang,['English','Filipino','Cebuano','Mixed','Other'],true))throw new RuntimeException('Choose a language.');
 $ref=trim((string)($input['reference']??''));if(mb_strlen($ref)>20000)throw new RuntimeException('Reference is too long.');
 $pdo->beginTransaction();try{
  $scope=reportScope($pdo,$id,['view'=>'ai']);if($scope['role']!=='Physician')throw new RuntimeException('Only the assigned physician can evaluate a transcript.');
  $q=$pdo->prepare("SELECT t.TranscriptText,c.ClinicID FROM AITranscriptVersions t JOIN Consultations c ON c.ConsultationID=t.ConsultationID JOIN Users u ON u.UserID=c.DoctorID WHERE t.TranscriptVersionID=? AND t.TranscriptType='Original_STT' AND c.DoctorID=? AND c.ClinicID=? AND u.Status='Active' AND u.DeletedAt IS NULL FOR UPDATE");$q->execute([(int)($input['source']??0),$id,$scope['clinic']]);$source=$q->fetch();if(!$source)throw new RuntimeException('Original transcript not found in your consultations.');
  $w=reportWer($ref,$source['TranscriptText']);
  $pdo->prepare('INSERT INTO AITranscriptEvaluations(TranscriptVersionID,PhysicianID,ClinicID,Language,ReferenceText,ReferenceHash,HypothesisHash,ReferenceWords,HypothesisWords,Substitutions,Deletions,Insertions,WordErrorRate) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE Language=VALUES(Language),ReferenceText=VALUES(ReferenceText),ReferenceHash=VALUES(ReferenceHash),HypothesisHash=VALUES(HypothesisHash),ReferenceWords=VALUES(ReferenceWords),HypothesisWords=VALUES(HypothesisWords),Substitutions=VALUES(Substitutions),Deletions=VALUES(Deletions),Insertions=VALUES(Insertions),WordErrorRate=VALUES(WordErrorRate),EvaluatedAt=NOW()')->execute([(int)$input['source'],$id,$source['ClinicID'],$lang,$ref,hash('sha256',$ref),hash('sha256',$source['TranscriptText']),$w['reference'],$w['hypothesis'],$w['substitutions'],$w['deletions'],$w['insertions'],$w['wer']]);
  require_once __DIR__.'/audit.php';logActivity($pdo,$id,(int)$source['ClinicID'],'transcript_evaluation','Original transcript #'.(int)$input['source'].' evaluated against a physician-verified reference.');$pdo->commit();
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
