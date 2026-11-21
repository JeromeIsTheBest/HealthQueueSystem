(function () {
function initDirectory() {
  var lastTrigger = null;
  document.querySelectorAll('[data-directory-open]').forEach(function (trigger) {
    trigger.addEventListener('click', function () {
      var dialog = document.getElementById(trigger.dataset.directoryOpen);
      if (!dialog) return;
      lastTrigger = trigger;
      if (dialog.id === 'clinicDirectoryModal') setClinicForm(dialog, null);
      if (dialog.hasAttribute('data-staff-directory')) setStaffForm(dialog, null);
      if (!dialog.open) dialog.showModal();
    });
  });
  function setClinicForm(dialog, clinic) {
    var form = dialog.querySelector('form');
    if (!form) return;
    var fields = {clinic_id:'ClinicID',clinic_name:'ClinicName',address:'Address',contact_number:'ContactNumber',base_consultation_fee:'BaseConsultationFee',specialties:'Specialties',latitude:'Latitude',longitude:'Longitude'};
    Object.keys(fields).forEach(function (name) {
      var input = form.elements.namedItem(name);
      if (input) { input.value = clinic ? (clinic[fields[name]] ?? '') : ''; input.setCustomValidity(''); }
    });
    form.elements.base_consultation_fee.readOnly = !!(clinic && Number(clinic.HasMainPhysician));
    dialog.querySelector('#directoryFormTitle').textContent = clinic ? clinic.ClinicName : 'New clinic';
    dialog.querySelector('.section-kicker').textContent = clinic ? 'Edit clinic' : 'Add clinic';
    form.querySelector('[type="submit"]').textContent = clinic ? 'Save changes' : 'Add clinic';
    var cancel = form.querySelector('a.text-link');
    if (cancel) cancel.hidden = !clinic;
  }
  document.querySelectorAll('[data-clinic-edit]').forEach(function (trigger) {
    trigger.addEventListener('click', function (event) {
      if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
      var dialog = document.getElementById('clinicDirectoryModal');
      if (!dialog) return;
      var clinic;
      try { clinic = JSON.parse(trigger.dataset.clinicEdit); } catch (_) { return; }
      event.preventDefault();
      setClinicForm(dialog, clinic);
      lastTrigger = trigger;
      if (!dialog.open) dialog.showModal();
    });
  });
  function setStaffForm(dialog, member) {
    var form = dialog.querySelector('form');
    var fields = {member_id:'UserID',first_name:'FirstName',last_name:'LastName',clinic_id:'ClinicID',email:'Email',contact_number:'ContactNumber'};
    Object.keys(fields).forEach(function (name) {
      var input = form.elements.namedItem(name);
      input.value = member ? (member[fields[name]] ?? '') : '';
      input.setCustomValidity('');
    });
    var password = form.elements.namedItem('password');
    password.value = ''; password.required = !member;
    var label = form.querySelector('[data-staff-password-label]');
    label.firstChild.textContent = member ? 'New password ' : 'Temporary password';
    label.querySelector('.optional').textContent = member ? '(leave blank to keep current)' : '';
    dialog.querySelector('#directoryFormTitle').textContent = member ? member.FirstName + ' ' + member.LastName : 'New staff account';
    dialog.querySelector('.section-kicker').textContent = member ? 'Edit staff account' : 'Add staff';
    form.querySelector('[type="submit"]').textContent = member ? 'Save changes' : 'Create account';
    var cancel = form.querySelector('a.text-link'); if (cancel) cancel.hidden = !member;
  }
  document.querySelectorAll('[data-staff-edit]').forEach(function (trigger) {
    trigger.addEventListener('click', function (event) {
      if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
      var dialog = document.querySelector('[data-staff-directory]');
      if (!dialog) return;
      var member; try { member = JSON.parse(trigger.dataset.staffEdit); } catch (_) { return; }
      event.preventDefault(); setStaffForm(dialog, member); lastTrigger = trigger;
      if (!dialog.open) dialog.showModal();
    });
  });
  document.querySelectorAll('.dr-action-menu').forEach(function (menu) {
    menu.addEventListener('toggle', function () {
      if (!menu.open) return;
      document.querySelectorAll('.dr-action-menu[open]').forEach(function (other) { if (other !== menu) other.open = false; });
      var panel = menu.querySelector('.dr-more-content');
      var trigger = menu.querySelector('summary').getBoundingClientRect();
      var height = panel.getBoundingClientRect().height;
      panel.style.left = Math.max(8, Math.min(trigger.right - 190, window.innerWidth - 198)) + 'px';
      panel.style.top = (trigger.bottom + 8 + height > window.innerHeight - 8 ? Math.max(8, trigger.top - height - 8) : trigger.bottom + 8) + 'px';
    });
  });
  function closeClinicMenus(event) {
    document.querySelectorAll('.dr-action-menu[open]').forEach(function (menu) { if (!event || !menu.contains(event.target)) menu.open = false; });
  }
  window.addEventListener('resize', function () { closeClinicMenus(); });
  document.addEventListener('scroll', closeClinicMenus, true);
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
 }
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initDirectory);
else initDirectory();
})();
