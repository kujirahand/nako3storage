<?php
// 作品ページのショートカット
$id = intval($_SERVER['QUERY_STRING']);
if ($id <= 0) {
    $id = empty($_GET['id']) ? 0 : intval($_GET['id']);
    if ($id <= 0) {
        $id = empty($_GET['page']) ? 0 : intval($_GET['page']);
        if ($id <= 0) {
            header('location:./index.php');
            echo '<a href="index.php">index</a>';
            exit;
        }
    }
}
// 作品ページのショートカット
$_GET['action'] = 'widget';
$_GET['page'] = $id;
include_once('index.php');
