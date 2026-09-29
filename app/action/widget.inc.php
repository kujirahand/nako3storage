<?php
include_once __DIR__ . '/widget_frame.inc.php';

// * <iframe>のsandboxを使う (#132)
// widget.inc.php => widget_frame.html [iframe.src=action=widget_frame]
// widget_frame.inc.php => widget.html

function n3s_web_widget()
{
    // サンドボックスURLが未設定なら、主オリジンでの実行を防ぐためここで停止する (todo-security.md #4)
    n3s_require_sandbox_or_error();
    // get from database
    $a = n3s_show_get('widget', 'web', false);
    n3s_widgetd_check_private($a, 'widget');
    // run mode?
    $run = $a['run'] = isset($_GET['run']) ? intval($_GET['run']) : 0;
    $allow = $a['allow'] = isset($_GET['allow']) ? intval($_GET['allow']) : 0;
    $mute_name = $a['mute_name'] = isset($_GET['mute_name']) ? intval($_GET['mute_name']) : 0;
    $mute_title = $a['mute_title'] = isset($_GET['mute_title']) ? intval($_GET['mute_title']) : 0;
    $page = $a['page'] = isset($_GET['page']) ? intval($_GET['page']) : 0;
    // ui=1 なら貯蔵庫のヘッダ・フッタ付きで作品を表示する (#250)
    // ui=0 (既定) は従来通り、作品と作品ページへのリンクだけを表示する (iframe埋め込み用)
    $ui = $a['ui'] = n3s_widget_ui_mode($_GET);
    if ($ui === 1) {
        // タイトルと作者はヘッダに表示するので、作品内の表示は省略する
        $mute_title = $a['mute_title'] = 1;
    }
    // 注意: w_noname はテンプレート(widget_frame.html)でタイトル・作者の秘匿に使うため、
    // ?w_noname=... のようなGETパラメータで上書きされないよう $n3s_config 側も強制する
    // (n3s_template_fw() の $n3s_config + $params マージでは $n3s_config が優先されるため)。
    $a['w_noname'] = n3s_widget_is_noname($a);
    n3s_widget_force_config('w_noname', $a['w_noname']);
    $editkey = isset($_GET['editkey']) ? $_GET['editkey'] : '';
    // 「動かない」報告の確認ページ(本体オリジン)。限定公開の作品は editkey を引き継いで閲覧権限を判定する (#267)
    $broken_url = n3s_get_config('app_root_url', '') . "index.php?action=broken&page={$page}";
    if (is_string($editkey) && $editkey !== '') {
        $broken_url .= '&editkey=' . urlencode($editkey);
    }
    n3s_widget_force_config('broken_url', $broken_url);
    // 貯蔵庫API用の署名付きトークン (#194, docs/api.md)
    // ログインユーザーの user_id を含めるのは、本体オリジンで開いた UI付き実行画面(ui=1)だけ。
    // ui=0 はブログ等への iframe 埋め込み用で、サンドボックス上の他作品からも埋め込めてしまい
    // 中の作品が受け取ったトークンを同一オリジンで読まれ得るため、ゲスト(user_id=0)にする。
    $api_token = n3s_widget_api_token($a, $ui);
    if ($ui === 1) {
        // 他作品から iframe 埋め込み・window.open で参照されてトークンを読まれないようにする
        header("Content-Security-Policy: frame-ancestors 'none'");
        header('Cross-Origin-Opener-Policy: same-origin');
    }
    $nakotype = isset($a['nakotype']) ? $a['nakotype'] : 'wnako';
    $nakotype = preg_replace("/[^0-9a-zA-Z_\-]/", "", $nakotype);
    // sandbox
    $sandbox_url = n3s_get_config('sandbox_url', '');
    // editkey 等は必ずエンコードする。以前は editkey を生のまま連結していたため、
    // editkey=x%26page%3D456 のようにして、トークンを発行した作品とは別の作品(456)を
    // iframe で実行させ、そこへトークンを渡せた (#194)
    $a['iframe_url'] = n3s_widget_iframe_url($sandbox_url, [
        'page' => intval($a['app_id']),
        'run' => $run,
        'mute_name' => $mute_name,
        'mute_title' => $mute_title,
        'editkey' => is_string($editkey) ? $editkey : '',
        'allow' => $allow,
        'api_token' => $api_token,
        'nakotype' => $nakotype,
    ]);
    // -------------------------------------------------------
    // (互換性のために) 特別扱いする投稿 --- https://bit.ly/3Vpk1RI
    if (n3s_widget_is_redirect_app($page)) {
        $url = $a['iframe_url'];
        header('location:' . $url);
        $url_html = htmlspecialchars($url, ENT_QUOTES);
        echo "<html><body><a href='$url_html'>$url_html</a>";
        exit;
    }
    // ここまで
    // -------------------------------------------------------
    if ($allow) {
        $a['sandbox_params'] = 'allow-same-origin allow-modals allow-forms allow-scripts allow-pointer-lock allow-popups allow-presentation	allow-orientation-lock allow-downloads allow-top-navigation-to-custom-protocols allow-popups-to-escape-sandbox allow-top-navigation-by-user-activation';
    }
    n3s_template_fw('widget_frame.html', $a);
}

// widget(実行画面)で作品に渡す貯蔵庫APIトークンを返す (#194)
// ui=1 のときだけログイン中の user_id を含め、それ以外はゲスト(user_id=0)にする。
// n3s_get_user_id() はサンドボックス等のログイン不可ホストでは常に 0 を返す。
function n3s_widget_api_token($a, $ui)
{
    $app_id = isset($a['app_id']) ? intval($a['app_id']) : 0;
    $user_id = ($ui === 1) ? n3s_get_user_id() : 0;
    // 互換のため widget_frame(サンドボックス)へ直接リダイレクトする作品では、
    // ui=1 の frame-ancestors/COOP が最終ページを守れず、他作品から iframe 経由で
    // URL内のトークンを読まれるため、常にゲストにする
    if (n3s_widget_is_redirect_app($app_id)) {
        $user_id = 0;
    }
    return n3s_astorage_token_create($app_id, $user_id);
}

// 親ページを表示せず widget_frame へ直接リダイレクトする(互換性のため特別扱いする)作品か
function n3s_widget_is_redirect_app($app_id)
{
    return intval($app_id) === 991;
}

// サンドボックスで作品を実行する widget_frame の URL を組み立てる。値はすべてエンコードする。
function n3s_widget_iframe_url($sandbox_url, $params)
{
    return $sandbox_url . 'index.php?' . http_build_query(['action' => 'widget_frame'] + $params);
}
