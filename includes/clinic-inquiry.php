<?php
/** Match DECIMAL(10,2) without accepting exponents, negative values or excess precision. */
function validClinicInquiryPrice(string $price): bool
{
    return preg_match('/\A[0-9]{1,8}(?:\.[0-9]{1,2})?\z/', $price) === 1;
}

/** The review controller holds the inquiry row lock and owns this transaction. */
function clinicFromRegistrationInquiry(PDO $pdo, array $inquiry): int
{
    if (!$pdo->inTransaction()) throw new LogicException('Clinic approval requires a transaction.');
    $stmt=$pdo->prepare('SELECT ClinicID FROM Clinic WHERE ClinicName=? AND archived=0 LIMIT 1 FOR UPDATE');
    $stmt->execute([$inquiry['ClinicName']]); $existing=$stmt->fetchColumn();
    // A public inquiry must never overwrite an existing clinic's configured fee.
    if ($existing) return (int)$existing;
    $price=$inquiry['BaseConsultationFee'] ?? '0.00';
    if (!validClinicInquiryPrice((string)$price)) throw new RuntimeException('The consultation base price is invalid.');
    $pdo->prepare("INSERT INTO Clinic(ClinicName,Address,ContactNumber,BaseConsultationFee,OpenDays,OpenTime,CloseTime) VALUES(?,?,?,?,'','00:00','00:00')")->execute([$inquiry['ClinicName'],$inquiry['City'],$inquiry['Phone'],$price]);
    return (int)$pdo->lastInsertId();
}
