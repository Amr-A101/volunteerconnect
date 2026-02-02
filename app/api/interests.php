<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../models/InterestModel.php';

header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');

$model = new InterestModel($GLOBALS['dbc']);
$all = $model->all();

if ($q !== '') {
    $all = array_filter($all, function($row) use ($q) {
        return stripos($row['interest_name'], $q) !== false;
    });
}

echo json_encode(array_values($all));
?>