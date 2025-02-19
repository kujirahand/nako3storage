<?php
// no api login
function n3s_api_logout()
{
    n3s_api_output('ng', ['msg' => 'should use web access']);
}

function n3s_web_logout()
{
    // set session
    n3s_logout();
    // message
    n3s_template_fw('basic.html', ['contents' => 'ログアウトしました。']);
}
