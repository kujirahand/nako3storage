# 扉絵(カバー画像)機能と一覧タイル表示の実装計画

## 1. 目的

- 投稿時に「扉絵(カバー画像)」を設定できるようにする。
- 扉絵は、実行前の画面(widget/show の `start_screen`)や、作品一覧のタイル表示に使う。
- `action=list` のレイアウトを大々的に刷新し、タイル表示にする。モバイル端末では x.com のようなタイムライン型カード表示にする。
- デザインは「和風で雅な雰囲気・テーマカラーはピンク(桜色)」でまとめる。

## 2. 仕様まとめ

| 項目 | 内容 |
|---|---|
| 扉絵サイズ | 600 x 240 ピクセル固定 |
| リサイズ | GD を使用。大きい画像は縮小したうえで中央で切り抜き(cover crop)。小さい画像は拡大して切り抜き |
| 保存先 | 既存の素材(`images` テーブル + `images/NNN/` 実ファイル)として自動アップロード |
| 著作権区分 | `SELF`(自分専用)。既存仕様どおり token を自動生成 |
| DB | `apps.image_id INTEGER DEFAULT 0` を追加 |
| デフォルト | `image_id = 0` のとき `https://n3s.nadesi.com/image.php?f=721.png` を表示(設定で変更可能にする) |
| 設定可能者 | ログインユーザーのみ(推奨。理由は後述) |

## 3. DB スキーマ変更

### 3.1 `app/sql/init-main.sql`

`apps` テーブルに以下を追加する。

```sql
image_id INTEGER DEFAULT 0, /* 扉絵(imagesテーブルのimage_id) 0:扉絵なし */
```

ファイル末尾のコメント欄に ALTER 履歴を追記する。

```sql
2026/07/15 (#xxx) 扉絵カラムを追加 (既存DBは n3s_db_migrate_apps() が自動マイグレーション)
ALTER TABLE apps ADD COLUMN image_id INTEGER DEFAULT 0;
```

### 3.2 既存 DB のマイグレーション (`app/n3s_lib.inc.php`)

`n3s_db_migrate_apps()` を拡張する。現在は `show_list` カラムの有無だけを見て早期リターンしているため、
「`PRAGMA table_info(apps)` で取得したカラム名の集合を作り、無いカラムだけ ALTER する」形にリファクタする。

```php
function n3s_db_migrate_apps()
{
    $columns = db_get('PRAGMA table_info(apps)', [], 'main');
    if (!is_array($columns)) { return; }
    $names = array_column($columns, 'name');
    if (!in_array('show_list', $names)) {
        db_exec("ALTER TABLE apps ADD COLUMN show_list INTEGER DEFAULT 1", [], 'main');
        db_exec("UPDATE apps SET show_list=0 WHERE tag LIKE '%w_noname%'", [], 'main');
    }
    if (!in_array('image_id', $names)) { // 扉絵 (#xxx)
        db_exec("ALTER TABLE apps ADD COLUMN image_id INTEGER DEFAULT 0", [], 'main');
    }
}
```

`image_id` はバックフィル不要(全件 0 = 扉絵なしで良い)。

## 4. 設定の追加 (`app/n3s_config.def.php`)

```php
$n3s_config['cover_width']  = 600;   // 扉絵の幅
$n3s_config['cover_height'] = 240;   // 扉絵の高さ
$n3s_config['cover_default_url'] = 'https://n3s.nadesi.com/image.php?f=721.png'; // 扉絵なしの画像
```

- 本番では 721.png がそのまま使われる。ローカル開発環境に 721.png が無くても、絶対 URL なので表示できる。
- `n3s_config.ini.php` で上書き可能。

## 5. 投稿フォームでの扉絵指定

### 5.1 `app/template/save.html`

- `<form method="POST" ...>` に `enctype="multipart/form-data"` を追加する(現状 enctype 未指定のため必須)。
- 「扉絵」欄を追加する。
  - `<input type="file" name="cover_image" accept="image/jpeg,image/png,image/gif,image/webp">`
  - 既に扉絵がある場合は現在の扉絵をプレビュー表示する。
  - `<input type="checkbox" name="cover_delete" value="1">` 「扉絵を削除する」も併設(更新時のみ)。
  - 非ログイン時は欄自体を出さず、「扉絵の設定にはログインが必要です」と表示する。
- 注意書き:「600x240px にリサイズ・中央切り抜きされます。自分専用素材として登録されます」。

### 5.2 `app/action/edit.inc.php`

編集画面(`edit.html` 経由で save フォームを使う場合)にも同じ欄が出るよう、既存レコードの `image_id` をテンプレートに渡す。

## 6. 保存処理 (`app/action/save.inc.php` + `app/n3s_lib.inc.php`)

### 6.1 処理の流れ

`n3s_action_save_data_raw()` で `apps` への保存(新規は `n3s_saveNewProgram()`)が成功し `app_id` が確定した後に、扉絵を処理する。

```
if ($_FILES['cover_image'] があり、正常アップロードされた) {
    ログインしていなければエラー(扉絵はログインユーザー専用)
    $image_id = n3s_save_cover_image($app_id, $user_id, $_FILES['cover_image']);
    UPDATE apps SET image_id=? WHERE app_id=?
} elseif (cover_delete が指定された) {
    旧扉絵の images 行と実ファイルを削除し、apps.image_id=0 にする
}
```

- 扉絵処理の失敗は「投稿本体の保存は成功、扉絵だけ失敗」として扱い、投稿自体は rollback しない
  (アップロードし直しの負担を減らす)。エラーメッセージで扉絵の再設定を促す。
- ただし `images` への INSERT と実ファイル書き込みは、既存の `go_upload()` と同様に
  `begin`〜`commit` で囲み、ファイル保存失敗時は rollback する。

### 6.2 新設関数 `n3s_save_cover_image($app_id, $user_id, $file)` (`app/n3s_lib.inc.php`)

1. **検証**
   - `extension_loaded('gd')` を確認。無ければエラー(扉絵機能はGD必須)。
   - サイズ上限: 既存の `size_upload_max`(デフォルト7MB)を流用。
   - `getimagesize()` で実体が画像であることを確認(拡張子偽装対策)。
     対応形式: JPEG / PNG / GIF / WebP(GD のビルドに WebP が無い場合は JPEG/PNG/GIF のみ)。
2. **リサイズ + 中央切り抜き** — 新設関数 `n3s_gd_cover_resize($srcPath, $destPath, $w, $h)`
   - `imagecreatefromjpeg/png/gif/webp` で読み込む。
   - 縦横比を保ったまま「短辺が枠にちょうど収まる」倍率で縮小(小さい画像は拡大)し、
     中央から 600x240 を `imagecopyresampled()` で切り抜く。
   - **JPEG (quality 90) で出力**する。透過は白背景で塗り潰す。
     (再エンコードにより EXIF などのメタ情報も自動的に除去される)
3. **素材として登録** — 既存 `go_upload()` の DB 部分に合わせる
   - `copyright='SELF'`、`token=bin2hex(random_bytes(8))`。
   - `title = '扉絵: ' . 作品タイトル`、`app_id = 対象app_id`、`image_name = ''`
     (`nako_` 予約名の制限や `app_id+image_name` 重複チェックを避けるため image_name は使わない)。
   - `filename = "{$image_id}.jpg"`、実ファイルは `n3s_getImageFile($image_id, '.jpg', true, $token)` へ。
4. **旧扉絵の後始末**
   - 更新で扉絵を差し替えた場合、旧 `apps.image_id` の images 行と実ファイルを削除する
     (既存 `delete_image()` のファイル削除ロジックを関数化して流用)。
   - 作品削除 (`n3s_action_save_delete()`) 時も、扉絵の images 行と実ファイルを削除する。

### 6.3 セキュリティの確認点

- CSRF: 投稿フォーム自体の `edit_token` チェックの内側で処理するため追加トークン不要。
- 権限: 扉絵の差し替え・削除は投稿本体の編集権限チェック(本人 or 管理者)を通過した後にのみ実行される。
- パス: `filename` はシステム生成(`{image_id}.jpg`)のみで、ユーザー入力をパスに使わない。
- GD 再エンコードにより、画像に偽装した HTML/スクリプト等はそのまま保存されない。

## 7. 扉絵 URL の解決ヘルパー

`app/n3s_lib.inc.php` に追加する。

```php
// $rows: appsの行の配列(参照渡し)。各行に cover_url を付与する
function n3s_list_setCoverURL(&$rows)
```

- 行の `image_id` を集めて `SELECT image_id, filename, token FROM images WHERE image_id IN (...)` を1回で引く(N+1回避)。
- `token` が空でなければ `image.php?t={token}&f={filename}`、空なら `image.php?f={filename}`。
- `image_id=0` または images 行が消えている場合は `cover_default_url` を使う。
- 単体用に `n3s_get_cover_url($app_row)` も用意し、show/widget から使う。

## 8. 実行前画面(widget / show)への扉絵表示

- `app/action/show.inc.php` の `n3s_show_get()` で `cover_url` をテンプレート変数に追加する。
- `app/template/widget.html`: 既存の `#start_screen`(クリックで実行)の背景に扉絵を表示する。
  - `background-image: url({{$cover_url}}); background-size: cover; background-position: center;`
  - 中央に既存の「▶ 実行」ボタンを重ねる。`run=1`(自動実行)のときは従来どおり表示しない。
- `app/template/show.html`: show 画面にも同じ実行前スクリーンがあれば同様に適用する
  (実装時に `nako3storage_show.js` の実行開始フローを確認して合わせる)。
- widget は `w_noname` によるタイトル秘匿と独立(扉絵は表示してよい)。

## 9. `action=list` のタイル表示化とデザイン刷新

### 9.1 データ側 (`app/action/list.inc.php`)

- 各 SELECT の取得カラムに `image_id` を追加する(`list` / `list2` / `ranking` / `ranking_all`)。
- `n3s_list_setIcon()` などと同じ場所で `n3s_list_setCoverURL()` を呼ぶ。
- `n3s_api_list()` の出力に `cover_url` を追加する(ウィジェット等からの利用を想定)。

### 9.2 テンプレート (`app/template/list.html`) — 全面書き換え

現在の `<table>` ベースを廃止し、カードグリッドにする。

- **PC (>= 768px)**: タイルグリッド表示
  - `display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px;`
  - 各カード: 上部に扉絵(aspect-ratio 600/240、`object-fit: cover`)、下部にタイトル・作者・いいね(⭐)・日付・タグ。
- **モバイル (< 768px)**: x.com 風タイムライン
  - 1カラム縦積み。各投稿は「左に作者アイコン、右にヘッダ(作者名・日付)+タイトル+説明+扉絵(角丸大)+フッタ(⭐いいね・タグ)」の構成。
  - カード間は罫線区切り(カードの影は使わず、フラットに)。
- セクション構成は維持する: 偉大な投稿 / 人気の投稿 / 人気ユーザー / 最新の投稿 / ログインなし投稿 / ページャ / 管理用リンク。
  ランキング系も同じカード部品(`{{include}}` でカード部分を共通化: `parts_app_card.html` を新設)で描画する。

### 9.3 デザイン(和風・雅・ピンク)

`app/resource/basic.css` に CSS 変数とカード用スタイルを追加する(list 以外のページを壊さないよう、
`.n3s-tiles` などの新規クラス配下に限定する)。

- カラーパレット(桜・和色):
  - ベース: 桜色 `#fdf0f4` / 薄桜 `#fef7f9`(背景)
  - アクセント: 撫子色 `#e5a3b3` → 濃いピンク `#d1477a`(見出し・ボタン・リンク)
  - 文字: 墨色 `#3b3134`
  - 罫線: 灰桜 `#e8d3da`
- 雅な要素:
  - セクション見出しに小さな飾り(「❀」や二重線・金色 `#c9a86a` の細アクセント)。
  - カードは角丸 + ごく薄い影。ホバーでふわっと浮く。
  - フォントは既存構成を尊重しつつ `"Hiragino Mincho ProN", "Yu Mincho", serif` を見出しに適用。
- 既存の `parts_html_header.html` に viewport 設定済みなのでレスポンシブは CSS のみで対応可能。

## 10. 作業ステップ(実装順)

1. **フェーズ1: DB + 設定**
   - `init-main.sql` に `image_id` 追加、`n3s_db_migrate_apps()` 拡張、`n3s_config.def.php` に cover 設定追加。
2. **フェーズ2: 保存処理**
   - `n3s_gd_cover_resize()` / `n3s_save_cover_image()` を実装。
   - `save.html` に enctype + 扉絵欄、`save.inc.php` に扉絵の保存・差し替え・削除処理。
   - 作品削除時の扉絵削除。
3. **フェーズ3: 表示**
   - `n3s_list_setCoverURL()` / `n3s_get_cover_url()`、widget/show の実行前スクリーンに扉絵。
4. **フェーズ4: list 刷新**
   - `list.inc.php` のカラム追加、`parts_app_card.html` 新設、`list.html` 全面書き換え、`basic.css` にタイル + 和風テーマ追加。
5. **フェーズ5: 検証**
   - `php -l` で構文チェック、`just test` で既存テストの回帰確認。
   - ローカルサーバー(`php -S localhost:8000`)で以下を確認:
     - 新規投稿(扉絵あり/なし)、更新(差し替え/削除)、非ログイン投稿(扉絵欄が出ない)。
     - 大きい画像・縦長・横長・小さい画像のリサイズと切り抜き結果。
     - `image_id=0` でデフォルト画像が出ること。
     - list の PC タイル表示 / モバイル幅での X 風表示(`resize_window` 相当で確認)。
     - widget の実行前扉絵表示と、クリックで実行が始まること(`run=1` では非表示)。
   - テンプレート変更が反映されないときは `cache/*.html.php` を個別削除。

## 11. 確認事項(要判断)

実装前に以下を確認したい。かっこ内は本計画の推奨(暫定採用)値。

1. **非ログイン投稿の扉絵**: 「自分専用素材(SELF)」は user_id が前提のため、扉絵設定はログインユーザー限定とする(推奨: 限定する)。
2. **出力フォーマット**: リサイズ後は JPEG (quality 90) に統一する(推奨: JPEG。透過が必要なら PNG も検討)。
3. **show 画面への適用範囲**: 扉絵は widget の実行前スクリーンに加えて show 画面にも表示するか(推奨: 両方)。
4. **デフォルト画像**: `https://n3s.nadesi.com/image.php?f=721.png` を `cover_default_url` として設定化する(推奨: 設定化。ハードコードしない)。
5. **OGP対応**: 扉絵を `og:image` にも使うと SNS シェア時に映えるが、今回のスコープに含めるか(推奨: 別issueに分離)。
