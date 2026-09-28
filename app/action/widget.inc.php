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
    $api_token = n3s_getAPIToken();
    $_SESSION["api_token::$api_token"] = $page;
    $nakotype = isset($a['nakotype']) ? $a['nakotype'] : 'wnako';
    $nakotype = preg_replace("/[^0-9a-zA-Z_\-]/", "", $nakotype);
    // sandbox
    $sandbox_url = n3s_get_config('sandbox_url', '');
    $a['iframe_url'] = "{$sandbox_url}index.php?action=widget_frame&page={$page}&run={$run}&mute_name={$mute_name}&mute_title={$mute_title}&editkey={$editkey}&allow={$allow}&api_token={$api_token}&nakotype={$nakotype}";
    // -------------------------------------------------------
    // (互換性のために) 特別扱いする投稿 --- https://bit.ly/3Vpk1RI
    if ($page == 991) {
        $url = $a['iframe_url'];
        header('location:' . $url);
        echo "<html><body><a href='$url'>$url</a>";
        exit;
    }
    // ここまで
    // -------------------------------------------------------
    if ($allow) {
        $a['sandbox_params'] = 'allow-same-origin allow-modals allow-forms allow-scripts allow-pointer-lock allow-popups allow-presentation	allow-orientation-lock allow-downloads allow-top-navigation-to-custom-protocols allow-popups-to-escape-sandbox allow-top-navigation-by-user-activation';
    }
    n3s_template_fw('widget_frame.html', $a);
}
