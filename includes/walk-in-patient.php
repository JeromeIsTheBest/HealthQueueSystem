<?php
require_once __DIR__.'/auth.php';
require_once __DIR__.'/input-validation.php';
/** Staff walk-in creation only. Shared contact numbers must never merge patient identities. */
function createWalkInPatient(PDO $pdo,array $values):int
{
 if(!$pdo->inTransaction())throw new LogicException('Walk-in creation requires a transaction.');
 $first=hqCapitalizeName((string)($values['first_name']??''));$last=hqCapitalizeName((string)($values['last_name']??''));$phone=trim((string)($values['contact_number']??''));$email=trim((string)($values['email']??''));
 if($phone===''||!hqValidMobile($phone))throw new RuntimeException('Enter a valid mobile number');
 if($first===''||$last===''||mb_strlen($first)>100||mb_strlen($last)>100)throw new RuntimeException('Enter valid first and last names.');
 if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Enter a valid email address, or leave it blank.');
 $role=(int)$pdo->query("SELECT RoleID FROM Roles WHERE RoleName='Patient'")->fetchColumn();if(!$role)throw new RuntimeException('Patient registration unavailable.');
 if($email==='')$email='walkin.'.bin2hex(random_bytes(8)).'@healthqueue.local';
 $pdo->prepare("INSERT INTO Users(RoleID,FirstName,LastName,Email,ContactNumber,PasswordHash,Status,IsWalkInAccount) VALUES(?,?,?,?,?,?,'Active',1)")->execute([$role,$first,$last,$email,$phone,password_hash(bin2hex(random_bytes(16)),PASSWORD_DEFAULT)]);
 $id=(int)$pdo->lastInsertId();assignUserIdNumber($pdo,$id);return $id;
}
