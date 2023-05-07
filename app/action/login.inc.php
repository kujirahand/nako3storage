<?php
// for clickjacking
header('X-Frame-Options: SAMEORIGIN');

// no api login
function n3s_api_login()
{
    n3s_api_output('ng', ['msg'=>'should use web access']);
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
    $email = empty($_POST['email']) ? '' : $_POST['email'];
    $password = empty($_POST['password']) ? '' : $_POST['password'];
    $password2 = empty($_POST['password2']) ? '' : $_POST['password2'];
    $name = empty($_POST['name']) ? '' : $_POST['name'];
    $twitter_id = empty($_POST['twitter_id']) ? '' : $_POST['twitter_id'];
    // check params
    if ($password != '') {
        $error = '';
        $password = trim($password);
        $password2 = trim($password2);
        $name = trim($name);
        if ($password != $password2) {
            $error = 'パスワードが一致しません。';
        }
        if (strlen($password) < 8) {
            $error = 'パスワードは8文字以上で入力してください。';
        }
        if ($name == '') {
            $error = '名前を入力してください。';
        }
        if ($email == '') {
            $error = 'メールアドレスを入力してください。';
        }
        // emailの検証
        if (!preg_match('#^[a-zA-Z0-9\.\-\_]+\@[a-zA-Z0-9\.\-\_]+\.[a-zA-Z0-9]+$#', $email)) {
            $error = 'メールアドレスを正しく入力してください。';
            $email = '';
        }
        // all parameters are ok
        if ($error == '') {
            // check email
            $db = n3s_get_db();
            $user_id = n3s_get_user_id_by_email($email);
            if ($user_id != 0) {
                $error = 'このメールアドレスは既に登録されています。';
                $email = '';
            } else {
                // add user
                $user_id = n3s_add_user($email, $password, $name);
                if ($user_id == 0) {
                    $error = '既にメールアドレスが登録されています。<a href="index.php?action=login&page=forgot">こちらからパスワードを変更</a>してください。';
                } else {
                    // login
                    n3s_web_login_execute($user_id, $password);
                    return;
                }
            }
        }
    }

    n3s_template_fw('login_register.html', [
        'email' => $email,
        'name' => $name,
        'twitter_id' => $twitter_id,
        'error' => $error,
    ]);
}

function n3s_web_login_forgot()
{
    $error = '';
    $email_get = empty($_GET['email']) ? '' : $_GET['email'];
    $email = empty($_POST['email']) ? $email_get : $_POST['email'];
    $quiz = empty($_POST['quiz']) ? '' : $_POST['quiz'];
    if ($email != '') {
        $email = trim($email);
        $quiz = trim($quiz);
        if ($quiz != 'くさばな') {
            $error = 'クイズの答えを入力してください。';
        }
        if ($error == '') {
            $user_id = n3s_get_user_id_by_email($email);
            if ($user_id > 0) {
                $passtoken = "{$user_id}-".hash('sha256', $email . time());
                db_exec(
                    "UPDATE users SET pass_token=? WHERE user_id=?",
                    [$passtoken, $user_id]
                );
                // メールの送信
                $passtoken_enc = urlencode($passtoken);
                $http = ($_SERVER['REQUEST_SCHEME'] == 'https') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'];
                $script_name = $_SERVER['SCRIPT_NAME'];
                $baseurl = "{$http}://{$host}{$script_name}";
                $sendto = $email;
                $subject = '[なでしこ3貯蔵庫]パスワード再設定のお知らせ';
                $body = "親愛なるユーザーの皆様:\r\n\r\n"."「なでしこ3貯蔵庫」の事務局です。\r\n";
                $body .= "パスワードの変更の旨を承っております。\r\n\r\n";
                $body .= "もしも、本当にパスワードを変更したい場合、以下のURLをクリックしてください。\r\n";
                $body .= "{$baseurl}?action=login&page=setpw&p={$passtoken_enc}\r\n";
                $body .= "\r\n";
                $body .= "変更したくない場合、本メールは削除してください。。\r\n";
                $body .= "\r\n";
                $body .= "------------------\r\n";
                $body .= "なでしこ3貯蔵庫\r\n";
                $body .= "https://n3s.nadesi.com/\r\n";
                mb_send_mail($sendto, $subject, $body);
            }
            n3s_info('承りました', 'パスワード再設定のURLを記述したメールを送信しました。');
            return;
        }
    }
    n3s_template_fw('login_forgot.html', [
        'email' => $email,
        'error' => $error,
    ]);
}

function n3s_web_login_setpw()
{
    $email = '';
    $passtoken_get = empty($_GET['p']) ? '' : $_GET['p'];
    if ($passtoken_get != '') {
        $row = db_get1('SELECT * FROM users WHERE pass_token=? LIMIT 1', [$passtoken_get]);
        if ($row) {
            $email = $row['email'];
        } else {
            n3s_error('パスワードの変更失敗', 'メールに書かれているURLを全部貼り付けてください。');
            exit;
        }
    }
    $error = '';
    $passtoken_post = empty($_POST['pass_token']) ? '' : $_POST['pass_token'];
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
            // pass_tokenの検証
            $row = db_get1('SELECT * FROM users WHERE pass_token=? LIMIT 1', [$passtoken_post]);
            if (!$row) {
                n3s_error('パスワードの変更失敗', 'メールに書かれているURLを全部貼り付けてください。');
                exit;
            }
            $hash = n3s_login_password_to_hash($password);
            db_exec(
                'UPDATE users SET password=?, pass_token="", mtime=? WHERE pass_token=?', 
                [$hash, time(), $passtoken_post]);
            n3s_info('パスワードを変更しました', '<a href="index.php?action=login">ログインしてください。</a>', true);
            exit;
        }
    }
    n3s_template_fw('login_setpw.html', [
        'email' => $email,
        'pass_token' => $passtoken_get,
        'error' => $error,
    ]);
}

function n3s_web_login_trylogin() {
    $email = empty($_POST['email']) ? '' : $_POST['email'];
    $password = empty($_POST['password']) ? '' : $_POST['password'];
    if ($email != '' && $password != '') {
        $email = trim($email);
        $password = trim($password);
        $user_id = n3s_get_user_id_by_email($email);
        if ($user_id > 0) {
            $isOK = n3s_web_login_execute($email, $password);
            if (!$isOK) {
                return;
            }
        }
    }
    n3s_template_fw('login_email.html', [
        'email' => $email,
    ]);
}

function n3s_web_login_execute($user_id, $password) {
    // login
    $ok = n3s_login($user_id, $password);
    if (!$ok) { return false; }
    // redirect
    $backurl = n3s_getBackURL();
    if ($backurl == '') {
        $mypage = n3s_getURL('my', 'mypage');
        $backurl = $mypage;
    }
    header('location:' . $backurl);
    return true;
}

/*
function n3s_web_login_callback()
{
    // パラメータを得る
    // set session
    $_SESSION['n3s_login'] = time();
    $_SESSION['user_id'] = $user_id;
    $_SESSION['name'] = $name;
    $_SESSION['screen_name'] = $screen_name;
    $_SESSION['profile_url'] = $profile_url;
    // message
    $backurl = n3s_getBackURL();
    if ($backurl == '') {
        $mypage = n3s_getURL('my', 'mypage');
        $backurl = $mypage;
    }
    header('location:'.$backurl);
}
*/

function iget($info, $key, $def = '')
{
    if (!isset($info[$key])) {
        return $def;
    }
    return $info[$key];
}

