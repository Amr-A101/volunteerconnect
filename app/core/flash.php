<?php
// app/core/flash.php

// Check if the session is not started yet
if (session_status() == PHP_SESSION_NONE) {
    session_start(); // Start the session if it's not already started
}

/**
 * Set a flash message to display on next page load.
 * Types: success, error, info
 */
function flash(string $type, string $message): void {
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

/**
 * Get current flash message (if any) and remove it.
 */
function get_flash(): ?array {
    if (!empty($_SESSION['flash'])) {
        $msg = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $msg;
    }
    return null;
}
