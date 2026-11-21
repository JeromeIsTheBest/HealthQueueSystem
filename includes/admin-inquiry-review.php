<?php
require_once __DIR__ . '/clinic-inquiry.php';
require_once __DIR__.'/relationship-links.php';
// Shared by the admin dashboard and clinic request list.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$pdo) {
        $errors[] = 'The database is temporarily unavailable.';
    } elseif (($_POST['form_type'] ?? '') === 'review_inquiry') {
        $inquiryId = filter_input(INPUT_POST, 'inquiry_id', FILTER_VALIDATE_INT);
        $decision = $_POST['decision'] ?? '';
        if (!$inquiryId || !in_array($decision, ['approve', 'reject'], true)) {
            $errors[] = 'Invalid registration action.';
        } else {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('SELECT * FROM ClinicRegistrationInquiry WHERE InquiryID = ? FOR UPDATE');
                $stmt->execute([$inquiryId]);
                $inquiry = $stmt->fetch();
                $newClinicId = null;
                if (!$inquiry || $inquiry['Status'] !== 'Pending') {
                    $errors[] = 'This registration inquiry has already been reviewed.';
                } elseif ($decision === 'reject') {
                    $pdo->prepare("UPDATE ClinicRegistrationInquiry SET Status = 'Rejected' WHERE InquiryID = ?")->execute([$inquiryId]);
                    $flash = 'The clinic registration inquiry was declined.';
                } else {
                    $newClinicId = approveClinicInquiryWithLink($pdo,(int)$inquiryId);
                    $flash = 'Clinic approved and added to the directory.';
                }
                if ($errors) {
                    $pdo->rollBack();
                } else {
                    $pdo->commit();
                    logActivity($pdo, $user['UserID'], $newClinicId, 'Reviewed clinic registration', $inquiry['ClinicName'] . ' — ' . ucfirst($decision) . 'd');
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Inquiry review failed: ' . $e->getMessage());
                $errors[] = 'We could not update this registration inquiry.';
            }
        }
    }
}
