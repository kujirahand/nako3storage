<?php
$id = intval($_SERVER['QUERY_STRING']);
if ($id === 0) {
    header('location:./index.php');
    echo '<a href="index.php">index</a>';
    exit;
}
$uri = "show.php?app_id=$id";
header("location:./$uri");
echo "<a href=\"$uri\">Show Program</a>";
