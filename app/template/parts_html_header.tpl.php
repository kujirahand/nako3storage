<?php
global $n3s_config;
if (empty($page_title)) $page_title = "nako3storage";
if (empty($n3s_config['search_word'])) $n3s_config['search_word'] = "";
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title><?php echo $page_title ?></title>
    <meta name="description" content="nako3storage">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="skin/def/n3s.css">
</head>
<body>
<div id="n3s_header">
    <span><?php echo $page_title ?></span>
    <span>[<a href="index.php?page=all&amp;action=list">一覧</a>]</span>
    <span>[<a href="index.php?page=new&amp;action=show">新規</a>]</span>
    <form action="index.php">
        <input type="hidden" name="action" value="list">
        <input title="search word" name="search_word" placeholder="作者かタイトル(一部分)を入力" value="<?php echo $n3s_config['search_word'] ?>">
        <input type="submit" value="検索">
    </form>
</div>
