<?php
// widget は外部から読み込まれるので SAMEORIGIN だと動かない
// for clickjacking
// header('X-Frame-Options: SAMEORIGIN');

include_once dirname(__FILE__) . '/save.inc.php';
include_once dirname(__FILE__) . '/show.inc.php';

function n3s_web_widget_frame()
{
    // サンドボックスURLが設定済みなら、実際にそのオリジンからのリクエストであることまで
    // 検証する。未設定ならlocalhostのみ許可する (todo-security.md #4)
    n3s_require_widget_frame_origin_or_error();
    $a = n3s_show_get('widget', 'web', false);
    n3s_widgetd_check_private($a, 'widget_frame');
    // run mode?
    $a['run'] = isset($_GET['run']) ? intval($_GET['run']) : 0;
    $a['allow'] = isset($_GET['allow']) ? intval($_GET['allow']) : 0;
    $a['mute_title'] = isset($_GET['mute_title']) ? intval($_GET['mute_title']) : 0;
    $a['mute_name'] = isset($_GET['mute_name']) ? intval($_GET['mute_name']) : 0;
    $tags = isset($a['tag']) ? explode(',', $a['tag']) : [];
    for ($i = 0; $i < count($tags); $i++) { $tags[$i] = trim($tags[$i]); }
    $a['w_noname'] = in_array('w_noname', $tags);
    $a['api_token'] = isset($_GET['api_token']) ? $_GET['api_token'] : '';

    // ウィジェット実行統計を記録 (Issue #217)
    // run=1 かつ作品オーナー本人でない場合のみカウント
    if ($a['run'] === 1 && !empty($a['app_id'])) {
        $owner_id = intval(isset($a['user_id']) ? $a['user_id'] : 0);
        $is_owner = ($owner_id > 0 && n3s_get_user_id() === $owner_id);
        if (!$is_owner) {
            n3s_record_access('widget', $a['app_id']);
        }
    }

    // nakotypeの例外(html/text/javascript)のときの処理
    // 注意: nakotype は必ずDBに保存された値($a['nakotype'])を使うこと。
    // 以前は $_GET['nakotype'] を信用していたため、任意の作品(非ログインでも
    // 保存できる wnako 作品を含む)を ?nakotype=html 付きで開くだけで、本文が
    // text/html として主オリジンで配信され、未認証の Stored XSS が可能だった
    // (todo-security.md #1)。GETからの上書きは受け付けない。
    $nakotype = n3s_widget_frame_nakotype($a);
    if (n3s_widget_frame_is_raw_type($nakotype)) {
        $mime = get_mime_easy($nakotype);
        // ヘッダを追加
        header("Cross-Origin-Opener-Policy: same-origin");
        header("Cross-Origin-Embedder-Policy: require-corp");
        header("Content-type: $mime; charset=utf-8");
        // 本体を出力
        echo $a['body'];
        exit;
    }
    // ヘッダを追加
    header("Cross-Origin-Opener-Policy: same-origin");
    header("Cross-Origin-Embedder-Policy: require-corp");
    n3s_template_fw('widget.html', $a);
}

// widget_frame が配信に使う nakotype を決定する。必ずDBの保存値($a['nakotype'])を
// 使い、$_GET['nakotype'] からの上書きは受け付けない (todo-security.md #1)。
// 記号などはサニタイズして取り除く。
function n3s_widget_frame_nakotype($a)
{
    $nakotype = empty($a['nakotype']) ? 'wnako' : $a['nakotype'];
    return preg_replace("/[^0-9a-zA-Z_\-]/", "", $nakotype);
}

// 本文をテンプレートを介さず生のMIMEで直接出力する種類かどうか。
function n3s_widget_frame_is_raw_type($nakotype)
{
    return in_array($nakotype, ['html', 'text', 'js', 'csv', 'json'], true);
}

// サンドボックスURL(別オリジン)が設定されているか。未設定(空文字・空白のみ)なら false。
// 未設定のまま投稿プログラムを実行すると、主オリジンでユーザーコードが動作し、
// セッション乗っ取り等の XSS に直結する (todo-security.md #4)。
function n3s_is_sandbox_configured()
{
    return trim(n3s_get_config('sandbox_url', '')) !== '';
}

// サンドボックス未設定時に表示するメッセージ(HTML)を返す。
function n3s_sandbox_not_configured_message()
{
    $link = 'https://github.com/kujirahand/nako3storage#%E5%AE%89%E5%85%A8%E3%81%AB%E9%81%8B%E7%94%A8%E3%81%99%E3%82%8B%E3%81%9F%E3%82%81%E3%81%AEtips';
    return '<p>安全のため、サンドボックスURLが設定されていない環境では、' .
        '投稿されたプログラムを実行できません。</p>' .
        '<p>貯蔵庫の管理者に連絡して、' .
        '<a href="' . $link . '">サンドボックスURLを設定</a>してください。</p>';
}

// ローカル開発環境からのリクエストかどうかを判定する。
// login.inc.php の n3s_web_login_setpw_sendmail() と同じ考え方(HTTP_HOSTのホスト部分を
// ポート番号を除いて比較)で、開発時の利便性のためlocalhost/ループバックアドレスを検出する。
function n3s_is_localhost_request()
{
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    if (preg_match('/^\[([^\]]+)\]/', $host, $m)) {
        // IPv6リテラルのブラケット表記 "[::1]" や "[::1]:8000"
        $host = $m[1];
    } elseif (strpos($host, '::') === false) {
        // "host:port" 形式からポート番号を除去する
        // ("::"を含む場合はブラケット無しのIPv6リテラルとみなし、ポート分離はしない)
        $host = explode(':', $host . ':')[0];
    }
    return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
}

// サンドボックスURLが未設定なら、プログラムを実行させずにエラーを表示して終了する。
// widget の実行入口(n3s_web_widget)の先頭で呼ぶこと。
// n3s_web_widget() は main オリジン上で「sandboxオリジンへのiframeを埋め込んだページ」を
// 描画するだけの入口なので、ここでは「sandbox_urlが設定されているか(またはlocalhostか)」
// だけを見れば十分。実際のコード実行(widget_frame)側は
// n3s_require_widget_frame_origin_or_error() を使うこと。
// ただし localhost からのアクセス(ローカル開発)は、sandbox_url を用意しなくても
// 動作確認できるよう例外的にブロックしない。
function n3s_require_sandbox_or_error()
{
    if (n3s_is_sandbox_configured()) {
        return;
    }
    if (n3s_is_localhost_request()) {
        return;
    }
    n3s_error('プログラムを実行できません', n3s_sandbox_not_configured_message(), true);
    exit;
}

// 現在のリクエストの scheme://host (ポートを含む)を返す。
function n3s_current_origin()
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    return strtolower($scheme . '://' . $host);
}

// 設定済み sandbox_url の scheme://host (ポートを含む)を返す。
// 未設定、あるいは scheme/host を取得できない不正なURLの場合は '' を返す。
function n3s_sandbox_origin()
{
    $sandbox_url = trim(n3s_get_config('sandbox_url', ''));
    if ($sandbox_url === '') {
        return '';
    }
    $parts = parse_url($sandbox_url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return '';
    }
    $host = $parts['host'];
    if (!empty($parts['port'])) {
        $host .= ':' . $parts['port'];
    }
    return strtolower($parts['scheme'] . '://' . $host);
}

// 現在のリクエストが、設定済み sandbox_url と同一オリジンから来ているか判定する。
// sandbox_url が未設定・不正な場合は常に false (フェイルクローズ)。
function n3s_is_request_from_sandbox_origin()
{
    $sandbox_origin = n3s_sandbox_origin();
    if ($sandbox_origin === '') {
        return false;
    }
    return n3s_current_origin() === $sandbox_origin;
}

// sandbox_url は設定済みだが、現在のリクエストがそのオリジンから来ていない場合に
// 表示するメッセージ(HTML)を返す。
function n3s_sandbox_origin_mismatch_message()
{
    return '<p>このページはサンドボックス環境からのみ実行できます。' .
        'このURLに直接アクセスすることはできません。</p>' .
        '<p>作品の表示ページから「プログラムを実行」を選んでアクセスしてください。</p>';
}

// widget_frame(実際にプログラムを実行するエンドポイント)専用のガード。
// n3s_require_sandbox_or_error() との違い: sandbox_url が設定済みの場合、
// 「設定されているか」だけでなく「現在のリクエストが実際にそのsandboxオリジンから
// 来ているか」まで検証する。これが無いと、sandbox_url設定後であっても主オリジンへ
// 直接 index.php?action=widget_frame&page=<id> でアクセスすることでサンドボックス分離を
// 迂回でき、保存済み nakotype=html 等の本文が主オリジンで生出力されてしまう
// (todo-security.md #4 追加対応)。widget_frame の実行入口(n3s_web_widget_frame)の
// 先頭で呼ぶこと。
function n3s_require_widget_frame_origin_or_error()
{
    if (n3s_is_sandbox_configured()) {
        if (n3s_is_request_from_sandbox_origin()) {
            return;
        }
        n3s_error('プログラムを実行できません', n3s_sandbox_origin_mismatch_message(), true);
        exit;
    }
    // sandbox_url未設定時は、従来通りlocalhostのみ許可する(ローカル開発の利便性)
    if (n3s_is_localhost_request()) {
        return;
    }
    n3s_error('プログラムを実行できません', n3s_sandbox_not_configured_message(), true);
    exit;
}

function get_mime_easy($nakotype)
{
    $mime_types = [
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'text' => 'text/plain',
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'json' => 'application/json',
        'js' => 'application/javascript',
        'html' => 'text/html',
    ];
    return isset($mime_types[$nakotype]) ? $mime_types[$nakotype] : 'text/plain';
}

function n3s_api_widget()
{
    n3s_api_output(false, []);
}

// check private app
// 判定ロジック本体は n3s_lib.inc.php の n3s_private_access_allowed() /
// n3s_deny_private_access() に共通化した (todo-security.md #6)。
// 以前は保存時に指定される editkey ではなく、常に未使用のまま空文字が入っている
// access_key カラムを参照していたため、限定公開の作品が実質誰でも閲覧できてしまっていた。
function n3s_widgetd_check_private(&$a, $action = 'widget_frame')
{
    if (!$a) {
        return;
    }
    $editkey = isset($_GET['editkey']) ? $_GET['editkey'] : '';
    if (n3s_private_access_allowed($a, $editkey)) {
        return;
    }
    n3s_deny_private_access($a, 'web', $action);
}
