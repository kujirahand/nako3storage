<?php
include_once dirname(__FILE__).'/save.inc.php';

function n3s_web_presave() {
  $a = array();
  if (isset($_POST['body'])) {
    $a = $_POST;
  }
  n3s_action_save_check_param($a);

  // default presave value
  $a["rewrite"] = 'save';

  // check mode
  $mode = empty($_GET['mode']) ? 'save' : $_GET['mode'];
  if ($mode == 'afterlogin') {
    $a['rewrite'] = 'load';
  }
  
  // ログインしていないとき、ログイン後このページに戻ってくるように
  if (!n3s_is_login()) {
    $_SESSION['n3s_on_after_login'] = n3s_getURL('0', 'presave', [
      'mode' => 'afterlogin',
    ]);
  }

  n3s_template_fw('save.html', $a);
}
