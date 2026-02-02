<?php
// profile_vol.php (router for viewing a volunteer profile)
require_once __DIR__ . "/controllers/ProfileVolunteerController.php";

$controller = new ProfileVolunteerController();
$controller->index();
?>