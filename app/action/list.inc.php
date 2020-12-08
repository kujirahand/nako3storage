<?php
include_once dirname(__FILE__) . '/save.inc.php';

const MAX_APP = 30; // 何件まで表示するか

function n3s_web_list()
{
    $r = n3s_list_get();
    n3s_template_fw('list.html', $r);
}

function n3s_api_list()
{
    $r = n3s_list_get();
    $res = array();
    foreach ($r['list'] as $row) {
        $res[] = array(
            "app_id" => $row['app_id'],
            "title" => $row['title'],
            "author" => $row['author'],
            "memo" => $row['memo'],
            "mtime" => $row['mtime'],
        );
    }
    n3s_api_output(true, array(
        "list" => $res
    ));
}

function n3s_list_get()
{
    global $n3s_config;
    // get parameters
    $n3s_config['search_word'] = isset($_GET['search_word']) ? $_GET['search_word'] : '';
    $nofilter = empty($_GET['nofilter']) ? 0 : intval($_GET['nofilter']);
    // get db
    $db = n3s_get_db();
    // list
    $app_id = intval(empty($n3s_config['app_id']) ? 0 : $n3s_config['app_id']);
    if ($app_id <= 0) $app_id = PHP_INT_MAX;
    $wheres = array('app_id <= ?');
    if ($nofilter < 1) { // has filter ?
      $wheres[] = 'bad < 2';
    }
    $statements = array($app_id);
    if (!empty($n3s_config['search_word'])) {
        $wheres[] = 'author = ? OR title LIKE ?';
        $statements[] = $n3s_config['search_word'];
        $statements[] = "%".$n3s_config['search_word']."%";
    }
    $wheres[] = 'is_private = 0';
    $statements[] = MAX_APP;
    $h = $db->prepare('SELECT app_id,title,author,memo,mtime,fav FROM apps ' .
        ' WHERE ' . implode(' AND ', $wheres) .
        ' ORDER BY app_id DESC LIMIT ?');
    $h->execute($statements);
    $list = $h->fetchAll();
    // next
    $min_id = PHP_INT_MAX;
    foreach ($list as &$row) {
        n3s_action_save_check_param($row);
        $id = $row['app_id'];
        if ($min_id >= $id) $min_id = $id - 1;
    }
    $next_url = n3s_getURL('all', 'list', array('app_id' => $min_idi, 'nofilter' => $nofilter));
    if ($app_id === 0) $next_url = ""; // トップなので次はない
    return array(
        "list" => $list,
        "next_url" => $next_url,
    );
}
