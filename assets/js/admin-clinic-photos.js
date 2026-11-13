(() => {
  const input = document.querySelector('input[name="photos[]"]');
  if (!input) return;
  const preview = document.getElementById('newClinicPhotoPreviews');
  let urls = [];
  function arrange(item, onDrop) {
    let active = false;
    item.draggable = false;
    item.addEventListener('pointerdown', event => {
      if (event.target.closest('button') || event.button !== 0) return;
      active = true;
      item.setPointerCapture(event.pointerId);
      item.classList.add('is-moving');
    });
    item.addEventListener('pointermove', event => {
      if (!active) return;
      const target = document.elementFromPoint(event.clientX, event.clientY)?.closest('.admin-clinic-photo-item');
      if (!target || target === item || target.parentElement !== item.parentElement) return;
      const items = [...item.parentElement.children];
      if (items.indexOf(item) < items.indexOf(target)) target.after(item);
      else target.before(item);
    });
    function finish() {
      if (!active) return;
      active = false;
      item.classList.remove('is-moving');
      if (onDrop) onDrop();
    }
    item.addEventListener('pointerup', finish);
    item.addEventListener('pointercancel', finish);
    item.addEventListener('lostpointercapture', finish);
  }
  document.querySelectorAll('[data-saved-photo]').forEach(button => {
    arrange(button.closest('.admin-clinic-photo-item'));
    button.addEventListener('click', event => {
      event.preventDefault();
      const removal = document.createElement('input');
      removal.type = 'hidden';
      removal.name = 'remove_photos[]';
      removal.value = button.dataset.savedPhoto;
      input.form.append(removal);
      button.closest('.admin-clinic-photo-item').remove();
      input.focus();
    });
  });
  function render() {
    urls.forEach(url => URL.revokeObjectURL(url));
    urls = [];
    preview.replaceChildren();
    const files = [...input.files];
    files.forEach((file, index) => {
      const item = document.createElement('span');
      item.className = 'admin-clinic-photo-item';
      item.dataset.fileIndex = index;
      const image = document.createElement('img');
      image.alt = file.name;
      image.width = 100;
      image.height = 76;
      if (['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
        const url = URL.createObjectURL(file);
        urls.push(url);
        image.src = url;
      }
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'clinic-photo-remove';
      remove.textContent = '\u00d7';
      remove.setAttribute('aria-label', `Remove ${file.name}`);
      remove.addEventListener('click', event => {
        event.preventDefault();
        const transfer = new DataTransfer();
        [...input.files].forEach((entry, i) => { if (i !== Number(item.dataset.fileIndex)) transfer.items.add(entry); });
        input.files = transfer.files;
        render();
        input.focus();
      });
      item.append(image, remove);
      preview.append(item);
      arrange(item, () => {
        const transfer = new DataTransfer();
        [...preview.children].forEach(child => transfer.items.add(files[Number(child.dataset.fileIndex)]));
        input.files = transfer.files;
        render();
      });
    });
  }
  input.addEventListener('change', render);
})();
