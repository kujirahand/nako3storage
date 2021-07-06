<?php
// redirect HTTP => HTTPS
if (empty($_SERVER['HTTPS']) &&
    ($_SERVER['HTTP_HOST'] == 'n3s.nadesi.com')) {
  $url = 'https://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
  header("location: $url");
  exit;
}

require_once __DIR__.'/n3s_lib.inc.php';

n3s_main();

function n3s_main()
{
    n3s_db_init();  // n3s_lib.inc.php
    n3s_parseURI(); // n3s_lib.inc.php
    n3s_action();
}

function n3s_action()
{
    global $n3s_config;
    // 実行アクションの指定
    $action = $n3s_config['action'];
    $agent = $n3s_config['agent'];
    // サニタイズ処理
    // アルファベット以外のアクションを削除
    $action = preg_replace('/([^a-zA-Z0-9_]+)/', '', $action);
    $agent = preg_replace('/([^a-zA-Z0-9_]+)/', '', $agent);
    // モジュールを取得
    $file_action = $n3s_config['dir_action'] . "/$action.inc.php";
    $func_action = "n3s_{$agent}_{$action}";

    // WEBであればセッションを使う
    if ($agent === 'web') {
        session_start();
    }
    if (file_exists($file_action)) {
        include_once $file_action;
        if (function_exists($func_action)) {
            call_user_func($func_action);
            return;
        }
    }
    // アクションがなければエラーを表示
    n3s_error('不正なページ', 'アクションが見当たりません。');
    exit;
}
