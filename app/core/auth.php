<?php
// core/auth.php
date_default_timezone_set('Asia/Kuala_Lumpur');

if (session_status() == PHP_SESSION_NONE) {
    session_start(); // Start the session if it's not already started
}
require_once __DIR__ . "/db.php";

function require_login() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: /volcon/app/login.php");
        exit();
    }
}

function require_role($role) {
    require_login();
    if ($_SESSION['role'] !== $role) {
        header("Location: /volcon/app/login.php");
        exit();
    }
}

function current_user() {
    if (!isset($_SESSION['user_id'])) return null;

    static $cache = null;
    if ($cache !== null) return $cache;

    global $dbc;

    // Fetch base user
    $stmt = $dbc->prepare("
        SELECT user_id, email, role 
        FROM users 
        WHERE user_id = ? 
        LIMIT 1
    ");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) return null;

    /* ===============================
       LOAD ROLE-SPECIFIC PROFILE
       =============================== */
    if ($user['role'] === 'vol') {
        $stmt = $dbc->prepare("
            SELECT 
                vol_id,
                first_name,
                last_name,
                profile_picture
            FROM volunteers
            WHERE vol_id = ?
            LIMIT 1
        ");
        $stmt->bind_param("i", $user['user_id']);
        $stmt->execute();
        $profile = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $user = array_merge($user, $profile ?: []);

    } elseif ($user['role'] === 'org') {
        $stmt = $dbc->prepare("
            SELECT 
                org_id,
                name AS org_name,
                profile_picture
            FROM organizations
            WHERE org_id = ?
            LIMIT 1
        ");
        $stmt->bind_param("i", $user['user_id']);
        $stmt->execute();
        $profile = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $user = array_merge($user, $profile ?: []);
    }

    $cache = $user;
    return $cache;
}


function login_user($user_id, $role, $name) {
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user_id;
    $_SESSION['role']    = $role;
    $_SESSION['name']    = $name;
}

function redirect_if_logged_in() {
    if (!isset($_SESSION['user_id'])) return; // guest only

    // Redirect to dashboard based on role
    $role = $_SESSION['role'] ?? null;

    if ($role === 'vol') {
        header("Location: /volcon/app/dashboard_vol.php");
        exit;
    }

    if ($role === 'org') {
        header("Location: /volcon/app/dashboard_org.php");
        exit;
    }

    if ($role === 'admin') {
        header("Location: /volcon/app/dashboard_admin.php");
        exit;
    }
}


function timeAgo($datetime) {
    $time = is_numeric($datetime) ? (int)$datetime : strtotime($datetime);
    $diff = time() - $time;

    if ($diff < 5) {
        return 'just now';
    } elseif ($diff < 60) {
        return formatTime($diff, 'second');
    } elseif ($diff < 3600) {
        return formatTime(floor($diff / 60), 'minute');
    } elseif ($diff < 86400) {
        return formatTime(floor($diff / 3600), 'hour');
    } elseif ($diff < 172800) {
        return 'yesterday';
    } elseif ($diff < 604800) {
        return formatTime(floor($diff / 86400), 'day');
    } elseif ($diff < 2592000) {
        return formatTime(floor($diff / 604800), 'week');
    } elseif ($diff < 31536000) {
        return formatTime(floor($diff / 2592000), 'month');
    } else {
        return formatTime(floor($diff / 31536000), 'year');
    }
}

function formatTime($value, $unit) {
    $value = (int) $value;
    return $value . ' ' . $unit . ($value === 1 ? '' : 's') . ' ago';
}


?>