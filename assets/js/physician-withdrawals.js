document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-pw-open]').forEach(function (trigger) {
    trigger.addEventListener('click', function () {
      var dialog = document.getElementById(trigger.dataset.pwOpen);
      if (dialog) { dialog.pwTrigger = trigger; dialog.showModal(); }
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
  document.querySelectorAll('.pw-form, .pw-form-actions').forEach(function (form) {
    if (form.tagName !== 'FORM') return;
    form.addEventListener('submit', function (event) {
      if (form.dataset.submitting) { event.preventDefault(); return; }
      form.dataset.submitting='1';
      // Keep the clicked decision button enabled so its name/value is submitted.
      form.setAttribute('aria-busy','true');
    });
  });
});
