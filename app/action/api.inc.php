<?php
// API
//
// for clickjacking
header('X-Frame-Options: SAMEORIGIN');

function n3s_web_api()
{
    echo "not supported";
}
function n3s_api_api()
{
    // get parametes
    $api_token = isset($_GET['token']) ? $_GET['token'] : '';
    $page = isset($_GET['page']) ? $_GET['page'] : '';
    $key = isset($_GET['key']) ? $_GET['key'] : '';
    $value = isset($_GET['value']) ? $_GET['value'] : '';
    $default = isset($_GET['default']) ? $_GET['default'] : '';
    $user_id = intval(isset($_GET['user_id']) ? $_GET['user_id'] : '0');

    // echo $app_id.":". $api_token . ":" . $page;
    // check token
    if ($api_token === '') {
        api_error('token is empty'); exit;
    }

    // is_logined
    if ($page === 'is_logined') {
        $r = n3s_is_login();
        n3s_api_output($r, ["logined"=>$r]);
        exit;
    }
    // username_get
    if ($page === 'username_get') {
        $r = n3s_getUserInfo($user_id);
        if ($r) {
            n3s_api_output(true, ["name" => $r['name']]);
        } else {
            n3s_api_output(false, ["reason"=>"invalid user_id"]);
        }
        exit;
    }

    // get common info
    $app_id = isset($_SESSION["api_token::$api_token"]) ? $_SESSION["api_token::$api_token"] : 0;
    $user_id = n3s_get_user_id();
    // db path dir_astorage
    $dir_astorage = n3s_get_config('dir_astorage', '');
    if (!file_exists($dir_astorage)) {
        api_error('[SYSTEM ERROR] dir_astorage could not write...'); exit;
    }

    // astorage_put
    if ($page == 'astorage_put') {

    }

    // astorage_get
    if ($page == 'astorage_get') {
    }

}

function api_error($msg)
{
    n3s_api_output(false, ['error' => $msg]);
    exit;
}
