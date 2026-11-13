<?php if (!empty($hqIsAdminLayout)): ?>
  </div><!-- /.admin-main -->
</div><!-- /.admin-layout -->
<?php else: ?>
<footer class="site-footer">
  <div class="container">
    <div class="footer-row">
      <?php if (!empty($isLandingPage)): ?>
      <a class="landing-footer-brand" href="<?= HQ_BASE_URL ?>/index.php"><img src="<?= HQ_BASE_URL ?>/assets/img/brand/hq-soft-mark.png" alt="">HealthQueue</a>
      <ul class="footer-links">
        <li><a href="#services">Services</a></li>
        <li><a href="#how-it-works">How it works</a></li>
        <li><a href="#clinics">Clinics</a></li>
        <li><a href="#contact">Contact</a></li>
      </ul>
      <span class="footer-note">&copy; <?= date('Y') ?> HealthQueue</span>
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
</body>
</html>
