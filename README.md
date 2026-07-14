# なでしこ3貯蔵庫 (nako3storage)

## これは何？

- [プログラミング言語「なでしこv3」](https://nadesi.com)のプログラムとリソースを保存するためのストレージ。
- なでしこ3のプログラムやリソースの保存・読込を手軽に行うためのWebサービス。
- なでしこのサイトで運用しているものの、自分のサイトで運用したい場合も手軽に利用できる。

## 現在運用中の『なでしこ3貯蔵庫(nako3storage)』

- [基本URL] https://n3s.nadesi.com/

## 簡単なインストール方法

- [Release](https://github.com/kujirahand/nako3storage/releases)からZIPファイルをダウンロードして解凍。
- アーカイブをPHP7以上が動くWebサーバーへアップ。
- /appフォルダ以下に、`fw_simple`という名前で[php_fw_simple](https://github.com/kujirahand/php_fw_simple)のアーカイブをコピー。
- dataフォルダを書き込み可能にする。
- index.phpにアクセス。

## 詳細なインストール方法

- SSHでWebサーバーにログインする。
- `git clone --recursive https://github.com/kujirahand/nako3storage`コマンドを実行し、Gitのリポジトリをcloneする
- `cd nako3storage`コマンドを実行し、cloneしたディレクトリに移動する
- `mkdir cache`コマンドを実行し、キャッシュ用のディレクトリを作成する
- `bash scripts/setup.sh`コマンドを実行する
- 必要ライブラリのインストール `cd app` そして `composer install`
- `./n3s_config.ini.php` に以下のように情報を指定

```php
<?php
global $n3s_config;
// 管理ユーザーのIDを配列で指定　(以下は1と3のユーザーを管理者にする例)
$n3s_config['admin_users'] = [1, 3];
```

### git cloneで--recursiveを忘れた時

以下を実行してください。既存のリポジトリでエラーが出る場合も以下を実行してください。

```sh
git submodule update --init --recursive
```

### 追加で必要な設定

なでしこ貯蔵庫と同じように運用するためには、WebサーバーのApacheにて下記の指定が必要です。`.htaccess`に下記の設定を記述してください。

```xml
<IfModule mod_rewrite.c>
RewriteEngine On
# plain
RewriteRule ^plain/([0-9a-zA-Z_\-]+).nako3$ /index.php?page=$1&action=plain&type=nako3 [L]
RewriteRule ^plain/([0-9a-zA-Z_\-]+).js$ /index.php?page=$1&action=plain&type=js [L]
RewriteRule ^plain/([0-9a-zA-Z_\-]+).(sh|csv|txt|json|bat)$ /index.php?page=$1&action=plain&type=$2 [L]
# version
RewriteRule ^nako_version.json$ /nako_version.php [L]
# new / list / edit / show
RewriteRule ^new$ /index.php?action=edit&page=new [L,R]
RewriteRule ^list$ /index.php?page=all&action=list [L]
RewriteRule ^edit/([0-9]+)$ /index.php?page=$1&action=edit [L,R]
RewriteRule ^show/([0-9a-zA-Z_\-]+)$ /index.php?page=$1&action=show [L,R]
# sourcemap
RewriteRule ^([0-9a-z_\-]+)\.js\.map$ /cdn.php?f=release/$1.js.map
</IfModule>
```

## Googleアカウントでのログインを有効にする場合

メールアドレス/パスワードによるログインに加えて、Googleアカウントでのログインを有効にできます(設定しない場合、ログイン画面に「Googleでログイン」ボタンは表示されず、従来通りメールアドレス/パスワードのみでログインします)。設計の詳細は [docs/user_login_oauth_google.md](docs/user_login_oauth_google.md) を参照してください。

1. [Google Cloud Console](https://console.cloud.google.com/) で OAuth 2.0 クライアントID(種類は「ウェブ アプリケーション」)を作成する。
2. 「承認済みのリダイレクト URI」に、下記で設定する `google_oauth_redirect_uri` と**完全に一致する**URLを登録する(例: `https://n3s.example.com/index.php?action=login&page=google_callback`)。
3. `n3s_config.ini.php` に、発行されたクライアントIDとクライアントシークレット、上記のリダイレクトURIを追加する。

```php
// Google OAuth 2.0 の設定
$n3s_config['google_oauth_client_id'] = '(発行されたクライアントID)';
$n3s_config['google_oauth_client_secret'] = '(発行されたクライアントシークレット)';
$n3s_config['google_oauth_redirect_uri'] = 'https://n3s.example.com/index.php?action=login&page=google_callback';
```

`n3s_config.ini.php` はサイト固有の設定ファイルであり(`.gitignore`対象)、リポジトリにはコミットされません。クライアントシークレットも含むため、第三者に共有しないよう管理してください。

## コメント自動審査（Gemini API）を設定する場合

作品へのコメント投稿時、いたずらやスパム、誹謗中傷などを防ぐために Gemini API を利用した自動審査バッチを設定できます。
（設定しない場合、AI審査はパスされ、投稿されたコメントは自動で即時公開されます）

1. [Google AI Studio](https://aistudio.google.com/)等で Gemini API キーを取得する。
2. `n3s_config.ini.php` に、取得した API キーを追加する。

```php
// Gemini API の設定
$n3s_config['gemini_api_key'] = '(取得したGemini APIキー)';

// (オプション) AI審査を行わずすべて無条件で自動承認(公開)にする場合は true
$n3s_config['comment_audit_auto_approve'] = false;
```

3. コメント審査バッチを実行するために、cron 等で定期的に（例: 1時間に1回）以下のコマンドを実行するように設定します。

```sh
php scripts/comment_audit.php
```

または、`just` コマンドが使える環境であれば以下を実行することも可能です。

```sh
just comment-audit
```

## 安全に運用するためのTips

運用したいURL(n3s.example.com)に加えて、サンドボックスとして運用するURL(n3s-sandbox.example.com)を用意します。そして、その2つのURLには全く同じコンテンツが表示されるように設定してください。その上で、以下の設定を記述します。

```php
// sandbox (末尾にスラッシュを追加)
$n3s_config['sandbox_url'] = 'https://n3s-sandbox.example.com/';
```

### ウィジェット機能

次のように記述することでガジェットをブログやHTMLに貼り付けできます。

```html
<iframe width="232" height="320"
  src="https://n3s.nadesi.com/widget.php?1">
</iframe>
```

srcにオプションrun=1やmute_name=1を追加すると実行ボタンを押すことなくスクリプトが実行されます。

```html
 <iframe width="232" height="320"
  src="https://n3s.nadesi.com/widget.php?1&run=1&mute_name=1">
</iframe>
```

## 外部のプログラムから保存したい場合

Webフォームから、以下のURLに body=xxx&version=(なでしこバージョン) をポストします。

```text
<設置url>/index.php?page=0&action=presave
```

- POSTする値
  - body --- プログラム本体
  - version --- 利用なでしこのバージョン

### プログラムの読み込み

```text
<設置url>/api.php?action=show&page=(app_id)
```

GETでアクセスすると、プログラムと情報を取得できます。

## 仕様

- `id.php?(id)` にアクセスすると `index.php?action=show&app_id=(id)` にリダイレクトします。

### NGワードの追加方法

設定ファイル`n3s_config.ini.php`に、下記の項目を追加します。

```php
$n3s_config['ng_words'] = ["aaaa","bbbbb","cccc",...]
```
