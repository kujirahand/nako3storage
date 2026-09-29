# 貯蔵庫API (アプリ内ストレージ) の仕様と制限

Issue: [#194](https://github.com/kujirahand/nako3storage/issues/194)

貯蔵庫APIは、投稿された作品(なでしこプログラム)がサーバー側にデータを保存するためのAPIです。
ユーザーごとの保存領域 `user_db` と、作品ごとの共有領域 `app_db` があります。

このAPIは **「完全には偽装を防げない、不完全なモデル」** です。
仕組みと限界を理解したうえで、重要なデータ(個人情報・パスワード・お金に関わる情報など)は保存しないでください。

---

## 1. なぜこの方式なのか

投稿された作品は、すべてサンドボックスのオリジン(例: `https://n3s-sandbox.nadesi.com`)で実行されます。

- 全作品が **同じオリジン** を共有するため、ある作品は同一オリジンの別の作品のページ・DOM・`localStorage` などに触れられます。
- サンドボックスと本体(`n3s.nadesi.com`)は **同じサイト**(`nadesi.com`)です。

以前は、サンドボックスでログインさせ、そのセッションで API を使う設計でした。
この設計には次の問題がありました。

- 任意の作品が同一オリジンの `fetch()` で編集画面を読み、`edit_token` を取得して、ログイン中のユーザーとして作品の保存・削除・プロフィール変更などを行えた(アカウント乗っ取り)。
- 作品が同一オリジンのログインページを iframe に開き、パスワード入力を盗み見ることができた。
- `api_token` は `edit`/`widget` を開くだけで任意の `app_id` について発行できたため、他作品のデータを読み書きできた。

作品ごとにオリジンを分ける(サブドメインを割り当てる)のは運用上難しいため、
**サンドボックスではログインさせず、本体オリジンの実行画面が発行する署名付きトークンで `app_id` と `user_id` を渡す** 方式にしました。

---

## 2. 全体の流れ

```
[本体オリジン] 作品ページ (show)
   └─「プログラムを実行」→ widget.php?<app_id>&run=1&allow=1&ui=1   … 親ページ (本体オリジン)
         ├─ サーバーが token = sign({app_id, user_id, 期限}) を発行
         └─ <iframe src="(sandbox)/index.php?action=widget_frame&page=<app_id>&api_token=<token>">
               [サンドボックスオリジン] 作品が実行される
                  └─ api.php?action=api&page=<メソッド>&token=<token>   (同一オリジン・Cookie不要)
                        └─ サーバーは署名と期限を検証し、token 内の app_id / user_id だけを使う
```

- 親ページ(`widget`)は本体オリジンで開くため、本体のログイン状態から `user_id` を取れます。
- 作品は従来どおりサンドボックスの iframe で実行されます。
- API はセッションを一切見ません。サンドボックス側の `api.php` でも本体側の `api.php` でも、同じトークンで動きます。

---

## 3. トークン

### 形式

```
base64url(JSON {"a": app_id, "u": user_id, "e": 有効期限(UNIX時刻)}) + "." + base64url(HMAC-SHA256)
```

- 署名は `hash_hmac('sha256', "astorage:" . ペイロード, 署名鍵)`。
- 実装: `n3s_astorage_token_create()` / `n3s_astorage_token_verify()` (`app/n3s_lib.inc.php`)。
- サーバーにトークンを保存しない(ステートレス)。失効させたい場合は署名鍵を変更する(全トークンが無効になる)。

### 設定 (`n3s_config.ini.php`)

| キー | 既定値 | 説明 |
|---|---|---|
| `astorage_token_secret` | `""` | 署名鍵。16文字以上を指定する。空ならメインDBの `info` テーブル(`key='astorage_token_secret'`)に乱数で自動生成して保存する |
| `astorage_token_ttl` | `21600` (6時間) | トークンの有効期間(秒)。期限が切れたら作品を再実行する必要がある |

### 発行する場所

| 画面 | オリジン | トークンの `user_id` |
|---|---|---|
| `widget.php?<id>&ui=1`(作品ページの「プログラムを実行」) | 本体 | ログイン中なら自分の `user_id`、未ログインなら 0 |
| `widget.php?<id>`(`ui` なし・ブログ等への iframe 埋め込み用) | 本体 | 常に 0(ゲスト) |
| `index.php?action=edit&page=<id>`(編集画面) | 本体 | ログイン中なら自分の `user_id` |
| 上記をサンドボックスのホストで開いた場合 | サンドボックス | 常に 0(サンドボックスではログインできないため) |

- 未保存の作品(`app_id=0`)にはトークンを発行しません。作品を一度保存してから使ってください。
- `ui=1` の実行画面には `Content-Security-Policy: frame-ancestors 'none'` と `Cross-Origin-Opener-Policy: same-origin` を付けます。他の作品がこのページを iframe に埋め込んだり `window.open` で開いたりして、中の作品が受け取ったトークンを読むことを防ぐためです。
- `ui` なしの埋め込み用 widget は、他サイトやサンドボックス上の作品からも埋め込めます。そのためゲストのトークンしか渡しません。
- トークンは iframe の URL に含まれるため、`widget_frame` は `Referrer-Policy: strict-origin` を返し、作品が読み込む外部リソースへフルURLが漏れないようにしています。

---

## 4. 権限

`ctx.user_id` はトークン内の `user_id`、「作者」は `apps.user_id`(非ログイン投稿の作品には作者がいない)、「管理者」は `admin_users` です。

| API | ゲスト (`user_id=0`) | ログインユーザー |
|---|---|---|
| `is_logined` / `get_user` | ○ | ○ |
| `*_as_user`(全部) | × | ○(自分 × この作品の領域のみ) |
| `list_key_as_app` / `get_key_as_app` / `select_items_as_app` | ○ | ○ |
| `set_key_as_app` | × | 新規キーは○。既存キーは、作成者・作者・管理者のみ |
| `delete_key_as_app` | × | 作成者・作者・管理者のみ |
| `deleteall_key_as_app` | × | 作者・管理者のみ |
| `insert_item_as_app` | × | ○(書き込んだ `user_id` を記録) |
| `update_item_as_app` / `delete_item_as_app` | × | 書き込んだ本人・作者・管理者のみ |

- `app_db` の `items` / `keys` には `user_id`(書き込んだユーザー)列があります。
  以前から存在する app DB には、初回アクセス時に `n3s_astorage_migrate_app_db()` が列を追加します。移行前の行は `user_id=0` となり、作者・管理者だけが変更・削除できます。
- 作者が他人のキーを上書きしても、そのキーの作成者(`user_id`)は変わりません。
- 共有のハイスコアのように「誰でも上書きできる1つの値」はキーでは作れません。各ユーザーがアイテムを追加し、読み出す側で集計してください。

---

## 5. API一覧

リクエスト: `api.php?action=api&page=<メソッド名>&token=<トークン>&...`(GET/POST どちらでも可)。
レスポンスは JSON で、必ず `result`(true/false)を含みます。失敗時は `reason` にメッセージが入ります。

呼び出せるメソッドは `n3s_astorage_api_pages()` の一覧に限ります。

### 共通

| メソッド | パラメータ | 戻り値 |
|---|---|---|
| `is_logined` | なし | `logined`: トークンの `user_id>0` か |
| `get_user` | `user_id`(省略/0ならトークンのユーザー) | `user_id`, `name` |

### user 領域(ログインユーザー × app_id)

| メソッド | パラメータ | 戻り値 |
|---|---|---|
| `list_key_as_user` | なし | `keys` |
| `get_key_as_user` | `key` | `value`(無ければ null), `mtime` |
| `set_key_as_user` | `key`, `value` | |
| `delete_key_as_user` | `key` | |
| `deleteall_key_as_user` | なし | |
| `insert_item_as_user` | `key`, `value` | `item_id` |
| `select_items_as_user` | `key`, `offset`, `limit`(最大30), `sort`(ASC/DESC) | `values`: `[{item_id, value, mtime}]` |
| `delete_item_as_user` | `key`, `item_id` | |
| `update_item_as_user` | `key`, `item_id`, `value` | |

### app 領域(作品ごとの共有)

| メソッド | パラメータ | 戻り値 |
|---|---|---|
| `list_key_as_app` | なし | `keys` |
| `get_key_as_app` | `key` | `value`(無ければ null), `mtime` |
| `set_key_as_app` | `key`, `value` | |
| `delete_key_as_app` | `key` | |
| `deleteall_key_as_app` | なし | |
| `insert_item_as_app` | `key`, `value` | `item_id` |
| `select_items_as_app` | `key`, `offset`, `limit`(最大30), `sort`(ASC/DESC) | `values`: `[{item_id, value, mtime, user_id}]` |
| `delete_item_as_app` | `key`, `item_id` | |
| `update_item_as_app` | `key`, `item_id`, `value` | |

### サイズ上限

| 設定 | 既定値 |
|---|---|
| `size_astorage_key_max` | 256 バイト |
| `size_astorage_value_max` | 64KB |

---

## 6. ログインできるホストの制限

サンドボックスでログインできると、1章の問題がすべて起きます。そのため、ログインを許可するホストを制限しています。

- 実装: `n3s_login_allowed_host()` (`app/n3s_lib.inc.php`)
- `sandbox_url` のホストは **常に不許可**(`login_allowed_hosts` に書いても不許可)。
- `login_allowed_hosts` を設定した場合は、そのホストだけを許可する(`host` または `host:port`。localhost も明示が必要)。
- 未設定なら、`app_root_url` のホストと localhost(`localhost` / `127.0.0.1` / `::1`)を許可する。
- 許可されていないホストでは、次のように扱います。
  - `index.php?action=login`(登録・パスワード再設定・Googleログインを含む)はログインフォームを出さず、本体のログインページへのリンクだけを表示する。
  - `n3s_is_login()` は常に false を返す。過去にサンドボックスでログインして残っていたセッションも未ログイン扱いになる。

```php
// n3s_config.ini.php の例
$n3s_config['app_root_url'] = 'https://n3s.nadesi.com/';
$n3s_config['sandbox_url'] = 'https://n3s-sandbox.nadesi.com/';
// 必要なら明示する (未設定なら app_root_url のホストのみ)
$n3s_config['login_allowed_hosts'] = ['n3s.nadesi.com'];
```

---

## 7. 制限事項(不完全なモデルであること)

次の攻撃は防げません。「`user_id>0` ならそのユーザーがログインして実行している」と **概ね** 信じてよい、という程度の保証です。

1. **同一オリジンの他作品によるトークンの盗み見**
   作品が受け取ったトークンは、作品の JavaScript のメモリや iframe の URL にあります。サンドボックス上の別の作品が、何らかの方法でその iframe への参照を得ると、トークンを読めます。対策として以下を行っていますが、完全ではありません。
   - `user_id` 付きのトークンは `ui=1` の実行画面でだけ発行し、この画面は iframe 埋め込み・`window.open` からの参照を拒否する。
   - 有効期限を短くする(既定6時間)。
   - 盗まれた場合の被害は「そのユーザーの、その作品用のデータ」と「その作品の app 領域への、そのユーザー名義での書き込み」に限られます。アカウント自体は奪われません。
2. **作品自身が別の作品へ移動した場合**
   実行中の作品が、リンクなどで同じ iframe 内の別作品へ移動すると、移動先の作品が URL 内のトークンを読めます。
3. **作品の作者**
   ユーザーがその作品に入力したデータを、作品の作者が外部へ送信することは防げません。信頼できない作品に大切なデータを入力しないでください。
4. **`localStorage` 等の共有**
   サンドボックスの `localStorage` / `sessionStorage` / IndexedDB / Cookie は全作品で共有されます。トークンや個人データを保存しないでください。
5. **app 領域の荒らし・容量**
   ログインユーザーなら誰でも app 領域にアイテムを追加できます。1件あたりのサイズ上限はありますが、件数・合計容量・頻度の上限はまだありません。
6. **編集画面**
   編集画面(`edit`)は本体オリジンでプログラムを実行します。このため、実行したコードは本体のログインセッションの権限で動きます。これは貯蔵庫APIとは別の既存の問題です。他人の作品を編集画面で実行しないでください。

### 今後の課題

- 同一サイトであることに起因する既存リスクへの対策(別PRで対応予定):
  - Service Worker 登録の拒否(`nakotype=js` の作品がサンドボックス全体のリクエストを横取りできる可能性がある)。
  - 本体セッション Cookie の `__Host-` 化(サンドボックスから `Domain=nadesi.com` の Cookie を書き込まれる cookie tossing 対策)。
- app 領域の件数・容量・頻度の上限(クォータ)。
- 作品ごとのオリジン分離(実現できれば、トークンの盗み見の問題を根本的に解決できる)。

---

## 8. なでしこ用ライブラリ (`api.nako3`) の変更点

ライブラリ本体(`https://n3s.nadesi.com/plain/api.nako3`)は貯蔵庫に投稿された作品なので、このリポジトリの外で更新します。
サンドボックスのログインページへ移動していた「ログイン確認」は、ログインページがもう表示されないため、本体の作品ページへ案内するように変更してください。

```
●ログイン確認とは
　　「api.php?action=api&page=is_logined&token={TOKEN}」からAJAX_JSON取得してLに代入。
　　もし、L["result"]がいいえならば
　　　　「<h3>この作品の保存機能を使うにはログインが必要です</h3><p>なでしこ3貯蔵庫にログインしてから、作品ページの「プログラムを実行」で実行してください。</p>」のラベル作成。
　　　　B=「作品ページを開く」のボタン作成
　　　　Bをクリックした時には
　　　　　　「https://n3s.nadesi.com/id.php?{APP_ID}」にブラウザ移動。
　　　　ここまで。
　　　　それはいいえ。
　　違えば
　　　　それははい。
　　ここまで。
ここまで。
```

- `TOKEN` が空の場合(未保存の作品)や期限切れの場合は、`result=false` と `reason` が返ります。
- 読み取り系の `APP` 命令は、ログインしていなくても使えます。
