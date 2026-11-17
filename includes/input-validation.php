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

function hqValidCalendarValue(string $value,string $format='Y-m-d'):bool
{
 $date=DateTimeImmutable::createFromFormat('!'.$format,$value);return $date!==false && $date->format($format)===$value;
}
