# なでしこ3貯蔵庫 (nako3storage)

## これは何？

 - [プログラミング言語「なでしこv3」](https://nadesi.com)のプログラムとリソースを保存するためのストレージ。
 - なでしこ3のプログラムやリソースの保存・読込を手軽に行うためのWebサービス。
 - なでしこのサイトで運用しているものの、自分のサイトで運用したい場合も手軽に利用できる。

# 現在運用中の『なでしこ3貯蔵庫(nako3storage)』

 - [基本URL] https://n3s.nadesi.com/

## 簡単なインストール方法

 - [Release](https://github.com/kujirahand/nako3storage/releases)からZIPファイルをダウンロードして解凍。
 - アーカイブをPHP7以上が動くWebサーバーへアップ。
 - dataフォルダを書き込み可能にする。
 - index.phpにアクセス。

## 詳細なインストール方法

 - SSHでWebサーバーにログインする。
 - `git clone https://github.com/kujirahand/nako3storage`コマンドを実行し、Gitのリポジトリをcloneする
 - `cd nako3storage`コマンドを実行し、cloneしたディレクトリに移動する
 - `bash scripts/setup.sh`コマンドを実行する
 - 必要ライブラリ(Twitterの認証)のインストール `cd app` そして `composer install`
 - `./n3s_config.ini.php` に以下のようなTwitterの認証情報を指定

```
<?php
global $n3s_config;
// Twitterの認証情報を記述
$n3s_config['twitter_api_key'] = 'xxx';
$n3s_config['twitter_api_secret'] = 'xxx';
$n3s_config['twitter_acc_token'] = 'xxx-xxx';
$n3s_config['twitter_acc_secret'] = 'xxxxxx';
// 管理ユーザーのIDを配列で指定
$n3s_config['admin_users'] = [PHP_INT_MAX];
```

まず、Twitterの開発者サイトでアプリ登録してください。
Twitterのアプリ側でCallback先のURLを指定します。

```
(設置したURL)/callback.php
```

## 安全に運用するためのTips

運用したいURL(n3s.example.com)に加えて、サンドボックスとして運用するURL(n3s-sandbox.example.com)を用意します。
そして、その2つのURLには全く同じコンテンツが表示されるように設定してください。
その上で、以下の設定を記述します。

```
// sandbox (末尾にスラッシュを追加)
$n3s_config['sandbox_url'] = 'https://n3s-sandbox.example.com/';
```


## ガジェット

以下のように記述することでガジェットをブログやHTMLに貼り付けできる。

 ```
 <iframe width="232" height="320"
  src="http://localhost/repos/nako3storage/widget.php?1">
</iframe>
 ```

srcにオプションrun=1やmute_name=1を追加すると実行ボタンを押すことなくスクリプトが実行される。

 ```
 <iframe width="232" height="320"
  src="http://localhost/repos/nako3storage/widget.php?1&run=1&mute_name=1">
</iframe>
 ```


# 外部のプログラムから保存したい場合

Webフォームから、以下のURLに body=xxx&version=(なでしこバージョン) をポストすれば良い。

```
<設置url>/index.php?page=0&action=presave
```
 - POSTする値
   - body --- プログラム本体
   - version --- 利用なでしこのバージョン


### プログラムの読み込み

```
<設置url>/api.php?action=show&page=(app_id)
```

GETでアクセスすると、プログラムと情報を取得できる。

## 仕様

- `id.php?(id)` にアクセスすると `index.php?action=show&app_id=(id)` にリダイレクトする



