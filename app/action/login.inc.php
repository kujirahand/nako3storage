<?php
// for clickjacking
header('X-Frame-Options: SAMEORIGIN');
define('ITAZURA_ANSWER', 'ニンゲン');

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
                $password = hash('sha256', $email . time() . rand());
                $user_id = n3s_add_user($email, $password, $name);
                if ($user_id == 0) {
                    $error = '既にメールアドレスが登録されています。<a href="index.php?action=login&page=forgot">こちらからパスワードを変更</a>してください。';
                } else {
                    n3s_web_login_setpw_sendmail($user_id, $email, 'register');
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

function n3s_web_login_setpw_sendmail($user_id, $email, $action)
{
    $passtoken1 = n3s_randomIntStr(3);
    $passtoken2 = n3s_randomIntStr(4);
    $passtoken = "{$passtoken1}-{$passtoken2}";
    db_exec(
        "UPDATE users SET pass_token=?, mtime=? WHERE user_id=?",
        [$passtoken, time(), $user_id],
        'users'
    );
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
                n3s_web_login_setpw_sendmail($user_id, $email, 'forgot');
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
        exit;
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
        exit;
    }
    if ($token != $post_token) {
        n3s_error('セッションが切れました', "<a href='$url'>もう一度試行してください。</a>");
        exit;
    }
    // check pass token
    // get user info
    $row = db_get1(
        'SELECT * FROM users WHERE pass_token=? AND email=? AND mtime > ? LIMIT 1',
        [$passtoken_get, $email, time() - 60 * 5],
        'users'
    );
    if (!$row) {
        $retry = n3s_getURL('setpw', 'login', ['email' => $email]);
        n3s_log("[forgot] email={$email},error=パスワードの設定失敗/認証番号の入力ミス", "info");
        n3s_error('登録失敗', "改めてメールに書かれている登録番号を確認して入力してください。<a href='$retry'>やり直す</a>", true);
        exit;
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
            $salt = n3s_generate_salt();
            $hash = n3s_login_password_to_hash($password, $salt);
            db_exec(
                'UPDATE users SET password=?, salt=?, pass_token="", mtime=? WHERE user_id=?',
                [$hash, $salt, time(), $user_id],
                'users'
            );
            n3s_log("[forgot] email={$email} パスワードの再設定完了", "setpw");
            n3s_info('パスワードを設定しました', '<a href="index.php?action=login">ログインしてください。</a>', true);
            exit;
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
    if (!$ip) {
        // ログイン攻撃を検出する (1時間以内に10回以上のログイン失敗があれば拒否する)
        $r = db_get('SELECT count(*) FROM ip_check WHERE key=0 AND mtime>? AND ip=?', [time() - 60 * 60, $ip]);
        if ($r && $r['count(*)'] > 10) {
            n3s_log("ip={$ip},email={$email},error=ログイン失敗が多すぎる", "trylogin");
            n3s_error('ログインの拒否', 'ログイン失敗が多すぎます。しばらく時間をおいてからログインしてください。');
            exit;
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
            if ($user_id > 0) {
                // ユーザーのパスワードが空の場合をチェック
                $user = db_get1(
                    'SELECT * FROM users WHERE user_id=?',
                    [$user_id],
                    'users'
                );
                if ($user && ($user['password'] == '' || $user['password'] == null)) {
                    $error = 'お手数おかけしますが、セキュリティ強化のため、パスワードの再設定が必要です。より長いパスワードを再設定してください。<br><a href="index.php?action=login&page=forgot">こちらからパスワードを再設定</a>してください。';
                    $token = n3s_getEditToken();
                    n3s_template_fw('login_email.html', [
                        'email' => $email,
                        'error' => $error,
                        'token' => $token,
                    ]);
                    exit;
                }
                $isOK = n3s_web_login_execute($email, $password);
                if ($isOK) {
                    return;
                }
                // ip_check に記録するので、ここには記録しない
                // n3s_log("email={$email},error=パスワードの間違い", "trylogin");
                // ログイン失敗した回数を確認
                $errCount = isset($_SESSION['n3s_trylogin_count']) ? $_SESSION['n3s_trylogin_count'] : 0;
                if ($errCount >= 5) {
                    unset($_SESSION['n3s_trylogin_count']);
                    n3s_error(
                        'ログイン失敗',
                        "ログインに5回以上失敗しました。しばらく時間をおいてから再度お試しください。" .
                            "あるいは、<a href='index.php?action=login&page=forgot'>こちらのパスワード再設定</a>を試してください。",
                        true
                    );
                    exit;
                }
                $errCount++;
                $_SESSION['n3s_trylogin_count'] = $errCount;
                $error = "メールアドレスかパスワードが間違っています。#{$errCount}";
                db_exec("INSERT INTO ip_check (key, ip, memo, ctime) VALUES(?,?,?,?)", [0, $ip, $email, time()], 'log');
            }
        }
    }
    $token = n3s_getEditToken();

    n3s_template_fw('login_email.html', [
        'email' => $email,
        'error' => $error,
        'token' => $token,
    ]);
}

function n3s_web_login_execute($user_id, $password)
{
    // login
    $ok = n3s_login($user_id, $password);
    if (!$ok) {
        return false;
    }
    // redirect
    $backurl = n3s_getBackURL();
    if ($backurl == '') {
        $mypage = n3s_getURL('my', 'mypage');
        $backurl = $mypage;
    }
    header('location:' . $backurl);
    return true;
}

function iget($info, $key, $def = '')
{
    if (!isset($info[$key])) {
        return $def;
    }
    return $info[$key];
}
