<?php
require_once __DIR__ . '/includes/input-validation.php';
require_once __DIR__ . '/includes/clinic-inquiry.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/clinic-photos.php';

// The project is served from localhost/healthqueue in XAMPP, so asset and
// navigation links must retain the application directory prefix.
define('HQ_BASE_URL', '/healthqueue');
$pageTitle = 'HealthQueue — Skip the Wait, See Your Doctor Faster';
$isLandingPage = true;

// Featured clinics: pull from DB if available, otherwise fall back to
// static sample data so the landing page still renders before the
// database has been set up.
$clinics = [];
$pdo = getDbConnection();
$registrationErrors = [];
$registrationSuccess = !empty($_SESSION['clinic_registration_success']);
unset($_SESSION['clinic_registration_success']);
$registrationValues = [
    'clinic_name' => '', 'contact_name' => '', 'email' => '',
    'phone' => '', 'city' => '', 'staff_count' => '', 'base_consultation_fee' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'clinic_registration') {
    if(!verifyCsrfToken($_POST['csrf_token']??null))$registrationErrors[]='Your session expired. Please try again.';
    if(!$registrationErrors && $pdo) {try{if(!consumeRequestLimit($pdo,'clinic-inquiry',requestClientAddress(),5,900,currentUser()['UserID']??null))$registrationErrors[]='Too many inquiries. Please try again in 15 minutes.';}catch(PDOException $e){$registrationErrors[]='Registration is temporarily unavailable.';}}
    foreach ($registrationValues as $field => $value) {
        $registrationValues[$field] = trim((string) ($_POST[$field] ?? ''));
    }

    foreach (['clinic_name' => 'Clinic name', 'contact_name' => 'Contact name', 'email' => 'Email address', 'phone' => 'Phone number', 'city' => 'City'] as $field => $label) {
        if ($registrationValues[$field] === '') {
            $registrationErrors[] = $label . ' is required.';
        }
    }

    $registrationValues['contact_name'] = hqCapitalizeName($registrationValues['contact_name']);
    if (!hqValidMobile($registrationValues['phone'])) $registrationErrors[] = 'Enter a valid mobile number';

    if($registrationValues['staff_count']!=='' && filter_var($registrationValues['staff_count'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])===false)$registrationErrors[]='Number of staff must be a positive whole number.';

    if (!validClinicInquiryPrice($registrationValues['base_consultation_fee'])) $registrationErrors[] = 'Enter a valid consultation base price (0 to 99,999,999.99, up to two decimal places).';

    if ($registrationValues['email'] !== '' && !filter_var($registrationValues['email'], FILTER_VALIDATE_EMAIL)) {
        $registrationErrors[] = 'Please enter a valid email address.';
    }

    if (!$registrationErrors && $pdo) {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO ClinicRegistrationInquiry
                 (ClinicName, ContactName, Email, Phone, City, StaffCount, BaseConsultationFee)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $registrationValues['clinic_name'],
                $registrationValues['contact_name'],
                $registrationValues['email'],
                $registrationValues['phone'],
                $registrationValues['city'],
                $registrationValues['staff_count'] !== '' ? (int) $registrationValues['staff_count'] : null,
                $registrationValues['base_consultation_fee'],
            ]);
            $_SESSION['clinic_registration_success'] = true;
            header('Location: ' . HQ_BASE_URL . '/index.php#clinic-inquiry', true, 303);
            exit;
        } catch (PDOException $e) {
            error_log('Clinic registration inquiry failed: ' . $e->getMessage());
            $registrationErrors[] = 'We could not save your inquiry right now. Please email hello@healthqueue.app.';
        }
    } elseif (!$registrationErrors) {
        $registrationErrors[] = 'Online registration is temporarily unavailable. Please email hello@healthqueue.app.';
    }
}

if ($pdo) {
    try {
        $stmt = $pdo->query(
            "SELECT ClinicID, ClinicName, Address, ContactNumber, PhotoUrl
             FROM Clinic
             WHERE archived = 0 AND Status = 'Active'
             ORDER BY RegistrationDate DESC"
        );
        $clinics = $stmt->fetchAll();
    } catch (PDOException $e) {
        $clinics = [];
    }
}

if (empty($clinics)) {
    $clinics = [
        ['ClinicID' => null, 'ClinicName' => 'Sunrise Medical Center',   'Address' => '123 Rizal Ave, Manila',            'ContactNumber' => '+63 2 8123 4567', 'PhotoUrl' => null],
        ['ClinicID' => null, 'ClinicName' => 'Northgate Family Clinic',  'Address' => '456 Aurora Blvd, Quezon City',     'ContactNumber' => '+63 2 8987 6543', 'PhotoUrl' => null],
        ['ClinicID' => null, 'ClinicName' => 'Bayview Health Hub',       'Address' => '789 Roxas Blvd, Pasay',            'ContactNumber' => '+63 2 8555 0102', 'PhotoUrl' => null],
        ['ClinicID' => null, 'ClinicName' => 'Eastside Wellness Clinic', 'Address' => '321 Marcos Highway, Antipolo',     'ContactNumber' => '+63 2 8222 7788', 'PhotoUrl' => null],
    ];
}

require __DIR__ . '/includes/header.php';
?>

<main class="landing-page">
<!-- ===================== HERO ===================== -->
<section class="hero">
  <div class="container">
    <div class="landing-hero-copy">
      <span class="eyebrow">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M12 2 4 6v6c0 5 3.4 8.4 8 10 4.6-1.6 8-5 8-10V6l-8-4Z"/></svg>
        Care without the long wait
      </span>

      <h1>Your next visit.<br><span class="accent">A little less waiting.</span></h1>

      <p class="lead">
        Find a clinic, book your visit, and follow your queue in real time.
      </p>

      <div class="landing-hero-links">
      <div class="hero-actions">
        <a href="<?= HQ_BASE_URL ?>/auth/register.php" class="btn btn-primary">Book an appointment</a>
        <a href="<?= HQ_BASE_URL ?>/clinics.php" class="btn btn-outline">Explore clinics</a>
      </div>
      <p class="landing-signin">Already have an account? <a href="<?= HQ_BASE_URL ?>/auth/login.php">Sign in</a></p>
      </div>
    </div>

    <div class="landing-hero-visual">
      <figure class="landing-hero-illustration">
        <img src="<?= HQ_BASE_URL ?>/assets/img/landing/queue-phone-group-3d-soft.png" alt="3D character showing queue number S-12, with two people behind him checking their phones" width="1312" height="1199" fetchpriority="high" decoding="async">
      </figure>
    </div>
  </div>
  <svg class="landing-hero-waves" viewBox="0 0 1440 300" preserveAspectRatio="none" aria-hidden="true" focusable="false">
    <defs>
      <linearGradient id="landingWaveBack" x1="0" y1="0" x2="1" y2="1">
        <stop offset="0%" stop-color="#c8eff4" stop-opacity=".55"/>
        <stop offset="100%" stop-color="#9edce8" stop-opacity=".7"/>
      </linearGradient>
      <linearGradient id="landingWaveFront" x1="0" y1="0" x2="1" y2="1">
        <stop offset="0%" stop-color="#d3f1f6" stop-opacity=".7"/>
        <stop offset="100%" stop-color="#a5e3ed" stop-opacity=".8"/>
      </linearGradient>
    </defs>
    <path d="M0 54C110 218 288 258 488 245C650 235 730 273 888 231C1122 169 1160 41 1440 18V300H0Z" fill="url(#landingWaveBack)"/>
    <path d="M0 160C190 264 346 294 566 256C768 221 822 282 1014 258C1208 233 1260 125 1440 98V300H0Z" fill="url(#landingWaveFront)"/>
    <path d="M0 245C276 297 420 284 610 273C850 258 950 304 1126 271C1266 245 1348 204 1440 196V300H0Z" fill="#b4e8f0" fill-opacity=".46"/>
  </svg>
</section>

<div class="landing-highlights" aria-label="HealthQueue features">
  <div class="container">
    <div><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 3v3M16 3v3"/><path d="m9 15 2 2 4-4"/></svg><span>Online appointments</span></div>
    <div><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg><span>Live queue updates</span></div>
    <div><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M9 13h6M9 17h6"/></svg><span>Consultation records</span></div>
  </div>
</div>

<!-- ===================== SERVICES ===================== -->
<section class="section services-section" id="services">
  <div class="container">
    <div class="section-header">
      <span class="eyebrow">Services Offered</span>
      <h2><span class="landing-title-full">Care made easier, from start to finish.</span><span class="landing-title-mobile">Care made easier.</span></h2>
      <p>Appointments, queue updates, and consultation records. All connected.</p>
    </div>

    <div class="services-grid">
      <div class="service-card">
        <div class="service-icon">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 3v3M16 3v3"/><path d="m9 15 2 2 4-4"/></svg>
        </div>
        <h3>Online booking</h3>
        <p>Find a clinic and reserve your appointment in just a few taps.</p>
      </div>

      <div class="service-card">
        <div class="service-icon">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>
        </div>
        <h3>Live queue tracking</h3>
        <p>See your queue status in real time, so you know when to go.</p>
      </div>

      <div class="service-card">
        <div class="service-icon">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M9 13h6M9 17h6"/></svg>
        </div>
        <h3>Digital records</h3>
        <p>Keep your consultation details together in your patient account.</p>
      </div>

    </div>
  </div>
</section>

<!-- ===================== HOW IT WORKS ===================== -->
<section class="section how-it-works" id="how-it-works">
  <div class="container">
    <div class="section-header">
      <span class="eyebrow">How it works</span>
      <h2><span class="landing-title-full">Your visit, simplified in three steps</span><span class="landing-title-mobile">Your visit, simplified.</span></h2>
      <p>Spend less time waiting and more time focusing on your health.</p>
    </div>
    <div class="steps-grid">
      <article class="step-card">
        <div class="step-illustration step-illustration-find" aria-hidden="true"></div>
        <div class="step-marker"><span class="step-number">01</span></div>
        <h3>Find a clinic</h3>
        <p>Browse partner clinics and choose the care that fits your needs.</p>
      </article>
      <article class="step-card">
        <div class="step-illustration step-illustration-book" aria-hidden="true"></div>
        <div class="step-marker"><span class="step-number">02</span></div>
        <h3>Book your schedule</h3>
        <p>Select an available date and time, then confirm your appointment online.</p>
      </article>
      <article class="step-card">
        <div class="step-illustration step-illustration-track" aria-hidden="true"></div>
        <div class="step-marker"><span class="step-number">03</span></div>
        <h3>Track your queue</h3>
        <p>Follow your live queue number and arrive closer to your consultation time.</p>
      </article>
    </div>
  </div>
</section>

<!-- ===================== FEATURED CLINICS ===================== -->
<section class="section section-alt" id="clinics">
  <div class="container">
    <div class="section-header clinic-section-heading">
      <span class="eyebrow">Featured clinics</span>
      <h2><span class="landing-title-full">Find the right care near you.</span><span class="landing-title-mobile">Care near you</span></h2>
      <p>Browse participating clinics and their available physicians.</p>
      <a class="section-link" href="<?= HQ_BASE_URL ?>/clinics.php">View all clinics</a>
    </div>

    <p class="clinic-swipe-hint">Swipe left or right to explore clinics <span aria-hidden="true">&harr;</span></p>
    <div class="clinics-grid" id="featuredClinicTrack" tabindex="0" role="region" aria-label="Participating clinics. Swipe left or right to browse on mobile.">
      <?php foreach ($clinics as $clinic): ?>
        <?php $clinicPhoto = $pdo && $clinic['ClinicID'] ? (clinicCoverPhoto($pdo, $clinic) ?? '') : ''; ?>
        <div class="clinic-card">
          <?php if ($clinicPhoto !== '' && is_file(__DIR__ . '/assets/uploads/clinics/' . $clinicPhoto)): ?>
            <div class="clinic-thumb has-photo" style="background-image:url('<?= HQ_BASE_URL ?>/assets/uploads/clinics/<?= rawurlencode($clinicPhoto) ?>');background-size:cover;background-position:center;"></div>
          <?php else: ?>
            <div class="clinic-thumb"></div>
          <?php endif; ?>
          <div class="clinic-body">
            <h3><?= htmlspecialchars($clinic['ClinicName']) ?></h3>
            <p class="clinic-meta">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" style="flex-shrink:0;margin-top:2px;"><path d="M12 22s7-6.5 7-12A7 7 0 0 0 5 10c0 5.5 7 12 7 12Z"/><circle cx="12" cy="10" r="2.4"/></svg>
              <?= htmlspecialchars($clinic['Address']) ?>
            </p>
            <p class="clinic-meta">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" style="flex-shrink:0;margin-top:2px;"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3 19.5 19.5 0 0 1-6-6 19.8 19.8 0 0 1-3-8.7A2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .3 2 .7 3a2 2 0 0 1-.4 2.1L8 10.3a16 16 0 0 0 6 6l1.5-1.4a2 2 0 0 1 2.1-.4c1 .4 2 .6 3 .7a2 2 0 0 1 1.4 2.7Z"/></svg>
              <?= htmlspecialchars($clinic['ContactNumber']) ?>
            </p>
            <?php if ($clinic['ClinicID']): ?>
              <a href="<?= HQ_BASE_URL ?>/clinic-profile.php?clinic_id=<?= (int) $clinic['ClinicID'] ?>" class="btn btn-outline btn-sm btn-block">View Clinic</a>
            <?php else: ?>
              <a href="<?= HQ_BASE_URL ?>/auth/login.php" class="btn btn-outline btn-sm btn-block">View Clinic</a>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

  </div>
</section>

<!-- ===================== CLINIC PARTNERS ===================== -->
<section class="section partner-section" id="clinic-registration">
  <div class="container">
    <div class="partner-banner">
      <div class="partner-copy">
        <span class="eyebrow">For physicians &amp; clinics</span>
        <h2>Better patient flow<br>starts here.</h2>
        <p>Bring appointments, queues, and records together for your clinic.</p>
        <a href="#clinic-inquiry" class="btn btn-primary" data-open-clinic-inquiry>Register your clinic</a>
      </div>
      <div class="partner-illustration" aria-hidden="true"></div>
    </div>
    <details class="clinic-inquiry" id="clinic-inquiry" <?= ($registrationSuccess || $registrationErrors) ? 'open' : '' ?>>
      <summary>Clinic registration inquiry <span>Tell us about your clinic</span></summary>
      <div class="registration-layout">
        <div class="registration-copy">
          <span class="eyebrow">Let's get you started</span>
          <h2>Bring your clinic online.</h2>
          <p>Submit your details and our team will guide you through registration and onboarding.</p>
          <ul class="registration-benefits">
            <li>Manage appointments and walk-in queues together</li>
            <li>Give patients clear, real-time queue updates</li>
            <li>Get support for your clinic's setup</li>
          </ul>
        </div>
        <form class="registration-form" method="post" action="#clinic-registration">
          <input type="hidden" name="form_type" value="clinic_registration">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
          <h3>Clinic registration inquiry</h3>
          <p>Tell us a little about your clinic.</p>
          <?php if ($registrationSuccess): ?>
            <p class="form-message success" role="status">Thank you! Your registration inquiry has been received.</p>
          <?php endif; ?>
          <?php if ($registrationErrors): ?>
            <div class="form-message error" role="alert"><strong>Please check the following:</strong><ul><?php foreach ($registrationErrors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div>
          <?php endif; ?>
          <div class="form-grid">
            <label>Clinic name<input name="clinic_name" value="<?= htmlspecialchars($registrationValues['clinic_name']) ?>" required></label>
            <label>Contact person<input name="contact_name" value="<?= htmlspecialchars($registrationValues['contact_name']) ?>" required></label>
            <label>Email address<input type="email" name="email" value="<?= htmlspecialchars($registrationValues['email']) ?>" required></label>
            <label>Phone number<input type="tel" name="phone" inputmode="numeric" maxlength="11" pattern="09[0-9]{9}" title="Enter a valid mobile number" value="<?= htmlspecialchars($registrationValues['phone']) ?>" required></label>
            <label>City / location<input name="city" value="<?= htmlspecialchars($registrationValues['city']) ?>" required></label>
            <label>Base price of consultation (PHP)<input type="number" name="base_consultation_fee" min="0" max="99999999.99" step="0.01" inputmode="decimal" placeholder="e.g. 500.00" value="<?= htmlspecialchars($registrationValues['base_consultation_fee']) ?>" required></label>
            <label>Number of staff<input type="number" name="staff_count" min="1" value="<?= htmlspecialchars($registrationValues['staff_count']) ?>"></label>
          </div>
          <button type="submit" class="btn btn-primary btn-block">Submit registration inquiry</button>
          <small>We’ll use these details only to contact you about clinic registration.</small>
        </form>
      </div>
    </details>
  </div>
</section>

<!-- ===================== FAQ ===================== -->
<section class="section faq-section" id="faq">
  <div class="container">
    <div class="section-header">
      <span class="eyebrow">Frequently asked questions</span>
      <h2><span class="landing-title-full">A little clarity before your visit.</span><span class="landing-title-mobile">Before your visit</span></h2>
      <p>Everything you need to know before booking or registering a clinic.</p>
    </div>
    <div class="faq-list">
      <details><summary>Is HealthQueue free for patients?</summary><p>Patients can create an account, browse clinics, and book appointments through HealthQueue. Any consultation or booking fees are set by the individual clinic.</p></details>
      <details><summary>Can I track a walk-in queue?</summary><p>Yes. Participating clinics can provide real-time queue updates for both booked appointments and walk-in patients.</p></details>
      <details><summary>How does a physician or clinic register?</summary><p>Submit the clinic registration inquiry above. Our team will contact you to verify your details and guide you through setup.</p></details>
      <details><summary>Is patient information secure?</summary><p>HealthQueue is designed with role-based access so that users only see the records and tools appropriate to their role.</p></details>
    </div>
  </div>
</section>



</main>
<script src="<?= HQ_BASE_URL ?>/assets/js/landing.js?v=<?= filemtime(__DIR__ . '/assets/js/landing.js') ?>" defer></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
