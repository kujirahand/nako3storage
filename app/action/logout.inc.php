<?php
// no api login
function n3s_api_logout()
{
    n3s_api_output('ng', ['msg'=>'should use web access']);
}

function n3s_web_logout()
{
    // logout info
    $name = empty($_SESSION['name']) ? '?' : $_SESSION['name'];
    $user_id = empty($_SESSION['user_id']) ? 0 : $_SESSION['user_id'];
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    // set session
    unset($_SESSION['n3s_login']);
    unset($_SESSION['user_id']);
    unset($_SESSION['n3s_backurl']);
    unset($_SESSION['name']);
    // log
    if ($user_id > 0) {
        n3s_log("user_id=$user_id,name={$name},ip={$ip}", "logout", 0);
    }
    // message
    n3s_template_fw('basic.html', ['contents'=>'ログアウトしました。']);
}
