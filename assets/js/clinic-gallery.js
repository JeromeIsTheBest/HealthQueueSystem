(() => {
  const gallery = document.querySelector('.public-clinic-profile .clinic-gallery');
  if (!gallery) return;
  const mobile = window.matchMedia('(max-width:720px)');
  const reduced = window.matchMedia('(prefers-reduced-motion:reduce)');
  const panels = [...gallery.children].filter(panel => !panel.classList.contains('clinic-gallery-info'));
  for (let copy = 0; copy < 2; copy++) {
    for (const panel of panels) {
      const clone = panel.cloneNode(true);
      clone.classList.add('clinic-gallery-repeat');
      clone.setAttribute('aria-hidden', 'true');
      gallery.appendChild(clone);
    }
  }
  let cycle = 0;
  let position = 0;
  let previous = 0;
  let pauseUntil = 0;
  let touching = false;
  let frame = 0;
  function measure() {
    cancelAnimationFrame(frame);
    if (!mobile.matches) { gallery.scrollLeft = 0; return; }
    cycle = gallery.querySelector('.clinic-gallery-repeat').offsetLeft - panels[0].offsetLeft;
    gallery.scrollLeft = cycle;
    position = gallery.scrollLeft;
    previous = 0;
    if (!reduced.matches) frame = requestAnimationFrame(animate);
  }
  function animate(now) {
    const elapsed = previous ? Math.min(now - previous, 50) : 0;
    previous = now;
    if (!document.hidden && !touching && now > pauseUntil && !gallery.matches(':focus-within')) {
      position -= elapsed * 0.018;
      if (position < 1) position += cycle;
      gallery.scrollLeft = position;
    } else position = gallery.scrollLeft;
    frame = requestAnimationFrame(animate);
  }
  function pause() { pauseUntil = performance.now() + 3500; position = gallery.scrollLeft; }
  gallery.addEventListener('pointerdown', () => { touching = true; pause(); });
  window.addEventListener('pointerup', () => { touching = false; pause(); });
  window.addEventListener('pointercancel', () => { touching = false; pause(); });
  gallery.addEventListener('wheel', pause, { passive:true });
  gallery.addEventListener('keydown', pause);
  gallery.addEventListener('scroll', () => {
    if (!mobile.matches || !cycle || reduced.matches) return;
    if (gallery.scrollLeft >= cycle * 2) gallery.scrollLeft -= cycle;
    else if (gallery.scrollLeft < 1) gallery.scrollLeft += cycle;
  }, { passive:true });
  new ResizeObserver(measure).observe(gallery);
  mobile.addEventListener('change', measure);
  reduced.addEventListener('change', measure);
})();
