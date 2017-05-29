# nako3storage

Online storage for nadesiko3

# APIの使い方

### プログラムの保存

```
<設置url>/api.php?(app_id)&save
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

戻り値として、app_idを取得できる。
プログラムを更新したい時には、前回送信したeditkeyと同じキーでデータをPOSTするとプログラムやその他の情報を更新できる。

### プログラムの読み込み

```
<設置url>/api.php?(app_id)&show
```

GETでアクセスすると、プログラムと情報を取得できる。
