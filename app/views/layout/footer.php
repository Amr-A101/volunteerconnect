<?php
    // $role
    if (session_status() == PHP_SESSION_NONE) {
        session_start(); // Start the session if it's not already started
    }

    $role = isset($_SESSION['role']) ? $_SESSION['role'] : null;

    $footer_css = "/volcon/assets/css/layout/footer_guest.css";
    if ($role === 'vol') $footer_css = "/volcon/assets/css/layout/footer_vol.css";
    if ($role === 'org') $footer_css = "/volcon/assets/css/layout/footer_org.css";

?>

    </div>

    <?php require_once __DIR__ . '/../components/chat.php'; ?>
    
    <footer class="vc-footer">
        <div class="vc-footer-main-content"> <div class="vc-footer-container">

                <div class="vc-footer-col vc-col-brand-message">
                    <div class="vc-footer-logo">
                        <a href="/volcon" class="vc-logo">
                            <img src="/volcon/assets/res/logo/volcon-logo.png" alt="Volunteer Connect Logo" style="height: 56px;">
                        </a>
                    </div>
                    <div class="vc-footer-brand-tagline"> <?php if ($role === 'vol'): ?>
                            <p>As a volunteer, check new opportunities on the <a href="/volcon/app/browse_opportunities.php">Opportunities Page</a>.</p>
                        <?php elseif ($role === 'org'): ?>
                            <p>As an organization, manage your opportunities in the <a href="/volcon/app/my_opportunities.php">My Opportunities</a> section.</p>
                        <?php else: ?>
                            <p>Connecting volunteers with organizations to make a difference.</p> <?php endif; ?>
                    </div>
                </div>

                <div class="vc-footer-col vc-col-links">
                    <h4 class="vc-footer-heading">System</h4>
                    <nav class="vc-footer-nav vc-nav-company">
                        <a href="#">About Us</a>
                        <a href="#">Contact Us</a>
                        <a href="#">Careers</a>
                    </nav>
                </div>

                <div class="vc-footer-col vc-col-links">
                    <h4 class="vc-footer-heading">Legal & Support</h4>
                    <nav class="vc-footer-nav vc-nav-legal">
                        <a href="#">Terms of Service</a>
                        <a href="#">Privacy Policy</a>
                        <a href="/volcon/app/admin/adm_login.php">FAQ</a>
                    </nav>
                </div>
            </div> </div> <div class="vc-footer-bottom-row">
            <div class="vc-footer-bottom-container"> <p>&copy; <?php echo date('Y'); ?> Volunteer Connect. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <link rel="stylesheet" href="/volcon/assets/css/layout/body_base.css">
    <link rel="stylesheet" href="/volcon/assets/css/layout/footer_base.css">
    <link rel="stylesheet" href="<?= $footer_css ?>">

    <script src="/volcon/assets/js/alerts.js"></script>

</body>
</html>