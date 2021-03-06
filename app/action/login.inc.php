<?php
// for clickjacking
header('X-Frame-Options: SAMEORIGIN');

// for Twitter login
global $enabled_twitter;
$enabled_twitter = TRUE;
$autoload = dirname(__DIR__ ).'/vendor/autoload.php';
use Abraham\TwitterOAuth\TwitterOAuth; // Twitter auth
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    $enabled_twitter = FALSE;
}

// no api login
function n3s_api_login()
{
    n3s_api_output($ng, ['msg'=>'should use web access']);
}

function n3s_web_login()
{
    global $n3s_config, $enabled_twitter;
    if (!$enabled_twitter) {
        n3s_error('ログイン画面を利用できません', 'Twitterライブラリをインストールしてください。');
        exit;
    }

    // callback?
    $page = n3s_get_config('page', '');
    if ($page === 'twitter_callback') {
        n3s_web_login_callback();
        return;
    }

    // TWitter関連のパラメータを得る
    $apikey = n3s_get_config('twitter_api_key', '');
    $secret = n3s_get_config('twitter_api_secret', '');
    $access_token = n3s_get_config('twitter_acc_token','');
    $access_token_secret = n3s_get_config('twitter_acc_secret','');
    if ($apikey == '') {
        n3s_error('ログイン画面を利用できません', '設定にTwitterのキーを指定してください。');
        exit;
    }
    try {
        // callback url
        $login_callback = 'https://nadesi.com/v3/storage/callback.php';
        if ($_SERVER['HTTP_HOST'] === 'localhost') { // for test
            $login_callback = 'http://localhost/repos/nako3storage/callback.php';
        }
        // start
        $connection = new TwitterOAuth($apikey, $secret, $access_token, $access_token_secret);
        // $request_token = $connection->oauth('oauth/request_token', array('oauth_callback' => $login_callback));
        $request_token = $connection->oauth('oauth/request_token', [
            'oauth_callback' => $login_callback,
        ]);
        //リクエストトークンはコールバックページでも利用するためセッションに格納しておく
        $_SESSION['oauth_token'] = $request_token['oauth_token'];
        $_SESSION['oauth_token_secret'] = $request_token['oauth_token_secret'];
        //Twitterの認証画面のURL
        $login_url = $connection->url(
            'oauth/authorize', array(
            'oauth_token' => $request_token['oauth_token']));
        // ログインボタンの表示
        n3s_template_fw('login.html',[
            'login_url' => $login_url,
        ]);
    } catch (Exception $e) {
        n3s_error('Twitterと通信ができません', $e->getMessage());
    }
}

function n3s_web_login_callback() {
    // パラメータを得る
    $apikey = n3s_get_config('twitter_api_key', '');
    $secret = n3s_get_config('twitter_api_secret', '');
    $access_token = n3s_get_config('twitter_acc_token','');
    $access_token_secret = n3s_get_config('twitter_acc_secret','');
    // check get params
    $oauth_verifier = empty($_GET['oauth_verifier']) ? '' : $_GET['oauth_verifier'];
    $oauth_token = empty($_GET['oauth_token']) ? '' : $_GET['oauth_token'];
    // check session
    if (empty($_SESSION['oauth_token']) || empty($_SESSION['oauth_token_secret'])) {
        n3s_error('Twitterへのログインに失敗', $e->getMessage());
        return;
    }

    //リクエストトークンを使い、アクセストークンを取得する
    try {
        $twitter_connect = new TwitterOAuth(
            $apikey, $secret, 
            $_SESSION['oauth_token'], $_SESSION['oauth_token_secret']);
        $access_token = $twitter_connect->oauth('oauth/access_token', [
            'oauth_verifier' => $oauth_verifier, 
            'oauth_token'=> $oauth_token]);
    
        //アクセストークンからユーザの情報を取得する
        $user_connect = new TwitterOAuth($apikey, $secret, 
            $access_token['oauth_token'], $access_token['oauth_token_secret']);
        //アカウントの有効性を確認するためのエンドポイント
        $info = $user_connect->get('account/verify_credentials'); 
    } catch (Exception $e) {
        n3s_error('Twitterへのログインに失敗', $e->getMessage());
        return;
    }
    // info を得る
    $twitter_id = intval($info->id);
    $name = $info->name;
    $screen_name = $info->screen_name;
    $description = $info->description;
    $profile_url = $info->profile_image_url_https;
    // db update
    $db = n3s_get_db();
    $user_id = 0;
    $r = db_get1('SELECT * FROM users WHERE twitter_id=?', [$twitter_id]);
    if ($r) {
        $user_id = intval($r['user_id']);
    }
    if ($user_id === 0) {
        // register
        db_insert(
            'INSERT INTO users '.
            '      (name,screen_name,description,twitter_id,profile_url,ctime,mtime)'.
            'VALUES(   ?,          ?,          ?,         ?,          ?,     ?,   ?)', 
            [$name, $screen_name, $description, $twitter_id, $profile_url, time(), time()]);
        $r = db_get1('SELECT * FROM users WHERE twitter_id=?', [$twitter_id]);
        $user_id = $r['user_id'];
    } else {
        // update
        db_exec(
            'UPDATE users SET screen_name=?, description=?, profile_url=?, mtime=? WHERE user_id=?',
            [$screen_name, $description, $profile_url, time(), $user_id]);
    }
    // set session
    $_SESSION['n3s_login'] = time();
    $_SESSION['user_id'] = $user_id;
    $_SESSION['name'] = $name;
    $_SESSION['screen_name'] = $screen_name;
    $_SESSION['profile_url'] = $profile_url;
    // message
    // n3s_template_fw('basic.html', ['contents'=>'ログインしました。']);
    $mypage = n3s_getURL('my', 'mypage');
    header('location:'.$mypage);
}

function iget($info, $key, $def = '') {
    if (!isset($info[$key])) {
        return $def;
    }
    return $info[$key];
}




