<?php
// テスト用の共通セットアップ。
// アプリ本体は素の手続き型PHP (fw_simple) で書かれておりオートロードが無いため、
// 必要なファイルを一度だけ読み込み、DBやセッションはテストごとに使い捨てのものへ差し替える。

declare(strict_types=1);

define('N3S_TEST_ROOT', dirname(__DIR__));
define('N3S_TEST_TMP', sys_get_temp_dir() . '/nako3storage-tests-' . getmypid());

if (!is_dir(N3S_TEST_TMP)) {
    mkdir(N3S_TEST_TMP, 0777, true);
}

// デフォルト設定 (定数含む) を一度だけ読み込む。$n3s_config はテストごとに上書きする。
require_once N3S_TEST_ROOT . '/app/n3s_config.def.php';
require_once N3S_TEST_ROOT . '/app/n3s_lib.inc.php';
require_once N3S_TEST_ROOT . '/app/action/login.inc.php';
require_once N3S_TEST_ROOT . '/app/action/logout.inc.php';

/**
 * 各テストの前に呼び出す。$n3s_config・DB接続・セッション・スーパーグローバルを
 * すべてクリーンな状態(使い捨てのSQLiteファイル)に作り直す。
 */
function n3s_test_setup(array $config_overrides = []): string
{
    global $n3s_config, $FW_DB_INFO;

    $dir = N3S_TEST_TMP . '/' . uniqid('t', true);
    mkdir($dir, 0777, true);
    mkdir($dir . '/cache', 0777, true);

    $n3s_config['dir_sql'] = N3S_TEST_ROOT . '/app/sql';
    $n3s_config['dir_template'] = N3S_TEST_ROOT . '/app/template';
    $n3s_config['dir_cache'] = $dir . '/cache';
    $n3s_config['dir_data'] = $dir;
    $n3s_config['file_db_users'] = 'sqlite:' . $dir . '/n3s_users.sqlite';
    $n3s_config['file_db_log'] = 'sqlite:' . $dir . '/n3s_log.sqlite';
    $n3s_config['file_db_main'] = 'sqlite:' . $dir . '/n3s_main.sqlite';
    $n3s_config['baseurl'] = 'http://localhost';
    $n3s_config['admin_users'] = [1];
    $n3s_config['news_at_login'] = '';
    unset($n3s_config['edit_token']);
    // $n3s_config['page'] / ['action'] は本番では n3s_parseURI() が毎リクエスト
    // $_GET から作り直すが、テストでは n3s_parseURI() を呼ばないため、
    // 前のテストが (n3s_get_config('page', ...) を使う経路のために) 直接
    // $n3s_config['page'] をセットしていると次のテストへ残ってしまう。
    // ここで明示的にリセットし、テスト間の汚染を防ぐ。
    unset($n3s_config['page']);
    unset($n3s_config['mode']);

    foreach ($config_overrides as $key => $value) {
        $n3s_config[$key] = $value;
    }

    // DB接続をリセットし、使い捨てファイルに向ける (存在しなければ init-*.sql で自動作成される)
    // n3s_db_init() と同じく main/log/users の3つを登録する。
    $FW_DB_INFO = [];
    database_set($n3s_config['file_db_main'], $n3s_config['dir_sql'] . '/init-main.sql', 'main');
    database_set($n3s_config['file_db_users'], $n3s_config['dir_sql'] . '/init-users.sql', 'users');
    database_set($n3s_config['file_db_log'], $n3s_config['dir_sql'] . '/init-log.sql', 'log');

    // スーパーグローバルのリセット
    $_GET = [];
    $_POST = [];
    $_REQUEST = [];
    $_SESSION = [];
    $_SERVER['HTTP_HOST'] = 'localhost';
    // 通常運用時と同じく REMOTE_ADDR を設定しておく (docs/user_login.md #9:
    // REMOTE_ADDR が空のときだけ通る特殊なコードパスがあるため、既定では避ける)
    $_SERVER['REMOTE_ADDR'] = '203.0.113.10';

    return $dir;
}

/**
 * n3s_add_user() 相当だが、後方互換 (salt無し / def:: プレフィックス) の
 * 既存ユーザーを再現するためにDBへ直接書き込む。
 */
function n3s_test_add_legacy_user(string $email, string $password, string $name): int
{
    $hash = n3s_login_password_to_hash($password, '');
    $user_id = db_insert(
        'INSERT INTO users (email, password, name, salt) VALUES (?,?,?,?)',
        [$email, $hash, $name, ''],
        'users'
    );
    return (int) $user_id;
}

/**
 * 画面出力(echo/テンプレート描画)を伴う関数呼び出しを実行し、出力文字列を返す。
 * 呼び出した関数の戻り値が必要な場合は n3s_test_call() を使うこと。
 */
function n3s_test_capture(callable $fn): string
{
    ob_start();
    try {
        $fn();
    } finally {
        $out = ob_get_clean();
    }
    return $out === false ? '' : $out;
}

/**
 * 画面出力を捨てつつ、関数呼び出しの戻り値をそのまま返す。
 */
function n3s_test_call(callable $fn)
{
    ob_start();
    try {
        return $fn();
    } finally {
        ob_end_clean();
    }
}
