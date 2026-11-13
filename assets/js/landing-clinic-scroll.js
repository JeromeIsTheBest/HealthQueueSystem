(() => {
  document.querySelectorAll('#featuredClinicTrack, .landing-service-track, .landing-benefit-track').forEach(track => {
  if (!track.children.length) return;
  // Benefits appear once; users can swipe through the three original cards.
  if (track.classList.contains('landing-benefit-track')) return;
  const mobile = matchMedia('(max-width:760px)');
  const reduced = matchMedia('(prefers-reduced-motion:reduce)');
  const originals = [...track.children];
  for (let copy = 0; copy < 2; copy++) {
    originals.forEach(card => {
      const clone = card.cloneNode(true);
      clone.classList.add('clinic-card-repeat');
      clone.setAttribute('aria-hidden', 'true');
      clone.querySelectorAll('a,button').forEach(link => { link.tabIndex = -1; });
      track.append(clone);
    });
  }
  let cycle = 0, position = 0, previous = 0, frame = 0, pauseUntil = 0, touching = false;
  function measure() {
    cancelAnimationFrame(frame);
    if (!mobile.matches) { track.scrollLeft = 0; return; }
    cycle = track.querySelector('.clinic-card-repeat').offsetLeft - originals[0].offsetLeft;
    position = cycle;
    track.scrollLeft = position;
    previous = 0;
    if (!reduced.matches) frame = requestAnimationFrame(animate);
  }
  function animate(now) {
    const elapsed = previous ? Math.min(now - previous, 50) : 0;
    previous = now;
    if (!document.hidden && !touching && now > pauseUntil && !track.querySelector(':focus-visible') && !track.matches(':focus-visible')) {
      position += elapsed * 0.02;
      if (position >= cycle * 2) position -= cycle;
      track.scrollLeft = position;
    } else position = track.scrollLeft;
    frame = requestAnimationFrame(animate);
  }
  function pause() { pauseUntil = performance.now() + 3500; position = track.scrollLeft; }
  track.addEventListener('pointerdown', () => { touching = true; pause(); });
  function release() { if (touching) { touching = false; pause(); } }
  window.addEventListener('pointerup', release);
  window.addEventListener('pointercancel', release);
  track.addEventListener('wheel', pause, {passive:true});
  track.addEventListener('keydown', pause);
  track.addEventListener('scroll', () => {
    if (!mobile.matches || !cycle || reduced.matches) return;
    if (track.scrollLeft >= cycle * 2) track.scrollLeft -= cycle;
    else if (track.scrollLeft < 1) track.scrollLeft += cycle;
  }, {passive:true});
  new ResizeObserver(measure).observe(track);
  mobile.addEventListener('change', measure);
  reduced.addEventListener('change', measure);
  });
})();
