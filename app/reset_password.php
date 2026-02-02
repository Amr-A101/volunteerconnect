<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/core/db.php";
require_once __DIR__ . "/core/flash.php";

$stage = 'email';
$error = null;

/* -----------------------------
   HANDLE POST
----------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* STAGE 1: VERIFY EMAIL */
    if (isset($_POST['email'])) {

        $email = trim($_POST['email']);

        $stmt = $dbc->prepare("
            SELECT user_id, status 
            FROM users 
            WHERE email = ? 
            LIMIT 1
        ");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if (!$user) {
            $error = "Email address not found.";
        } elseif ($user['status'] === 'suspended') {
            $error = "This account is suspended. Please contact support at support@volunteerconnect.org.";
        } else {
            $_SESSION['reset_user_id'] = $user['user_id'];
            $_SESSION['reset_email'] = $email; // Store for potential display
            $stage = 'password';
        }

    }

    /* STAGE 2: SET NEW PASSWORD */
    elseif (isset($_POST['new_password'])) {

        $password = $_POST['new_password'];
        $confirm  = $_POST['confirm_password'];

        if (!isset($_SESSION['reset_user_id'])) {
            $error = "Session expired. Please restart password reset.";
            $stage = 'email';
        }

        else {
            $uid = $_SESSION['reset_user_id'];

            // Fetch current user info
            $stmt = $dbc->prepare("
                SELECT email 
                FROM users 
                WHERE user_id = ? 
                LIMIT 1
            ");
            $stmt->bind_param("i", $uid);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();

            /* PASSWORD POLICY */
            if (!preg_match(
                '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,16}$/',
                $password
            )) {
                $error = "Password must be 8–16 characters and include uppercase, lowercase, number, and symbol.";
                $stage = 'password';
            }

            elseif ($password !== $confirm) {
                $error = "Passwords do not match.";
                $stage = 'password';
            }

            /* UPDATE PASSWORD */
            else {
                $newHash = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $dbc->prepare("
                    UPDATE users 
                    SET password = ? 
                    WHERE user_id = ?
                ");
                $stmt->bind_param("si", $newHash, $uid);
                $stmt->execute();

                // Clear reset session
                unset($_SESSION['reset_user_id']);
                unset($_SESSION['reset_email']);

                flash('success', 'Password reset successful. Please sign in with your new password.');
                header("Location: /volcon/app/login.php");
                exit;
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Reset Password - Volunteer Connect</title>

    <link rel="icon" type="image/png" href="/volcon/assets/res/logo/favicon.png">
    <link rel="shortcut icon" href="/volcon/assets/res/logo/favicon.ico">

    <link rel="stylesheet" href="/volcon/assets/css/signup.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>
<body>

<div class="vc-auth-layout">

    <!-- LEFT: FORM -->
    <div class="vc-auth-form-panel">

        <div class="vc-auth-form-wrapper">

            <h1 class="vc-auth-title"><i class="fa-solid fa-key"></i> Reset Password</h1>
            
            <?php if ($stage === 'email'): ?>
                <p class="vc-auth-subtitle">
                    Enter your registered email address to reset your password.
                </p>
            <?php else: ?>
                <p class="vc-auth-subtitle">
                    Create a new password for 
                    <strong><?= htmlspecialchars($_SESSION['reset_email'] ?? 'your account') ?></strong>
                </p>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="vc-alert vc-alert-danger">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($stage === 'email'): ?>

                <!-- EMAIL FORM -->
                <form method="post" class="vc-auth-form">
                    <label class="vc-form-label">Email Address</label>
                    <input type="email"
                           name="email"
                           class="vc-form-input"
                           placeholder="e.g. user@example.com"
                           required>

                    <button type="submit" class="vc-btn-primary">
                        Verify Email
                    </button>
                </form>

            <?php else: ?>

                <!-- PASSWORD FORM -->
                <form method="post" class="vc-auth-form">

                    <label class="vc-form-label">New Password</label>
                    <div class="vc-pw-wrap">
                        <input type="password"
                               name="new_password"
                               id="reset-password"
                               class="vc-form-input"
                               placeholder="8–16 characters with number & symbol"
                               required>
                        <button type="button"
                                class="vc-toggle-pw"
                                data-target="reset-password">
                            <i class="fa-solid fa-eye"></i>
                        </button>
                    </div>

                    <div class="vc-pw-meter" id="meter"></div>
                    <div class="vc-pw-hint" id="hint"></div>

                    <label class="vc-form-label">Confirm Password</label>
                    <div class="vc-pw-wrap">
                        <input type="password"
                               name="confirm_password"
                               id="reset-password-confirm"
                               class="vc-form-input"
                               placeholder="Re-enter password"
                               required>
                        <button type="button"
                                class="vc-toggle-pw"
                                data-target="reset-password-confirm">
                            <i class="fa-solid fa-eye"></i>
                        </button>
                    </div>
                    <div class="vc-pw-match" id="match"></div>

                    <button type="submit" class="vc-btn-primary">
                        Reset Password
                    </button>
                </form>

            <?php endif; ?>

            <p class="vc-auth-footer">
                <a href="/volcon/app/login.php">Back to Login</a>
                <?php if ($stage === 'password'): ?>
                    | <a href="?restart">Use a different email</a>
                <?php endif; ?>
            </p>

        </div>
    </div>

    <!-- RIGHT: MEDIA -->
    <div class="vc-auth-media">
        <img src="/volcon/assets/res/volcon-collage.jpg" alt="">
        <div class="vc-auth-media-overlay"></div>
    </div>

</div>

<!-- Password utilities (same as signup) -->
<script type="module" src="/volcon/assets/js/utils/form_utils.js"></script>
<script type="module" src="/volcon/assets/js/update/reset_password.js"></script>
<?php require_once __DIR__ . '/views/layout/footer.php'; ?>