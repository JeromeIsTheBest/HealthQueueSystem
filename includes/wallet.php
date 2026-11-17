<?php
/**
 * Simulated patient wallet. Every change to Users.WalletBalance goes
 * through one of these functions so a WalletTransactions row is always
 * written alongside it -- the balance is never updated on its own.
 * No real money moves anywhere in this file.
 *
 * Each function reads the balance with SELECT ... FOR UPDATE, so the
 * caller must already be inside a PDO transaction (beginTransaction/
 * commit/rollBack) before calling -- these functions never manage the
 * transaction themselves, since callers often need to combine a wallet
 * change with another update (e.g. marking BookingFeePaid) atomically.
 */

/** Adds funds to a patient's wallet (a simulated top-up). Caller must be inside a transaction. */
function walletTopUp(PDO $pdo, int $patientId, float $amount, string $description, ?string $requestKey = null): float
{
    $stmt = $pdo->prepare('SELECT WalletBalance FROM Users WHERE UserID = ? FOR UPDATE');
    $stmt->execute([$patientId]);
    $balance=(float)$stmt->fetchColumn();
    if ($requestKey !== null) {
        if(!preg_match('/\A[a-f0-9]{64}\z/',$requestKey))throw new RuntimeException('Refresh the wallet page and try again.');
        $existing=$pdo->prepare('SELECT PatientID FROM WalletTransactions WHERE RequestKey=? FOR UPDATE');$existing->execute([$requestKey]);$owner=$existing->fetchColumn();
        if($owner!==false){if((int)$owner!==$patientId)throw new RuntimeException('Invalid top-up request.');return $balance;}
    }
    $newBalance = round($balance + $amount, 2);

    $pdo->prepare('UPDATE Users SET WalletBalance = ? WHERE UserID = ?')->execute([$newBalance, $patientId]);
    $pdo->prepare(
        'INSERT INTO WalletTransactions (PatientID, Type, Amount, BalanceAfter, Description, RequestKey) VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$patientId, 'TopUp', $amount, $newBalance, $description, $requestKey]);

    return $newBalance;
}

/** Deducts funds for a booking payment. Returns false (no changes made) if the balance is insufficient. Caller must be inside a transaction. */
function walletDeduct(PDO $pdo, int $patientId, float $amount, ?int $appointmentId, string $description): bool
{
    $stmt = $pdo->prepare('SELECT WalletBalance FROM Users WHERE UserID = ? FOR UPDATE');
    $stmt->execute([$patientId]);
    $currentBalance = (float) $stmt->fetchColumn();

    if ($currentBalance < $amount) {
        return false;
    }

    $newBalance = round($currentBalance - $amount, 2);
    $pdo->prepare('UPDATE Users SET WalletBalance = ? WHERE UserID = ?')->execute([$newBalance, $patientId]);
    $pdo->prepare(
        'INSERT INTO WalletTransactions (PatientID, Type, Amount, BalanceAfter, RelatedAppointmentID, Description) VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$patientId, 'BookingPayment', $amount, $newBalance, $appointmentId, $description]);

    return true;
}

/** Original wallet debit, never the clinic's mutable current fee. */
function walletBookingAmount(PDO $pdo,int $patientId,int $appointmentId): float
{
    $stmt=$pdo->prepare("SELECT COALESCE(SUM(Amount),0) FROM WalletTransactions WHERE PatientID=? AND RelatedAppointmentID=? AND Type='BookingPayment'");
    $stmt->execute([$patientId,$appointmentId]);return round((float)$stmt->fetchColumn(),2);
}

/** Refunds funds back into a patient's wallet (e.g. after a cancellation). Caller must be inside a transaction. */
function walletRefund(PDO $pdo, int $patientId, float $amount, ?int $appointmentId, string $description): float
{
    $stmt = $pdo->prepare('SELECT WalletBalance FROM Users WHERE UserID = ? FOR UPDATE');
    $stmt->execute([$patientId]);
    $currentBalance = (float) $stmt->fetchColumn();
    if ($appointmentId !== null) {
        $existing = $pdo->prepare("SELECT COUNT(*) FROM WalletTransactions WHERE RelatedAppointmentID = ? AND Type = 'Refund'");
        $existing->execute([$appointmentId]);
        if ((int) $existing->fetchColumn()) return $currentBalance;
    }
    if ($appointmentId !== null) $amount=walletBookingAmount($pdo,$patientId,$appointmentId);
    if ($amount <= 0) return $currentBalance;
    $newBalance = round($currentBalance + $amount, 2);

    $pdo->prepare('UPDATE Users SET WalletBalance = ? WHERE UserID = ?')->execute([$newBalance, $patientId]);
    $pdo->prepare(
        'INSERT INTO WalletTransactions (PatientID, Type, Amount, BalanceAfter, RelatedAppointmentID, Description) VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$patientId, 'Refund', $amount, $newBalance, $appointmentId, $description]);

    return $newBalance;
}

/** Cancels an active booking, removes every live queue ticket, and refunds at most once. Caller owns transaction. */
function cancelBooking(PDO $pdo, int $appointmentId, int $patientId, string $reason, string $description): bool
{
    $stmt = $pdo->prepare("SELECT a.Status, a.BookingFeePaid, c.ClinicName, c.BaseConsultationFee FROM Appointments a JOIN Clinic c ON c.ClinicID = a.ClinicID WHERE a.AppointmentID = ? AND a.PatientID = ? FOR UPDATE");
    $stmt->execute([$appointmentId, $patientId]);
    $booking = $stmt->fetch();
    if (!$booking || !in_array($booking['Status'], ['Pending', 'Confirmed'], true)) return false;

    $pdo->prepare("UPDATE Appointments SET Status = 'Cancelled', CancellationReason = ? WHERE AppointmentID = ?")
        ->execute([$reason, $appointmentId]);
    $pdo->prepare("UPDATE Queue SET Status = 'Removed' WHERE AppointmentID = ? AND Status IN ('Waiting', 'Calling', 'Serving')")
        ->execute([$appointmentId]);
    $pdo->prepare("UPDATE AppointmentRescheduleRequests SET Status = 'Withdrawn', ReviewedAt = NOW() WHERE AppointmentID = ? AND Status = 'Pending'")
        ->execute([$appointmentId]);
    if ((int) $booking['BookingFeePaid']) {
        $refunded = $pdo->prepare("SELECT COUNT(*) FROM WalletTransactions WHERE RelatedAppointmentID = ? AND Type = 'Refund'");
        $refunded->execute([$appointmentId]);
        if (!(int) $refunded->fetchColumn()) {
            walletRefund($pdo, $patientId, walletBookingAmount($pdo,$patientId,$appointmentId), $appointmentId, $description . $booking['ClinicName']);
        }
    }
    return true;
}

/** Atomically pay a pending booking once. Caller owns the transaction. */
function payBookingFromWallet(PDO $pdo, int $patientId, int $appointmentId): array
{
    if (!$pdo->inTransaction()) throw new LogicException('Booking payment requires a transaction.');
    $stmt = $pdo->prepare('SELECT a.Status, a.BookingFeePaid, c.BaseConsultationFee, c.ClinicName FROM Appointments a JOIN Clinic c ON c.ClinicID = a.ClinicID WHERE a.AppointmentID = ? AND a.PatientID = ? FOR UPDATE');
    $stmt->execute([$appointmentId, $patientId]);
    $booking = $stmt->fetch();
    if (!$booking) throw new RuntimeException('This appointment is unavailable.');
    if ((int) $booking['BookingFeePaid']) return ['paid' => true, 'already_paid' => true];
    if ($booking['Status'] !== 'Pending') throw new RuntimeException('This request can no longer be paid for.');
    $fee = round((float) $booking['BaseConsultationFee'], 2);
    if (!is_finite($fee) || $fee < 0) throw new RuntimeException('The booking fee is unavailable. Please contact the clinic.');
    if (!walletDeduct($pdo, $patientId, $fee, $appointmentId, 'Booking fee for ' . $booking['ClinicName'])) {
        throw new RuntimeException('Your wallet balance is not enough. Add funds to your wallet, then try again.');
    }
    $pdo->prepare("UPDATE Appointments SET BookingFeePaid = 1, BookingFeePaidAt = NOW(), BookingPaymentMethod = 'wallet' WHERE AppointmentID = ? AND PatientID = ?")
        ->execute([$appointmentId, $patientId]);
    return ['paid' => true, 'already_paid' => false];
}
