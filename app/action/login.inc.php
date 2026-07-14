<?php
// for clickjacking
header('X-Frame-Options: SAMEORIGIN');
define('ITAZURA_ANSWER', 'ニンゲン');

// パスワード再設定の認証番号に対する総当たり対策 (todo-security.md #5)
define('N3S_SETPW_MAX_TRIES', 10);        // ウィンドウ内に許容する失敗回数
define('N3S_SETPW_WINDOW_SEC', 60 * 15);  // 失敗回数を数える時間窓(秒)

// email または ip に紐づく、ウィンドウ内のパスワード再設定の失敗回数を返す。
// (memo に email を保存しているため、分散IPからの単一アカウント狙いも数えられる)
function n3s_setpw_recent_failures($email, $ip)
{
    $since = time() - N3S_SETPW_WINDOW_SEC;
    $r = db_get1(
        'SELECT count(*) AS cnt FROM ip_check WHERE key=1 AND ctime>? AND (memo=? OR ip=?)',
        [$since, $email, $ip],
        'log'
    );
    return $r ? intval($r['cnt']) : 0;
}

// パスワード再設定の認証番号ミスを1件記録する。
function n3s_setpw_record_failure($email, $ip)
{
    db_exec(
        'INSERT INTO ip_check (key, ip, memo, ctime) VALUES(?,?,?,?)',
        [1, $ip, $email, time()],
        'log'
    );
}

// 認証番号の「再発行」そのものに対する総当たり/スパム対策 (todo-security.md #5 追加対応)。
// 以前は再発行のたびに失敗カウンタ(n3s_setpw_recent_failures)をリセットしていたため、
// 攻撃者が「数回試行→再発行→数回試行」を繰り返すことで N3S_SETPW_MAX_TRIES の制限を
// 実質無制限に回避できてしまっていた。そのため、失敗カウンタは再発行時にリセットしない
// (n3s_web_login_setpw_sendmail() 参照)方針に変更し、加えて再発行そのものにもIP単位の
// 回数制限を設ける。
define('N3S_SETPW_REISSUE_MAX_TRIES', 5);        // ウィンドウ内に許容する再発行回数
define('N3S_SETPW_REISSUE_WINDOW_SEC', 60 * 15); // 再発行回数を数える時間窓(秒)

// ipに紐づく、ウィンドウ内の認証番号の再発行回数を返す。
function n3s_setpw_recent_reissues($ip)
{
    $since = time() - N3S_SETPW_REISSUE_WINDOW_SEC;
    $r = db_get1(
        'SELECT count(*) AS cnt FROM ip_check WHERE key=2 AND ctime>? AND ip=?',
        [$since, $ip],
        'log'
    );
    return $r ? intval($r['cnt']) : 0;
}

// 認証番号の再発行を1件記録する。
function n3s_setpw_record_reissue($ip)
{
    db_exec(
        'INSERT INTO ip_check (key, ip, memo, ctime) VALUES(?,?,?,?)',
        [2, $ip, '', time()],
        'log'
    );
}

// no api login
function n3s_api_login()
{
    n3s_api_output('ng', ['msg' => 'should use web access']);
}

function n3s_web_login()
{
    // set back page?
    $back = empty($_GET['back']) ? '' : $_GET['back'];
    if ($back) {
        // allow back ? (サイト内からの指定のみ許可)
        if (preg_match('#^index\.php\?action\=#', $back)) {
            n3s_setBackURL($back);
        }
    }
    // check page
    $page = n3s_get_config('page', ''); // trylogin / register / forgot
    if ($page == 'register') { // register
        n3s_web_login_register();
        return;
    } else if ($page == 'forgot') { // forgot
        n3s_web_login_forgot();
        return;
    } else if ($page == 'setpw') { // set password
        n3s_web_login_setpw();
        return;
    } else if ($page == 'trylogin') { // trylogin
        n3s_web_login_trylogin();
        return;
    } else if ($page == 'google_login') { // Googleログイン開始
        n3s_web_login_google_start();
        return;
    } else if ($page == 'google_callback') { // Googleログインのコールバック
        n3s_web_login_google_callback();
        return;
    } else {
        n3s_web_login_trylogin();
        return;
    }
}

function n3s_web_login_register()
{
    $error = '登録に必要な項目を入力してください。';
    // get parametes
    $email = empty($_POST['email']) ? '' : trim($_POST['email']);
    $email2 = empty($_POST['email2']) ? '' : trim($_POST['email2']);
    $name = empty($_POST['name']) ? '' : trim($_POST['name']);
    $itazura = empty($_POST['itazura']) ? '' : trim($_POST['itazura']);
    // check params
    if ($email != '' && $name != '') {
        $error = '';
        if ($name == '' || mb_strlen($name) > 12 || mb_strlen($name) < 4) {
            $error = '名前を4文字以上12文字以内で入力してください。';
        }
        if ($email == '') {
            $error = 'メールアドレスを入力してください。';
        }
        if ($email != $email2) {
            $error = '確認用に入力されたメールアドレスが合致しません。';
        }
        if ($itazura != ITAZURA_ANSWER) {
            $error = 'イタズラ防止用の質問が間違っています。お手数ですが、質問に答えください。';
        }
        // emailの検証
        if (!preg_match('#^[a-zA-Z0-9\.\-\_]+\@[a-zA-Z0-9\.\-\_]+\.[a-zA-Z0-9]+$#', $email)) {
            $error = 'メールアドレスを正しく入力してください。';
            $email = '';
        }
        // all parameters are ok
        if ($error == '') {
            // check email
            $user_id = n3s_get_user_id_by_email($email);
            if ($user_id != 0) {
                $error = 'このメールアドレスは既に登録されています。';
                $email = '';
            } else {
                // add user
                // 初期パスワードは本人がメール経由で再設定するまでのダミー。
                // 予測不能にするため CSPRNG を使う (rand() は予測可能: todo-security.md #5)。
                $password = bin2hex(random_bytes(32));
                $user_id = n3s_add_user($email, $password, $name);
                if ($user_id == 0) {
                    $error = '既にメールアドレスが登録されています。<a href="index.php?action=login&page=forgot">こちらからパスワードを変更</a>してください。';
                } else {
                    if (!n3s_web_login_setpw_sendmail($user_id, $email, 'register')) {
                        n3s_error(
                            '登録できません',
                            '短時間に再発行の操作が繰り返されたため、しばらく時間をおいてから再度お試しください。'
                        );
                        return;
                    }
                    n3s_web_login_setpw($email);
                    return;
                }
            }
        }
    }
    // log
    n3s_log("{$email},name={$name},error={$error}(email:{$email})", "try_register", 1);
    // show template
    n3s_template_fw('login_register.html', [
        'email' => $email,
        'email2' => $email2,
        'name' => $name,
        'error' => $error,
    ]);
}

// 認証番号を発行してメール送信する。再発行の回数制限に達していれば発行せず false を返す。
// (呼び出し元は false の場合、n3s_web_login_setpw() へ進まずエラーを表示すること)
function n3s_web_login_setpw_sendmail($user_id, $email, $action)
{
    // 再発行そのものにIP単位の回数制限をかける (todo-security.md #5 追加対応)。
    // これが無いと、認証番号の試行に失敗するたびに新しい番号を再発行することで、
    // 総当たり試行回数を実質無制限にできてしまう。
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    if ($ip !== '' && n3s_setpw_recent_reissues($ip) >= N3S_SETPW_REISSUE_MAX_TRIES) {
        n3s_log("[forgot] email={$email},ip={$ip},error=認証番号の再発行回数超過", "setpw", 1);
        return false;
    }
    if ($ip !== '') {
        n3s_setpw_record_reissue($ip);
    }
    $passtoken1 = n3s_randomIntStr(3);
    $passtoken2 = n3s_randomIntStr(4);
    $passtoken = "{$passtoken1}-{$passtoken2}";
    db_exec(
        "UPDATE users SET pass_token=?, mtime=? WHERE user_id=?",
        [$passtoken, time(), $user_id],
        'users'
    );
    // 注意: 認証番号の試行失敗カウンタ(n3s_setpw_recent_failures)は、ここではリセットしない。
    // 以前は再発行のたびにリセットしていたが、それだと「試行→再発行」を繰り返すことで
    // N3S_SETPW_MAX_TRIES の制限を回避できてしまっていた。失敗カウンタは
    // N3S_SETPW_WINDOW_SEC の時間経過により自然に失効する。
    // メールの送信
    $host = $_SERVER['HTTP_HOST'];
    // $passtoken_enc = urlencode($passtoken);
    // $http = empty($_SERVER['REQUEST_SCHEME']) ? 'http' : $_SERVER['REQUEST_SCHEME'];
    // $script_name = $_SERVER['SCRIPT_NAME'];
    // $baseurl = "{$http}://{$host}{$script_name}";
    $sendto = $email;
    $subject = ($action == 'register') ? '[なでしこ3貯蔵庫] ユーザー登録について' : '[なでしこ3貯蔵庫]パスワード再設定について';
    $actionName = ($action == 'register') ? 'ユーザー登録' : 'パスワードの再設定';
    $body = "「なでしこ3貯蔵庫」の事務局です。\r\n";
    $body .= "{$actionName}の旨を承っております。登録ページで下記の番号を入力してください。\r\n";
    $body .= "{$passtoken}\r\n";
    $body .= "\r\n";
    $body .= "※もし、{$actionName}に覚えがない場合、本メールはそのまま削除してください。\r\n";
    $body .= "ご迷惑をおかけして申し訳ありません。\r\n";
    $body .= "\r\n";
    @mb_send_mail($sendto, $subject, $body);
    if (explode(':', $host . ':')[0] === 'localhost') {
        echo "<pre>" . $body . "</pre>";
    }
    return true;
}

function n3s_web_login_forgot()
{
    $error = '';
    $email_get = empty($_GET['email']) ? '' : trim($_GET['email']);
    $email = empty($_POST['email']) ? $email_get : trim($_POST['email']);
    $email2 = empty($_POST['email2']) ? $email_get : trim($_POST['email2']);
    $quiz = empty($_POST['quiz']) ? '' : $_POST['quiz'];
    if ($email != '') {
        $email = trim($email);
        $quiz = trim($quiz);
        if ($quiz != 'くさばな') {
            $error = 'クイズの答えを入力してください。';
        }
        if ($email != $email2) {
            $error = 'メールアドレスが合致しません。';
        }
        if ($error == '') {
            $user_id = n3s_get_user_id_by_email($email);
            if ($user_id > 0) {
                // メールアドレスの存在有無を区別させないため、再発行の回数制限時のみ
                // 専用のエラーを表示する(存在しないメールアドレスの場合は通常通り
                // 認証番号入力画面へ進める)。
                if (!n3s_web_login_setpw_sendmail($user_id, $email, 'forgot')) {
                    n3s_error(
                        '再発行できません',
                        '短時間に再発行の操作が繰り返されたため、しばらく時間をおいてから再度お試しください。'
                    );
                    return;
                }
            }
            // log
            n3s_log("sendto={$email}", "forgot");
            n3s_web_login_setpw($email);
            return;
        }
    }
    // show template
    n3s_template_fw('login_forgot.html', [
        'email' => $email,
        'email2' => $email2,
        'error' => $error,
    ]);
}

function n3s_web_login_setpw($email = '')
{
    $url = n3s_getURL('setpw', 'login', []);
    if ($email == '') {
        $email = empty($_REQUEST['email']) ? '' : $_REQUEST['email'];
    }
    $pass1 = empty($_POST['pass1']) ? '' : $_POST['pass1'];
    $pass2 = empty($_POST['pass2']) ? '' : $_POST['pass2'];
    $passtoken_get = trim($pass1) . '-' . trim($pass2);
    $post_token = empty($_POST['token']) ? '' : $_POST['token'];
    $token = n3s_getEditToken('setpw', FALSE);
    if ($email == '') {
        $retry = n3s_getURL('forgot', 'login');
        n3s_error('パスワードの設定失敗', "メール情報が失われました。<a href='$retry'>手順をやり直してください</a>。", TRUE);
        return;
    }
    if ($pass1 == '' || $pass2 == '' || $post_token == '') {
        $email_ = htmlspecialchars($email, ENT_QUOTES);
        $body = <<< __EOS__
<div class="showblock">
<p>メールに記載された7桁の番号を入力しくてださい。認証番号は5分間のみ有効です。</p>
<form method="post" action="{$url}">
<input type="text" name="pass1" value="" placeholder="最初の3桁" style="width:40%;max-width:100px;"> - 
<input type="text" name="pass2" value="" placeholder="後半の4桁" style="width:40%;max-width:130px;"><br>
<input type="hidden" name="token" value="{$token}">
<input type="hidden" name="email" value="{$email_}">
<input type="submit" value="送信">
</form>
</div>
__EOS__;
        n3s_info('認証番号の入力', $body, TRUE);
        return;
    }
    if ($token != $post_token) {
        n3s_error('セッションが切れました', "<a href='$url'>もう一度試行してください。</a>");
        return;
    }
    // 認証番号の総当たり対策 (todo-security.md #5)
    // 一定時間内の失敗が上限を超えたら、発行済みトークンを無効化して打ち切る。
    // (CSRFトークンは攻撃者自身のセッションで取得できるため障壁にならない)
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    if (n3s_setpw_recent_failures($email, $ip) >= N3S_SETPW_MAX_TRIES) {
        db_exec('UPDATE users SET pass_token="" WHERE email=?', [$email], 'users');
        n3s_log("[forgot] email={$email},ip={$ip},error=認証番号の試行回数超過", "setpw", 1);
        $retry = n3s_getURL('forgot', 'login');
        n3s_error(
            '試行回数が多すぎます',
            "パスワード再設定の認証番号を何度も間違えたため、安全のためこの認証番号は無効になりました。" .
                "お手数ですが、<a href='$retry'>最初からやり直して</a>ください。",
            true
        );
        return;
    }
    // check pass token
    // get user info
    $row = db_get1(
        'SELECT * FROM users WHERE pass_token=? AND email=? AND mtime > ? LIMIT 1',
        [$passtoken_get, $email, time() - 60 * 5],
        'users'
    );
    if (!$row) {
        n3s_setpw_record_failure($email, $ip);
        $retry = n3s_getURL('setpw', 'login', ['email' => $email]);
        n3s_log("[forgot] email={$email},error=パスワードの設定失敗/認証番号の入力ミス", "info");
        n3s_error('登録失敗', "改めてメールに書かれている登録番号を確認して入力してください。<a href='$retry'>やり直す</a>", true);
        return;
    }
    $user_id = $row['user_id'];
    // パスワードのチェック
    $error = '';
    $password = empty($_POST['password']) ? '' : $_POST['password'];
    $password2 = empty($_POST['password2']) ? '' : $_POST['password2'];
    if ($password != '') {
        $password = trim($password);
        $password2 = trim($password2);
        if ($password != $password2) {
            $error = 'パスワード(確認用)が合致しません。';
        }
        if (strlen($password) < 8) {
            $error = 'パスワードは8文字以上で入力してください。';
        }
        if ($error == '') {
            $hash = n3s_password_hash($password);
            db_exec(
                'UPDATE users SET password=?, salt=?, pass_token="", mtime=? WHERE user_id=?',
                [$hash, '', time(), $user_id],
                'users'
            );
            n3s_log("[forgot] email={$email} パスワードの再設定完了", "setpw");
            n3s_info('パスワードを設定しました', '<a href="index.php?action=login">ログインしてください。</a>', true);
            return;
        }
    }
    n3s_template_fw('login_setpw.html', [
        'email' => $email,
        'pass1' => $pass1,
        'pass2' => $pass2,
        'error' => $error,
        'token' => $token,
    ]);
}

function n3s_web_login_trylogin()
{
    $error = 'なでしこ３貯蔵庫のアカウント情報を入力してください';
    $email = empty($_POST['email']) ? '' : $_POST['email'];
    $password = empty($_POST['password']) ? '' : $_POST['password'];
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    if ($ip != '') {
        // ログイン攻撃を検出する (1時間以内に10回以上のログイン失敗があれば拒否する)
        $r = db_get1('SELECT count(*) FROM ip_check WHERE key=0 AND ctime>? AND ip=?', [time() - 60 * 60, $ip], 'log');
        if ($r && $r['count(*)'] > 10) {
            n3s_log("ip={$ip},email={$email},error=ログイン失敗が多すぎる", "trylogin");
            n3s_error('ログインの拒否', 'ログイン失敗が多すぎます。しばらく時間をおいてからログインしてください。');
            return;
        }
    }
    if ($email != '' && $password != '') {
        // トークンのチェック
        if (!n3s_checkEditToken()) {
            $error = 'セッションが切れました。もう一度ログインしてください。';
        } else {
            // ログインのチェック
            $email = trim($email);
            $password = trim($password);
            $user_id = n3s_get_user_id_by_email($email);
            $isOK = false;

            if ($user_id > 0) {
                // ユーザーのパスワードが空の場合をチェック
                $user = db_get1(
                    'SELECT * FROM users WHERE user_id=?',
                    [$user_id],
                    'users'
                );
                if ($user && ($user['password'] == '' || $user['password'] == null)) {
                    if (!empty($user['google_sub'])) {
                        // Googleログイン専用ユーザー (docs/user_login_oauth_google.md #8, #7.3)
                        $error = 'このアカウントはGoogleアカウントでログインするアカウントとして登録されています。<br>'.
                            '下の「Googleでログイン」ボタンからログインしてください。<br>'.
                            '<br>'.
                            'パスワードでのログインも使いたい場合は、'.
                            '<a href="index.php?action=login&page=forgot">こちらからパスワードを設定</a>してください。'.
                            '<br><hr>';
                    } else {
                        $error = 'お手数おかけしますが、セキュリティ強化のため、パスワードの再設定が必要です。より長いパスワードを再設定してください。<br>'.
                            '<a style="font-size:1.5" href="index.php?action=login&page=forgot">→こちらのリンクからパスワードを再設定</a>してください。<br>'.
                            '<br>'.
                            'なお、パスワードの再設定は、登録されているメールアドレス宛に送られる認証番号を入力するだけで完了します。簡単ですので、よろしくお願いします。'.
                            '<br><hr>';
                    }
                    $token = n3s_getEditToken();
                    n3s_template_fw('login_email.html', [
                        'email' => $email,
                        'error' => $error,
                        'token' => $token,
                        'google_login_enabled' => n3s_google_login_enabled(),
                    ]);
                    return;
                }
                $isOK = n3s_web_login_execute($email, $password);
            }

            if ($isOK) {
                return;
            }

            // ログイン失敗時の共通処理
            $errCount = isset($_SESSION['n3s_trylogin_count']) ? $_SESSION['n3s_trylogin_count'] : 0;
            if ($errCount >= 5) {
                unset($_SESSION['n3s_trylogin_count']);
                n3s_error(
                    'ログイン失敗',
                    "ログインに5回以上失敗しました。しばらく時間をおいてから再度お試しください。" .
                        "あるいは、<a href='index.php?action=login&page=forgot'>こちらのパスワード再設定</a>を試してください。",
                    true
                );
                return;
            }
            $errCount++;
            $_SESSION['n3s_trylogin_count'] = $errCount;
            $error = "メールアドレスかパスワードが間違っています。#{$errCount}";
            db_exec("INSERT INTO ip_check (key, ip, memo, ctime) VALUES(?,?,?,?)", [0, $ip, $email, time()], 'log');
        }
    }
    $token = n3s_getEditToken();

    n3s_template_fw('login_email.html', [
        'email' => $email,
        'error' => $error,
        'token' => $token,
        'news_at_login' => n3s_get_config('news_at_login', ''),
        'google_login_enabled' => n3s_google_login_enabled(),
    ]);
}

function n3s_web_login_execute($email, $password)
{
    // login
    $ok = n3s_login($email, $password);
    if (!$ok) {
        return false;
    }
    n3s_web_login_redirect_after_login();
    return true;
}

// ログイン確定後、back URL(なければマイページ)へリダイレクトする
// (パスワードログイン・Googleログイン共通)
function n3s_web_login_redirect_after_login()
{
    $backurl = n3s_getBackURL();
    if ($backurl == '') {
        $backurl = n3s_getURL('my', 'mypage');
    }
    header('location:' . $backurl);
}

function n3s_google_login_enabled()
{
    return n3s_get_config('google_oauth_client_id', '') !== '';
}

// ------------------------------------------------------------
// Googleログイン (docs/user_login_oauth_google.md)
// ------------------------------------------------------------

function n3s_web_login_google_start()
{
    if (!n3s_google_login_enabled()) {
        n3s_error('設定エラー', 'Googleログインは現在利用できません。');
        return;
    }
    $state = bin2hex(random_bytes(16));
    $_SESSION['n3s_oauth_state'] = $state;
    $_SESSION['n3s_oauth_state_time'] = time();
    header('location:' . n3s_google_get_auth_url($state));
}

function n3s_web_login_google_callback()
{
    if (!empty($_GET['error'])) {
        n3s_log("error={$_GET['error']}", "login_google", 1);
        n3s_error('ログインの中止', 'Googleログインがキャンセルされました。');
        return;
    }
    // stateの検証 (CSRF対策)。使い捨てにするため検証前にセッションから取り出す
    $state = empty($_GET['state']) ? '' : $_GET['state'];
    $session_state = isset($_SESSION['n3s_oauth_state']) ? $_SESSION['n3s_oauth_state'] : '';
    $state_time = isset($_SESSION['n3s_oauth_state_time']) ? $_SESSION['n3s_oauth_state_time'] : 0;
    unset($_SESSION['n3s_oauth_state'], $_SESSION['n3s_oauth_state_time']);
    $state_ok = $state !== '' && $session_state !== '' && hash_equals($session_state, $state);
    if (!$state_ok || (time() - $state_time) > 60 * 10) {
        n3s_error('セッションが切れました', "<a href='index.php?action=login'>もう一度試行してください。</a>", true);
        return;
    }
    $code = empty($_GET['code']) ? '' : $_GET['code'];
    if ($code === '') {
        n3s_error('ログイン失敗', 'Googleからの応答が不正です。');
        return;
    }
    // 認可コード→トークン交換
    $token = n3s_google_exchange_code($code);
    if ($token === false || empty($token['id_token'])) {
        n3s_log("token exchange failed", "login_google", 1);
        n3s_error('ログイン失敗', 'Googleとの通信に失敗しました。時間をおいて再度お試しください。');
        return;
    }
    // ID Tokenのclaims検証
    $claims = n3s_google_verify_id_token($token['id_token']);
    if ($claims === false) {
        n3s_log("id_token invalid", "login_google", 1);
        n3s_error('ログイン失敗', 'Googleアカウントの情報を確認できませんでした。');
        return;
    }
    // ユーザーの検索・作成・紐付け
    $user = n3s_google_find_or_create_user($claims);
    if ($user === false) {
        n3s_error('ログイン失敗', 'アカウントの作成に失敗しました。');
        return;
    }
    n3s_login_session_start($user);
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    n3s_log("{$user['email']},sub={$claims['sub']},ip={$ip},name={$user['name']}", "login_google", 1);
    n3s_web_login_redirect_after_login();
}

function iget($info, $key, $def = '')
{
    if (!isset($info[$key])) {
        return $def;
    }
    return $info[$key];
}
