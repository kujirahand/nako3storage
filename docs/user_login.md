# ユーザーログイン・認証の仕組み

なでしこ3貯蔵庫 (nako3storage) のユーザー登録・ログイン・セッション管理の実装をまとめたドキュメントです。
コードを変更する際はここに書かれた前提を確認し、実装と差異があれば本ドキュメントを更新してください。

---

## 1. 関連ファイル

- `app/action/login.inc.php`: 登録・ログイン・パスワード再設定の画面とロジック本体。
- `app/action/logout.inc.php`: ログアウト処理。
- `app/n3s_lib.inc.php`: ログイン判定、パスワードハッシュ、CSRF トークン、セッション補助関数。
- `app/index.inc.php`: `n3s_action()` 内で `session_start()` を呼び出す（全アクション共通）。
- `app/sql/init-users.sql`: `users` テーブルの定義。
- `app/sql/init-log.sql`: `ip_check` テーブルの定義（ログイン失敗の記録用）。
- `app/template/login.html`, `login_email.html`, `login_register.html`, `login_forgot.html`, `login_setpw.html`: ログイン関連テンプレート。

認証データは `data/n3s_users.sqlite`（DB 接続名 `users`）で管理されます。ログイン失敗の記録は `data/n3s_log.sqlite`（DB 接続名 `log`）の `ip_check` に入ります。

---

## 2. セッション

- `app/index.inc.php` の `n3s_action()` が、アクション実行の直前に `ini_set('session.gc_maxlifetime', $n3s_config['session_lifetime'])` → `@session_start()` を行う。全 `action` 共通で、Web/API どちらの agent でも実行される。
- `session_lifetime` のデフォルトは `app/n3s_config.def.php` で `60 * 60 * 24`（24時間）。
- ログイン成功時に設定される `$_SESSION` キー（`n3s_login()` 内）:
  - `n3s_login`: `true`（ログイン済みフラグ）
  - `user_id`: ログインユーザーの `user_id`
  - `name`, `screen_name`: ユーザー名（DB の `name` 列をそのままコピー）
  - `profile_url`: 現状は常に空文字で初期化される
- ログイン判定は `n3s_is_login()`（`$_SESSION['n3s_login']` の有無のみを見る）。ユーザー ID は `n3s_get_user_id()`、表示用情報一式は `n3s_get_login_info()` で取得する。
- ログイン試行失敗回数は `$_SESSION['n3s_trylogin_count']` にセッション単位で保持し、5回失敗するとエラー画面を出して以後の入力を拒否する（`n3s_web_login_trylogin()`）。
- リダイレクト先は `$_SESSION['n3s_backurl']`（`n3s_setBackURL()` / `n3s_getBackURL()`）。ログイン画面に `back=index.php?action=...` が付いている場合のみ許可し、サイト外URLへは飛ばさない。

---

## 3. `users` テーブル (`app/sql/init-users.sql`)

| カラム | 内容 |
|---|---|
| `user_id` | 主キー |
| `email` | メールアドレス（UNIQUE）。ログインIDとして使う |
| `password` | ハッシュ化済みパスワード |
| `pass_token` | パスワード設定/再設定用の認証番号（7桁、期限5分） |
| `name` | 表示名 |
| `login_token` | UNIQUE。現状の登録/ログインフローでは未使用（將来利用のための予約列） |
| `screen_name`, `description`, `profile_url`, `twitter_id` | プロフィール情報 |
| `salt` | パスワードハッシュ用ソルト（ユーザー個別） |
| `ctime`, `mtime` | 作成・更新時刻（UNIXタイム） |

---

## 4. 認証処理の流れ

### 4.1 ユーザー登録 (`page=register` → `n3s_web_login_register()`)

1. `email` / `email2`（確認用）/ `name` / `itazura`（いたずら防止クイズ、答えは固定文字列 `ニンゲン`）を受け取る。
2. 入力チェック: 名前が4〜12文字、メール確認一致、クイズ正解、メール形式の正規表現チェック。
3. `n3s_get_user_id_by_email()` で既存メールでないか確認。既存なら登録拒否し「パスワード再設定」へ誘導。
4. 新規なら `n3s_add_user($email, $password, $name)` を実行。
   - このとき渡す `$password` は `hash('sha256', $email . time() . rand())` によるランダム値で、実際にユーザーが使うパスワードではない（アカウント作成時点のダミー値）。
   - `n3s_add_user()` 内で `n3s_generate_salt()` によりユーザー個別ソルトを生成し、`n3s_login_password_to_hash()` でハッシュ化して保存する。
5. `n3s_web_login_setpw_sendmail($user_id, $email, 'register')` で認証番号（`pass_token`）をメール送信。
6. `n3s_web_login_setpw($email)` を呼び出し、パスワード設定画面へ遷移。

### 4.2 認証番号メール送信 (`n3s_web_login_setpw_sendmail()`)

- `passtoken1`（3桁）+ `-` + `passtoken2`（4桁）の形式の認証番号を `n3s_randomIntStr()` で生成し、`users.pass_token` と `mtime` を更新。
- `mb_send_mail()` でメール送信（`@` でエラー抑制）。
- `$_SERVER['HTTP_HOST']` が `localhost` の場合はメール本文をそのまま画面に出力する（ローカル開発用のフォールバック。本番ホストでは表示されない）。

### 4.3 パスワード設定 (`page=setpw` → `n3s_web_login_setpw()`)

1. `email` は GET/POST または呼び出し元から引き継ぐ。無ければパスワード再設定手順のやり直しを促してエラー終了。
2. 認証番号入力フォーム（`pass1` 3桁 + `pass2` 4桁）と CSRF トークン (`n3s_getEditToken('setpw', false)`) を表示。未入力ならここで終了。
3. `token`（CSRF）が一致しなければエラー。
4. `pass_token` と `email` が一致し、かつ `mtime > time() - 300`（5分以内）のレコードを検索。見つからなければエラーで終了（`ip_check` への記録はしていない点に注意）。
5. 新パスワード (`password` / `password2`) を検証:
   - 確認用と一致すること
   - 8文字以上であること
6. OK なら `n3s_generate_salt()` で新しいソルトを生成し、`n3s_login_password_to_hash()` でハッシュ化。`users.password`, `salt` を更新し、`pass_token` を空文字にクリアする。
7. 完了後は「ログインしてください」という案内を表示するのみで、自動ログインはしない。

### 4.4 パスワード再設定申請 (`page=forgot` → `n3s_web_login_forgot()`)

1. `email` / `email2`（確認用）/ `quiz`（固定文字列 `くさばな` の入力必須）を検証。
2. 該当ユーザーが存在すれば `n3s_web_login_setpw_sendmail($user_id, $email, 'forgot')` で認証番号を再送信。
   - 存在しない場合でもエラーにはせず同じ画面遷移をする（メールアドレスの存在有無を外部から判別しにくくするため）。
3. `n3s_web_login_setpw($email)` へ遷移し、以降は 4.3 と同じ認証番号入力〜パスワード設定フローに合流する。

### 4.5 ログイン (`page=trylogin` → `n3s_web_login_trylogin()`)

1. `email` / `password` を POST から取得。
2. IP ベースのブルートフォース対策: `$_SERVER['REMOTE_ADDR']` が空の場合に限り、`ip_check` テーブルで直近1時間の失敗回数が10回超なら拒否する。
   - 実装上、`REMOTE_ADDR` が取得できているとき（通常運用の大半のケース）はこのカウントチェックを素通りする点に注意。
3. CSRF トークン (`n3s_checkEditToken()`、既定キー `default`) を検証。不一致なら「セッションが切れました」エラー。
4. `n3s_get_user_id_by_email($email)` でユーザーを検索。
5. 該当ユーザーの `password` が空文字/NULL の場合（＝登録直後でまだ本パスワード未設定、または旧仕様の移行漏れ）は、ログインさせずにパスワード再設定を案内して終了する。
6. `n3s_web_login_execute($email, $password)` → 内部で `n3s_login($email, $password)` を実行。
   - 成功: `$_SESSION` にログイン情報を設定し、ログを記録（`n3s_log(..., "login", 1)`）。`n3s_getBackURL()` があればそこへ、なければマイページへリダイレクト。
   - 失敗: セッションの `n3s_trylogin_count` をインクリメントし、5回以上で一時ブロック（案内メッセージのみでロック機構はセッション依存）。あわせて `ip_check` に失敗レコードを1件追加（`key=0`, `ip`, `memo=email`, `ctime`）。
7. 成功・失敗にかかわらず、フォーム再表示時は新しい CSRF トークンを都度発行する。

### 4.6 ログアウト (`n3s_web_logout()` → `n3s_logout()`)

- `$_SESSION` から `n3s_login`, `user_id`, `n3s_backurl`, `name` を `unset`。
- ログイン中だった場合のみ `n3s_log(..., "logout", 0)` を記録。
- `n3s_api_logout()` は常に `should use web access` を返し、API 経由のログアウト（および `n3s_api_login()` も同様にログイン）は禁止されている。

---

## 5. パスワードハッシュの仕様

```php
function n3s_login_password_to_hash($password, $salt = '')
{
    if ($salt === '' || $salt === null) {
        // 後方互換: saltが未設定の既存ユーザー向け
        $hash = hash('sha256', $password . '::' . LOGIN_HASH_SALT_DEFAULT);
        return 'def::' . $hash;
    }
    // ユーザー個別のsaltを使用
    $hash = hash('sha256', $password . '::' . $salt);
    return 'salt::' . $hash;
}
```

- 保存形式はプレフィックス付き文字列:
  - `salt::<sha256>`: ユーザー個別ソルト（`users.salt`）を使った通常パターン。新規登録・パスワード設定は必ずこちら。
  - `def::<sha256>`: `salt` 列が空の既存ユーザー向け後方互換パターン。`n3s_lib.inc.php` 内の定数 `LOGIN_HASH_SALT_DEFAULT`（全ユーザー共通の固定ソルト）を使う。
- ソルト生成は `n3s_generate_salt()` = `bin2hex(random_bytes(32))`（64文字の16進文字列、暗号学的乱数）。
- 検証時 (`n3s_login()`) は DB に保存された `salt` を使って同じロジックでハッシュを再計算し、文字列一致 (`!==`) で比較する。
- ハッシュアルゴリズムは単純な SHA-256 の1回計算であり、`password_hash()`/`bcrypt`/`Argon2` のような計算コスト調整（ストレッチング）は行っていない。DB 漏洩時のオフライン総当たり耐性は低い。

---

## 6. CSRF トークン

- `n3s_getEditToken($key = 'default', $update = true)`:
  - `$update === false` かつセッションに既存トークンがあればそれを返す（`setpw` 画面で使用）。
  - それ以外は `$n3s_config['edit_token']` にキャッシュがなければ `bin2hex(random_bytes(32))` を新規生成し、`$_SESSION["n3s_edit_token_$key"]` に保存する。
- `n3s_checkEditToken($key = 'default')`: セッションの `n3s_edit_token_$key` と `$_REQUEST['edit_token']` を比較。
- ログインフォーム (`trylogin`) は既定キー `default`、パスワード設定フォーム (`setpw`) は専用キー `setpw` を使う。

---

## 7. 管理者判定

- `n3s_is_admin()`: `n3s_get_user_id()` が `n3s_get_config('admin_users', [1])`（`n3s_config.ini.php` で上書き可能）のリストに含まれるかを厳密比較 (`===`) で判定。
- 管理者は投稿の編集・削除、アップロードファイルの削除、`bad`（通報）管理、`admin` アクションなどで一般ユーザーと異なる権限を持つ（`app/action/save.inc.php`, `upload.inc.php`, `bad.inc.php`, `admin.inc.php`, `show.inc.php`, `list.inc.php` などで参照）。

---

## 8. ログイン状態を利用している主な箇所

- `save.inc.php`: 投稿保存時の `user_id`/`author` 上書き、編集権限判定、削除権限判定。
- `upload.inc.php`: アップロード自体がログイン必須。削除時の権限判定に `n3s_is_admin()`。
- `mypage.inc.php`, `fav.inc.php`, `presave.inc.php`: 未ログイン時はログイン画面へ誘導、またはブロック。
- `api.inc.php`: アプリ内ストレージ API のうち `page=is_logined` / `page=get_user` 以外はログイン必須（`n3s_is_login()`）。
- `bad.inc.php`, `admin.inc.php`: 通報・管理系機能へのアクセス制御に `n3s_is_login()` / `n3s_is_admin()` を使用。

---

## 9. セキュリティ上の注意点

- ブルートフォース対策は「セッション単位の失敗回数（5回）」と「IPベースの直近1時間の失敗回数（10回）」の二段構えだが、後者は現状 `REMOTE_ADDR` が空文字のときしか判定に入らない実装になっている（`login.inc.php` の `if (!$ip)` 分岐）。IP が取得できる通常環境ではこのチェックを通過してしまうため、変更を加える際はこの条件式を要確認。
- パスワードハッシュは単純な SHA-256 + ソルトであり、ストレッチングを行う `password_hash()`/`password_verify()` への移行が課題として残っている。移行する場合は `def::` / `salt::` プレフィックスとの共存（既存ハッシュの検証経路を壊さない）を考慮すること。
- `pass_token` によるパスワード設定・再設定フローは、メールアドレスの実在確認を兼ねている。認証番号の有効期限は5分固定（`n3s_web_login_setpw()` 内の `time() - 60 * 5`）。
- `n3s_web_login_register()` の新規ユーザーには、実際に使われないランダムなダミーパスワードがまず設定され、`pass_token` を使ったパスワード設定フローを経て初めて有効なパスワードが設定される。ダミーパスワードのハッシュがそのまま残っている間はログイン不可（4.5 の手順5で弾かれる）。
- `n3s_api_login()` / `n3s_api_logout()` は常に失敗を返し、API 経由のログイン・ログアウトはできない（Web セッションのみ）。
- ログインフォームの「メールアドレスを記憶」機能はブラウザの `localStorage` にメールアドレス（パスワードは含まない）を保存するクライアントサイドの利便機能であり、サーバー側のログイン処理とは無関係。
