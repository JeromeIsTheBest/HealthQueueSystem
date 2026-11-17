<?php
/** Fixed-window limits; identities are hashed rather than stored as email/IP text. */
function consumeRequestLimit(PDO $pdo,string $scope,string $identity,int $limit,int $seconds=900):bool
{
 $seconds=max(1,(int)$seconds);$key=hash('sha256','healthqueue|'.$scope.'|'.strtolower(trim($identity)));
 $sql="INSERT INTO RequestRateLimits (BucketKey,WindowStart,Attempts) VALUES (?,NOW(),1) ON DUPLICATE KEY UPDATE Attempts=IF(WindowStart<=NOW()-INTERVAL {$seconds} SECOND,1,Attempts+1),WindowStart=IF(WindowStart<=NOW()-INTERVAL {$seconds} SECOND,NOW(),WindowStart)";
 $pdo->prepare($sql)->execute([$key]);$stmt=$pdo->prepare('SELECT Attempts FROM RequestRateLimits WHERE BucketKey=?');$stmt->execute([$key]);return (int)$stmt->fetchColumn()<=$limit;
}
function requestClientAddress():string{return (string)($_SERVER['REMOTE_ADDR']??'local');}

function clearRequestLimit(PDO $pdo,string $scope,string $identity):void
{
 $pdo->prepare('DELETE FROM RequestRateLimits WHERE BucketKey=?')->execute([hash('sha256','healthqueue|'.$scope.'|'.strtolower(trim($identity)))]);
}
