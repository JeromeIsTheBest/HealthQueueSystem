(function () {
  function init() {
    var description = document.getElementById('clinicDescription');
    var count = document.getElementById('clinicDescriptionCount');
    if (description && count) description.addEventListener('input', function () { count.textContent = Array.from(description.value).length.toLocaleString('en-US'); });
    var dialog = document.getElementById('feeRequestDialog');
    var trigger = document.getElementById('openFeeRequest');
    if (!dialog || !trigger) return;
    trigger.addEventListener('click', function () { if (!dialog.open) dialog.showModal(); });
    document.getElementById('closeFeeRequest').addEventListener('click', function () { dialog.close(); });
    dialog.addEventListener('close', function () { trigger.focus(); });
    dialog.addEventListener('click', function (event) {
      if (event.target !== dialog) return;
      var b = dialog.getBoundingClientRect();
      if (event.clientX < b.left || event.clientX > b.right || event.clientY < b.top || event.clientY > b.bottom) dialog.close();
    });
    if (dialog.dataset.open === '1') dialog.showModal();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
