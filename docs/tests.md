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

> **注意: `$n3s_config['page']` を書き換えるテストは要注意**
>
> 本番では `n3s_parseURI()` がリクエストごとに `$_GET` の内容を `$n3s_config` へコピーするが、
> テストはこれを呼ばない。そのため `n3s_web_login()` のページ振り分け (`n3s_get_config('page', '')`)
> や `n3s_action_save_data_raw()` の対象 `app_id` (`n3s_get_config('page', 0)`) をテストする際は、
> `$_GET['page']` ではなく **`global $n3s_config; $n3s_config['page'] = '...';` を直接書き換える**
> 必要がある(`tests/Feature/LoginDispatcherTest.php` 参照)。
>
> この `$n3s_config['page']` は `beforeEach` で `unset()` されるとはいえ、**同じテスト関数の中で
> 書き換えたままにすると、そのテストの実行中は他の関数にも影響する**。実際、ログインのページ振り分け
> テストで `$n3s_config['page'] = 'forgot'` をセットしたまま放置していたところ、後続テストが
> Pest の同一プロセス内で `n3s_get_config('page', 0)` を新規投稿の `app_id` 解決に使っており、
> `'forgot'` という文字列を `app_id` と誤認して投稿保存が失敗する、というテスト間汚染が実際に発生した
> (`n3s_test_setup()` 側で `page`/`mode` をリセットする対応で解消済み)。`$n3s_config` の任意のキーを
> 書き換えるテストを追加するときは、他のテストへ波及しないか常に注意すること。

他に用意しているヘルパー:

- `n3s_test_add_legacy_user($email, $password, $name)` — `salt` 列が空の後方互換ユーザー
  (`def::` プレフィックス)を直接DBへ作る。`n3s_login()` の後方互換パスをテストするために使う。
- `n3s_test_capture(callable $fn): string` — `echo`/テンプレート描画を伴う関数呼び出しを
  出力バッファに包み、出力文字列を返す。戻り値は捨てる。
- `n3s_test_call(callable $fn)` — 出力は捨てて、関数の**戻り値**をそのまま返す。
  出力と戻り値の両方が要る場合は、必要な方に応じてどちらかを使い分ける
  (同時に取りたい場合は `ob_start()`/`ob_get_clean()` をテスト側で直接書く)。

---

## 4. カバーしている範囲

- パスワードハッシュ (`n3s_login_password_to_hash`)・ソルト生成 (`n3s_generate_salt`)
- ユーザー作成・メール検索 (`n3s_add_user` / `n3s_get_user_id_by_email` / UNIQUE制約)
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

`app/action/login.inc.php` の一部の関数は、処理の最後に `exit;` を呼びます
(例: `n3s_web_login_setpw()` の成功パス、`n3s_web_login_trylogin()` の5回失敗ブロック、
IPブロック時など)。PHP の `exit()` は Pest のテストプロセスごと即座に終了させてしまうため、
これらの分岐を直接呼ぶテストは書けません(書くとテストランナーごと落ちて何も報告されずに終わります)。

そのため、既存のテストは **exit しないことが確認できている分岐だけ** を対象にしています。
新しくテストを追加する場合は、対象の関数を読んで `exit` に到達しない入力を選ぶよう注意してください。
特に以下は自動テストの対象外です(手動確認 (`php -S localhost:8000`) で担保する):

- 新規登録・パスワード再設定申請の**成功パス**(`n3s_web_login_setpw()` へ遷移し、そこで `exit` する)
- ログイン5回連続失敗時のブロック
- パスワード未設定ユーザーのログイン試行時の案内画面

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
