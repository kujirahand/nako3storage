<?php
// 作品ページのショートカット
$id = (int) ($_SERVER['QUERY_STRING']);
if ($id === 0) {
    header('location:./index.php');
    echo '<a href="index.php">index</a>';
    exit;
}
$_GET['action'] = 'widget';
$_GET['page'] = $id;
include_once('index.php');
