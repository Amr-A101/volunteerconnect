<?php 
if (session_status() == PHP_SESSION_NONE) {
    session_start(); // Start the session if it's not already started
}
session_destroy(); 
header("Location: " . "/volcon/app/login.php"); 

exit();