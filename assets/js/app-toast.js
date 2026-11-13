(() => {
  const notices = document.querySelectorAll('.admin-main .form-message.success');
  if (!notices.length) return;
  const stack = document.createElement('div');
  stack.className = 'app-toast-stack';
  document.body.append(stack);

  notices.forEach(notice => {
    const toast = document.createElement('div');
    toast.className = 'app-toast';
    toast.setAttribute('role', 'status');
    toast.setAttribute('aria-live', 'polite');
    toast.innerHTML = '<span class="app-toast-icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 4 4L19 6"/></svg></span><div class="app-toast-copy"><strong>Update saved</strong><p></p></div><button type="button" class="app-toast-close" aria-label="Dismiss notification"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 6 12 12M6 18 18 6"/></svg></button><span class="app-toast-progress" aria-hidden="true"></span>';
    toast.querySelector('p').textContent = notice.textContent.trim();
    notice.replaceWith(toast);
    stack.append(toast);

    let dismissing = false;
    let timer;
    const dismiss = () => {
      if (dismissing) return;
      dismissing = true;
      clearTimeout(timer);
      const remove = () => {
        toast.remove();
        if (!stack.children.length) stack.remove();
      };
      if (!toast.animate || matchMedia('(prefers-reduced-motion: reduce)').matches) {
        remove();
        return;
      }
      toast.animate([{ opacity: 1, transform: 'translateY(0)' }, { opacity: 0, transform: 'translateY(-8px)' }], { duration: 180, fill: 'forwards' }).onfinish = remove;
    };
    toast.querySelector('.app-toast-close').addEventListener('click', dismiss);
    timer = window.setTimeout(dismiss, 2000);
  });
})();
