<?php
// app/verify_vol.php

require_once __DIR__ . "/core/db.php";
require_once __DIR__ . "/core/flash.php";

$token = $_GET['token'] ?? '';

if (!$token) {
    flash('error', 'Invalid verification link.');
    header("Location: login.php");
    exit;
}

$stmt = $dbc->prepare("
    UPDATE users
    SET email_verified = 1,
        status = 'verified',
        verify_token = NULL
    WHERE verify_token = ?
    LIMIT 1
");
$stmt->bind_param("s", $token);
$stmt->execute();

if ($stmt->affected_rows === 1) {
    flash('success', 'Email verified successfully. You may now login.');
} else {
    flash('error', 'Verification link is invalid or already used.');
}

$stmt->close();
header("Location: login.php");
exit;
