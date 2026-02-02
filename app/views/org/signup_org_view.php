<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Organization Signup - Volunteer Connect</title>

    <link rel="icon" type="image/png" href="/volcon/assets/res/logo/favicon.png">
    <link rel="shortcut icon" href="/volcon/assets/res/logo/favicon.ico">

    <link rel="stylesheet" href="/volcon/assets/css/signup.css">
    <link rel="stylesheet" href="/volcon/assets/css/signup_org.css">
    <link rel="stylesheet"
      href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
</head>
<body>

<div class="vc-auth-layout vc-auth-layout-org">

    <!-- LEFT: FORM (2/3 WIDTH) -->
    <div class="vc-auth-form-panel">

        <div class="vc-auth-form-wrapper wide">

            <h1 class="vc-auth-title">Organization Registration</h1>
            <p class="vc-auth-subtitle">
                Complete your organization profile to start posting volunteer opportunities.
            </p>

            <?php if (!empty($errors)): ?>
                <div class="vc-alert vc-alert-danger">
                    <?php foreach ($errors as $e): ?>
                        <div><?= htmlspecialchars($e) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post"
                  action="signup_org.php"
                  enctype="multipart/form-data"
                  class="vc-auth-form vc-auth-form-org">

                <!-- =====================
                     LEFT COLUMN – ACCOUNT
                ====================== -->
                <section class="vc-form-section">
                    <h3 class="vc-section-title">
                        <i class="fa-solid fa-user-lock"></i> Account Information
                    </h3>

                    <label class="vc-form-label">Username <span>*</span></label>
                    <input type="text" name="username"
                           class="vc-form-input"
                           placeholder="e.g. green_earth_org"
                           required value="<?= htmlspecialchars($old['username'] ?? '') ?>">

                    <label class="vc-form-label">Email <span>*</span></label>
                    <input type="email" name="email"
                           class="vc-form-input"
                           placeholder="contact@organization.org"
                           required value="<?= htmlspecialchars($old['email'] ?? '') ?>">

                    <label class="vc-form-label">Password <span>*</span></label>
                    <div class="vc-pw-wrap">
                        <input type="password"
                               name="password"
                               id="org-password"
                               class="vc-form-input"
                               placeholder="8–16 characters with uppercase & number"
                               required
                               value="<?= $old['password'] ?? '' ?>">
                        <button type="button"
                                class="vc-toggle-pw"
                                data-target="org-password">
                            <i class="fa-solid fa-eye"></i>
                        </button>
                    </div>

                    <div class="vc-pw-meter" id="org-meter"></div>
                    <div class="vc-pw-hint" id="org-hint"></div>

                    <label class="vc-form-label">Confirm Password <span>*</span></label>
                    <div class="vc-pw-wrap">
                        <input type="password"
                               name="password_confirm"
                               id="org-password-confirm"
                               class="vc-form-input"
                               placeholder="Re-enter your password"
                               required>
                        <button type="button"
                                class="vc-toggle-pw"
                                data-target="org-password-confirm">
                            <i class="fa-solid fa-eye"></i>
                        </button>
                    </div>

                    <div class="vc-pw-match" id="org-match"></div>
                </section>

                <!-- =====================
                     RIGHT COLUMN – ORG INFO
                ====================== -->
                <section class="vc-form-section">
                    <h3 class="vc-section-title">
                        <i class="fa-solid fa-building"></i> Organization Details
                    </h3>

                    <label class="vc-form-label">Profile Picture (Optional)</label>
                    <input type="file" name="profile_picture" accept="image/*">

                    <label class="vc-form-label">Organization Name <span>*</span></label>
                    <input type="text" name="org_name"
                           class="vc-form-input"
                           placeholder="e.g. Green Earth Initiative"
                           required value="<?= htmlspecialchars($old['org_name'] ?? '') ?>">

                    <label class="vc-form-label">Mission <span>*</span></label>
                    <textarea name="mission"
                              class="vc-form-input"
                              placeholder="Our mission is to promote sustainability through community action..."
                              required><?= htmlspecialchars($old['mission'] ?? '') ?></textarea>

                    <label class="vc-form-label">About Organization <span>*</span></label>
                    <textarea name="description"
                              class="vc-form-input"
                              placeholder="Tell volunteers what your organization does, your impact, and values."
                              required><?= htmlspecialchars($old['description'] ?? '') ?></textarea>

                    <label class="vc-form-label">Contact Number <span>*</span></label>
                    <input type="text" name="contact_info"
                           class="vc-form-input"
                           placeholder="+60 12-345 6789"
                           required value="<?= htmlspecialchars($old['contact_info'] ?? '') ?>">

                    <label class="vc-form-label">Address <span>*</span></label>
                    <input type="text" name="address"
                           class="vc-form-input"
                           placeholder="Street address, building name"
                           required value="<?= htmlspecialchars($old['address'] ?? '') ?>">

                    <label class="vc-form-label">Postcode <span>*</span></label>
                    <input type="text" name="postcode"
                           class="vc-form-input"
                           placeholder="e.g. 43000"
                           required value="<?= htmlspecialchars($old['postcode'] ?? '') ?>">

                    <label class="vc-form-label">Area, State, Country <span>*</span></label>
                    <div class="vc-form-row-2">
                        <select id="state_org" name="state_org" required>
                            <option value="">-- Select State --</option>
                        </select>

                        <select id="city_org" name="city_org" required>
                            <option value="">-- Select Town or Area --</option>
                        </select>
                    </div>

                    <select id="country_org" name="country_org">
                        <option value="Malaysia" selected>Malaysia</option>
                    </select>
                </section>

                <!-- SUBMIT -->
                <div class="vc-form-actions">
                    <button type="submit" name="final_submit" class="vc-btn-primary">
                        Complete Registration
                    </button>
                </div>

            </form>

            <p class="vc-auth-footer">
                Want to restart? <a href="signup.php">Go back</a>
            </p>

        </div>
    </div>

    <!-- RIGHT: MEDIA (1/3 WIDTH) -->
    <div class="vc-auth-media">
        <video autoplay muted loop playsinline>
            <source src="/volcon/assets/res/volcon-footage.mp4" type="video/mp4">
        </video>
        <div class="vc-auth-media-overlay"></div>
    </div>

</div>

<script>
    var OLD_STATE = "<?= htmlspecialchars($old['state_org'] ?? '') ?>";
    var OLD_CITY  = "<?= htmlspecialchars($old['city_org'] ?? '') ?>";
</script>

<script src="/volcon/assets/js/utils/location_selector.js"></script>
<script type="module" src="/volcon/assets/js/utils/form_utils.js"></script>
<script type="module" src="/volcon/assets/js/signup/signup_org.js"></script>
