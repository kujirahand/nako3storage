<?php
// for clickjacking
header('X-Frame-Options: SAMEORIGIN');

// 「作品が動かない」報告 (#267)
// 実行画面(widget.php)はサンドボックスオリジンで表示されるためログイン状態が分からない。
// そのため実行画面からは本体オリジンのこのページを開き、ここでログイン確認・報告を行う。
function n3s_web_broken()
{
    $app_id = (int) (empty($_REQUEST['page']) ? '0' : $_REQUEST['page']);
    $app = ($app_id > 0) ? db_get1('SELECT app_id,title,is_private FROM apps WHERE app_id=?', [$app_id]) : null;
    if (!$app) {
        n3s_error('作品が見つかりません', '報告する作品が見つかりませんでした。');
        return;
    }
    if (! n3s_is_login()) {
        $login_url = htmlspecialchars(n3s_getURL('my', 'login'), ENT_QUOTES);
        n3s_error('ログインが必要です',
            "作品が動かないことを報告するには<a href=\"{$login_url}\">ログイン</a>してください。".
            "ログイン後、もう一度実行画面の報告ボタンを押してください。", true);
        return;
    }
    $q = empty($_REQUEST['q']) ? '' : $_REQUEST['q'];
    if ($q === 'up') {
        $res = n3s_broken_report($app_id);
        if (is_string($res)) {
            n3s_error('報告に失敗しました', $res);
            return;
        }
        // 1: 報告した / 2: 既に報告済み
        n3s_broken_template($app, $res['added'] ? 1 : 2, '');
        return;
    }
    if (n3s_broken_is_reported($app_id, n3s_get_user_id())) {
        n3s_broken_template($app, 2, '');
        return;
    }
    n3s_broken_template($app, 0, n3s_getEditToken());
}

function n3s_broken_template($app, $done, $edit_token)
{
    global $n3s_config;
    $params = [
        'broken_app_id' => intval($app['app_id']),
        // 非公開・限定公開の作品はタイトルを出さない
        'broken_title' => ($app['is_private'] == 0) ? $app['title'] : '',
        'broken_done' => $done,
        'broken_edit_token' => $edit_token,
    ];
    // n3s_template_fw() は $n3s_config (GETパラメータを含む) を優先するため、
    // ?broken_done=1 などで上書きされないよう $n3s_config 側にも強制する
    foreach ($params as $k => $v) {
        $n3s_config[$k] = $v;
    }
    n3s_template_fw('broken.html', $params);
}

// API: 報告件数を返す (報告は Web の確認ページから行う)
function n3s_api_broken()
{
    $app_id = (int) (empty($_REQUEST['page']) ? '0' : $_REQUEST['page']);
    try {
        $r = ($app_id > 0) ? db_get1('SELECT broken_report FROM apps WHERE app_id=?', [$app_id]) : null;
        echo $r ? intval($r['broken_report']) : 0;
    } catch (Exception $e) {
        error_log('n3s_broken error: ' . $e->getMessage());
        echo "0";
    }
}

// ログインユーザーが既にこの作品を「動かない」報告済みか
function n3s_broken_is_reported($app_id, $user_id)
{
    $r = db_get1('SELECT broken_report_id FROM broken_reports WHERE app_id=? AND user_id=?', [$app_id, $user_id]);
    return !empty($r);
}

// 「動かない」報告を1件記録する。報告者は broken_reports に記録し、1ユーザー1作品につき1回だけ数える。
// 成功時は ['count' => 報告後の件数, 'added' => 今回加算したか]、失敗時はエラーメッセージ(string)を返す
function n3s_broken_report($app_id)
{
    // 報告はDBを更新するため、CSRF対策としてPOST + edit_tokenを必須にする
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return '不正なリクエストです。';
    }
    if (! n3s_checkEditToken()) {
        return 'ページの有効期限が切れました。もう一度報告ボタンからやり直してください。';
    }
    $user_id = n3s_get_user_id();
    if (! n3s_is_login() || $user_id <= 0) {
        return 'ログインしてください。';
    }
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    $began = false;
    try {
        db_begin();
        $began = true;
        $r = db_get1('SELECT broken_report FROM apps WHERE app_id=?', [$app_id]);
        if (!$r) {
            db_rollback();
            return '作品が見つかりません。';
        }
        // UNIQUE(app_id, user_id) により、同時に報告されても二重には記録されない
        db_exec('INSERT OR IGNORE INTO broken_reports (app_id, user_id, ip, ctime) VALUES (?,?,?,?)',
            [$app_id, $user_id, $ip, time()]);
        $c = db_get1('SELECT changes() AS c');
        $added = ($c && intval($c['c']) > 0);
        if ($added) {
            // 管理者の報告は一発で+3
            $up_count = n3s_is_admin() ? 3 : 1;
            db_exec('UPDATE apps SET broken_report=broken_report+? WHERE app_id=?', [$up_count, $app_id]);
            $r = db_get1('SELECT broken_report FROM apps WHERE app_id=?', [$app_id]);
        }
        db_commit();
        return ['count' => intval($r['broken_report']), 'added' => $added];
    } catch (Exception $e) {
        if ($began) {
            try { db_rollback(); } catch (Exception $e2) { /* ignore */ }
        }
        // 例外の詳細(パスやSQLを含みうる)はサーバーログにのみ記録し、利用者へは返さない
        error_log('n3s_broken error: ' . $e->getMessage());
        return 'サーバーエラーが発生しました。';
    }
}
