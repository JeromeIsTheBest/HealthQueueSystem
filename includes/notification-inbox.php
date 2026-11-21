<?php // Shared patient, physician and clinic notification presentation; controllers enforce ownership. ?>
<main class="portal-shell notifications-page"><div class="container">
  <section class="portal-hero"><div><span class="eyebrow"><?= htmlspecialchars($inboxEyebrow) ?></span><h1>Notifications</h1><p><?= htmlspecialchars($inboxDescription) ?></p></div></section>

  <?php if ($flash): ?><p class="form-message success" role="status"><?= htmlspecialchars($flash) ?></p><?php endif; ?>
  <?php if ($errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <?php if ($dataError): ?><p class="form-message error" role="alert"><?= htmlspecialchars($dataError) ?></p><?php endif; ?>

  <section class="portal-section">
    <?php if ($notifications): ?>
      <form method="post" class="notification-selection-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
        <div class="notif-toolbar">
          <div class="notif-selection-actions">
          <label class="notif-select-all-wrap"><input type="checkbox" class="notif-select-all"> Select all</label>
          <button type="button" class="btn btn-outline btn-sm" data-selection-cancel hidden>Cancel</button>
          <button type="submit" name="form_type" value="delete_selected" class="btn btn-outline btn-sm notification-delete-selected ma-danger" disabled><span>Delete selected (<span class="notification-selection-count">0</span>)</span></button>
          </div>
          <div class="notif-default-actions">
          <button type="submit" name="form_type" value="mark_all_read" class="btn btn-primary btn-sm">Mark All Read</button>
          <button type="submit" name="form_type" value="delete_all" class="btn btn-outline btn-sm" onclick="return confirm('Delete ALL notifications? This cannot be undone.');">Delete All</button>
          </div>
        </div>
        <div class="compact-list notification-list">
          <?php foreach ($notifications as $note): ?>
            <article class="notification-row<?= $note['IsRead'] ? ' is-read' : '' ?>" data-id="<?= (int) $note['NotificationID'] ?>" data-read="<?= $note['IsRead'] ? '1' : '0' ?>"<?php if(!empty($note['RecordAccessRequestID'])): ?> data-record-access-request="<?= (int)$note['RecordAccessRequestID'] ?>" data-record-access-status="<?= htmlspecialchars($note['RecordAccessStatus']??'Expired',ENT_QUOTES) ?>"<?php endif; ?><?php if(!empty($note['VisitCompletionAppointmentID'])): ?> data-visit-completion-appointment="<?= (int)$note['VisitCompletionAppointmentID'] ?>" data-visit-completion-status="<?= htmlspecialchars($note['VisitCompletionStatus']??'Closed',ENT_QUOTES) ?>"<?php endif; ?>>
              <label class="notif-check-wrap aria-label="Select notification"><input type="checkbox" class="notif-check" name="notification_ids[]" value="<?= (int) $note['NotificationID'] ?>"></label>
              <div class="notif-content">
                <button type="button" class="notif-message-toggle" aria-haspopup="dialog" aria-controls="notificationDetailModal">
                  <strong class="notif-message" id="notification-message-<?= (int) $note['NotificationID'] ?>"><?= htmlspecialchars($note['Message']) ?></strong>
                  <span class="notif-expand-label">View details</span>
                </button>
                <time class="notif-time" datetime="<?= htmlspecialchars(date('c', strtotime($note['CreatedAt']))) ?>"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($note['CreatedAt']))) ?></time>
                <div class="notif-row-actions" id="notification-actions-<?= (int) $note['NotificationID'] ?>">
                  <button type="button" class="btn btn-outline btn-sm ma-danger" data-delete-id="<?= (int) $note['NotificationID'] ?>">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                    Delete notification
                  </button>
                </div>
              </div>
              <?php if (!$note['IsRead']): ?><span class="status-badge">Unread</span><?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
      </form>
    <?php else: ?>
      <div class="empty-state"><div class="empty-icon">+</div><h3>No notifications yet</h3><p><?= htmlspecialchars($inboxEmptyDescription) ?></p></div>
    <?php endif; ?>
  </section>
</div></main>

<div class="modal-overlay" id="notificationDetailModal">
  <div class="modal-box notification-detail-box" role="dialog" aria-modal="true" aria-labelledby="notificationDetailTitle" aria-describedby="notificationDetailMessage">
    <button type="button" class="modal-close" data-modal-close aria-label="Close notification">&times;</button>
    <span class="eyebrow">Updates</span>
    <div class="notification-detail-heading">
      <h2 id="notificationDetailTitle">Notification details</h2>
      <span class="status-badge" id="notificationDetailStatus"></span>
    </div>
    <div class="notification-detail-meta"><time id="notificationDetailTime"></time></div>
    <p id="notificationDetailMessage" class="notification-detail-message"></p>
    <div id="notificationRecordAccess" class="notification-record-access" hidden>
      <p class="notification-record-access-info">Opening this message marks it Read. It does not approve sharing your records.</p>
      <button type="button" class="btn btn-primary" id="notificationReviewRecordAccess">Review request</button>
      <p id="notificationRecordAccessState" role="status"></p>
    </div>
    <div id="notificationVisitCompletion" class="notification-record-access" hidden>
      <p class="notification-record-access-info">Reading this message does not confirm your consultation. Review your visit to confirm completion or report an issue.</p>
      <button type="button" class="btn btn-primary" id="notificationReviewVisitCompletion">Review visit</button>
      <p id="notificationVisitCompletionState" role="status"></p>
      <a href="<?= HQ_BASE_URL ?>/patient/my-appointments.php">View My Appointments</a>
    </div>
    <p id="notificationReadError" class="form-message error" role="alert" hidden></p>
    <form method="post" class="notification-detail-actions">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
      <input type="hidden" name="notification_id" id="notificationDetailId">
      <input type="hidden" name="notification_ids[]" id="notificationDetailDeleteId">
      <button type="submit" name="form_type" value="mark_read" class="btn btn-primary" id="notificationDetailMarkRead">Mark as read</button>
      <button type="submit" name="form_type" value="delete_selected" class="btn btn-outline ma-danger" onclick="return confirm('Delete this notification?');">Delete notification</button>
    </form>
  </div>
</div>
