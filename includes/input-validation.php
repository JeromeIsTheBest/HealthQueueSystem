<?php
/** Capitalize name initials without lowercasing intentional internal capitals. */
function hqCapitalizeName(string $name): string
{
    return preg_replace_callback('/(^|[\s\-])([\p{L}])/u', static fn(array $m): string => $m[1] . mb_strtoupper($m[2], 'UTF-8'), trim($name));
}

/** Optional phone fields may be empty; populated fields must contain 11 ASCII digits starting with 09. */
function hqValidMobile(string $number): bool
{
    return $number === '' || preg_match('/\A09[0-9]{9}\z/', $number) === 1;
}

/** Account mobile numbers are shared across all roles, including inactive accounts. */
function hqMobileInUse(PDO $pdo,string $number,int $exceptUserId=0):bool
{
    $number=trim($number);if($number==='')return false;
    $stmt=$pdo->prepare('SELECT UserID FROM Users WHERE TRIM(ContactNumber)=? AND UserID<>? LIMIT 1');
    $stmt->execute([$number,$exceptUserId]);return (bool)$stmt->fetchColumn();
}
function hqDuplicateMobileError(Throwable $error):bool
{
    return $error instanceof PDOException && (string)$error->getCode()==='23000'
        && str_contains($error->getMessage(),'uq_users_account_mobile');
}

function hqValidCalendarValue(string $value,string $format='Y-m-d'):bool
{
 $date=DateTimeImmutable::createFromFormat('!'.$format,$value);return $date!==false && $date->format($format)===$value;
}
