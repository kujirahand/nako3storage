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
    $dbPath = $dir_astorage."/{$user_id}.json";

    // astorage_put
    if ($page == 'astorage_put') {
        $data = [];
        if (file_exists($dbPath)) {
            $data = json_decode(file_load($dbPath), true);
        }
        if (empty($data[$app_id])) {
            $data[$app_id] = [];
        }
        $data[$app_id][$key] = $value;
        file_save($dbPath, json_encode($data));
        n3s_api_output(true, ['message' => "saved."]);
        exit;
    }

    // astorage_get
    if ($page == 'astorage_get') {
        if (!file_exists($dbPath)) {
            n3s_api_output(true, ['value' => $default]);
            exit;
        }
        $data = json_decode(file_load($dbPath), true);
        $value = empty($data[$app_id][$key]) ? $default : $data[$app_id][$key];
        n3s_api_output(true, ['value' => $value]);
        exit;
    }

    // astorage_keys
    if ($page == 'astorage_keys') {
        if (!file_exists($dbPath)) {
            n3s_api_output(true, ["keys" => []]);
            exit;
        }
        $data = json_decode(file_load($dbPath), true);
        $kv = empty($data[$app_id]) ? [] : $data[$app_id];
        $keys = [];
        foreach ($kv as $key => $val) {
            $keys[] = $key;
        }
        n3s_api_output(true, ['keys' => $keys]);
        exit;
    }

    api_error("no page");
}

function api_error($msg)
{
    n3s_api_output(false, ['reason' => $msg]);
    exit;
}

function file_save($filename, $data)
{
    // ファイルを書き込みモードで開く
    $file = fopen($filename, 'w');

    // ファイルロックの取得（排他ロック）
    if (flock($file, LOCK_EX)) {
        // データをファイルに書き込む
        fwrite($file, $data);

        // ロックを解除
        flock($file, LOCK_UN);
    } else {
        // ロックが取得できなかった場合はエラーを表示
        n3s_api_output(false, ['reason' => 'ロックを取得できませんでした。']);
    }

    // ファイルを閉じる
    fclose($file);
}

function file_load($filename)
{
    // ファイルを読み込みモードで開く
    $file = fopen($filename, 'r');

    // ファイルロックの取得（共有ロック）
    if (flock($file, LOCK_SH)) {
        // ファイルからデータを読み込む
        $data = fread($file, filesize($filename));

        // ロックを解除
        flock($file, LOCK_UN);
    } else {
        // ロックが取得できなかった場合はエラーを表示
        n3s_api_output(false, ['reason' => 'ロックを取得できませんでした。']);
        $data = false;
    }

    // ファイルを閉じる
    fclose($file);

    return $data;
}
