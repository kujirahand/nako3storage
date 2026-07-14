# show_list カラムによる作品一覧掲載制御 (Issue #202)

## 背景

従来「作品一覧に掲載しない」は、タグ (`apps.tag`、カンマ区切り) に `w_noname` を手入力する方式で実現していた。
除外条件 `tag != "w_noname"` が一覧・ランキング・トップページ埋め込み・検索の計7箇所に散在し、複雑で分かりにくかった。

Issue #202 に従い、`apps` テーブルに `show_list` カラムを追加し、一覧掲載可否の条件を一本化する。

## 仕様

### show_list カラム

- `apps.show_list INTEGER DEFAULT 1`
- `1`: 作品一覧・ランキング・検索結果に掲載する (デフォルト)
- `0`: 掲載しない (URLを知っている人は閲覧できる。`is_private` とは別軸)

### 自動マイグレーション

`n3s_db_init()` から毎回呼ばれる `n3s_db_migrate_apps()` (`app/n3s_lib.inc.php`) が、
既存DBに `show_list` カラムが無ければ以下を実行する (冪等)。

```sql
ALTER TABLE apps ADD COLUMN show_list INTEGER DEFAULT 1;
UPDATE apps SET show_list=0 WHERE tag LIKE '%w_noname%';
```

- カラム追加時に一度だけバックフィルが走る。2回目以降は `PRAGMA table_info(apps)` の
  チェックで早期リターンするため、手動で `show_list=1` に戻した作品が巻き戻されることはない。
- 従来の除外条件は完全一致 (`tag != "w_noname"`) だったが、バックフィルは部分一致
  (`LIKE '%w_noname%'`) で行う。`w_noname,game` のような複合タグの作品も
  「非掲載にしたい」という投稿者の意図に沿って非掲載へ引き継ぐ (意図的な仕様変更)。
- 本番環境ではデプロイ後の初回アクセスで自動的にマイグレーションされる。

### 保存時の show_list 決定ロジック

`app/action/save.inc.php` の `n3s_save_decide_show_list($a, $b)` で決定する。優先順位:

1. **タグに `w_noname` が含まれる** → `0` (後方互換。チェックボックスより優先)
2. **フォームからの明示指定** (`show_list=0|1`) → その値
3. **未指定 (POSTにキーが無い) かつ更新** → 既存レコードの値を維持 (旧クライアント互換)
4. **未指定かつ新規** → `1` (掲載)

### 投稿フォームのチェックボックス

`app/template/save.html` の公開設定の直後に「作品一覧に掲載する」チェックボックスを追加。

- checkbox 未チェック時は POST にキーが現れないため、同名の hidden (`value="0"`) を
  checkbox (`value="1"`) の直前に置く (PHP は後勝ち)。これにより
  「チェック=1 / 未チェック=0 / フォームを経由しないPOST=キー無し(未指定)」の3値を区別できる。
- 新規投稿フォームでは初期チェック済み。編集時は DB の値を反映する。
- タグ欄に `w_noname` を入力して保存すると、JS がチェックを外し (見た目の同期)、
  サーバー側でも show_list=0 が強制される。

### w_noname タグの今後の役割

- **一覧掲載の制御**: show_list カラムに移行 (タグ入力は後方互換として反映)
- **widget (実行画面) のタイトル/作者名の秘匿**: 引き続き `w_noname` タグで判定
  (`app/action/widget_frame.inc.php`)。これは「一覧掲載」とは別の関心事のため変更しない。
- **退会処理** (`app/action/mypage.inc.php`): 従来どおり `tag='w_noname'` を設定しつつ
  (widget 秘匿のため)、`show_list=0` も明示的に設定する。
- **Discord Webhook 通知の抑制** (`app/n3s_lib.inc.php` の `n3s_discord_webhook()`):
  `tag == 'w_noname'` 判定から `show_list == 0` 判定へ変更。

## 変更ファイル一覧

| ファイル | 変更内容 |
|---|---|
| `app/sql/init-main.sql` | `apps` に `show_list` カラム追加 + ALTER 履歴コメント |
| `app/n3s_lib.inc.php` | `n3s_db_migrate_apps()` 新設、`n3s_updateProgram()` に show_list 反映、Webhook 抑制条件変更 |
| `app/action/save.inc.php` | `show_list` の正規化・決定ロジック (`n3s_tag_has_w_noname()` / `n3s_save_decide_show_list()`) |
| `app/action/list.inc.php` | 一覧・ランキング・トップページの除外条件を `show_list = 1` に置換 (3箇所) |
| `app/action/search.inc.php` | 検索3種の除外条件を `show_list=1` に置換 (3箇所) |
| `app/action/mypage.inc.php` | 退会処理で `show_list=0` を設定 |
| `app/template/save.html` | チェックボックス追加、タグ欄ヒント更新、JS 連動 |
| `tests/Feature/ShowListTest.php` | マイグレーション・保存・一覧/検索除外のテスト |

## 検証方法

```sh
just test          # Pest テスト
php -S localhost:8000
```

手動確認:

- 新規投稿フォームでチェックボックスが初期チェック済みであること
- チェックを外して保存 → `index.php?action=list` に出ない → 編集画面でチェックOFFが復元される
- タグに `w_noname` を入れて保存 → 一覧・ランキング・検索に出ない
- `api.php?action=list` にも show_list=0 の作品が出ない (`n3s_list_get()` 共有のため自動追従)
