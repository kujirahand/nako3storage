# テストの仕組み (Pest)

`/tests` 以下に [Pest](https://pestphp.com/) (v4) によるテストがあります。現状はログイン・認証まわり
(`docs/user_login.md` に対応する実装)を対象にしています。

このドキュメントはテストの構成・実行方法・前提・既知の制約をまとめたものです。実装と差異が出たら
本ドキュメントも更新してください(`AGENTS.md` の運用方針と同様)。

---

## 1. 実行方法

### just を使う場合 (推奨)

```sh
just test
```

初回は `vendor/` が無いので、自動的に依存関係のインストール (`composer install` 相当) が走ってから
Pest が実行されます。2回目以降は `vendor/bin/pest` が既にあればインストールをスキップします。

`composer` コマンドが使える環境ではそれを使います。`composer` が無い環境(このリポジトリの開発コンテナなど)
では、先に以下を実行してリポジトリ直下に `composer.phar` を用意してください。

```sh
just composer-phar   # https://getcomposer.org/installer から composer.phar を取得 (初回のみ)
just test            # 以降は composer.phar を自動的に使う
```

その他の主なレシピ:

```sh
just test-filter tests/Unit/UserModelTest.php   # 特定ファイルだけ実行
just test-filter tests/Feature                  # 特定ディレクトリだけ実行
just lint                                        # PHP構文チェック (AGENTS.md #13 と同等)
```

`just` 単体 (引数なし) は `just test` と同じです(`Justfile` の `default` レシピ)。

### just を使わない場合

```sh
composer install     # or: php composer.phar install
php vendor/bin/pest
```

---

## 2. 依存関係とファイル配置

- ルートの `composer.json` / `composer.lock` / `vendor/` — **テスト専用**の依存関係 (`pestphp/pest`)。
  アプリ本体 (`app/`) は今まで通り `app/composer.json` / `app/vendor/` を使っており、混同しないこと。
- `phpunit.xml` — Pest/PHPUnit の設定。`bootstrap="vendor/autoload.php"`、テスト対象は `./tests`。
- `tests/Pest.php` — Pest のエントリーポイント。`tests/bootstrap.php` を読み込み、
  `Feature`・`Unit` 両ディレクトリに `TestCase` 適用と `beforeEach` フックを設定する。
- `tests/TestCase.php` — 素の `PHPUnit\Framework\TestCase`(現状カスタマイズなし)。
- `tests/bootstrap.php` — アプリのライブラリ読み込みと、テストごとの初期化ヘルパー群
  (`n3s_test_setup()` など)。詳細は次節。
- `tests/Unit/` — `app/n3s_lib.inc.php` の純粋なロジック(パスワードハッシュ、CSRFトークン、
  セッション判定、ユーザー作成など)のテスト。
- `tests/Feature/` — `app/action/login.inc.php` の実際のアクション関数(ログイン試行、
  ユーザー登録・パスワード再設定のバリデーション、API経由ログイン拒否など)のテスト。

`/vendor`、`/composer.phar` は `.gitignore` 済み。`composer.lock` はリポジトリにコミットして
バージョンを固定する。

---

## 3. bootstrap の仕組み (`tests/bootstrap.php`)

このプロジェクトは Composer オートロードの無い、素の手続き型 PHP (`fw_simple`) 構成です。
そのため、テスト側で `app/n3s_config.def.php` → `app/n3s_lib.inc.php` →
`app/action/login.inc.php` / `logout.inc.php` を一度だけ `require_once` し、
関数を素で呼び出す形でテストします(単体テストというより「関数レベルの結合テスト」に近い)。

各テストの実行前 (`beforeEach`) には `n3s_test_setup()` が呼ばれ、以下を作り直します:

- **DB**: テスト専用の一時ディレクトリ (`sys_get_temp_dir()/nako3storage-tests-<pid>/...`) に
  空の SQLite ファイルを用意し、`database_set()` で `main` / `log` / `users` を張り替える。
  実体が無ければ `app/sql/init-*.sql` で自動的にスキーマが作られる (`fw_database.lib.php` の挙動)。
  実データ (`data/*.sqlite`) には一切触れない。
- **テンプレート/キャッシュ**: `dir_template` は実際の `app/template` を指す(読み取り専用なので
  実行時生成にそのまま使う)。`dir_cache` はテストごとの一時ディレクトリを指すため、
  本番用の `cache/` を汚さない。
- **スーパーグローバル**: `$_GET` / `$_POST` / `$_REQUEST` / `$_SESSION` を空にし、
  `$_SERVER['REMOTE_ADDR']` に通常運用を模した適当な値 (`203.0.113.10`) をセットする
  (理由は 5. を参照)。
- **`$n3s_config`**: `baseurl` や `admin_users` など、テストで必要な最小限のデフォルト値を設定する。
  `n3s_test_setup(['admin_users' => [42]])` のように連想配列を渡せば個別に上書きできる。
  `page` / `mode` は毎回 `unset()` してリセットする(理由は下記コラム参照)。

- ログイン成功・失敗、`salt::` / `def::` 両方のハッシュ形式 (`n3s_login`)
- パスワード未設定 (`password=''`) アカウントは常にログイン不可であること (`n3s_login`)
- ログイン状態の参照系 (`n3s_is_login` / `n3s_get_user_id` / `n3s_get_login_info` / `n3s_is_admin`)
- ログアウトとログ記録 (`n3s_logout`)
- CSRFトークンの発行・検証 (`n3s_getEditToken` / `n3s_checkEditToken`)
- リダイレクト先の一時保存 (`n3s_setBackURL` / `n3s_getBackURL`)
- API経由のログイン・ログアウトが常に拒否されること (`n3s_api_login` / `n3s_api_logout`)
- `n3s_web_login_execute()` によるログイン確定とリダイレクト先解決
- `n3s_web_login_trylogin()` のログイン試行(成功/パスワード誤り/CSRF不正/未登録メール)、
  失敗回数のセッションへの積み上げ(1〜4回目)、未登録メールでは失敗カウント・`ip_check`記録が
  されない非対称性
- `n3s_web_login_register()` / `n3s_web_login_forgot()` の入力バリデーション失敗パターン
- `n3s_web_login_setpw_sendmail()` の認証番号発行、`n3s_web_login_setpw()` の一部の分岐
  (詳細は次節)、および `n3s_web_login()` 経由(引数なし呼び出し)での `$_REQUEST['email']`
  フォールバック
- `n3s_web_login()` 自体のページ振り分け (`page=register`/`forgot`/`trylogin`/未指定) と、
  `back` パラメータのサイト内URLホワイトリスト判定(外部URL・プロトコル相対URLは無視されること)

---

## 5. テストしていない・できない範囲と理由

`app/action/login.inc.php` 内で使われていた `exit;` はすべて `return;` に変更されたため、これまでテストプロセスが強制終了されていた正常系や一部のエラー系（新規登録・パスワード再設定申請の成功パス、ログイン5回連続失敗時のブロック、パスワード未設定ユーザーのログイン試行時の案内画面など）についても自動テストが可能となり、テストを追加しました。

ただし、以下の条件については自動テストの対象外です（あるいは特別な環境準備が必要なため、手動確認 `php -S localhost:8000` などで担保します）:

- **ログイン攻撃のブロック (IPブロック)**
  IPチェック用の条件 (1時間以内に10回以上のログイン失敗がある場合) をテスト環境で模倣するには、テスト用DBに対して `log` スキーマ上の `ip_check` レコードを事前にインサートして準備する必要があるなどの理由から。

### 既知の不具合を固定化しているテスト

実装を読む中で見つかった、ドキュメント (`docs/user_login.md`) 通りには動いていない・意図と異なると
思われる挙動を、いくつかテストとして固定化しています。**いずれも未修正です。** 修正する場合は
該当テストの期待値も合わせて更新してください。

- **`REMOTE_ADDR` が空のとき ip_check の参照先DBを取り違える**
  (`tests/Feature/TryLoginTest.php`)
  `docs/user_login.md` #9 にある通り、ブルートフォース検出は `REMOTE_ADDR` が空のときだけ動く実装
  ですが、実際のクエリ (`app/action/login.inc.php` の `db_get('SELECT count(*) FROM ip_check ...')`)
  は `dbname` 引数を省略しており、既定の `main` DB を参照してしまいます。`ip_check` テーブルは
  `log` DB にしか無いため、この分岐を通ると `PDOException` で落ちます。
  `tests/bootstrap.php` は通常運用を模して `REMOTE_ADDR` にダミー値を設定しているため、他のテストは
  このバグを踏みません。該当テストだけ明示的に `REMOTE_ADDR` を unset して再現・固定化しています。

- **CSRFトークンのキャッシュがキーごとに分かれていない**
  (`tests/Unit/CsrfTokenTest.php`)
  `n3s_getEditToken($key, $update=true)` は生成済みトークンを `$n3s_config['edit_token']` という
  単一の変数にキャッシュしており、`$key` ごとには分かれていません。同一リクエスト内で異なる `$key`
  (例: `'default'` の次に `'setpw'`) を呼ぶと、2回目は新規発行がスキップされて1回目のトークンが
  再利用され、かつ `$_SESSION["n3s_edit_token_setpw"]` は書き込まれません。

- **`n3s_get_user_name()` がユーザー名ではなく常に0を返す**
  (`tests/Unit/LoginSessionTest.php`)
  `$_SESSION['name']` を `(int)` キャストして返しているため、数字始まりでない通常の名前は
  必ず `int(0)` になります。`n3s_lib.inc.php` 内で `$a['author'] = n3s_get_user_name();` として
  投稿の著者名に使われている箇所があり、ログインユーザー名が入るべき場所が0になってしまいます。

- **未登録メールアドレスへのログイン試行は失敗回数のカウント対象外**
  (`tests/Feature/TryLoginTest.php`)
  `n3s_web_login_trylogin()` の失敗カウント・`ip_check` への記録は、`n3s_get_user_id_by_email()`
  が実在ユーザーを返した場合 (`$user_id > 0`) の分岐内でのみ行われます。存在しないメールアドレスへの
  試行は、セッションの失敗回数にもIPログにも一切残りません。ブルートフォース対策やメールアドレスの
  在否推測しやすさに関わる、仕様として明文化されていない挙動です。

---

## 6. 実行結果の目安

2026-07-14 時点で `just test` は 85 テスト・198 assertion がすべて成功します
(このうちログイン・認証まわりが大半で、他はプログラム保存・アップロード・CDNなど他分野のテスト)。
