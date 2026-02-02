<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Volunteer Signup - Volunteer Connect</title>

    <link rel="icon" type="image/png" href="/volcon/assets/res/logo/favicon.png">
    <link rel="shortcut icon" href="/volcon/assets/res/logo/favicon.ico">

    <!-- Core auth styles -->
    <link rel="stylesheet" href="/volcon/assets/css/signup.css">
    <!-- Volunteer-specific layout -->
    <link rel="stylesheet" href="/volcon/assets/css/signup_vol.css">

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>
<body>

<div class="vc-auth-layout vc-auth-layout-vol">

    <!-- LEFT: FORM -->
    <div class="vc-auth-form-panel">

        <div class="vc-auth-form-wrapper vc-auth-form-wrapper-wide">

            <h1 class="vc-auth-title">Volunteer Registration</h1>
            <p class="vc-auth-subtitle">
                Complete your profile to start volunteering.
            </p>

            <?php if (!empty($errors)): ?>
                <div class="vc-alert vc-alert-danger">
                    <?php foreach ($errors as $e): ?>
                        <div><?= htmlspecialchars($e) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post"
                  action="signup_vol.php"
                  enctype="multipart/form-data"
                  class="vc-auth-form vc-auth-form-vol">

                <!-- =======================
                     COLUMN 1: ACCOUNT INFO
                ======================== -->
                <section class="vc-form-section">
                    <h3 class="vc-form-section-title">
                        <i class="fa-solid fa-user-lock"></i> Account Information
                    </h3>

                    <label class="vc-form-label">Username <span>*</span></label>
                    <input type="text" name="username" class="vc-form-input"
                           placeholder="e.g. adam_hakim"
                           required value="<?= htmlspecialchars($old['username'] ?? '') ?>">

                    <label class="vc-form-label">Email <span>*</span></label>
                    <input type="email" name="email" class="vc-form-input"
                           placeholder="e.g. adam@email.com"
                           required value="<?= htmlspecialchars($old['email'] ?? '') ?>">

                    <label class="vc-form-label">Password <span>*</span></label>
                    <div class="vc-pw-wrap">
                        <input type="password" name="password"
                               id="vol-password"
                               class="vc-form-input"
                               placeholder="8–16 chars with number & symbol"
                               required
                               value="<?= $old['password'] ?? '' ?>">
                        <button type="button"
                                class="vc-toggle-pw"
                                data-target="vol-password">
                            <i class="fa-solid fa-eye"></i>
                        </button>
                    </div>
                    <div class="vc-pw-meter" id="vol-meter"></div>
                    <div class="vc-pw-hint" id="vol-hint"></div>

                    <label class="vc-form-label">Confirm Password <span>*</span></label>
                    <div class="vc-pw-wrap">
                        <input type="password" name="password_confirm"
                               id="vol-password-confirm"
                               class="vc-form-input"
                               placeholder="Re-enter password"
                               required>
                        <button type="button"
                                class="vc-toggle-pw"
                                data-target="vol-password-confirm">
                            <i class="fa-solid fa-eye"></i>
                        </button>
                    </div>
                    <div class="vc-pw-match" id="vol-match"></div>
                </section>

                <!-- =======================
                     COLUMN 2: VOLUNTEER INFO
                ======================== -->
                <section class="vc-form-section">
                    <h3 class="vc-form-section-title">
                        <i class="fa-solid fa-handshake-angle"></i> Volunteer Details
                    </h3>

                    <label class="vc-form-label">Profile Picture (optional)</label>
                    <input type="file" name="profile_picture" accept="image/*">

                    <label class="vc-form-label">First Name <span>*</span></label>
                    <input type="text" name="first_name" class="vc-form-input"
                           placeholder="e.g. Adam"
                           required value="<?= htmlspecialchars($old['first_name'] ?? '') ?>">

                    <label class="vc-form-label">Last Name <span>*</span></label>
                    <input type="text" name="last_name" class="vc-form-input"
                           placeholder="e.g. Hakim"
                           required value="<?= htmlspecialchars($old['last_name'] ?? '') ?>">
                    
                    <label class="vc-form-label">Gender <span>*</span></label>
                    <select name="gender" class="vc-form-input" required>
                        <option value="">-- Select Gender --</option>
                        <option value="m" <?= (isset($old['gender']) && $old['gender'] == 'm') ? 'selected' : '' ?>>Male</option>
                        <option value="f" <?= (isset($old['gender']) && $old['gender'] == 'f') ? 'selected' : '' ?>>Female</option>
                    </select>

                    <label class="vc-form-label">State <span>*</span></label>
                    <select id="state_vol" name="state_vol" class="vc-form-input" required>
                        <option value="">-- Select State --</option>
                    </select>

                    <label class="vc-form-label">Town / Area <span>*</span></label>
                    <select id="city_vol" name="city_vol" class="vc-form-input" required>
                        <option value="">-- Select Town or Area --</option>
                    </select>

                    <label class="vc-form-label">Country</label>
                    <select name="country_vol" class="vc-form-input">
                        <option value="Malaysia" selected>Malaysia</option>
                    </select>

                    <label class="vc-form-label">Phone Number <span>*</span></label>
                    <input type="text" name="phone_no" class="vc-form-input"
                           placeholder="e.g. 012-3456789"
                           required value="<?= htmlspecialchars($old['phone_no'] ?? '') ?>">

                    <label class="vc-form-label">Birthdate <span>*</span></label>
                    <input type="date" name="birthdate" class="vc-form-input"
                           required value="<?= htmlspecialchars($old['birthdate'] ?? '') ?>">
                </section>

                <!-- =======================
                     FULL-WIDTH ACTION
                ======================== -->
                <div class="vc-form-actions">
                    <button type="submit" name="final_submit" class="vc-btn-primary">
                        Complete Registration
                    </button>

                    <p class="vc-auth-footer">
                        Want to restart? <a href="signup.php">Go back</a>
                    </p>
                </div>

            </form>
        </div>
    </div>

    <!-- RIGHT: MEDIA -->
    <div class="vc-auth-media">
        <div class="vc-media-track">
            <img src="/volcon/assets/res/volcon-collage.jpg" alt="">
            <img src="/volcon/assets/res/volcon-collage.jpg" alt="">
        </div>
        <div class="vc-auth-media-overlay"></div>
    </div>

</div>

<script>
    var OLD_STATE = "<?= $old['state_vol'] ?? '' ?>";
    var OLD_CITY  = "<?= $old['city_vol'] ?? '' ?>";
</script>

<script src="/volcon/assets/js/utils/location_selector.js"></script>
<script type="module" src="/volcon/assets/js/utils/form_utils.js"></script>
<script type="module" src="/volcon/assets/js/signup/signup_vol.js"></script>
