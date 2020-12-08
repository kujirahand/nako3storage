<?php
// for Twitter login
require_once dirname(__DIR__ ).'/vendor/autoload.php'; // for autoload
use Abraham\TwitterOAuth\TwitterOAuth; // Twitter auth

// no api login
function n3s_api_login()
{
    n3s_api_output($ng, ['msg'=>'should use web access']);
}

function n3s_web_login()
{
    global $n3s_config;

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
        $connection = new TwitterOAuth($apikey, $secret, $access_token, $access_token_secret);
        // $request_token = $connection->oauth('oauth/request_token', array('oauth_callback' => $login_callback));
        $request_token = $connection->oauth('oauth/request_token', []);
        //リクエストトークンはコールバックページでも利用するためセッションに格納しておく
        $_SESSION['oauth_token'] = $request_token['oauth_token'];
        $_SESSION['oauth_token_secret'] = $request_token['oauth_token_secret'];
        //Twitterの認証画面のURL
        $login_url = $connection->url('oauth/authorize', array('oauth_token' => $request_token['oauth_token']));
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
    //
    $oauth_verifier = empty($_GET['oauth_verifier']) ? '' : $_GET['oauth_verifier'];
    $oauth_token = empty($_GET['oauth_token']) ? '' : $_GET['oauth_token'];

    //リクエストトークンを使い、アクセストークンを取得する
    $twitter_connect = new TwitterOAuth(
        $apikey, $secret, 
        $_SESSION['oauth_token'], $_SESSION['oauth_token_secret']);
    $access_token = $twitter_connect->oauth('oauth/access_token', [
        'oauth_verifier' => $oauth_verifier, 
        'oauth_token'=> $oauth_token]);

    //アクセストークンからユーザの情報を取得する
    $user_connect = new TwitterOAuth($apikey, $secret, 
        $access_token['oauth_token'], $access_token['oauth_token_secret']);
    $user_info = $user_connect->get('account/verify_credentials'); //アカウントの有効性を確認するためのエンドポイント
    print_r($user_info);

}

