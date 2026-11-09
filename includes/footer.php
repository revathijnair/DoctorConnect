<?php
/**
 * DOCTOR CONNECT - Common Footer Template
 */

$clinicPhone = get_clinic_setting('clinic_phone', '+1 (800) 555-0199');
$clinicEmail = get_clinic_setting('clinic_email', 'care@doctorconnect.com');
?>
</main>

<footer class="footer">
    <div style="max-width: 1240px; margin: 0 auto; display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; gap:16px;">
        <div>
            <strong>DoctorConnect</strong> &mdash; Token-Based Online Doctor Consultation Platform
        </div>
        <div>
            <span>Support: <?= e($clinicPhone) ?></span> &bull; 
            <span>Email: <?= e($clinicEmail) ?></span>
        </div>
        <div style="font-size:12px; color:#94a3b8;">
            &copy; <?= date('Y') ?> All Rights Reserved &bull; Secure Telehealth System
        </div>
    </div>
</footer>

<script src="<?= BASE_URL ?>/assets/js/main.js"></script>
<?php if (isset($extraScripts)): ?>
    <?= $extraScripts ?>
<?php endif; ?>
</body>
</html>
