<?php if (!empty($hqIsAdminLayout)): ?>
  </div><!-- /.admin-main -->
</div><!-- /.admin-layout -->
<?php else: ?>
<footer class="site-footer">
  <div class="container">
    <div class="footer-row">
      <?php if (!empty($isLandingPage)): ?>
      <div class="landing-footer-identity">
      <a class="landing-footer-brand" href="<?= HQ_BASE_URL ?>/index.php"><img src="<?= HQ_BASE_URL ?>/assets/img/brand/hq-soft-mark.png" alt="">HealthQueue</a>
      </div>
      <div class="landing-footer-social" id="contact" aria-label="Contact and social media">
        <h2 class="landing-footer-contact-title">Get in touch</h2>
        <span class="landing-social-row" title="Facebook page link coming soon">
          <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M14 21v-8h3l.5-4H14V7c0-1 .3-2 2-2h2V1.4A25 25 0 0 0 15 1c-3 0-5 1.8-5 5v3H7v4h3v8z"/></svg>
          <span>HealthQueue</span>
        </span>
        <a class="landing-social-row" href="mailto:healthqueue@gmail.com" aria-label="Email healthqueue@gmail.com">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 19V5l9 7 9-7v14M3 8l9 7 9-7"/></svg>
          <span>healthqueue@gmail.com</span>
        </a>
      </div>
      <span class="footer-note landing-footer-copyright">&copy; <?= date('Y') ?> HealthQueue</span>
      <?php else: ?>
      <span class="footer-note">&copy; <?= date('Y') ?> HealthQueue. All rights reserved.</span>
      <ul class="footer-links">
        <li><a href="#">Privacy Policy</a></li>
        <li><a href="#">Terms &amp; Conditions</a></li>
        <li><a href="<?= HQ_BASE_URL ?>/index.php#contact">Contact Us</a></li>
      </ul>
      <?php endif; ?>
    </div>
  </div>
</footer>
<?php endif; ?>

<script src="<?= HQ_BASE_URL ?>/assets/js/main.js?v=<?= @filemtime(__DIR__ . '/../assets/js/main.js') ?: 1 ?>"></script>
<?php if ($hqAppRole): ?>
<script src="<?= HQ_BASE_URL ?>/assets/js/app-toast.js?v=<?= filemtime(__DIR__ . '/../assets/js/app-toast.js') ?>" defer></script>
<?php endif; ?>
<?php if(($hqUser['RoleName']??'')==='Patient'){require __DIR__.'/record-access-widget.php';require __DIR__.'/visit-confirmation-widget.php';} ?>
</body>
</html>
