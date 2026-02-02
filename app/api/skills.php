<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../models/SkillModel.php';

header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');

$model = new SkillModel($GLOBALS['dbc']);
$all = $model->all();

if ($q !== '') {
    $all = array_filter($all, function($row) use ($q) {
        return stripos($row['skill_name'], $q) !== false;
    });
}

echo json_encode(array_values($all));
?>