<?php
include_once dirname(__FILE__).'/save.inc.php';

function n3s_action_list() {
  $db = n3s_get_db();
  $app_id = intval(empty($_GET['app_id']) ? 0 : $_GET['app_id']);
  $h = $db->prepare(
    'SELECT app_id,title,author,memo,mtime FROM apps '.
    ' WHERE app_id >= ? '.
    ' AND is_private = 0'.
    ' ORDER BY app_id DESC LIMIT 30');
  $h->execute(array($app_id));
  $list = $h->fetchAll();
  $next_id = 0;
  foreach ($list as &$row) {
    n3s_action_save_check_param($row);
    if ($next_id == 0) $next_id = $row['app_id'] + 1;
  }
  n3s_template('list', array(
    "list" => $list,
    "next" => "<a href='index.php?all&amp;list&amp;app_id=$next_id'>次を見る</a>",
  ));
}
