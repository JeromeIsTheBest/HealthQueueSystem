document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-pw-open]').forEach(function (trigger) {
    trigger.addEventListener('click', function () {
      var dialog = document.getElementById(trigger.dataset.pwOpen);
      if (dialog) { dialog.pwTrigger = trigger; if (!dialog.open) dialog.showModal(); }
    });
  });
  document.querySelectorAll('.pw-dialog').forEach(function (dialog) {
    dialog.querySelectorAll('[data-pw-close]').forEach(function (button) { button.addEventListener('click', function () { dialog.close(); }); });
    dialog.addEventListener('close', function () { if (dialog.pwTrigger) dialog.pwTrigger.focus(); });
    dialog.addEventListener('click', function (event) {
      if (event.target !== dialog) return;
      var box=dialog.getBoundingClientRect();
      if (event.clientX<box.left||event.clientX>box.right||event.clientY<box.top||event.clientY>box.bottom) dialog.close();
    });
    if (dialog.dataset.pwAuto === '1') dialog.showModal();
  });
  var method=document.getElementById('withdrawalMethod');
  if (method) {
    function syncMethod() {
      var bank=method.value==='Bank';
      document.querySelectorAll('[data-pw-bank], [data-pw-mobile]').forEach(function (group) {
        var active=group.hasAttribute('data-pw-bank')?bank:!bank;
        group.hidden=!active;
        group.querySelectorAll('input').forEach(function (input) { input.disabled=!active; });
      });
    }
    method.addEventListener('change',syncMethod); syncMethod();
  }
  document.querySelectorAll('[data-self-withdrawal]').forEach(function (form) {
    var entry = form.querySelector('[data-pw-entry]');
    var confirmation = form.querySelector('[data-pw-confirmation]');
    var dialog = form.closest('dialog');
    var money = function (cents) { return 'PHP ' + (cents / 100).toLocaleString('en-PH', {minimumFractionDigits:2,maximumFractionDigits:2}); };
    function resetReview() { entry.hidden=false;confirmation.hidden=true;form.elements.confirm_simulation.value='0';delete form.dataset.reviewReady;delete form.dataset.submitting;form.removeAttribute('aria-busy'); }
    form.querySelector('[data-pw-back]').addEventListener('click', resetReview);
    dialog.addEventListener('close', resetReview);
    form.addEventListener('submit', function (event) {
      if (event.submitter && event.submitter.hasAttribute('data-pw-confirm') && form.dataset.reviewReady === '1') { form.elements.confirm_simulation.value='1';return; }
      event.preventDefault();
      var amount = Math.round(Number(form.elements.amount.value) * 100);
      var available = Number(form.dataset.availableCents);
      if (!Number.isFinite(amount) || amount <= 0 || amount > available) return;
      var bank = form.elements.payout_method.value === 'Bank';
      var number = form.elements[bank ? 'bank_account_number' : 'phone'].value;
      form.querySelector('[data-pw-amount]').textContent=money(amount);
      form.querySelector('[data-pw-method]').textContent=form.elements.payout_method.value;
      form.querySelector('[data-pw-account]').textContent=form.elements.account_name.value;
      form.querySelector('[data-pw-destination]').textContent=(bank ? form.elements.bank_name.value + ' ? ' : '')+'????'+number.slice(-4);
      form.querySelector('[data-pw-remaining]').textContent=money(available-amount);
      entry.hidden=true;confirmation.hidden=false;form.dataset.reviewReady='1';dialog.scrollTop=0;
      form.querySelector('[data-pw-confirm]').focus();
    });
  });
  document.querySelectorAll('.pw-form, .pw-form-actions').forEach(function (form) {
    if (form.tagName !== 'FORM') return;
    form.addEventListener('submit', function (event) {
      if (event.defaultPrevented) return;
      if (form.dataset.submitting) { event.preventDefault(); return; }
      form.dataset.submitting='1';
      // Keep the clicked decision button enabled so its name/value is submitted.
      form.setAttribute('aria-busy','true');
    });
  });
});
