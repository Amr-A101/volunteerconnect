<?php
if (!isset($GLOBALS['dbc'])) {

    $dbc = new mysqli("localhost", "root", "", "volcon");

    if ($dbc->connect_error) {
        die("Database connection failed: " . $dbc->connect_error);
    }

    $dbc->set_charset("utf8mb4");

    // Store in global scope for all other files
    $GLOBALS['dbc'] = $dbc;
}
?>