<?php
// profile_org.php (router for viewing an organization profile)
require_once __DIR__ . "/controllers/ProfileOrganizationController.php";

$controller = new ProfileOrganizationController();
$controller->index();
?>