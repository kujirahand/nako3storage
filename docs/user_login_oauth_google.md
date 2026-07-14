# Googleアカウントによるログイン 設計ドキュメント

[Issue #227](https://github.com/kujirahand/nako3storage/issues/227) 対応。
既存のメール/パスワード認証 ([user_login.md](user_login.md)) に加えて、Googleアカウントでのログイン（OAuth 2.0 / OpenID Connect の認可コードフロー）を実装するための設計をまとめる。
**このドキュメントは設計段階のものであり、実装はまだ行われていない。** 実装時はここに書かれた前提を見直し、差異があれば更新すること。

---

## 1. 方針・スコープ

- 認可コードフロー（Authorization Code Flow）のみをサポートする。JS SDK（Google Identity Services のワンタップ等）は使わず、サーバーサイドのリダイレクトのみで完結させる。既存アプリが素の手続き型PHP（フレームワーク無し）であることに合わせ、依存ライブラリを増やさない。
- スコープは `openid email profile` のみ。Googleカレンダー等の追加APIは呼ばないため `access_type=offline`（リフレッシュトークン取得）は不要 → `access_type=online` を使う。取得したアクセストークン/リフレッシュトークンは保存しない（本人確認だけに使い捨てる）。
- 既存のメール/パスワードログインは変更しない。Googleログインは追加の選択肢として `login_email.html` にボタンを追加する形にする。
- 新規のcomposer依存（JWTライブラリ等）は追加しない。ID Tokenの検証は後述の方法でライブラリ無しに行う。

---

## 2. 全体フロー

```
[ユーザー]        [なでしこ3貯蔵庫]                [Google]
   |  「Googleでログイン」クリック                     |
   |------------------->|                              |
   |                     | state生成・セッション保存    |
   |                     |----------------------------->| (認可画面へリダイレクト)
   |                     |                              |
   |  Googleでログイン・同意                            |
   |------------------------------------------------->  |
   |                     |<-----------------------------|
   |                     | ?code=...&state=... で戻る    |
   |                     | state検証                    |
   |                     |----------------------------->| POST /token (code→token, client_secret付き)
   |                     |<-----------------------------| id_token, access_token
   |                     | id_tokenのclaims検証          |
   |                     | sub/emailでユーザー検索・作成 |
   |                     | セッション確立                |
   |<--------------------| マイページへリダイレクト      |
```

---

## 3. 関連ファイル（新規・変更予定）

| ファイル | 変更内容 |
|---|---|
| `app/action/login.inc.php` | `n3s_web_login()` のディスパッチに `page=google_login` / `page=google_callback` を追加。Google専用の処理関数群を追加。 |
| `app/n3s_lib.inc.php` | Google OAuth用のヘルパー関数（トークン交換、ID Token検証、ユーザー検索/作成/紐付け）、セッション確立処理の共通化。 |
| `app/n3s_config.def.php` | `google_oauth_client_id` / `google_oauth_client_secret` / `google_oauth_redirect_uri` のデフォルト値（空文字）を追加。 |
| `n3s_config.ini.php`（gitignore対象・サイト固有設定） | 実際のクライアントIDとシークレットを設定する場所。既存の `admin_users` 上書き等と同じ扱い。 |
| `app/sql/init-users.sql` | `users` テーブルに `google_sub` カラムを追加（新規構築時）。 |
| `app/sql/migrate-xxxx-add-google-sub.sql`（新規、仮称） | 既存DBに対する `ALTER TABLE` マイグレーション。 |
| `app/template/login_email.html` | 「Googleでログイン」ボタンを追加。 |
| `docs/user_login.md` | Googleログイン追加に伴う前提の更新（本ドキュメントへのリンク、`users`テーブル定義の追記など）。 |

---

## 4. DBスキーマ変更

`users` テーブルに、Googleの安定した一意識別子である `sub`（subject）を保存するカラムを追加する。

```sql
-- app/sql/init-users.sql に追記（新規構築時）
ALTER TABLE users ADD COLUMN google_sub TEXT DEFAULT '';
```

既存の `login_token`（未使用の予約列）や `twitter_id`（Twitter連携の名残、未実装）は意味が異なるため流用しない。`google_sub` は文字列（Googleの `sub` は数値だが将来的な形式変更に備えて文字列として扱う）。

一意制約は、SQLiteの部分インデックス（partial index）を使い「空文字は対象外」とすることで、Google未連携ユーザー（`google_sub=''`）が複数いてもUNIQUE制約に抵触しないようにする。

```sql
CREATE UNIQUE INDEX IF NOT EXISTS idx_users_google_sub
  ON users(google_sub) WHERE google_sub != '';
```

既存DBへの適用は、`init-users.sql` の実行では追いつかない（既にテーブルが存在するため）ので、別途マイグレーションSQL・適用手順（`Justfile` へのタスク追加、または起動時の簡易マイグレーションチェック）を用意する。既存のマイグレーション運用の有無を実装前に確認すること（現状 `app/sql/` にはマイグレーション用の仕組みが見当たらないため、初回導入時にどう既存本番DBへ反映するかは要検討）。

---

## 5. 設定項目

`app/n3s_config.def.php` に既存の設定と同じ並びでデフォルト値（空文字）を追加する。

```php
// Google OAuth
"google_oauth_client_id" => "",
"google_oauth_client_secret" => "",
// 例: "https://n3s.nadesi.com/index.php?action=login&page=google_callback"
"google_oauth_redirect_uri" => "",
```

実際の値は既存の `admin_email` 等と同様、gitignore対象の `n3s_config.ini.php`（サイト固有設定）で上書きする。`google_oauth_client_id` が空の場合はGoogleログインのボタン自体を表示しない（未設定環境で壊れたリンクを出さないため）。

Google Cloud Console 側の設定:
- 承認済みのリダイレクトURIに `google_oauth_redirect_uri` と同じ値を登録する。
- OAuth同意画面のスコープは `openid`, `email`, `profile` のみ。

---

## 6. ルーティング・画面変更

`n3s_web_login()`（`app/action/login.inc.php`）のディスパッチに以下を追加する。既存の `back` パラメータ処理（サイト内URLのみ許可）はそのまま共通で使う。

```php
} else if ($page == 'google_login') {
    n3s_web_login_google_start();
    return;
} else if ($page == 'google_callback') {
    n3s_web_login_google_callback();
    return;
}
```

`login_email.html` にボタンを追加（`google_oauth_client_id` が設定されている場合のみ表示）:

```html
{{ if $google_login_enabled }}
<div class="info_box">
    <a class="pure-button" href="index.php?action=login&page=google_login">Googleでログイン</a>
</div>
{{ /if }}
```

※テンプレートの条件分岐構文は `fw_simple` の実装に合わせる（既存テンプレートの記法を要確認）。

コールバックURLは新規の独立ファイルを作らず、既存の `index.php?action=login&page=google_callback` をそのまま使う方針とする。ルート直下の `callback.php`（`page=twitter_callback` を強制する未使用ファイル）と同様の「短縮URL」パターンも選択肢だが、実装対象を増やさないためここでは採用しない。

---

## 7. 処理詳細

### 7.1 ログイン開始 (`n3s_web_login_google_start()`)

1. `state` を `bin2hex(random_bytes(16))` で生成し、`$_SESSION['n3s_oauth_state']` と `$_SESSION['n3s_oauth_state_time'] = time()` に保存する（CSRF対策。既存の `pass_token` の5分有効期限と同様、`state` も10分程度で無効化する）。
2. 認可エンドポイントURLを組み立ててリダイレクトする。

```php
function n3s_google_get_auth_url($state)
{
    $client_id = n3s_get_config('google_oauth_client_id', '');
    $redirect_uri = n3s_get_config('google_oauth_redirect_uri', '');
    $params = [
        'client_id' => $client_id,
        'redirect_uri' => $redirect_uri,
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $state,
        'access_type' => 'online',
        'prompt' => 'select_account',
    ];
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
}

function n3s_web_login_google_start()
{
    if (n3s_get_config('google_oauth_client_id', '') === '') {
        n3s_error('設定エラー', 'Googleログインは現在利用できません。');
        return;
    }
    $state = bin2hex(random_bytes(16));
    $_SESSION['n3s_oauth_state'] = $state;
    $_SESSION['n3s_oauth_state_time'] = time();
    header('location:' . n3s_google_get_auth_url($state));
}
```

### 7.2 コールバック (`n3s_web_login_google_callback()`)

1. **ユーザーによる拒否**: `$_GET['error']` があれば（例: `access_denied`）、エラー表示してログイン画面へ戻す。
2. **state検証**: `$_GET['state']` が `$_SESSION['n3s_oauth_state']` と一致し、かつ `time() - $_SESSION['n3s_oauth_state_time'] < 60 * 10` であることを確認。検証後は必ず `unset()` して使い捨てにする（リプレイ防止）。不一致・期限切れなら「セッションが切れました」エラー（既存の `n3s_web_login_setpw()` の `token` 不一致時の文言と統一）。
3. **トークン交換**: `$_GET['code']` を使い、サーバー間通信（curl）でGoogleのトークンエンドポイントに`client_secret`付きでPOSTする。

```php
function n3s_google_exchange_code($code)
{
    $params = [
        'code' => $code,
        'client_id' => n3s_get_config('google_oauth_client_id', ''),
        'client_secret' => n3s_get_config('google_oauth_client_secret', ''),
        'redirect_uri' => n3s_get_config('google_oauth_redirect_uri', ''),
        'grant_type' => 'authorization_code',
    ];
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $http_code !== 200) {
        return false;
    }
    return json_decode($body, true);
}
```

4. **ID Tokenの検証**: レスポンスに含まれる `id_token`（JWT）を検証する。

   このID Tokenは「サーバー間の直接通信（`client_secret` で認証済みのHTTPS接続）」でGoogleから受け取ったものであり、ブラウザ経由で改ざんされうる経路を通っていない。そのため署名検証（JWKS取得・鍵ローテーション対応）を実装する必要性は低いと判断し、**JWTの中間セグメント（payload）をbase64url decodeしてclaimsを取り出し、以下を検証するだけ**にする。これにより追加の composer 依存（JWTライブラリ）や、署名検証用の追加ネットワーク呼び出しを避ける。

   - `aud` === 自分の `google_oauth_client_id`
   - `iss` が `https://accounts.google.com` または `accounts.google.com`
   - `exp` が現在時刻より未来
   - `email_verified` が `true`（文字列 `"true"` の場合もあるため緩やかに比較）

   より高い保証が必要になった場合（例: 将来的にモバイルアプリ等ブラウザを経由しない経路からもID Tokenを受け取るようになった場合）は、`https://www.googleapis.com/oauth2/v3/certs` のJWKSを使った署名検証に切り替える拡張ポイントとして、検証関数を独立させておく（`n3s_google_verify_id_token($id_token): array|false`）。

```php
function n3s_google_verify_id_token($id_token)
{
    $parts = explode('.', $id_token);
    if (count($parts) !== 3) {
        return false;
    }
    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
    if (!is_array($payload)) {
        return false;
    }
    $client_id = n3s_get_config('google_oauth_client_id', '');
    if (($payload['aud'] ?? '') !== $client_id) {
        return false;
    }
    if (!in_array($payload['iss'] ?? '', ['https://accounts.google.com', 'accounts.google.com'], true)) {
        return false;
    }
    if (($payload['exp'] ?? 0) < time()) {
        return false;
    }
    $email_verified = $payload['email_verified'] ?? false;
    if ($email_verified !== true && $email_verified !== 'true') {
        return false;
    }
    return $payload; // sub, email, name などを含む
}
```

5. **ユーザーの検索・作成・紐付け**（優先順位）:
   1. `google_sub`（`payload['sub']`）で既存ユーザーを検索 → 見つかればそのままログイン。
   2. 見つからなければ `email`（`payload['email']`）で既存ユーザーを検索 → 見つかれば、そのユーザーに `google_sub` を紐付けて（アカウントリンク）ログイン。
      - この紐付けは「Googleが検証済みのメールアドレス」と「既存アカウント登録時にメール認証番号で検証済みのメールアドレス」が一致する場合のみ行うため、メールアドレスの所有者取り違えによるアカウント乗っ取りリスクは既存の登録フローと同水準と考える。
   3. どちらにも該当しなければ新規ユーザーを作成（`n3s_add_user_google($email, $name, $sub)`）。パスワードは空文字のまま（＝パスワードログインは使わせない）。いたずら防止クイズやメール確認フローは、Google自身がメール所有権を検証済みのためスキップする。
6. **セッション確立**: `n3s_login()` 内でセッションキーを設定している処理（`$_SESSION['n3s_login']` 等）を `n3s_login_session_start($user)` として切り出し、パスワードログインとGoogleログインの両方から呼べるようにする（重複コード防止）。
7. ログ記録: `n3s_log("{$email},sub={$sub}", "login_google", 1)`。
8. リダイレクト: 既存の `n3s_web_login_execute()` と同じく `n3s_getBackURL()` があればそこへ、無ければマイページへ。

### 7.3 パスワード認証とGoogle認証の併用（アカウントの二重化）

同一ユーザーが「メール/パスワード」「Google」の両方でログインできる状態を、明示的にサポートする。

- **既存のパスワードユーザーがGoogleでログインした場合**（7.2 手順5-2）: `email` 一致でアカウントリンクする際、`password` 列には一切手を加えない。`google_sub` を追記するだけなので、リンク後は同じアカウントに対してパスワードログインとGoogleログインの**両方**が使える状態になる。
- **Google経由で新規作成されたユーザーが後からパスワードも使えるようにする場合**: 新規作成直後は `password` が空文字のため、そのユーザーはGoogleログインのみ可能な状態から始まる（8節参照）。既存の「パスワードを忘れた場合」フロー（`page=forgot` → `page=setpw`）でメール認証番号を使って新しいパスワードを設定すれば、以後はパスワードログインも使えるようになる。この既存フローに変更は不要（`n3s_get_user_id_by_email()` はGoogle経由ユーザーも通常ユーザーと同じ `users` テーブルの行として扱うため）。
- どちらの経路でログインしても同じ `user_id` に紐づくため、投稿・お気に入り等のデータはシームレスに引き継がれる。ログイン方法の選択によってデータが分かれることはない。
- ユーザーへの案内: マイページ等に「連携中のログイン方法」を表示する画面は本設計のスコープ外だが、将来的に「Google連携済み」「パスワード設定済み」の状態をユーザー自身が確認できるUIを追加すると親切（TODOへ追記）。

---

## 8. 既存コードへの影響・注意点

- `n3s_web_login_trylogin()` には「`users.password` が空文字ならパスワード再設定を促す」分岐がある（[user_login.md 4.5](user_login.md) 手順5）。Google連携のみのユーザーはパスワードが常に空のため、このままだとメール/パスワードログインを試みたGoogle専用ユーザーに「パスワードの再設定」という誤った案内が出てしまう。`google_sub` が設定されているユーザーの場合は「Googleでログインしてください」という案内に出し分ける修正が必要。
- `n3s_add_user()` はパスワードを必須引数として要求する実装になっている。Google経由の新規作成は素のパスワードを持たないため、別関数 `n3s_add_user_google()` を用意し、`password` 列は空文字・`google_sub` 列にsubを保存する形にする。
- 管理者判定 (`n3s_is_admin()`) やその他 `n3s_get_user_id()` を使う既存機能（`fav`, `save`, `upload` 等）は `user_id` にのみ依存しているため、Google経由でログインしたユーザーでも変更なく動作する。

---

## 9. セキュリティ上の注意点

- `state` パラメータによるCSRF対策を必ず行う（7.2 手順2）。使い捨て・有効期限付き。
- `email_verified` が真でないemailは信用しない（Google Workspace外の一部フローでは偽装可能なため）。
- `client_secret` はコミット対象外の `n3s_config.ini.php` にのみ保存する。
- リダイレクトURIはGoogle Cloud Console側で完全一致のもの以外は使えないため、それ自体がオープンリダイレクト対策になる。ただし、`n3s_setBackURL()` に渡す `back` パラメータは既存同様「サイト内URLのみ許可」のチェックを維持する。
- アクセストークン・リフレッシュトークンは保存しない（本人確認以外の用途に使わないため、漏洩時の影響範囲を最小化する）。
- ブルートフォース対策（既存のIP/セッション単位の失敗カウント）はパスワード推測を防ぐためのものであり、Googleログインには本質的に不要。ただし異常なアクセス（同一IPからの大量のcallback呼び出し等）を検知したい場合は別途レート制限を検討する（本設計では対象外）。

---

## 10. テスト方針

既存テスト（`tests/Feature/LoginExecuteTest.php` 等）は `n3s_test_setup()` でDB・セッションを使い捨てにした上で、対象関数を直接呼び出すスタイル。Google連携部分もネットワーク呼び出し（トークン交換）を関数として分離してあるため、Pestのテストではこれらをスタブに差し替えられるようにする。例えば `$n3s_config['_google_http_client']` のような差し替え可能なコールバックを用意し、本番はcurl実装、テストはこの設定値を上書きしてダミーレスポンスを返す、という形にする（既存の `dir_sql` 等をテストで上書きする方式と同じ考え方）。

想定するテストケース:
- `n3s_google_get_auth_url()` が正しいクエリパラメータ（`client_id`, `redirect_uri`, `scope`, `state` 等）を含むURLを生成する。
- `n3s_web_login_google_start()` が `state` をセッションに保存し、Googleの認可URLへリダイレクトする。
- `n3s_web_login_google_callback()`:
  - `state` 不一致・期限切れでエラーになる。
  - トークン交換失敗（HTTPエラー等）でエラーになる。
  - `email_verified=false` のID Tokenを拒否する。
  - 新規ユーザーが作成され、ログイン状態になる。
  - 既存の `google_sub` を持つユーザーが再ログインできる。
  - メールアドレスが一致する既存ユーザーに `google_sub` が紐付けられる。
- Google連携ユーザーがパスワードログインを試みたときに、適切な案内文が出る（8節の修正）。

---

## 11. 実装チェックリスト（TODO）

- [ ] `app/sql/init-users.sql` に `google_sub` カラムを追加
- [ ] 既存DB向けマイグレーションSQL・適用手順の用意
- [ ] `app/n3s_config.def.php` に設定キーを追加
- [ ] `n3s_lib.inc.php` にヘルパー関数群を追加（トークン交換・ID Token検証・ユーザー検索/作成/紐付け・セッション確立の共通化）
- [ ] `login.inc.php` にディスパッチとGoogle専用処理関数を追加
- [ ] `login_email.html` にボタン追加（設定が空なら非表示）
- [ ] `n3s_web_login_trylogin()` のパスワード空文字分岐にGoogle専用ユーザー向けの案内を追加
- [ ] Pestテストの追加
- [ ] `docs/user_login.md` の更新（本ドキュメントへのリンク、テーブル定義の追記）
- [ ] Google Cloud Console側でOAuthクライアントを作成し、リダイレクトURIを登録
- [ ] （将来）マイページ等に「連携中のログイン方法（パスワード/Google）」を表示するUI（7.3節）
