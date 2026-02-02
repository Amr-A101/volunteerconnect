<link rel="stylesheet" href="/volcon/assets/css/login.css">

<div class="vc-auth-page">

    <!-- LEFT VISUAL PANEL -->
    <div class="vc-auth-visual">
        <div class="vc-auth-scroll-bg"></div>
    </div>

    <!-- RIGHT LOGIN PANEL -->
    <div class="vc-auth-form-wrapper">
        <div class="vc-auth-form">

            <img src="/volcon/assets/res/logo/volcon-logo.png" 
                 alt="Volunteer Connect Logo" 
                 class="vc-auth-logo">

            <h1>Welcome Back!</h1>
            <h2>Your community is waiting for you</h2>

            <?php if (!empty($error)): ?>
                <div class="vc-auth-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" novalidate>
                <input type="text" name="login_id" placeholder="Email or Username" required>
                <input type="password" name="password" placeholder="Password" required>

                <button type="submit">Sign In</button>
            </form>

            <p class="vc-auth-small">
                New here? <a href="/volcon/app/signup.php">Join now</a>
            </p>
            <p class="vc-auth-small">
                <a href="/volcon/app/reset_password.php">Forgot your password?</a>
            </p>

        </div>
    </div>

</div>
