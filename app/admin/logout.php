<?php
// logout.php
session_start();
session_destroy();
header("Location: adm_login.php");
exit();
?>
