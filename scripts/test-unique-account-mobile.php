<?php
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../config/db.php';require __DIR__.'/../includes/input-validation.php';
$pdo=getDbConnection();if(!$pdo)exit(1);
// Shadow Users for this connection; application records are never written.
$sql=$pdo->query('SHOW CREATE TABLE Users')->fetch(PDO::FETCH_NUM)[1];
$sql=preg_replace('/^CREATE TABLE/','CREATE TEMPORARY TABLE',$sql);
$sql=preg_replace('/^.*CONSTRAINT.*FOREIGN KEY.*\n/m','',$sql);$sql=preg_replace('/,\s*\)/',')',$sql);$pdo->exec($sql);
function mobileCheck(bool $ok,string $label):void{if(!$ok)throw new LogicException($label);echo 'PASS: '.$label.PHP_EOL;}
$s=$pdo->prepare("INSERT INTO Users(UserID,RoleID,FirstName,LastName,Email,PasswordHash,ContactNumber) VALUES(?,?,'Fixture','User',?,'fixture-not-a-real-password',?)");
foreach(['Admin','Staff','Physician','Patient'] as $i=>$role){$id=$i+1;$number='0900000000'.$id;$s->execute([$id,$id,'fixture'.$id.'@example.test',$number]);mobileCheck(hqMobileInUse($pdo,$number)&&!hqMobileInUse($pdo,$number,$id),$role.' number is reserved; own profile number is allowed');}
try{$s->execute([5,1,'new-fixture@example.test','09000000001']);throw new LogicException('Duplicate insert accepted');}catch(PDOException $e){mobileCheck(hqDuplicateMobileError($e),'Database rejects duplicate creation with identifiable error');}
try{$pdo->exec("UPDATE Users SET ContactNumber='09000000001' WHERE UserID=2");throw new LogicException('Duplicate update accepted');}catch(PDOException $e){mobileCheck(hqDuplicateMobileError($e),'Database rejects another account number on update');}
$pdo->exec("UPDATE Users SET ContactNumber='09000000001' WHERE UserID=1");mobileCheck(true,'Saving unchanged own number succeeds');
$pdo->exec("UPDATE Users SET Status='Inactive',DeletedAt=NOW() WHERE UserID=1");mobileCheck(hqMobileInUse($pdo,'09000000001'),'Inactive and archived account numbers remain reserved');
try{$s->execute([6,1,'space-fixture@example.test',' 09000000001 ']);throw new LogicException('Whitespace bypass accepted');}catch(PDOException $e){mobileCheck(hqDuplicateMobileError($e),'Whitespace cannot bypass database uniqueness');}
$s->execute([7,1,'blank1@example.test','']);$s->execute([8,1,'blank2@example.test','']);$s->execute([9,1,'null1@example.test',null]);$s->execute([10,1,'null2@example.test',null]);mobileCheck(!hqMobileInUse($pdo,''),'Optional empty numbers can repeat');
$pdo->exec("UPDATE Users SET ContactNumber='09000000009' WHERE UserID=1");mobileCheck(!hqMobileInUse($pdo,'09000000001')&&hqMobileInUse($pdo,'09000000009'),'Changing a number releases the previous number');
echo "Temporary fixtures only. No messages sent.\n";
