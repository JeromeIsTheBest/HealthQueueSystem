<?php
require_once __DIR__.'/relationship-links.php';
/** Fixed-window limits; identities are hashed rather than stored as email/IP text. */
function consumeRequestLimit(PDO $pdo,string $scope,string $identity,int $limit,int $seconds=900,?int $userId=null):bool
{
 return consumeLinkedRequestLimit($pdo,$scope,$identity,$limit,$seconds,$userId);
}
function requestClientAddress():string{return (string)($_SERVER['REMOTE_ADDR']??'local');}

function clearRequestLimit(PDO $pdo,string $scope,string $identity):void
{
 $pdo->prepare('DELETE FROM RequestRateLimits WHERE BucketKey=?')->execute([hash('sha256','healthqueue|'.$scope.'|'.strtolower(trim($identity)))]);
}
