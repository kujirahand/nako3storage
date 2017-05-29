<?php
include_once dirname(__FILE__).'/save.inc.php';

function n3s_action_show() {
  global $n3s_config;
  $app_id = intval($_GET['page']);
  /*
  if ($app_id === 0) {
    $url = $n3s_config['baseurl'].'/index.php?all&list';
    header('location: '.$url);
    return;
  }
  */
  $db = n3s_get_db();
  if ($app_id > 0) {
    $sql = "SELECT * FROM apps WHERE app_id=$app_id";
    $a = array();
    $a = $db->query($sql)->fetch();
    if (!$a) {
      n3s_error('プログラムがありません',
        "<p>app_id={$app_id}のプログラムはありません。</p>".
        "<p><a href='index.php?new&amp;show'>→新規作成</a><br />".
        "<a href='index.php?all&amp;list'>→一覧を見る</a></p>");
      exit;
    }
  } else {
    $a = array();
  }
  n3s_action_save_check_param($a);
  n3s_template('show', $a);
}
