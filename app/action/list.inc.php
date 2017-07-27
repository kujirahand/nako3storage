<?php
include_once dirname(__FILE__) . '/save.inc.php';

const MAX_APP = 50; // 何件まで表示するか

function n3s_web_list()
{
    $r = n3s_list_get();
    n3s_template('list', $r);
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
    $db = n3s_get_db();
    // list
    $app_id = intval(empty($_GET['app_id']) ? 0 : $_GET['app_id']);
    if ($app_id <= 0) $app_id = PHP_INT_MAX;
    $h = $db->prepare(
        'SELECT app_id,title,author,memo,mtime FROM apps ' .
        ' WHERE app_id <= ? ' .
        ' AND is_private = 0' .
        ' ORDER BY app_id DESC LIMIT ?');
    $h->execute(array($app_id, MAX_APP));
    $list = $h->fetchAll();
    // next
    $min_id = PHP_INT_MAX;
    foreach ($list as &$row) {
        n3s_action_save_check_param($row);
        $id = $row['app_id'];
        if ($min_id >= $id) $min_id = $id - 1;
    }
    $next_url = n3s_getURL('all', 'list', array('app_id' => $min_id));
    if ($app_id === 0) $next_url = ""; // トップなので次はない
    return array(
        "list" => $list,
        "next_url" => $next_url,
    );
}
