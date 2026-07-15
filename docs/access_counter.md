# アクセスカウンターの仕組み (素材 / 作品)

なでしこ3貯蔵庫には、アクセス数を数える仕組みが2系統ある。

- **素材のアクセスカウント** (Issue #246): `image.php` 経由のダウンロード/表示回数。
- **作品のアクセスカウント** (Issue #217 → 本ドキュメント): `show` / `widget` / `api` 経由の作品閲覧数。

どちらも同じ設計方針を採る。

> リクエスト毎にDBを直接集計・更新するのではなく、生ログをINSERTするだけに留め、
> 1時間に1回程度のバッチで重複除去・集計・生ログ削除を行う。

これはアクセスが多い本番環境で、リクエスト毎の `UPDATE ... SET count = count + 1` や
アップサートがロック・パフォーマンスのボトルネックになるのを避けるため。書き込みは
`INSERT` 1本だけなので、アクセス集中時も安全に追記できる。

---

## 1. 素材のアクセスカウント (Issue #246)

### 記録

- `n3s_api_image()` (`app/action/image.inc.php`) がファイル配信の直前に
  `n3s_record_image_access($image_id)` (`app/n3s_lib.inc.php`) を呼ぶ。
- ログDB (`data/n3s_log.sqlite`) の `image_access_log` テーブルへ
  `(image_id, ip, ctime)` を1行追加する。重複除去はしない。

### 集計

- `scripts/image_count.php` (`just image-count`) を 1 時間に 1 回程度 cron で実行する。
- 未処理の `image_access_log` を `(image_id, ip)` の組で重複除去し、
  `images.view` (main DB) へユニークIP数を加算する。
- 集計済みのログ行は削除する。

### 表示

- 管理者向け統計ページ (`index.php?action=stats`、`n3s_web_stats()`) の
  「閲覧数 上位素材」に `images.view` 降順のランキングを表示する
  (`n3s_stats_top_images()`、`app/action/stats.inc.php`)。

---

## 2. 作品のアクセスカウント (Issue #217)

### 経緯

初期実装 (Issue #217) は `n3s_record_access()` がリクエスト毎に
`access_stats` (日別集計テーブル) へ直接アップサートする方式だった。
その後、素材 (#246) と同じ「生ログ + 定期集計」方式へ揃えるため、
下記の内容に置き換えた。あわせて月別集計 (`access_stats_monthly`) と、
作品情報画面へのトータル/月間アクセス数表示を追加した。

### 記録

以下の3箇所が `n3s_record_access($kind, $app_id)` (`app/n3s_lib.inc.php`) を呼ぶ。

| kind     | 呼び出し元 | 説明 |
|----------|-----------|------|
| `show`   | `n3s_web_show()` (`app/action/show.inc.php`) | 作品ページの閲覧 (`index.php?action=show`) |
| `widget` | `n3s_web_widget_frame()` (`app/action/widget_frame.inc.php`)、`n3s_api_rec_widget()` (`app/action/rec_widget.inc.php`) | 埋め込みウィジェットでの実行 |
| `api`    | `n3s_api_show()` (`app/action/show.inc.php`) | `api.php?action=show` によるAPI取得 |

`n3s_record_access()` は、ログDBの `app_access_log` テーブルへ
`(app_id, kind, ip, ctime)` を1行追加する。重複除去はしない。
`app_id <= 0` の場合は何も記録しない。

```php
function n3s_record_access($kind, $app_id) { ... }
```

### 集計

- `scripts/app_count.php` (`just app-count`) を 1 時間に 1 回程度 cron で実行する。
- 集計本体は `n3s_aggregate_app_access()` (`app/n3s_lib.inc.php`)。
- 未処理の `app_access_log` を `(date, kind, app_id, ip)` の組で重複除去し、以下を更新する。
  - `access_stats` (日別集計。`app_id=0` は全体合計)
  - `access_stats_monthly` (月別集計。`month`='YYYY-MM'。同じく `app_id=0` は全体合計)
  - `apps.view` (トータルアクセス数。`show` + `widget` の合計のみ加算。`api` は含めない)
- 処理対象は実行開始時点までのログ (`MAX(log_id)`) に固定する。実行中に増える分は次回に回す。
- main DB (`apps.view`) と log DB (`access_stats` 等 + ログ削除) は別トランザクションで
  順に処理する。main 側の更新に成功し log 側の更新に失敗した場合、ログは削除されないため
  次回実行で再集計されるが、`apps.view` は先に加算済みなので二重計上され得る
  (`image_count.php` と同様、ごく稀な失敗時のみのトレードオフとして許容している)。
- 重複除去は **その回のバッチ実行内のログだけ** を対象に行う。同一IPが別々のバッチ実行
  にまたがってアクセスした場合は、それぞれ1回として数えられる
  (`image_count.php` と同じ割り切り)。

### 表示

- `n3s_web_show()` が `n3s_get_app_access_count($app_id)` でトータル/今月のアクセス数を取得し、
  作品情報テンプレート (`app/template/show.html`) の「作品情報」欄へ
  「アクセス数(累計)」「アクセス数(今月)」として表示する。
  - 累計 = `apps.view`
  - 今月 = `access_stats_monthly` の当月 (`date('Y-m')`)・対象作品・`show`+`widget` 合計
- どちらも `n3s_aggregate_app_access()` が最後にバッチ集計した時点の値であり、
  表示直前のアクセス (このページ表示自体の `show` カウントを含む) はまだ反映されない。
  リアルタイム集計ではない。

### マイグレーションとバックフィル

- `n3s_db_migrate_access_stats()` (`app/n3s_lib.inc.php`、`n3s_db_init()` から毎回呼ばれる) が、
  既存DBに無ければ `access_stats` / `access_stats_monthly` / `app_access_log` を作成する。冪等。
- `access_stats_monthly` を **新規作成した時だけ**、`n3s_backfill_access_stats_monthly()` が
  既存の `access_stats` (旧・リアルタイム集計時代の日別データ) から月別集計と `apps.view` を
  一度だけ復元する。2回目以降のマイグレーションではテーブルが既に存在するためスキップされ、
  二重計上は起きない。
- 注意: 移行前の `access_stats` はリクエスト毎の生ヒット数 (重複除去なし) だったため、
  バックフィルで復元される値は、移行後にユニークIPベースで集計される値より多めに出ることがある。

---

## 3. テーブル一覧 (log DB: `data/n3s_log.sqlite`)

| テーブル | 用途 | 書き込み元 | 集計元 |
|---------|------|-----------|--------|
| `app_access_log` | 作品アクセスの生ログ | `n3s_record_access()` | `scripts/app_count.php` |
| `access_stats` | 作品の日別アクセス統計 | `scripts/app_count.php` | 管理者統計ページ (`n3s_web_stats()`) |
| `access_stats_monthly` | 作品の月別アクセス統計 | `scripts/app_count.php` | 作品情報の「アクセス数(今月)」 |
| `image_access_log` | 素材アクセスの生ログ | `n3s_record_image_access()` | `scripts/image_count.php` |

main DB (`data/n3s_main.sqlite`) 側は `apps.view` (作品のトータルアクセス数) と
`images.view` (素材の累計閲覧数) が対応する集計値を保持する。

## 4. 運用上の注意

- `scripts/app_count.php` と `scripts/image_count.php` は、どちらも cron で
  1時間に1回程度実行することを前提にしている。実行を止めると `app_access_log` /
  `image_access_log` が肥大化し続けるので、稼働状況を定期的に確認すること。
- どちらのバッチもべき等ではあるが、二重実行を並行させると `apps.view` /
  `images.view` の加算がずれる可能性がある。cron の多重起動を避けること
  (例: `flock` でロックする)。
