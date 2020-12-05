# nako3storage

## これは何？

- [プログラミング言語「なでしこv3」](https://nadesi.com)のプログラムとリソースを保存するためのストレージ。
- Webインターフェイスもあるが、APIからも自由に操作できるもの。
- なでしこ3のプログラムやリソースの保存・読込を手軽に行うためのWebサービス

# 現在運用中のnako3storage

 - [URL] https://nadesi.com/v3/storage/

## インストール

1. `git clone https://github.com/kujirahand/nako3storage`コマンドを実行し、Gitのリポジトリをcloneする
1. `cd nako3storage`コマンドを実行し、cloneしたディレクトリに移動する
1. `bash scripts/setup.sh`コマンドを実行する

# 外部のプログラムとの連携

formを使う場合、以下のURLに body=xxx&version=0.0.6 をポストすれば良い。

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
- editkey --- 編集する時に必要なキー
- is_private --- 通常は0を。プログラムを非公開にしたいときは1を指定。
- need_key --- 0:公開 1:access_keyを指定する (まだ未実装)
- access_key --- need_keyを1にしたい際に必要 (まだ未実装

戻り値として、app_idを取得できる。
プログラムを更新したい時には、前回送信したeditkeyと同じキーでデータをPOSTするとプログラムやその他の情報を更新できる。

### プログラムの読み込み

```
<設置url>/api.php?(app_id)&show
```

GETでアクセスすると、プログラムと情報を取得できる。

## 仕様

- `p.php?(id)` にアクセスすると `show.php?app_id=(id)` にリダイレクトする



