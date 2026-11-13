(() => {
  const inquiry = document.getElementById('clinic-inquiry');
  if (!inquiry) return;

  function openInquiry() {
    if (!Element.prototype.animate || matchMedia('(prefers-reduced-motion: reduce)').matches) {
      inquiry.open = true;
      return;
    }
    inquiry.dispatchEvent(new CustomEvent('landing:expand'));
  }

  document.querySelectorAll('[data-open-clinic-inquiry]').forEach(link => {
    link.addEventListener('click', openInquiry);
  });

  function openFromHash() {
    if (location.hash === '#clinic-inquiry' && !inquiry.open) inquiry.open = true;
  }
  window.addEventListener('hashchange', openFromHash);
})();

(() => {
  const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)');
  if (!Element.prototype.animate) return;

  // Native details keep keyboard controls and remain usable without JavaScript.
  document.querySelectorAll('.landing-page .faq-list details, .landing-page .clinic-inquiry').forEach(details => {
    const summary = details.querySelector('summary');
    let animation;
    let expanded = details.open;

    function setExpanded(next) {
      if (next === expanded && !animation) return;
      if (reducedMotion.matches) {
        details.open = expanded = next;
        return;
      }
      const startHeight = details.getBoundingClientRect().height;
      if (animation) animation.cancel();
      expanded = next;
      details.open = true;
      const styles = getComputedStyle(details);
      const collapsedHeight = summary.getBoundingClientRect().height
        + parseFloat(styles.borderTopWidth) + parseFloat(styles.borderBottomWidth)
        + parseFloat(styles.paddingTop) + parseFloat(styles.paddingBottom);
      const endHeight = expanded ? details.getBoundingClientRect().height : collapsedHeight;

      details.style.overflow = 'hidden';
      animation = details.animate(
        [{ height: `${startHeight}px` }, { height: `${endHeight}px` }],
        { duration: details.id === 'clinic-inquiry' ? 420 : 280, easing: 'cubic-bezier(.22, 1, .36, 1)' }
      );
      animation.onfinish = () => {
        details.open = expanded;
        details.style.overflow = '';
        animation = undefined;
      };
    }
    summary.addEventListener('click', event => {
      if (reducedMotion.matches) return;
      event.preventDefault();
      setExpanded(!expanded);
    });
    details.addEventListener('landing:expand', () => setExpanded(true));
    details.addEventListener('toggle', () => {
      if (!animation) expanded = details.open;
    });
    reducedMotion.addEventListener('change', () => {
      if (!animation) return;
      animation.cancel();
      animation = undefined;
      details.open = expanded;
      details.style.overflow = '';
    });
  });

  // Animate only when visible; content is never hidden while waiting for JS.
  if (!('IntersectionObserver' in window) || reducedMotion.matches) return;
  const observer = new IntersectionObserver(entries => {
    entries.forEach(entry => {
      if (!entry.isIntersecting) return;
      observer.unobserve(entry.target);
      if (reducedMotion.matches) return;
      entry.target.animate(
        [{ opacity: 0, transform: 'translateY(14px)' }, { opacity: 1, transform: 'translateY(0)' }],
        { duration: 500, easing: 'cubic-bezier(.22, 1, .36, 1)' }
      );
    });
  }, { threshold: 0.12 });
  document.querySelectorAll('.faq-section .section-header, .faq-list details, .landing-contact, .landing-home .site-footer').forEach(element => observer.observe(element));
})();
