# 素材検索（タイトル・解説）

## 1. 目的

公開素材の一覧画面 (`index.php?action=upload&mode=list`) で、素材の**タイトル**または**解説**をキーワード検索できるようにする。

対象データはメイン DB (`data/n3s_main.sqlite`) の `images` テーブルである。素材本文や実ファイルの内容は検索しない。

## 2. 現在の実装

- 一覧処理は `app/action/upload.inc.php` の `list_image()`。
- 表示テンプレートは `app/template/upload-list.html`。
- タイトルと解説は `images.title` / `images.description` にあり、アップロード時の `go_upload()` と、詳細画面からの `update_image_meta()` が保存する。
- 一覧は現在、閲覧数順（既定）または最新順（`sort=mtime`）で20件ずつ取得する。
- `copyright='SELF'` の素材は自分専用であり、公開一覧には表示してはいけない。現在はテンプレート側で表示を抑止しているため、検索追加時は SQL 側でも必ず除外する。

## 3. 仕様

### 3.1 入口とパラメータ

- 検索ボックスは素材一覧画面の並び替えタブの下、一覧の上に置く。
- フォームは `GET` とし、送信先は `index.php` とする。
- hidden フィールドで `action=upload` と `mode=list` を送る。
- 検索語のパラメータ名は `search_word` とする。
- 検索語の前後空白は `trim()` で除去する。
- `sort`（`ranking` / `mtime`）は hidden フィールドで引き継ぐ。タブのリンクも、検索中は `search_word` を引き継ぐ。
- 「検索」ボタンのほか、検索語が入っている時だけ「クリア」リンクを表示する。クリア先は `index.php?action=upload&mode=list&sort=<現在のsort>` とする。

### 3.2 検索対象・公開範囲

- `images.title LIKE ? OR images.description LIKE ?` の部分一致検索とする。
- `copyright != 'SELF'` を検索 SQL の `WHERE` 条件に必ず含める。
- `SELF` 以外のライセンス（`CC0`、`MIT`、`CC-BY`）は検索対象に含める。
- `token` の有無、`app_id`、ファイル拡張子、画像か否かは検索条件にしない。
- 素材のダウンロード URL・詳細画面・権限判定の仕組みは変更しない。

### 3.3 キーワードと結果

- 空欄で送信した場合は通常の一覧を表示する。
- 1文字以上の検索語を受け付ける。素材名には短い語（例: `猫`、`星`）があり得るため、作品検索の2文字以上制限は適用しない。
- `%` と `_` は SQL `LIKE` のワイルドカードとして解釈させず、検索語の文字として扱う。バックスラッシュでエスケープし、`LIKE ? ESCAPE '\\'` を使う。
- 検索語に `*` の特別な意味は与えない（作品検索とは異なる）。
- 結果は選択中の並び順で表示する。
  - `ranking`: `view DESC, image_id DESC`
  - `mtime`: `image_id DESC`（既存の最新順と合わせる）
- 該当なしでは「『…』に一致する公開素材はありません。」と表示する。検索語はテンプレートの通常エスケープ出力を使う。

### 3.4 ページング

- 1ページは既存どおり20件とする。
- 最新順は `max_id` カーソル方式を維持する。次ページ URL に `search_word` と `sort=mtime` を含める。
- ランキング順は `offset` 方式を維持する。次ページ URL に `search_word` と `sort=ranking` を含める。
- 検索語・並び順を変更した時は、`max_id` / `offset` を引き継がず先頭ページから表示する。
- `copyright != 'SELF'` を SQL で除外してから `LIMIT` する。これにより、SELF 素材がページ枠を消費して表示件数が減る問題を防ぐ。

### 3.5 性能

- 今回は `LIKE '%検索語%'` による部分一致検索とする。SQLite の通常インデックスは先頭ワイルドカード付きの `LIKE` を効率化しにくいため、この変更だけでは検索用インデックスを追加しない。
- 素材数や検索頻度が大きく増えた段階で、SQLite FTS5 の導入を別タスクとして検討する。その際も `copyright != 'SELF'` による公開範囲の制限を忘れない。

## 4. 実装手順

1. `app/action/upload.inc.php` の `list_image()` を確認し、`sort`、カーソル、表示用データの組み立てを変更対象として把握する。

2. `search_word` を `$_GET` から取得して `trim()` する。`%`、`_`、`\\` をこの順にエスケープし、部分一致用の値 `"%{$escaped_word}%"` を作る。

3. 一覧 SQL を組み立てる。

   - 共通条件を `copyright != 'SELF'` とする。
   - 検索語が空でなければ `AND (title LIKE ? ESCAPE '\\' OR description LIKE ? ESCAPE '\\')` を加える。
   - 値は必ず `db_get()` のプレースホルダ配列で渡す。検索語を SQL 文字列へ連結しない。
   - `LIMIT`、`OFFSET`、`max_id` のパラメータ順が SQL と一致することを確認する。

4. `ranking` と `mtime` の両分岐で、次ページ URL に検索語を含める。タブ URL とクリア URL も同じ URL 生成関数 `n3s_getURL()` で作る。

5. `n3s_template_fw('upload-list.html', ...)` に、少なくとも以下を追加する。

   - `search_word`
   - `has_search`（空文字判定をテンプレートへ複雑に持ち込まないための真偽値）
   - `link_clear_search`
   - 検索語を保持した `link_ranking_url` と `link_mtime_url`

6. `app/template/upload-list.html` に検索フォームを追加する。

   - `<label>` を置き、検索入力に対応付ける。
   - `value` は `{{$search_word}}` を使う（`safe` は使わない）。
   - hidden の `action`、`mode`、`sort` を置く。
   - 検索中の0件表示と、非検索時の「公開素材はまだありません。」を区別する。

7. 必要に応じて `app/resource/basic.css` に、検索フォーム用の `n3s-material-search` などを追加する。既存の `n3s-upload-*` コンポーネントの余白、入力欄、モバイル表示に合わせ、ページ全体の共通検索 CSS は変更しない。

8. `tests/Feature/UploadDesignTest.php` に検索の回帰テストを追加する。

   - タイトル一致の公開素材が結果に出る。
   - 解説一致の公開素材が結果に出る。
   - 一致しない素材は出ない。
   - `SELF` 素材は、タイトルまたは解説が一致しても出ない。
   - `%`、`_` を含む検索語がワイルドカードとして広がらない。
   - 検索語を含んだタブ URL と次ページ URL が生成される。
   - 該当なしの文言が表示される。

9. 対象ファイルの構文チェックと全テストを実行する。

   ```sh
   php -l app/action/upload.inc.php
   just test
   ```

10. ローカルサーバーで、次を手動確認する。

   - `http://localhost:8000/index.php?action=upload&mode=list`
   - `http://localhost:8000/index.php?action=upload&mode=list&search_word=検索語`
   - ランキング順と最新順の切り替え後も検索語が残ること。
   - 次ページ、クリア、画像プレビュー、非画像素材の表示が正しいこと。

## 5. 完了条件

- タイトル・解説のどちらでも、公開素材を部分一致検索できる。
- 自分専用（`SELF`）素材は検索結果にも通常一覧にも含まれない。
- SQL インジェクションおよび `LIKE` ワイルドカードの意図しない拡張を防いでいる。
- 検索中も並び替え・ページング・クリアが一貫して動作する。
- テンプレート上の検索語・素材タイトル・解説は HTML エスケープされたまま出力される。
- `just test` が成功する。
