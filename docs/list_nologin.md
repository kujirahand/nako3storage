# ログインなしの投稿専用一覧ページ (action=list_nologin) 実装計画

## 1. 目的と概要
`index.php?action=list` において、ログインなし（ゲスト）の投稿一覧（`user_id == 0`）が表示されていますが、表示件数が制限されており、また専用のソートやページネーションが提供されていないため、見づらくなっています。
そこで、ログインなしの投稿に特化した専用の表示ページ `index.php?action=list_nologin` を追加し、タイル表示（カード形式）、ソート（最新順、アクセス順、お気に入り数順）、および `[前へ]` `[次へ]` によるページネーションを提供します。

---

## 2. 設計詳細

### A. コントローラ (`app/action/list_nologin.inc.php`)
新しく `app/action/list_nologin.inc.php` を作成し、Web表示用の関数 `n3s_web_list_nologin()` を定義します。

1. **パラメータの受け取り**
   - `sort`: ソート順の指定。
     - `mtime` (デフォルト): 更新日時の新しい順 (`ORDER BY mtime DESC, app_id DESC`)
     - `view`: アクセス数（閲覧数）の多い順 (`ORDER BY view DESC, app_id DESC`)
     - `fav`: お気に入り（いいね）数の多い順 (`ORDER BY fav DESC, app_id DESC`)
   - `offset`: ページ移動用のオフセット値（デフォルト: `0`）。
   - `limit`: 1ページあたりの表示件数（デフォルト: `24` 件。タイル表示が綺麗に並ぶよう3または4の倍数を採用）。

2. **データベースクエリ (SQLite)**
   - 条件 (`WHERE` 句):
     - `user_id = 0` (非ログインユーザーによる投稿)
     - `is_private = 0` (公開投稿のみ)
     - `show_list = 1` (一覧掲載フラグが有効なもののみ)
     - `bad == 0` (通報がないクリーンな投稿のみ。管理者の場合は `nofilter` や `onlybad` パラメータで表示切替できるようにする)
   - クエリ例:
     ```sql
     SELECT app_id, title, author, memo, mtime, fav, view, user_id, tag, nakotype, bad, image_id
     FROM apps
     WHERE user_id = 0 AND is_private = 0 AND show_list = 1 AND bad = 0
     ORDER BY {mtime | view | fav} DESC, app_id DESC
     LIMIT ? OFFSET ?
     ```

3. **データの加工**
   - 既存の `list.inc.php` で使われているアイコン設定やタグリンク、カバー画像URLなどの付与関数を呼び出し、HTMLカード（`card_html`）を生成します。
     - `n3s_list_setIcon($list)`
     - `n3s_list_setCoverURL($list)`
     - `n3s_list_setUserProfileURL($list)`
     - `n3s_list_setTagLink($list)`
     - `n3s_list_setCardHTML($list)`

4. **ページネーション (次へ・前へ) の制御**
   - 総件数を `SELECT count(*)` で取得するか、または `limit + 1` 件取得して次ページの有無を判定します。
   - 前のページへのリンク用の `prev_url`（`offset > 0` の場合のみ）と、次のページへのリンク用の `next_url`（次ページが存在する場合のみ）を生成します。

---

### B. テンプレート (`app/template/list_nologin.html`)
新しく `app/template/list_nologin.html` を作成し、UIを構築します。

1. **基本レイアウト**
   - `parts_html_header.html` と `parts_html_footer.html` をインクルードして、共通のデザインテーマを適用します。
2. **ソートリンクの配置**
   - 「最新順」「アクセス順」「お気に入り順」を切り替えるためのリンク（またはタブ風のボタン）を配置します。現在のアクティブなソートにはハイライトスタイルを適用します。
3. **タイル表示**
   - クラス `n3s-tiles` を持った `<div>` 内に、PHP側で生成された `card_html` を展開します。
     ```html
     <div class="n3s-tiles">
       {{ for $list as $r }}
       {{ $r.card_html | raw }}
       {{ endfor }}
     </div>
     ```
4. **ページネーションボタン**
   - リスト下部に `[前へ]` と `[次へ]` を並べたナビゲーションエリアを配置します。

---

### C. 動線の追加
既存の `index.php?action=list` (メイン一覧ページ) にある「ログインなしの投稿の一覧」セクションの下部に、「もっと見る...」などのリンクを追加し、新設する `index.php?action=list_nologin` へ遷移できるようにします。

---

## 3. 実装・検証手順

1. **計画の合意** (本ファイル)
2. **コントローラの実装**
   - `app/action/list_nologin.inc.php` を作成。
3. **テンプレートの実装**
   - `app/template/list_nologin.html` を作成。
4. **既存ページの修正**
   - `app/template/list.html` のログインなしセクションにリンクを追加。
5. **テスト・動作確認**
   - `just test` または Pest による自動テストが通ることを確認。
   - ブラウザで `index.php?action=list_nologin` にアクセスし、タイル表示、ソート、ページネーションが期待通り動作することを確認する。
