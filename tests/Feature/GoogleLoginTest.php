<?php
// app/action/login.inc.php の n3s_web_login_google_start() / n3s_web_login_google_callback()
// および app/n3s_lib.inc.php のGoogle OAuthヘルパー関数群。
// docs/user_login_oauth_google.md の設計に対応するテスト。
//
// ネットワーク呼び出し(トークン交換)は n3s_google_http_post() 経由で行われ、
// $n3s_config['_google_http_post'] にcallableを差し込むことで実際のHTTP通信を行わずに検証する。

function n3s_test_google_enable(string $client_id = 'test-client-id'): void
{
    n3s_set_config('google_oauth_client_id', $client_id);
    n3s_set_config('google_oauth_client_secret', 'test-client-secret');
    n3s_set_config('google_oauth_redirect_uri', 'http://localhost/index.php?action=login&page=google_callback');
}

function n3s_test_jwt_encode($data): string
{
    return rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');
}

function n3s_test_fake_id_token(array $claims = []): string
{
    $payload = array_merge([
        'iss' => 'https://accounts.google.com',
        'aud' => 'test-client-id',
        'sub' => 'g-sub-1',
        'email' => 'google1@example.com',
        'email_verified' => true,
        'name' => 'Google太郎',
        'exp' => time() + 3600,
    ], $claims);
    return n3s_test_jwt_encode(['alg' => 'none', 'typ' => 'JWT']) . '.' . n3s_test_jwt_encode($payload) . '.sig';
}

function n3s_test_google_stub_token_exchange(array $claims = [], $response = null): void
{
    $id_token = n3s_test_fake_id_token($claims);
    n3s_set_config('_google_http_post', function ($url, $params) use ($id_token, $response) {
        if ($response !== null) {
            return $response;
        }
        return ['id_token' => $id_token, 'access_token' => 'dummy-access-token'];
    });
}

// n3s_web_login_google_start() を呼び、セッションに保存されたstateを使って
// $_GET を組み立てる(実際のリダイレクト→コールバックの往復を模擬する)。
function n3s_test_google_prepare_callback_get(string $code = 'dummy-code'): void
{
    n3s_test_capture(fn () => n3s_web_login_google_start());
    $_GET['state'] = $_SESSION['n3s_oauth_state'];
    $_GET['code'] = $code;
}

test('n3s_google_get_auth_url() は client_id/redirect_uri/scope/state を含むURLを生成する', function () {
    n3s_test_google_enable();

    $url = n3s_google_get_auth_url('state-abc');

    expect($url)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth?')
        ->and($url)->toContain('client_id=test-client-id')
        ->and($url)->toContain('state=state-abc')
        ->and($url)->toContain('scope=openid+email+profile');
});

test('google_oauth_client_id が未設定ならGoogleログイン開始はエラーになる', function () {
    $out = n3s_test_capture(fn () => n3s_web_login_google_start());

    expect($out)->toContain('Googleログインは現在利用できません')
        ->and($_SESSION)->not->toHaveKey('n3s_oauth_state');
});

test('Googleログイン開始でstateがセッションに保存される', function () {
    n3s_test_google_enable();

    n3s_test_capture(fn () => n3s_web_login_google_start());

    expect($_SESSION['n3s_oauth_state'])->toBeString()->not->toBe('')
        ->and($_SESSION['n3s_oauth_state_time'])->toBeInt();
});

test('コールバックでGoogle側のerrorパラメータがあればログインせずエラー表示する', function () {
    n3s_test_google_enable();
    $_GET['error'] = 'access_denied';

    $out = n3s_test_capture(fn () => n3s_web_login_google_callback());

    expect($out)->toContain('キャンセルされました')
        ->and(n3s_is_login())->toBeFalse();
});

test('stateが一致しない場合はログインできない', function () {
    n3s_test_google_enable();
    n3s_test_google_prepare_callback_get();
    $_GET['state'] = 'wrong-state';

    $out = n3s_test_capture(fn () => n3s_web_login_google_callback());

    expect($out)->toContain('セッションが切れました')
        ->and(n3s_is_login())->toBeFalse();
});

test('stateの有効期限(10分)が切れている場合はログインできない', function () {
    n3s_test_google_enable();
    n3s_test_google_prepare_callback_get();
    $_SESSION['n3s_oauth_state_time'] = time() - 60 * 11;

    $out = n3s_test_capture(fn () => n3s_web_login_google_callback());

    expect($out)->toContain('セッションが切れました')
        ->and(n3s_is_login())->toBeFalse();
});

test('トークン交換に失敗した場合はログインできない', function () {
    n3s_test_google_enable();
    n3s_test_google_prepare_callback_get();
    n3s_set_config('_google_http_post', function ($url, $params) {
        return false;
    });

    $out = n3s_test_capture(fn () => n3s_web_login_google_callback());

    expect($out)->toContain('Googleとの通信に失敗しました')
        ->and(n3s_is_login())->toBeFalse();
});

test('email_verifiedがfalseのID Tokenは拒否される', function () {
    n3s_test_google_enable();
    n3s_test_google_prepare_callback_get();
    n3s_test_google_stub_token_exchange(['email_verified' => false]);

    $out = n3s_test_capture(fn () => n3s_web_login_google_callback());

    expect($out)->toContain('確認できませんでした')
        ->and(n3s_is_login())->toBeFalse()
        ->and(n3s_get_user_id_by_email('google1@example.com'))->toBe(0);
});

test('audが自サイトのclient_idと異なるID Tokenは拒否される', function () {
    n3s_test_google_enable();
    n3s_test_google_prepare_callback_get();
    n3s_test_google_stub_token_exchange(['aud' => 'someone-elses-client-id']);

    $out = n3s_test_capture(fn () => n3s_web_login_google_callback());

    expect($out)->toContain('確認できませんでした')
        ->and(n3s_is_login())->toBeFalse();
});

test('新規のGoogleアカウントでログインするとユーザーが作成されログイン状態になる', function () {
    n3s_test_google_enable();
    n3s_test_google_prepare_callback_get();
    n3s_test_google_stub_token_exchange([
        'sub' => 'g-sub-new',
        'email' => 'newgoogle@example.com',
        'name' => '新規グーグル太郎',
    ]);

    n3s_test_capture(fn () => n3s_web_login_google_callback());

    expect(n3s_is_login())->toBeTrue();
    $user_id = n3s_get_user_id_by_email('newgoogle@example.com');
    expect($user_id)->toBeGreaterThan(0);
    $user = db_get1('SELECT * FROM users WHERE user_id=?', [$user_id], 'users');
    expect($user['google_sub'])->toBe('g-sub-new')
        ->and($user['password'])->toBe('');
});

test('既にgoogle_subが紐付いたユーザーは再度Googleログインできる', function () {
    n3s_test_google_enable();
    n3s_test_google_prepare_callback_get();
    n3s_test_google_stub_token_exchange([
        'sub' => 'g-sub-existing',
        'email' => 'existing-google@example.com',
    ]);
    n3s_test_capture(fn () => n3s_web_login_google_callback());
    $_SESSION = [];

    n3s_test_google_prepare_callback_get();
    n3s_test_google_stub_token_exchange([
        'sub' => 'g-sub-existing',
        'email' => 'existing-google@example.com',
    ]);
    n3s_test_capture(fn () => n3s_web_login_google_callback());

    expect(n3s_is_login())->toBeTrue()
        ->and(n3s_get_user_id())->toBe(n3s_get_user_id_by_email('existing-google@example.com'));
});

test('既存のパスワードユーザーと同じ検証済みメールでGoogleログインするとアカウントが紐付けられる(パスワードは維持される)', function () {
    $user_id = n3s_add_user('linked@example.com', 'original-password', 'リンク太郎');

    n3s_test_google_enable();
    n3s_test_google_prepare_callback_get();
    n3s_test_google_stub_token_exchange([
        'sub' => 'g-sub-link',
        'email' => 'linked@example.com',
    ]);

    n3s_test_capture(fn () => n3s_web_login_google_callback());

    expect(n3s_is_login())->toBeTrue()
        ->and(n3s_get_user_id())->toBe((int) $user_id);

    $user = db_get1('SELECT * FROM users WHERE user_id=?', [$user_id], 'users');
    expect($user['google_sub'])->toBe('g-sub-link');

    // パスワードでのログインも引き続き使える (docs/user_login_oauth_google.md #7.3)
    $_SESSION = [];
    $token = n3s_getEditToken();
    $_POST['email'] = 'linked@example.com';
    $_POST['password'] = 'original-password';
    $_REQUEST['edit_token'] = $token;
    n3s_test_capture(fn () => n3s_web_login_trylogin());
    expect(n3s_is_login())->toBeTrue();
});

test('Googleログイン専用ユーザーがパスワードログインを試みるとGoogleログインを促す案内が出る', function () {
    n3s_test_google_enable();
    n3s_test_google_prepare_callback_get();
    n3s_test_google_stub_token_exchange([
        'sub' => 'g-sub-onlygoogle',
        'email' => 'onlygoogle@example.com',
    ]);
    n3s_test_capture(fn () => n3s_web_login_google_callback());
    $_SESSION = [];

    $token = n3s_getEditToken();
    $_POST['email'] = 'onlygoogle@example.com';
    $_POST['password'] = 'whatever-password';
    $_REQUEST['edit_token'] = $token;
    $out = n3s_test_capture(fn () => n3s_web_login_trylogin());

    expect($out)->toContain('Googleアカウントでログインするアカウントとして登録されています')
        ->and($out)->not->toContain('パスワードの再設定が必要です')
        ->and(n3s_is_login())->toBeFalse();
});
