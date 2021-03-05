# nako3storage

## これは何？

 - [プログラミング言語「なでしこv3」](https://nadesi.com)のプログラムとリソースを保存するためのストレージ。
 - なでしこ3のプログラムやリソースの保存・読込を手軽に行うためのWebサービス。
 - なでしこのサイトで運用しているものの、自分のサイトで運用したい場合も手軽に利用できる。

# 現在運用中のnako3storage

 - [基本URL] https://nadesi.com/v3/storage/

## インストール

 - `git clone https://github.com/kujirahand/nako3storage`コマンドを実行し、Gitのリポジトリをcloneする
 - `cd nako3storage`コマンドを実行し、cloneしたディレクトリに移動する
 - `bash scripts/setup.sh`コマンドを実行する
 - 必要ライブラリ(Twitterの認証)のインストール `cd app` そして `composer install`

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

# APIの使い方

### プログラムの保存

```
<設置url>/api.php?page=(app_id)&action=save
```

POST メソッドで以下のデータを送信すると、プログラムを保存できる。

- app_id --- 新規は0を指定。
- body --- プログラム本体
- title --- プログラムのタイトル
- author --- 制作者名
- email --- 連絡先
- memo --- プログラムの説明
- version --- 利用しているなでしこのバージョン
- is_private --- 通常は0を。プログラムを非公開にしたいときは1を指定。
- need_key --- 0:公開 1:access_keyを指定する (まだ未実装)
- access_key --- need_keyを1にしたい際に必要 (まだ未実装

戻り値として、app_idを取得できる。
プログラムを更新したい時には、前回送信したeditkeyと同じキーでデータをPOSTするとプログラムやその他の情報を更新できる。

### プログラムの読み込み

```
<設置url>/api.php?action=show&page=(app_id)
```

GETでアクセスすると、プログラムと情報を取得できる。

## 仕様

- `id.php?(id)` にアクセスすると `index.php?action=show&app_id=(id)` にリダイレクトする

## Twitterログイン

Twitterでアプリ登録してください。
設定にキーを指定します。

```
$n3s_config['twitter_api_key'] = 'xxxx';
$n3s_config['twitter_api_secret'] = 'xxxx';
$n3s_config['twitter_acc_token'] = 'xxx-xxxx';
$n3s_config['twitter_acc_secret'] = 'xxxx';
```

Twitterのアプリ側でCallback先のURLを指定します。

```
(設置したURL)/callback.php
```


