<?php
require_once __DIR__ . "/core/auth.php";
redirect_if_logged_in();

require_once __DIR__ . "/controllers/SignupVolController.php";

$controller = new SignupVolController();
$controller->showVolStep();
?>