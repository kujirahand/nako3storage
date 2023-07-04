<?php
// for clickjacking
header('X-Frame-Options: SAMEORIGIN');

include_once __DIR__.'/save.inc.php';

function n3s_web_presave()
{
    $a = array();
    if (isset($_POST['body'])) {
        $a = $_POST;
    }
    n3s_action_save_check_param($a);

    // default presave value
    $a["load_src"] = 'no';

    // check mode
    $mode = empty($_GET['mode']) ? 'save' : $_GET['mode'];
    if ($mode === 'afterlogin') {
        $a['load_src'] = 'yes';
    }
  
    // ログインしていないとき、ログイン後このページに戻ってくるように
    if (! n3s_is_login()) {
        $_SESSION['n3s_on_after_login'] = n3s_getURL('0', 'presave', [
      'mode' => 'afterlogin',
    ]);
    }

    // 編集トークンを埋め込む
    $a['edit_token'] = n3s_getEditToken();
    n3s_template_fw('save.html', $a);
}
