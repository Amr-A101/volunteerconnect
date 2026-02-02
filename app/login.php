<?php

require_once "controllers/LoginController.php";
$controller = new LoginController();

// handle login POST
$controller->handleLogin();
$controller->index();


?>