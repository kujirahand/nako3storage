<?php
// no api login
function n3s_api_logout()
{
    n3s_api_output($ng, ['msg'=>'should use web access']);
}

function n3s_web_logout()
{
    // set session
    unset($_SESSION['n3s_login']);
    unset($_SESSION['user_id']);
    // message
    n3s_template_fw('basic.html', ['contents'=>'ログアウトしました。']);
}




