<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
session_save_path(__DIR__.'/../storage');
require_once __DIR__.'/../includes/auth.php';
$pdo = getDbConnection();
$ddl=$pdo->query('SHOW CREATE TABLE SignupEmailVerifications')->fetch(PDO::FETCH_NUM)[1];
$ddl=preg_replace('/^.*CONSTRAINT.*FOREIGN KEY.*\n/m','',$ddl);$ddl=preg_replace('/,\s*\)/',')',$ddl);
$pdo->exec(preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $ddl));
function challenge(PDO $pdo): void {
    $token=createSignupEmailVerification($pdo,'fixture@example.test');
    $_SESSION['signup_code']=['token'=>$token,'email'=>'fixture@example.test','hash'=>password_hash('012345',PASSWORD_DEFAULT),'expires'=>time()+600,'attempts'=>0];
}
function assertCode(bool $ok,string $label): void { if (!$ok) throw new RuntimeException($label); echo "PASS: $label\n"; }
challenge($pdo);
assertCode(verifySignupCode($pdo,'other@example.test','012345')!==null,'Different email rejected');
assertCode(verifySignupCode($pdo,'fixture@example.test','12345')!==null,'Malformed code rejected');
assertCode(verifySignupCode($pdo,'fixture@example.test','999999')!==null,'Incorrect code rejected');
assertCode(verifySignupCode($pdo,'fixture@example.test','012345')===null,'Six-digit code including leading zero verifies');
assertCode(findSignupEmailVerification($pdo,$_SESSION['signup_code']['token'])!==null,'Verified token available for account creation');
assertCode(verifySignupCode($pdo,'fixture@example.test','012345')!==null,'Code cannot be reused');
challenge($pdo); $_SESSION['signup_code']['expires']=time()-1;
assertCode(verifySignupCode($pdo,'fixture@example.test','012345')!==null,'Expired session code rejected');
challenge($pdo); $pdo->exec('UPDATE SignupEmailVerifications SET ExpiresAt=DATE_SUB(NOW(), INTERVAL 1 MINUTE)');
assertCode(verifySignupCode($pdo,'fixture@example.test','012345')!==null,'Database expiry enforced');
challenge($pdo); for($i=0;$i<5;$i++) verifySignupCode($pdo,'fixture@example.test','999999');
assertCode(verifySignupCode($pdo,'fixture@example.test','012345')!==null,'Five incorrect attempts lock the code');
challenge($pdo); $old=$_SESSION['signup_code']['token']; challenge($pdo);
assertCode(findSignupEmailVerification($pdo,$old)===null,'Replacement invalidates previous verification');
assertCode(verifySignupCode($pdo,'fixture@example.test','012345')===null,'Replacement code can verify');
$pdo->exec('UPDATE SignupEmailVerifications SET UsedAt=NOW()');
assertCode(findSignupEmailVerification($pdo,$_SESSION['signup_code']['token'])===null,'Consumed verification cannot create another account');
session_destroy();
echo "Temporary tables only. No emails sent.\n";
