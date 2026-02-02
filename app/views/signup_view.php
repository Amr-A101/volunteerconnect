<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Sign up - Volunteer Connect</title>

    <link rel="icon" type="image/png" href="/volcon/assets/res/logo/favicon.png">
    <link rel="shortcut icon" href="/volcon/assets/res/logo/favicon.ico">
    
    <link rel="stylesheet" href="/volcon/assets/css/signup.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
</head>
<body>

<div class="vc-auth-layout">

    <!-- LEFT: SIGNUP FORM -->
    <div class="vc-auth-form-panel">

        <div class="vc-auth-form-wrapper">
            <img src="/volcon/assets/res/logo/volcon-logo.png" 
                 alt="Volunteer Connect Logo" 
                 class="vc-auth-logo">
                 
            <h1 class="vc-auth-title">Create Your Account</h1>
            <p class="vc-auth-subtitle">Join Volunteer Connect and start making impact.</p>

            <?php if (!empty($errors)): ?>
                <div class="vc-alert vc-alert-danger">
                    <?php foreach ($errors as $e): ?>
                        <div><?= htmlspecialchars($e) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post" action="signup.php" class="vc-auth-form">

                <label class="vc-form-label">Username</label>
                <input type="text" name="username"
                       class="vc-form-input"
                       placeholder="e.g. daniel_yusof13"
                       required value="<?= htmlspecialchars($old['username'] ?? '') ?>">

                <label class="vc-form-label">Email</label>
                <input type="email" name="email"
                       class="vc-form-input"
                       placeholder="e.g. daniel@example.com"
                       required value="<?= htmlspecialchars($old['email'] ?? '') ?>">

                <!-- PASSWORD -->
                <label class="vc-form-label">Password</label>
                <div class="vc-pw-wrap">
                    <input type="password"
                           name="password"
                           id="password"
                           class="vc-form-input"
                           placeholder="8–16 characters with uppercase, number & symbol"
                           required>
                    <button
                        type="button"
                        class="vc-toggle-pw"
                        data-target="password">
                        <i class="fa-solid fa-eye"></i>
                    </button>
                </div>

                <div class="vc-pw-meter" id="meter"></div>
                <div class="vc-pw-hint" id="hint"></div>
                <div class="vc-pw-errors" id="pwErrors"></div>

                <!-- ROLE -->
                <label class="vc-form-label">Select Role</label>
                <select name="role" class="vc-form-input" required>
                    <option value="">-- Choose Role --</option>
                    <option value="vol" <?= ($old['role'] ?? '') === 'vol' ? 'selected' : '' ?>>Volunteer</option>
                    <option value="org" <?= ($old['role'] ?? '') === 'org' ? 'selected' : '' ?>>Organization</option>
                </select>

                <button type="submit" class="vc-btn-primary">Continue</button>
            </form>

            <p class="vc-auth-footer">
                Already registered? <a href="login.php">Login here</a>
            </p>
        </div>
    </div>

    <!-- RIGHT: LOOPING MEDIA -->
    <div class="vc-auth-media">
        <video autoplay muted loop playsinline>
            <source src="/volcon/assets/res/volcon-footage.mp4" type="video/mp4">
        </video>
        <div class="vc-auth-media-overlay"></div>
    </div>

</div>

<script type="module" src="/volcon/assets/js/utils/form_utils.js"></script>
<script type="module" src="/volcon/assets/js/signup/signup_step1.js"></script>

