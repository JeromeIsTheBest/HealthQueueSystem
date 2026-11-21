<?php // Staff-only controller supplies a clinic-scoped list and CSRF-protected handlers. ?>
<p class="form-message warning">Review the actual outcome before closing a past visit. Completion requires the latest finalized note. A no-show retains any paid booking fee and does not earn consultation commission.</p>
<?php if(!$expiredList): ?><div class="ma-empty"><h3>No past visits need review</h3></div><?php endif; ?>
<div class="sa-list"><?php foreach($expiredList as $item): ?>
<article class="sa-item sa-row"><div class="sa-row-main"><strong><?= htmlspecialchars($item['FirstName'].' '.$item['LastName']) ?></strong><p><?= htmlspecialchars($item['AppointmentDate']) ?> · <?= htmlspecialchars($item['PhysicianName']?:'Unassigned physician') ?> · Latest note: <?= htmlspecialchars($item['LastNoteStatus']?:'Not recorded') ?></p></div>
<?php if($item['Status']==='Pending'): ?>
<form method="post" onsubmit="return confirm('Decline this expired pending request? Any eligible wallet payment is refunded once.');"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="appointment_id" value="<?= (int)$item['AppointmentID'] ?>"><input type="hidden" name="decline_reason" value="Appointment date passed; reviewed by the clinic"><button class="btn btn-outline btn-sm" name="form_type" value="reject_request">Decline expired request</button></form>
<?php elseif($item['LastNoteStatus']==='Finalized'): ?>
<a class="btn btn-outline btn-sm" href="appointments.php?tab=confirm#completion-<?= (int)$item['AppointmentID'] ?>">Review completion</a>
<?php elseif(!$item['HasFinalized']): ?>
<form method="post" onsubmit="return confirm('Confirm this patient did not attend? Any paid booking fee is retained.');"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="appointment_id" value="<?= (int)$item['AppointmentID'] ?>"><button class="btn btn-outline btn-sm" name="form_type" value="mark_no_show">Confirm no-show</button></form>
<?php else: ?><span class="status-badge">Physician must finalize the latest revision</span><?php endif; ?>
</article><?php endforeach; ?></div>
