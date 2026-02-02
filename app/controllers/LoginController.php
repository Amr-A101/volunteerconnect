<?php
// app/controllers/LoginController.php

require_once __DIR__ . "/../core/db.php";
require_once __DIR__ . "/../core/auth.php";
require_once __DIR__ . "/../models/UserModel.php";

class LoginController {

    protected $dbc;
    protected $userModel;
    public $error = "";

    public function __construct() {
        // uses shared db connection
        $this->dbc = $GLOBALS['dbc'];
        $this->userModel = new UserModel($this->dbc);
    }

    public function handleLogin() 
    {
        if ($_SERVER["REQUEST_METHOD"] !== "POST") return;

        $loginId  = trim($_POST['login_id'] ?? '');
        $password = $_POST['password'] ?? '';

        // 1. Lookup user
        $user = $this->userModel->getUserByLoginId($loginId);

        if (!$user) {
            $this->error = "Username or email not found.";
            return;
        }

        // 2. Verify password
        if (!password_verify($password, $user['password'])) {
            $this->error = "Incorrect password.";
            return;
        }

        // 3. Suspended
        if ($user['status'] === 'suspended') {
            $this->error = "Your account has been suspended. Contact support at support@volunteerconnect.org";
            return;
        }

        // 4. EMAIL NOT VERIFIED (VOLUNTEER) //got issue with SSL certs
        // if ($user['role'] === 'vol' && (int)$user['email_verified'] === 0) {
        if ($user['role'] === 'vol' && $user['status'] === 'pending') {
            $this->error = "Please check and verify your email before logging in.";
            return;
        }

        // 5. Resolve display name
        $name = $this->resolveDisplayName($user);

        // 6. Login (NOW it is safe)
        session_regenerate_id(true);
        login_user($user['user_id'], $user['role'], $name);

        // 7. Redirect
        if ($user['role'] === 'org' && $user['status'] !== 'verified') {
            header("Location: /volcon/app/verify_org.php");
            exit;
        }

        if ($user['role'] === 'vol') {
            header("Location: /volcon/app/dashboard_vol.php");
        } elseif ($user['role'] === 'org') {
            header("Location: /volcon/app/dashboard_org.php");
        } elseif ($user['role'] === 'admin') {
            header("Location: /volcon/app/dashboard_adm.php");
        }

        exit();
    }


    /**
     * Resolve human-readable display name for session.
     */
    protected function resolveDisplayName($user)
    {
        // Default
        $name = "User";

        if ($user['role'] === 'vol') {
            $p = $this->userModel->getVolunteerName($user['user_id']);
            if ($p) {
                $fn = trim($p['first_name'] ?? '');
                $ln = trim($p['last_name'] ?? '');
                $name = trim("$fn $ln") ?: "Volunteer";
            }
        }

        if ($user['role'] === 'org') {
            $p = $this->userModel->getOrganizationName($user['user_id']);
            $name = $p['name'] ?? "Organization";
        }

        if ($user['role'] === 'admin') {
            $name = "Administrator";
        }

        return $name;
    }

    public function index() 
    {
        $page_title = "Login";
        $error = $this->error;

        redirect_if_logged_in();
        require_once __DIR__ . '/../views/layout/header.php';  

        require __DIR__ . '/../views/login_view.php';

        require_once __DIR__ . '/../views/layout/footer.php';
    }
}
?>