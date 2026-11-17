// Mobile nav toggle
document.addEventListener('DOMContentLoaded', function () {
  var toggle = document.getElementById('navToggle');
  var navLinks = document.querySelector('.nav-links');

  if (!toggle || !navLinks) return;

  toggle.addEventListener('click', function () {
    var open = navLinks.classList.toggle('is-open');
    toggle.setAttribute('aria-expanded', String(open));
  });

  navLinks.querySelectorAll('a').forEach(function (link) {
    link.addEventListener('click', function () {
      navLinks.classList.remove('is-open');
      toggle.setAttribute('aria-expanded', 'false');
    });
  });
});

// Admin sidebar toggle -- two buttons sharing the same job, shown one at a
// time by breakpoint: a "desktop" one beside the HealthQueue logo (collapses
// the sidebar to an icon rail, remembered in localStorage across page loads)
// and a "mobile" one in the topbar (opens/closes the off-canvas drawer --
// it has to live outside the sidebar itself since the drawer is translated
// off-screen when closed, so a trigger placed inside it would be unreachable).
document.addEventListener('DOMContentLoaded', function () {
  var toggles = document.querySelectorAll('.admin-sidebar-toggle');
  var sidebar = document.getElementById('adminSidebar');
  var scrim = document.getElementById('adminSidebarScrim');

  if (!toggles.length || !sidebar) return;

  var mobileQuery = window.matchMedia('(max-width: 980px)');
  var appPortal = document.body.classList.contains('app-portal');
  var mobileToggle = document.querySelector('.admin-sidebar-toggle-mobile');

  function setAriaExpanded(expanded) {
    toggles.forEach(function (btn) { btn.setAttribute('aria-expanded', String(expanded)); });
  }

  function setOpen(open) {
    sidebar.classList.toggle('is-open', open);
    if (scrim) scrim.classList.toggle('is-open', open);
    setAriaExpanded(open);
    if (appPortal) {
      document.body.classList.toggle('is-app-menu-open', open && mobileQuery.matches);
      sidebar.inert = mobileQuery.matches && !open;
      if (open) {
        var firstLink = sidebar.querySelector('.admin-nav-link');
        if (firstLink) firstLink.focus();
      } else if (mobileQuery.matches && mobileToggle) {
        mobileToggle.focus();
      }
    }
  }

  function setCollapsed(collapsed) {
    sidebar.classList.toggle('is-collapsed', collapsed);
    setAriaExpanded(!collapsed);
    try { localStorage.setItem('hqSidebarCollapsed', String(collapsed)); } catch (e) { /* storage unavailable -- ignore */ }
  }

  // Sync both buttons' aria-expanded with whatever state the inline
  // flash-prevention script (in header.php) already applied before this ran.
  if (!mobileQuery.matches) {
    setAriaExpanded(!sidebar.classList.contains('is-collapsed'));
  }

  toggles.forEach(function (toggle) {
    toggle.addEventListener('click', function () {
      if (mobileQuery.matches) {
        setOpen(!sidebar.classList.contains('is-open'));
      } else {
        setCollapsed(!sidebar.classList.contains('is-collapsed'));
      }
    });
  });

  if (scrim) {
    scrim.addEventListener('click', function () { setOpen(false); });
  }

  sidebar.querySelectorAll('a').forEach(function (link) {
    link.addEventListener('click', function () { setOpen(false); });
  });

  if (appPortal) {
    sidebar.inert = mobileQuery.matches && !sidebar.classList.contains('is-open');
    document.addEventListener('keydown', function (event) {
      if (!mobileQuery.matches || !sidebar.classList.contains('is-open')) return;
      if (event.key === 'Escape') { event.preventDefault(); setOpen(false); }
      if (event.key === 'Tab') {
        var links = sidebar.querySelectorAll('a[href], button:not([disabled])');
        var items = Array.from(links).filter(function (item) { return item.getClientRects().length; });
        var first = items[0], last = items[items.length - 1];
        if (!first) return;
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
      }
    });
    mobileQuery.addEventListener('change', function () {
      setOpen(false);
      sidebar.inert = mobileQuery.matches;
      if (!mobileQuery.matches) setAriaExpanded(!sidebar.classList.contains('is-collapsed'));
    });
  }
});

// Generic modal open/close: any [data-modal-open="id"] trigger shows the
// element with that id, any [data-modal-close] (or a click on the overlay
// itself) closes its containing .modal-overlay, and Escape closes whichever
// modal is currently open. Visibility is driven purely by the "is-open"
// class (see style.css) so opening/closing always animates smoothly --
// centered modals fade+scale, .modal-drawer ones slide in from the right.
window.hqOpenModal = function (modal) {
  if (!modal) return;
  modal.classList.add('is-open');
  var firstInput = modal.querySelector('input, select, textarea');
  if (firstInput) firstInput.focus();
};

window.hqCloseModal = function (modal) {
  if (modal) modal.classList.remove('is-open');
  if (modal && modal.id === 'notificationDetailModal') {
    document.body.classList.remove('is-notification-modal-open');
    if (modal.notificationTrigger) modal.notificationTrigger.focus();
  }
};

document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-modal-open]').forEach(function (trigger) {
    trigger.addEventListener('click', function () {
      window.hqOpenModal(document.getElementById(trigger.getAttribute('data-modal-open')));
    });
  });

  document.querySelectorAll('.modal-overlay').forEach(function (overlay) {
    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) window.hqCloseModal(overlay);
    });
    overlay.querySelectorAll('[data-modal-close]').forEach(function (btn) {
      btn.addEventListener('click', function () { window.hqCloseModal(overlay); });
    });
  });

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    document.querySelectorAll('.modal-overlay.is-open').forEach(window.hqCloseModal);
  });
});

// Confirm-before-submit: a button with [data-confirm-modal="modalId"] opens
// that modal instead of submitting its form directly. The modal's own
// [data-confirm-submit] button then submits whichever form the trigger
// belonged to -- replaces native confirm() dialogs with the app's own
// modal style. One shared modal can serve many buttons on the same page.
//
// A modal field tagged [data-confirm-field="formFieldName"] has its value
// copied into the matching hidden input (same "name") on the target form
// just before submitting -- e.g. a reason-for-cancellation select. If the
// chosen <option> has data-other="true", the value instead comes from
// whichever field carries [data-confirm-field-for="formFieldName"] (a
// free-text "please specify" input revealed only for that option).
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-confirm-modal]').forEach(function (trigger) {
    trigger.addEventListener('click', function () {
      var modal = document.getElementById(trigger.getAttribute('data-confirm-modal'));
      if (!modal) return;
      var form = trigger.closest('form');
      var confirmBtn = modal.querySelector('[data-confirm-submit]');
      if (confirmBtn) {
        confirmBtn.onclick = function () {
          if (!form) return;
          modal.querySelectorAll('[data-confirm-field]').forEach(function (field) {
            var name = field.getAttribute('data-confirm-field');
            var input = form.querySelector('[name="' + name + '"]');
            if (!input) return;
            var value = field.value;
            var selectedOption = field.tagName === 'SELECT' ? field.options[field.selectedIndex] : null;
            if (selectedOption && selectedOption.getAttribute('data-other') === 'true') {
              var otherField = modal.querySelector('[data-confirm-field-for="' + name + '"]');
              value = otherField ? otherField.value : '';
            }
            input.value = value;
          });
          form.submit();
        };
      }
      window.hqOpenModal(modal);
    });
  });
});

// Lightweight month-grid calendar picker (no external library). Renders
// into a container holding .calendar-month-label / [data-nav] / .calendar-days
// markup, writes the chosen date (YYYY-MM-DD) into hiddenInput, and disables
// any day before today. Used by the booking modals' date field.
//
// options.isEnabled(iso) -> false greys out a date (e.g. no physician hours);
// options.noteFor(iso) -> text such as "Fully booked" shown as a tooltip;
// options.onMonthChange(year, monthIndex) fires when the shown month changes.
// Returns { render, clear } so callers can refresh after loading data.
window.hqInitCalendar = function (container, hiddenInput, onSelect, options) {
  if (!container || !hiddenInput) return null;
  options = options || {};

  var monthLabel = container.querySelector('.calendar-month-label');
  var daysEl = container.querySelector('.calendar-days');
  var prevBtn = container.querySelector('[data-nav="-1"]');
  var nextBtn = container.querySelector('[data-nav="1"]');
  var monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

  var today = new Date();
  today.setHours(0, 0, 0, 0);
  var viewYear = today.getFullYear();
  var viewMonth = today.getMonth();
  var selectedDate = hiddenInput.value || null;

  function pad(n) { return n < 10 ? '0' + n : String(n); }
  function toIso(y, m, d) { return y + '-' + pad(m + 1) + '-' + pad(d); }

  function render() {
    monthLabel.textContent = monthNames[viewMonth] + ' ' + viewYear;
    daysEl.innerHTML = '';

    var firstWeekday = new Date(viewYear, viewMonth, 1).getDay();
    var daysInMonth = new Date(viewYear, viewMonth + 1, 0).getDate();

    for (var i = 0; i < firstWeekday; i++) {
      var blank = document.createElement('span');
      blank.className = 'calendar-day-blank';
      daysEl.appendChild(blank);
    }

    for (let d = 1; d <= daysInMonth; d++) {
      let iso = toIso(viewYear, viewMonth, d);
      let cellDate = new Date(viewYear, viewMonth, d);
      let btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'calendar-day';
      btn.textContent = String(d);

      if (cellDate.getTime() < today.getTime() || (options.isEnabled && !options.isEnabled(iso))) {
        btn.disabled = true;
      }
      var note = options.noteFor ? options.noteFor(iso) : '';
      if (note) {
        btn.title = note;
        btn.classList.add('is-full');
        btn.setAttribute('aria-label', d + ' — ' + note);
      }
      if (cellDate.getTime() === today.getTime()) {
        btn.classList.add('is-today');
      }
      if (iso === selectedDate) {
        btn.classList.add('is-selected');
      }

      btn.addEventListener('click', function () {
        selectedDate = iso;
        hiddenInput.value = iso;
        render();
        if (onSelect) onSelect(iso);
      });

      daysEl.appendChild(btn);
    }

    if (prevBtn) prevBtn.disabled = (viewYear === today.getFullYear() && viewMonth === today.getMonth());
  }

  if (prevBtn) {
    prevBtn.addEventListener('click', function () {
      viewMonth--;
      if (viewMonth < 0) { viewMonth = 11; viewYear--; }
      render();
      if (options.onMonthChange) options.onMonthChange(viewYear, viewMonth);
    });
  }
  if (nextBtn) {
    nextBtn.addEventListener('click', function () {
      viewMonth++;
      if (viewMonth > 11) { viewMonth = 0; viewYear++; }
      render();
      if (options.onMonthChange) options.onMonthChange(viewYear, viewMonth);
    });
  }

  render();
  if (options.onMonthChange) options.onMonthChange(viewYear, viewMonth);

  return {
    render: render,
    clear: function () { selectedDate = null; hiddenInput.value = ''; render(); }
  };
};

// Notification rows: tap a row to expand its message; tick checkboxes to
// choose which ones to delete ("Select all" + "Delete selected").
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.notification-list').forEach(function (list) {
    var form = list.closest('form');
    if (!form) return;
    var boxes = Array.prototype.slice.call(list.querySelectorAll('.notif-check'));
    var selectAll = form.querySelector('.notif-select-all');
    var deleteBtn = form.querySelector('.notification-delete-selected');
    var countEl = form.querySelector('.notification-selection-count');
    var selectionActions = form.querySelector('.notif-selection-actions');
    var defaultActions = form.querySelector('.notif-default-actions');
    var selectionStart = form.querySelector('[data-selection-start]');
    var selectionCancel = form.querySelector('[data-selection-cancel]');
    var selectionMode = false;
    var canSelect = !!selectionActions;

    function setSelectionMode(active) {
      selectionMode = active;
      form.classList.toggle('is-selection-mode', active);
      selectionActions.hidden = !active;
      defaultActions.hidden = active;
      if (!active) boxes.forEach(function (box) { box.checked = false; });
      update();
    }
    if (canSelect) {
      form.classList.add('notification-selection-ready');
      if (selectionStart) selectionStart.hidden = false;
      selectionCancel.hidden = false;
      setSelectionMode(false);
      function focusSelectionOrigin() {
        var target = selectionStart || list.querySelector('.notif-message-toggle');
        if (target) target.focus();
      }
      if (selectionStart) selectionStart.addEventListener('click', function () { setSelectionMode(true); selectAll.focus(); });
      selectionCancel.addEventListener('click', function () { setSelectionMode(false); focusSelectionOrigin(); });
      form.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && selectionMode) {
          event.preventDefault(); setSelectionMode(false); focusSelectionOrigin();
        }
      });
    }

    function update() {
      var checked = boxes.filter(function (box) { return box.checked; }).length;
      boxes.forEach(function (box) { box.closest('.notification-row').classList.toggle('is-selected', box.checked); });
      if (countEl) countEl.textContent = String(checked);
      if (deleteBtn) deleteBtn.disabled = checked === 0;
      if (selectAll) {
        selectAll.checked = checked > 0 && checked === boxes.length;
        selectAll.indeterminate = checked > 0 && checked < boxes.length;
      }
    }

    boxes.forEach(function (box) { box.addEventListener('change', update); });
    if (selectAll) {
      selectAll.addEventListener('change', function () {
        boxes.forEach(function (box) { box.checked = selectAll.checked; });
        update();
      });
    }

    // Clicking the row (but not its checkbox or buttons) opens it: the full
    // message plus a "Delete notification" button for just that one.
    function markOpenedNotificationRead(row, modal) {
      var id = row.getAttribute('data-id');
      var button = document.getElementById('notificationDetailMarkRead');
      var error = document.getElementById('notificationReadError');
      if (error) { error.hidden = true; error.textContent = ''; }
      if (row.getAttribute('data-read') === '1') return;
      button.hidden = true;
      button.disabled = true;
      button.textContent = 'Marking as read...';
      if (row.readRequest) return;
      var body = new URLSearchParams();
      body.set('form_type', 'auto_mark_read');
      body.set('notification_id', id);
      body.set('csrf_token', form.querySelector('[name="csrf_token"]').value);
      row.readRequest = fetch(window.location.href, { method: 'POST', body: body, credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (response) { return response.json().then(function (data) { if (!response.ok || !data.ok) throw new Error(data.message || 'We could not mark this notification as read. Please try again.'); return data; }); })
        .then(function (data) {
          row.setAttribute('data-read', '1');
          row.classList.add('is-read');
          var badge = row.querySelector(':scope > .status-badge');
          if (badge) badge.remove();
          document.querySelectorAll('.admin-nav-link[href$="/notifications.php"] .admin-nav-badge').forEach(function (badge) {
            if (data.unread > 0) badge.textContent = String(data.unread); else badge.remove();
          });
          if (data.unread === 0) document.querySelectorAll('.header-icon-btn[href$="/notifications.php"] .header-icon-badge').forEach(function (badge) { badge.remove(); });
          if (document.getElementById('notificationDetailId').value === id) {
            document.getElementById('notificationDetailStatus').textContent = 'Read';
            button.hidden = true; button.disabled = true; button.textContent = 'Already read';
          }
        })
        .catch(function (failure) {
          if (document.getElementById('notificationDetailId').value !== id) return;
          button.hidden = false; button.disabled = false; button.textContent = 'Mark as read';
          if (error) { error.textContent = failure.message || 'We could not mark this notification as read. Please try again.'; error.hidden = false; }
        })
        .finally(function () { row.readRequest = null; });
    }
    list.querySelectorAll('.notification-row').forEach(function (row) {
      var holdTimer = null;
      var startX = 0, startY = 0;
      var suppressClick = false;
      function cancelHold() { clearTimeout(holdTimer); holdTimer = null; }
      if (canSelect) {
        row.addEventListener('pointerdown', function (event) {
          cancelHold(); suppressClick = false;
          if (!event.isPrimary || event.button !== 0 || event.target.closest('.notif-check-wrap, .notif-row-actions')) return;
          startX = event.clientX; startY = event.clientY;
          holdTimer = setTimeout(function () {
            setSelectionMode(true);
            row.querySelector('.notif-check').checked = true;
            update(); suppressClick = true; holdTimer = null;
          }, 550);
        });
        row.addEventListener('pointermove', function (event) {
          if (Math.abs(event.clientX - startX) > 10 || Math.abs(event.clientY - startY) > 10) cancelHold();
        });
        ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (name) { row.addEventListener(name, cancelHold); });
        window.addEventListener('scroll', cancelHold, { passive: true, capture: true });
        row.addEventListener('contextmenu', function (event) { event.preventDefault(); });
      }
      row.addEventListener('click', function (e) {
        if (e.target.closest('.notif-check-wrap, .notif-row-actions')) return;
        if (suppressClick) { suppressClick = false; e.preventDefault(); return; }
        if (canSelect && selectionMode) {
          var box = row.querySelector('.notif-check');
          box.checked = !box.checked; update(); return;
        }
        var detailModal = document.getElementById('notificationDetailModal');
        if (detailModal) {
          var read = row.getAttribute('data-read') === '1';
          var time = row.querySelector('.notif-time');
          document.getElementById('notificationDetailMessage').textContent = row.querySelector('.notif-message').textContent;
          document.getElementById('notificationDetailTime').textContent = time.textContent;
          document.getElementById('notificationDetailTime').dateTime = time.dateTime;
          document.getElementById('notificationDetailStatus').textContent = read ? 'Read' : 'Unread';
          document.getElementById('notificationDetailId').value = row.getAttribute('data-id');
          document.getElementById('notificationDetailDeleteId').value = row.getAttribute('data-id');
          document.getElementById('notificationDetailMarkRead').hidden = true;
          document.getElementById('notificationDetailMarkRead').disabled = read;
          document.getElementById('notificationDetailMarkRead').textContent = read ? 'Already read' : 'Mark as read';
          detailModal.notificationTrigger = row.querySelector('.notif-message-toggle');
          window.hqOpenModal(detailModal);
          document.body.classList.add('is-notification-modal-open');
          detailModal.querySelector('.modal-close').focus();
          markOpenedNotificationRead(row, detailModal);
          return;
        }
        var open = row.classList.toggle('is-open');
        var msg = row.querySelector('.notif-message');
        if (msg) msg.classList.toggle('expanded', open);
        var trigger = row.querySelector('.notif-message-toggle');
        if (trigger) {
          trigger.setAttribute('aria-expanded', String(open));
          var label = trigger.querySelector('.notif-expand-label');
          if (label) label.textContent = open ? 'Hide details' : 'Show details';
        }
      });
    });

    list.querySelectorAll('[data-delete-id]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (!confirm('Delete this notification?')) return;
        // Only this one -- untick anything else so it isn't deleted too.
        boxes.forEach(function (box) { box.checked = false; });
        [['notification_ids[]', btn.getAttribute('data-delete-id')], ['form_type', 'delete_selected']].forEach(function (pair) {
          var input = document.createElement('input');
          input.type = 'hidden';
          input.name = pair[0];
          input.value = pair[1];
          form.appendChild(input);
        });
        form.submit();
      });
    });

    if (deleteBtn) {
      deleteBtn.addEventListener('click', function (e) {
        var checked = boxes.filter(function (box) { return box.checked; }).length;
        if (!checked || !confirm('Delete ' + checked + ' selected notification' + (checked === 1 ? '' : 's') + '?')) {
          e.preventDefault();
        }
      });
    }

    update();
  });
  var detailModal = document.getElementById('notificationDetailModal');
  if (detailModal) detailModal.addEventListener('keydown', function (event) {
    if (event.key !== 'Tab' || !detailModal.classList.contains('is-open')) return;
    var buttons = Array.from(detailModal.querySelectorAll('button:not([disabled])')).filter(function (button) { return button.getClientRects().length; });
    var first = buttons[0], last = buttons[buttons.length - 1];
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
  });
});

// Pay booking fee panel (includes/payment-panel.php): highlight the chosen
// method, show card fields for "card" and a demo note for e-wallets.
function hqSyncPayMethods(container) {
  var checked = container.querySelector('input[type="radio"]:checked');
  var method = checked ? checked.value : '';
  container.querySelectorAll('.pay-method').forEach(function (label) {
    label.classList.toggle('is-selected', label.getAttribute('data-method') === method);
  });
  var card = container.querySelector('[data-card-fields]');
  if (card) card.hidden = method !== 'card';
  var ewallet = container.querySelector('[data-ewallet-note]');
  if (ewallet) ewallet.hidden = method !== 'gcash' && method !== 'maya';
}
document.addEventListener('change', function (e) {
  var container = e.target.closest && e.target.closest('[data-pay-methods]');
  if (container) hqSyncPayMethods(container);
});
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-pay-methods]').forEach(hqSyncPayMethods);
});

// Sets the wallet option's balance text and whether it can be used.
window.hqSetWallet = function (container, balanceText, canPay) {
  var radio = container.querySelector('input[value="wallet"]');
  var label = container.querySelector('.pay-method-wallet');
  var note = container.querySelector('[data-wallet-note]');
  if (!radio || !label) return;
  radio.disabled = !canPay;
  label.classList.toggle('is-disabled', !canPay);
  if (note) {
    note.textContent = '₱' + balanceText + ' balance' + (canPay ? '' : ' · not enough');
    note.classList.toggle('is-short', !canPay);
  }
  container.querySelectorAll('input[type="radio"]').forEach(function (option) { option.checked = canPay && option.value === 'wallet'; });
  hqSyncPayMethods(container);
};

// "Slot held for 14:24" countdown for an unpaid booking.
window.hqStartHold = function (el, seconds) {
  if (!el) return;
  clearInterval(el._holdTimer);
  var left = Math.max(0, parseInt(seconds, 10) || 0);
  el.querySelector('span').innerHTML = 'Slot held for <b data-hold-left></b>';
  var out = el.querySelector('[data-hold-left]');
  var tick = function () {
    if (left <= 0) {
      el.classList.add('is-expired');
      el.querySelector('span').textContent = 'Hold expired — pay now to keep the slot if it is still free';
      clearInterval(el._holdTimer);
      return;
    }
    el.classList.remove('is-expired');
    if (out) out.textContent = Math.floor(left / 60) + ':' + String(left % 60).padStart(2, '0');
    left--;
  };
  tick();
  el._holdTimer = setInterval(tick, 1000);
};
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-hold-seconds]').forEach(function (el) {
    if (el.getAttribute('data-hold-seconds') !== '') window.hqStartHold(el, el.getAttribute('data-hold-seconds'));
  });
});

// Shared mobile-number input rules for every role and public form.
document.addEventListener('DOMContentLoaded', function () {
  var selector = 'input[name="contact_number"], input[name="emergency_contact_number"], input[name="phone"]';
  document.querySelectorAll(selector).forEach(function (input) {
    input.setAttribute('inputmode', 'numeric');
    input.setAttribute('maxlength', '11');
    input.setAttribute('pattern', '09[0-9]{9}');
    var message = 'Enter a valid mobile number';
    var error = document.createElement('span');
    error.className = 'hq-mobile-error';
    error.id = 'hq-mobile-error-' + Array.from(document.querySelectorAll(selector)).indexOf(input);
    error.hidden = true;
    error.setAttribute('aria-live', 'polite');
    input.insertAdjacentElement('afterend', error);
    input.setAttribute('aria-describedby', [input.getAttribute('aria-describedby'), error.id].filter(Boolean).join(' '));
    function validate(showError) {
      var invalid = input.value !== '' && !/^09[0-9]{9}$/.test(input.value);
      input.setCustomValidity(invalid ? message : '');
      error.textContent = invalid && showError ? message : '';
      error.hidden = !invalid || !showError;
      if (invalid && showError) input.setAttribute('aria-invalid', 'true');
      else input.removeAttribute('aria-invalid');
    }
    input.addEventListener('blur', function () { validate(true); });
    input.addEventListener('invalid', function () { validate(true); });
    validate(false);
    input.addEventListener('beforeinput', function (event) {
      if (!event.isComposing && event.data && /[^0-9]/.test(event.data)) event.preventDefault();
    });
    input.addEventListener('input', function () {
      var start = input.selectionStart;
      var prefix = input.value.slice(0, start === null ? input.value.length : start);
      var clean = input.value.replace(/[^0-9]/g, '').slice(0, 11);
      if (input.value !== clean) {
        input.value = clean;
        var position = Math.min(prefix.replace(/[^0-9]/g, '').length, 11);
        input.setSelectionRange(position, position);
      }
      validate(!error.hidden);
    });
    input.addEventListener('paste', function (event) {
      var pasted = (event.clipboardData || window.clipboardData).getData('text');
      if (/[^0-9]/.test(pasted)) event.preventDefault();
    });
  });
});
