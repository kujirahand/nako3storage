# なでしこ3貯蔵庫 (nako3storage) エージェント作業ガイド

このリポジトリは、[なでしこv3](https://nadesi.com) のプログラムと関連リソースを保存・共有する Web サービスです。PHP7 以上、SQLite、軽量フレームワーク `fw_simple` を前提にしています。

AI エージェントがこのリポジトリで作業する時は、まずこのファイルを読み、実装の入口、DB の分離、テンプレート、セッション・権限、検証方法を把握してください。

タスクランナーの`just`を使って、テスト実行できます。`docs/tests.md` に仕組みの詳細と既知の制約があります。

---

## 1. 作業時の基本方針

- 変更前に対象の入口ファイル、`app/action/*.inc.php`、テンプレート、SQL を直接確認すること。
- 既存のプレーン PHP 構成を尊重し、大きなフレームワークやビルド工程を新規導入しないこと。
- DB は複数 SQLite に分かれている。`apps` とプログラム本文、ユーザー情報、ログ、アプリ内ストレージを混同しないこと。
- `data/`、`images/`、`cache/`、`cache-cdn/` は実行時データやキャッシュを含む。不要な削除・整形・一括コミットをしないこと。
- `n3s_config.ini.php` は環境固有設定。秘密情報やローカル設定を一般化せず、必要最小限の変更に留めること。
- `app/fw_simple/` と `nadesiko3hub/` は Git サブモジュール。通常の機能修正では中身を直接変更しないこと。
- ユーザー投稿、アップロード、ログイン、画像配信、CDN キャッシュを触る場合は、入力検証・権限・トークン・パストラバーサル対策を必ず確認すること。

---

## 2. プロジェクト概要

なでしこ3のプログラム、ライブラリ、画像、音声、テキスト、その他リソースを保存し、Web UI・ウィジェット・一部 API から参照できるようにするサービスです。

主な特徴:

- Web UI でプログラムを投稿・編集・表示する。
- プログラム本文はメイン DB ではなく、分割された material DB に保存する。
- 画像や音声などのアップロードファイルは `images` テーブルと実ファイルで管理する。
- ユーザー DB、ログ DB、アプリ内ストレージ DB を分離する。
- 投稿実行やウィジェット表示ではサンドボックス用 URL を設定できる。
- なでしこ本体やプラグイン JS は `cdn.php` 経由で jsDelivr から取得し、`cache-cdn/` にキャッシュできる。

---

## 3. 主要な入口ファイル

- `index.php`: Web 版の入口。設定を読み、`$n3s_config['agent'] = 'web'` を設定して `app/index.inc.php` を実行する。
- `api.php`: API 版の入口。`$n3s_config['agent'] = 'api'` を設定して同じディスパッチへ入る。
- `image.php`: アップロードファイル配信用の入口。`agent=api`、`action=image` を強制し、`app/action/image.inc.php` へ流す。
- `widget.php`: `widget.php?123` のような埋め込み用ショートカット。`action=widget`、`page=<id>` を設定して `index.php` を読み込む。
- `id.php`: 作品 ID のショートカット。
- `cdn.php`: なでしこ本体・プラグイン・CSS・map などを jsDelivr から取得し、必要に応じて `cache-cdn/` に保存して返す。
- `nadesiko3hub_update.php`: `nadesiko3hub` 連携用。

設定読み込みの順序:

1. `app/n3s_config.def.php` でデフォルト設定を作る。
2. ルートの `n3s_config.ini.php` があれば上書き読み込みする。
3. 各入口ファイルが `agent` や `action` を設定する。
4. `app/index.inc.php` が `n3s_main()` を実行する。

---

## 4. ディレクトリ構成

- `app/`: アプリケーション本体。
- `app/action/`: 画面・API ごとのアクション。`n3s_web_<action>()` / `n3s_api_<action>()` を定義する。
- `app/template/`: `fw_simple` テンプレート。
- `app/resource/`: CSS と JS。`basic.css`、`nako3storage_edit.js`、`nako3storage_show.js` など。
- `app/sql/`: SQLite 初期化 SQL。
- `app/fw_simple/`: テンプレートエンジンと DB ラッパーのサブモジュール。
- `data/`: メイン DB、ユーザー DB、ログ DB、material DB などの保存先。
- `data_astorage/`: アプリ内ストレージ API 用 DB の保存先。
- `images/`: アップロードファイルの実体。100 件単位で `000/` のようなサブディレクトリに分かれる。
- `cache/`: テンプレートコンパイル結果。
- `cache-cdn/`: `cdn.php` が取得した CDN ファイルのキャッシュ。
- `nadesiko3hub/`: 公開作品をファイル出力する連携先サブモジュール。
- `scripts/`: セットアップ補助スクリプト。
- `docs/`: 追加ドキュメント置き場。

---

## 5. ルーティングとディスパッチ

`app/index.inc.php` の `n3s_main()` が全体の基本フローです。

1. `n3s_db_init()` で `main`、`log`、`users` の SQLite 接続を初期化する。
2. `n3s_parseURI()` が `$_GET` を `$n3s_config` に取り込み、`page`、`action`、`baseurl` を決める。
3. `n3s_action()` が `action` と `agent` を `/([^a-zA-Z0-9_]+)/` でサニタイズする。
4. `app/action/{$action}.inc.php` を読み込む。
5. `n3s_{$agent}_{$action}` を組み立て、存在すれば `call_user_func()` で呼ぶ。
6. 関数やファイルがなければ `n3s_error('不正なページ', ...)` を返す。

例:

- `index.php?action=show&page=123` -> `app/action/show.inc.php` -> `n3s_web_show()`
- `api.php?action=show&page=123` -> `app/action/show.inc.php` -> `n3s_api_show()`
- `image.php?f=1.png` -> `app/action/image.inc.php` -> `n3s_api_image()`

新しいアクションを追加する場合:

- `app/action/<name>.inc.php` を追加する。
- Web 用なら `n3s_web_<name>()`、API 用なら `n3s_api_<name>()` を定義する。
- テンプレートが必要なら `app/template/<name>.html` を追加する。
- 権限・CSRF・入力サイズ・公開範囲のチェックを既存アクションに合わせる。

---

## 6. データベース構成

SQLite は役割ごとに分かれています。`app/sql/*.sql` が初期化スキーマです。

### メイン DB: `data/n3s_main.sqlite`

初期化 SQL は `app/sql/init-main.sql`。

- `info`: システム設定や内部状態。
- `apps`: 投稿メタ情報。タイトル、作者、ユーザー ID、公開状態、タグ、ライセンス、閲覧数、いいね数など。
- `comments`: コメント。
- `images`: アップロードファイルのメタ情報。
- `bookmarks`: ユーザーのブックマーク。

重要: `apps` はプログラム本文を持ちません。本文は material DB の `materials.body` にあります。

### material DB: `data/sub_material_{$db_id}.sqlite3`

初期化 SQL は `app/sql/init-material.sql`。

`n3s_getMaterialDB($material_id)` が保存先を決めます。

- `db_id = floor($material_id / 100)`
- DB ファイルは `data/sub_material_{$db_id}.sqlite3`
- DB 接続名は DB ファイルの basename
- `materials.material_id` は現在 `app_id` と同じ値

`materials` テーブル:

- `material_id`
- `body`
- `type`
- `app_id`

本文取得は `n3s_getMaterialData($app_id)`、保存は `n3s_saveNewProgram()` と `n3s_updateProgram()` を確認してください。

### ユーザー DB: `data/n3s_users.sqlite`

初期化 SQL は `app/sql/init-users.sql`。

- `users`: メール、パスワードハッシュ、パスワード再設定トークン、名前、プロフィール情報、ユーザー個別 salt など。

ログインパスワードは `n3s_login_password_to_hash()` で保存されます。既存ユーザー向けに `LOGIN_HASH_SALT_DEFAULT` の後方互換処理があります。

### ログ DB: `data/n3s_log.sqlite`

初期化 SQL は `app/sql/init-log.sql`。

- `logs`: 操作ログ。
- `ip_check`: IP 関連チェック。

### アプリ内ストレージ DB: `data_astorage/`

`app/action/api.inc.php` の `n3s_api_astorage_db()` が作成します。

- ユーザー単位: `data_astorage/users/user000001.sqlite3`
- アプリ単位: `data_astorage/apps/app000001.sqlite3`
- 初期化 SQL は `app/sql/astorage_user.sql` と `app/sql/astorage_app.sql`

この API は、編集画面で発行された `api_token` とログイン済みセッションを前提にします。

---

## 7. 投稿保存・編集の重要仕様

主な実装は `app/action/save.inc.php` と `app/action/show.inc.php` です。

- `n3s_web_save()` は `mode=edit` で保存、`mode=delete` で削除、`mode=reset_bad` で通報値リセットを行う。
- `n3s_api_save()` は廃止済みで、API 経由の保存は拒否される。
- 保存本体は `n3s_action_save_data_raw()`。
- CSRF 対策は `n3s_getEditToken()` / `n3s_checkEditToken()`。
- ログイン投稿は `user_id` と `author` がログインユーザーで上書きされる。
- ログインユーザーの投稿は、本人または管理者だけが編集できる。
- 非ログイン投稿は `editkey` で編集可否を判定する。
- `app_name` は英数字・`_`・`-` のみにサニタイズされ、一意性チェックがある。
- `ng_words` は `n3s_config.ini.php` などの設定で指定し、本文・タイトル・作者・説明に含まれると保存できない。
- `prog_hash` により、同じユーザーが同一本文を重複投稿するのを防ぐ。
- `version` は最低 `3.1.19` に補正される。
- 非ログイン時は `nakotype != 'wnako'` の保存が拒否される。
- 保存後、条件を満たせば `nadesiko3hub` へ `.nako3` ファイルを出力し、Discord Webhook も実行される。

公開状態:

- `is_private = 0`: 公開。
- `is_private = 1`: 非公開。
- `is_private = 2`: 限定公開。本人、管理者、または正しい `editkey` を持つアクセスのみ閲覧可能。

`n3s_check_private()` は Web/API の表示前に公開範囲を判定します。表示・API・ウィジェット関連を変更する時は必ず確認してください。

---

## 8. アップロードと画像配信

主な実装は `app/action/upload.inc.php` と `app/action/image.inc.php` です。

アップロード:

- Web アップロードはログイン必須。
- `mode=go` でアップロード、`mode=show` で情報表示、`mode=delete` で削除、`mode=list` で一覧表示。
- CSRF 対策は `edit_token`。
- 許可拡張子は `jpg|jpeg|gif|png|svg|mml|mp3|ogg|oga|xml|txt|csv|tsv|json|mid|xlsx|sf2|sf3|py|html|md|css|js|mjs|pdf|toml|ini|yaml`。
- サイズ上限は `size_upload_max`。デフォルトは 7MB。
- 著作権設定は `SELF`、`CC0`、`MIT`、`CC-BY` のみ許可。
- `image_name` を指定する場合は英数字・`_`・`-`・`.` のみ。拡張子必須。
- 一般ユーザーは `nako_` で始まる `image_name` を使えない。管理者のみ例外。
- 同一 `app_id` と `image_name` の組み合わせは重複不可。
- `SELF` の場合は `bin2hex(random_bytes(8))` の token が生成される。

実ファイル保存:

- `n3s_getImageDir($id)` により `floor($id / 100)` 単位のディレクトリへ保存する。
- 通常ファイルは `images/000/1.png` のように保存される。
- token 付きファイルは `images/000/1-<token>.png` のように保存される。
- DB の `images.filename` は `1.png` のような公開ファイル名を保持する。

画像配信:

- `image.php?f=1.png` は `n3s_api_image()` で配信される。
- `image.php?t=<token>&f=1.png` は `SELF` ファイルなど token 付き実ファイルに必要。
- `image.php?app_id=<id>&image_name=<name>` でも検索できる。
- `mp3`、`ogg`、`oga` は HTTP Range 対応のため実ファイル URL へリダイレクトする。
- 配信時は `Access-Control-Allow-Origin: *` と `Cross-Origin-Resource-Policy: cross-origin` が付く。

注意: 現状のアップロード検証は主に拡張子ベースです。MIME 検証を追加する場合は、既存互換と保存済みファイルへの影響を確認してください。

---

## 9. API の注意点

`api.php?action=show&page=<id>` と `api.php?action=list` は公開情報の取得に使われます。

一方、`api.php?action=api&page=...` 系はアプリ内ストレージ用です。

- `n3s_api_api()` は `token` を必須にする。
- `page=is_logined` と `page=get_user` は特別扱い。
- それ以外はログイン済みユーザーであることが必要。
- `$_SESSION["api_token::<token>"]` に app_id が入っていることが必要。
- この token は `app/action/edit.inc.php` の `n3s_web_edit()` で発行される。
- `n3s_api__set_key_as_user`、`n3s_api__insert_item_as_app` など、関数名 `n3s_api__{$page}` で呼び分ける。

重要: 投稿保存 API は廃止済みです。外部からプログラムを保存する機能を復活させる場合は、セキュリティ設計から見直してください。

---

## 10. テンプレート

テンプレートエンジンは `app/fw_simple/fw_template_engine.lib.php` です。

- テンプレートソースは `app/template/`。
- コンパイル済みキャッシュは `cache/`。
- 描画は `n3s_template_fw($template_name, $params)`。
- `n3s_template_fw()` は `$n3s_config + $params` をテンプレートへ渡す。

主な記法:

- `{{$name}}`: HTML エスケープ付き変数出力。
- `{{$name.key1.key2}}`: 配列を階層参照。
- `{{$name | safe}}`: 生 HTML として出力。
- `{{$name | filter}}`: テンプレートフィルタを適用。
- `{{include filename}}`: 別テンプレートを取り込む。
- `{{if $cond}} ... {{else}} ... {{endif}}`: 条件分岐。
- `{{for $list as $key=>$val}} ... {{endfor}}`: 繰り返し。
- `{{eval code}}` / `{{e code}}`: PHP コード実行。
- `{{# comment }}`: コメント。

テンプレートを変更したのに反映されない場合は、`cache/*.html.php` の存在を疑ってください。ただし、キャッシュ削除は必要なファイルに限定し、実行時生成物をまとめて消さないでください。

---

## 11. CDN となでしこ実行環境

`cdn.php` は `https://cdn.jsdelivr.net/npm/nadesiko3@<version>/<file>` を基本に、ローカルキャッシュを返します。

- バージョンは `3.x.y` 形式のみ許可。
- `f` パラメータは `..`、`:`、`%20` を除去し、許可文字以外なら 404。
- `cache_config['cache_all']` は現在 `TRUE`。
- JS/CSS/map は内容を直接返す。
- その他ファイルは `cache-cdn/` または CDN へ 307 リダイレクトする。
- 取得内容が HTML など不正そうな場合はキャッシュを破棄または 404 にする。

`show.inc.php` はなでしこ本体とプラグイン読み込みタグを組み立てます。バージョン互換の条件分岐があるため、プラグイン読み込みを触る場合は `n3s_show_get()` 全体を確認してください。

---

## 12. セキュリティ上の注意

- `n3s_action()` はアクション名と agent をサニタイズするが、各アクション内の入力検証は別途必要。
- 投稿保存・削除・アップロード・パスワード設定などの変更系は `edit_token` または専用 token を確認すること。
- `n3s_check_private()` の公開範囲判定を迂回しないこと。
- `image.php` の `f` は `数字.拡張子` 形式に限定される。画像名ショートカットを追加・変更する時もパストラバーサルに注意すること。
- `cdn.php` の `f` パラメータ制限を緩めないこと。
- HTML をテンプレートへ渡す場合は `| safe` の使用箇所を確認し、ユーザー入力をそのまま混ぜないこと。
- `custom_head`、`memo`、`body`、`image_name`、`app_name` は XSS・パストラバーサル・公開範囲に関わりやすい。
- Discord Webhook は `n3s_discord_webhook()` で `exec('curl ... &')` を使う。URL と JSON は `escapeshellarg()` されているが、変更時はシェル引数化を崩さないこと。
- `n3s_config.ini.php` に `admin_users`、`discord_webhook_url`、メール設定などが入る可能性がある。秘密情報をログやドキュメントに出さないこと。

---

## 13. ローカル実行と検証

`/tests` 以下に [Pest](https://pestphp.com/) によるテストがあります(主にログイン・認証まわり。`docs/user_login.md` 参照)。仕組みの詳細・カバー範囲・既知の制約は `docs/tests.md` にまとめてある。変更内容に応じて、以下を組み合わせて確認してください。

テストの実行 ([just](https://github.com/casey/just) を使う場合。詳細は `docs/tests.md`):

```sh
just test
```

`just` を使わない場合:

```sh
composer install       # ルートの composer.json (テスト専用。app/composer.json とは別)
php vendor/bin/pest
```

- ルートの `composer.json` はテスト専用の依存関係 (`pestphp/pest`) を管理する。アプリ本体は引き続き `app/composer.json` を使う。
- `tests/bootstrap.php` がテストごとに使い捨ての SQLite (`users`/`log`/`main`) とセッション・`$n3s_config` を作り直すため、`data/*.sqlite` などの実データには触れない。
- `app/action/login.inc.php` の一部関数(`n3s_web_login_setpw()` など)は正常系の最後に `exit;` するため、Pest プロセスごと終了してしまう分岐がある。既存テストは exit しない分岐だけを対象にしているので、これらの関数にテストを追加する際は要注意。
- `tests/Feature/TryLoginTest.php` に、`REMOTE_ADDR` が空文字のときブルートフォース検出クエリが誤って `main` DB を参照し例外になる既知の不具合を固定化したテストがある(`docs/user_login.md` #9 参照)。修正する場合はこのテストの更新も検討すること。

PHP 構文チェック:

```sh
find . -path './app/fw_simple/.git' -prune -o -path './nadesiko3hub/.git' -prune -o -name '*.php' -print -exec php -l {} \;
```

対象ファイルだけの構文チェック:

```sh
php -l app/action/save.inc.php
php -l app/n3s_lib.inc.php
```

ローカルサーバー:

```sh
php -S localhost:8000
```

代表的な確認 URL:

- `http://localhost:8000/index.php`
- `http://localhost:8000/index.php?action=list`
- `http://localhost:8000/index.php?action=show&page=1`
- `http://localhost:8000/index.php?action=edit&page=1`
- `http://localhost:8000/api.php?action=show&page=1`
- `http://localhost:8000/image.php?f=1.png`

CDN 関連を確認する時はネットワーク取得や `cache-cdn/` への書き込みが発生します。不要なキャッシュ差分を作らないように注意してください。

セットアップ:

```sh
git submodule update --init --recursive
bash scripts/setup.sh
cd app && composer install
```

注意: `scripts/setup.sh` は `n3s_config.ini.php` を書き換えます。既存のローカル設定がある環境で安易に実行しないでください。

---

## 14. コーディング規約と既存スタイル

- PHP は既存の手続き型スタイルに合わせる。
- 共通関数は `app/n3s_lib.inc.php`、画面固有処理は `app/action/*.inc.php` に置く。
- DB 操作は `fw_simple` の `db_get1()`、`db_get()`、`db_exec()`、`db_insert()`、`database_set()`、`database_get()` を使う。
- SQL パラメータは原則プレースホルダを使う。既存コードに直埋め箇所があっても、新規コードでは増やさない。
- 表示は `n3s_template_fw()` を使い、テンプレートに寄せる。
- JSON API は `n3s_api_output()` を使う。
- エラー表示は Web なら `n3s_error()`、情報表示は `n3s_info()` を使う。
- リダイレクトは `n3s_jump()` または `header('location:...')` の既存パターンに合わせる。
- タイムゾーンは `Asia/Tokyo`。
- ファイル名、アクション名、`nakotype`、`app_name` などの許可文字は既存正規表現を確認して合わせる。

---

## 15. よく触るファイル別メモ

- `app/n3s_config.def.php`: デフォルト設定、DB パス、サイズ上限、サンドボックス、Webhook 設定。
- `app/n3s_lib.inc.php`: DB 初期化、URL、テンプレート、ログイン、トークン、素材 DB、画像パス、保存共通処理。
- `app/action/save.inc.php`: 投稿保存、削除、編集権限、NG ワード、重複投稿チェック。
- `app/action/show.inc.php`: 作品表示、API 表示、非公開・限定公開チェック、なでしこ JS 読み込み。
- `app/action/edit.inc.php`: 編集画面とアプリ内 API token 発行。
- `app/action/upload.inc.php`: ファイルアップロード、削除、一覧、著作権・ファイル名チェック。
- `app/action/image.inc.php`: アップロードファイル配信。
- `app/action/api.inc.php`: アプリ内ストレージ API。
- `app/action/login.inc.php`: ユーザー登録、ログイン、パスワード再設定。
- `app/action/list.inc.php`: 一覧、ランキング、ユーザー別一覧。
- `app/sql/*.sql`: DB スキーマ。
- `app/template/*.html`: 表示テンプレート。
- `app/resource/basic.css`: 全体 CSS。
- `app/resource/nako3storage_edit.js`: 編集画面用 JS。
- `app/resource/nako3storage_show.js`: 表示画面用 JS。
- `cdn.php`: なでしこ CDN キャッシュ。

---

## 16. 変更時の確認チェックリスト

投稿保存を触った場合:

- 新規投稿と更新の両方を確認する。
- ログイン投稿、非ログイン投稿、管理者例外、`editkey` を確認する。
- `materials.body` に保存されることを確認する。
- `apps.prog_hash`、`mtime`、`nadesiko3hub`、Webhook への影響を確認する。

表示を触った場合:

- `show`、`edit`、`widget`、`api show` の影響を確認する。
- 非公開・限定公開・`editkey` の挙動を確認する。
- なでしこバージョン別のプラグイン読み込み条件を壊していないか確認する。

アップロードを触った場合:

- ログイン必須、CSRF、著作権設定、拡張子、サイズ上限、`image_name` 重複、`SELF` token を確認する。
- DB だけ成功して実ファイル保存に失敗するケースで rollback されることを維持する。
- 削除時に DB と実ファイルの両方が処理されることを確認する。

API を触った場合:

- 公開 API とログイン済みアプリ内ストレージ API を混同しない。
- `token` と `$_SESSION["api_token::<token>"]` の関係を確認する。
- JSON は `n3s_api_output()` で返す。

DB スキーマを触った場合:

- `app/sql/*.sql` と既存 DB のマイグレーション方針を分けて考える。
- コメントに残っている過去 ALTER TABLE 履歴を確認する。
- 既存 `data/*.sqlite` を不用意にコミット対象へ含めない。

---

## 17. この AGENTS.md の更新方針

このファイルはエージェント向けの作業契約です。実装と異なる内容を見つけたら、該当コードを確認したうえで更新してください。特に DB 分割、公開範囲、保存 API 廃止、アップロード token、CDN キャッシュの仕様は作業ミスにつながりやすいため、変更時に必ず反映してください。
