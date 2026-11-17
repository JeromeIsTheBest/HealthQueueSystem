document.addEventListener('DOMContentLoaded', function () {
  var lastTrigger = null;
  document.querySelectorAll('[data-directory-open]').forEach(function (trigger) {
    trigger.addEventListener('click', function () {
      var dialog = document.getElementById(trigger.dataset.directoryOpen);
      if (!dialog) return;
      lastTrigger = trigger;
      dialog.showModal();
    });
  });
  document.querySelectorAll('.dr-dialog').forEach(function (dialog) {
    dialog.querySelectorAll('[data-directory-close]').forEach(function (button) {
      button.addEventListener('click', function () { dialog.close(); });
    });
    dialog.addEventListener('close', function () { if (lastTrigger) lastTrigger.focus(); });
    dialog.addEventListener('click', function (event) {
      if (event.target !== dialog) return;
      var bounds = dialog.getBoundingClientRect();
      if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) dialog.close();
    });
    if (dialog.dataset.openOnLoad === '1') dialog.showModal();
  });
  document.addEventListener('click', function (event) {
    document.querySelectorAll('.dr-more[open]').forEach(function (menu) { if (!menu.contains(event.target)) menu.open = false; });
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') document.querySelectorAll('.dr-more[open]').forEach(function (menu) { menu.open = false; });
  });
});
