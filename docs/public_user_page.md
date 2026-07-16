# ユーザー作品ページ (action=user) 実装計画

## 1. 目的と概要

ユーザーごとの作品を網羅的に確認できる専用ページ `index.php?action=user&user_id=<id>` を新設します。

- ユーザーごとの作品を **[最新順] [アクセス数順] [お気に入り順]** で並べ替えでき、**[検索]** でそのユーザーの作品内を絞り込めるようにする。
- 画面上部にユーザーのプロフィール画像・名前・プロフィール文、設定されていれば X (Twitter) へのリンクを配置する。
- 既存のユーザー別一覧 `index.php?user_id=63&action=list` は新ページ `index.php?action=user&user_id=63` に**差し替え**、旧URLへのアクセスは新ページへ**リダイレクト**する。

---

## 2. 設計詳細

### A. コントローラ (`app/action/user.inc.php`)

`list_login.inc.php` と同じ構成で新規作成します。カード生成ヘルパーを再利用するため `include_once __DIR__ . '/list.inc.php';` します。

1. **関数構成**
   - `n3s_web_user()`: Web 表示。`n3s_user_get()` の結果を `user.html` に渡す。
   - `n3s_user_get()`: データ取得本体。

2. **パラメータ**
   - `user_id` (必須): 対象ユーザー。`intval()` で取得し、`0 以下` または `users` テーブルに存在しない場合は `n3s_error('不正なページ', ...)`。
   - `sort`: 並び順。ホワイトリスト検証 (`in_array(..., true)`)。
     - `mtime` (デフォルト): 最新順 (`ORDER BY mtime DESC, app_id DESC`)
     - `view`: アクセス数順 (`ORDER BY view DESC, app_id DESC`)
     - `fav`: お気に入り順 (`ORDER BY fav DESC, app_id DESC`)
   - `search_word`: 検索語 (任意)。指定時はそのユーザーの作品内を検索する。
     - 2文字以上を要求 (既存 `search.inc.php` と同じ)。1文字以下ならエラーメッセージを表示して一覧は全件表示。
     - `*` を含む場合は `%` に変換、含まない場合は前後に `%` を付与 (既存 search と同じワイルドカード仕様)。
     - 条件: `(title LIKE ? OR memo LIKE ? OR tag LIKE ?)`
   - `offset`: ページネーション用 (デフォルト 0、負値は 0 に補正、上限 10000)。
   - `nofilter` / `onlybad`: 管理者向け表示切替 (list_login と同様)。指定時は `noindex=1`。

3. **WHERE 共通条件**
   - `user_id = ?`
   - `is_private = 0` (公開作品のみ。限定公開(2)・非公開(1)は本人のものでも表示しない — 公開プロフィールページのため)
   - `show_list = 1` (一覧掲載フラグ #202)
   - `bad = 0` (通常時。`nofilter`/`onlybad` 指定時は list_login と同じ切替)

4. **クエリ例**
   ```sql
   SELECT app_id, title, author, memo, mtime, fav, view, user_id, tag,
          nakotype, bad, image_id, comment_count
   FROM apps
   WHERE user_id = ? AND is_private = 0 AND show_list = 1 AND bad = 0
     AND (title LIKE ? OR memo LIKE ? OR tag LIKE ?)  -- search_word指定時のみ
   ORDER BY {mtime | view | fav} DESC, app_id DESC
   LIMIT ? OFFSET ?
   ```
   - 1ページあたり `MAX_USER_PAGE = 24` 件 (list_login と同じ、タイルが揃う3/4の倍数)。
   - ページネーション判定用に同条件の `count(*)` も取得する。

5. **データ加工**
   - `n3s_list_setIcon()` / `n3s_list_setCoverURL()` / `n3s_list_setUserProfileURL()` / `n3s_list_setTagLink()` / `n3s_list_setCardHTML()` を呼び、`card_html` を生成。

6. **プロフィール情報の取得**
   - `db_get1('SELECT * FROM users WHERE user_id=?', [$user_id], 'users')`
   - `profile_url_large` = `n3s_get_user_image_url($user, 0)` (500px版。未設定時はデフォルト画像)
   - `x_url`: `screen_name` が空でなければ `https://x.com/{screen_name}`。
     - `screen_name` は `userinfo.inc.php` で `^[A-Za-z0-9_]{1,15}$` に検証済みだが、古いデータを考慮して出力前にも同じ正規表現で検証し、不一致ならリンクを出さない (フェイルクローズ)。
   - 総投稿数 (フィルタ前の公開作品数) をプロフィール部に表示する。

7. **ページネーションURL**
   - `n3s_getURL('', 'user', ['user_id'=>..., 'sort'=>..., 'search_word'=>..., 'offset'=>...])` で `prev_url` / `next_url` を構築 (list_login と同じ方式)。

### B. テンプレート (`app/template/user.html`)

`list_login.html` をベースに、桜デザイン (`.n3s-list-page` 系) で統一します。

1. **プロフィールセクション (画面上部)**
   - `list.html` の既存ユーザー紹介 (`.n3s-list-user-profile`: 200px画像 + 右にプロフィール) のマークアップ・CSSを流用。
   - 表示内容: プロフィール画像 / 名前 (h1) / 投稿数 / プロフィール文 (`description`、空なら「プロフィールはまだ設定されていません。」) / X リンク。
   - X リンクは `{{ if $x_url }}` で条件表示。`𝕏 @{{$screen_name}}` のようなピル型リンク (`target="_blank" rel="noopener"`)。

2. **ソートタブ**
   - `.n3s-upload-tabs` (role=tablist) を流用し、[最新順][アクセス数順][お気に入り順] の3タブ。
   - 各タブの href に `user_id` と `search_word` (検索中の場合) を引き継ぐ。

3. **検索ボックス**
   - タブの近くに `<form method="GET" action="index.php">` を配置。
     hidden で `action=user` / `user_id` / `sort` を持ち、`search_word` を入力する。
   - 検索中は「"○○" の検索結果: N件」の見出しと「検索を解除」リンクを表示。

4. **作品タイルとページネーション**
   - `.n3s-tiles` + `{{ $r.card_html | raw }}`。
   - 0件時は「作品が見つかりませんでした。」
   - `[◀ 前へ] [次へ ▶]` (list_login と同じ)。

5. **その他**
   - `{{ include parts_html_header.html }}` / `{{ include parts_html_footer.html }}` を先頭・末尾に必ず入れる。
   - 管理者向け `nofilter`/`onlybad` リンク (list_login と同様)。
   - `page_title` は「{ユーザー名} さんの作品一覧」を設定する。

### C. 既存リンクの差し替え

`index.php?user_id=<id>&action=list` 形式のリンクを `index.php?action=user&user_id=<id>` に差し替える。生成箇所は以下の7ヶ所。

| ファイル | 箇所 | 内容 |
|---|---|---|
| `app/action/list.inc.php` (n3s_list_setCardHTML) | 作者リンク | カードの「○○ 作」 |
| `app/template/show.html` | 66行付近 | 作品ページの作者リンク |
| `app/template/list.html` | 67行付近 | 人気ユーザーの一覧 |
| `app/template/library.html` | 34行付近 | ライブラリ一覧の作者リンク |
| `app/template/search.html` | 69行付近 | 検索結果の作者リンク |
| `app/action/fav.inc.php` | 50行付近 | いいね画面の作者リンク |
| `app/action/upload.inc.php` | 297行付近 (`link_user`) | 素材情報ページのユーザーリンク |

### D. 旧URLのリダイレクト (後方互換)

外部サイト・ブックマーク・検索エンジンからの旧URL対策:

- `n3s_list_get()` の冒頭で `$_GET['user_id'] > 0` の場合、
  `HTTP/1.1 301 Moved Permanently` + `Location: index.php?action=user&user_id=<id>` で新ページへ転送して `exit` する。
- リダイレクト後、`list.inc.php` 内の `$find_user_id` による絞り込みロジックと
  `list.html` のユーザー紹介セクション (`$find_user_info`) は実質デッドコードになるが、
  削除は最小限にとどめる (今回はリダイレクト追加のみとし、デッドコード削除は別途検討)。

---

## 3. 変更対象ファイル一覧

| ファイル | 種別 | 内容 |
|---|---|---|
| `app/action/user.inc.php` | 新規 | コントローラ (`n3s_web_user` / `n3s_user_get`) |
| `app/template/user.html` | 新規 | 表示テンプレート |
| `app/action/list.inc.php` | 変更 | 旧URLリダイレクト追加、カード作者リンク差し替え |
| `app/template/show.html` | 変更 | 作者リンク差し替え |
| `app/template/list.html` | 変更 | 人気ユーザーリンク差し替え |
| `app/template/library.html` | 変更 | 作者リンク差し替え |
| `app/template/search.html` | 変更 | 作者リンク差し替え |
| `app/action/fav.inc.php` | 変更 | 作者リンク差し替え |
| `app/action/upload.inc.php` | 変更 | `link_user` 差し替え |
| `app/resource/basic.css` | 変更(必要なら) | 検索ボックス・Xリンク等の最小限のスタイル追加 |
| `tests/Feature/UserViewTest.php` | 新規 | Pest テスト |
| `docs/public_user_page.md` | 本書 | 仕様・計画 |
| `AGENTS.md` | 変更 | 「よく触るファイル別メモ」に1行追記 |

---

## 4. セキュリティ上の注意

- `user_id` / `offset` は `intval()`、`sort` はホワイトリスト検証。
- `search_word` はプレースホルダで LIKE に渡す (SQL直埋め禁止)。
- `screen_name` は出力前に `^[A-Za-z0-9_]{1,15}$` を再検証してから X の URL を組み立てる。
- `description` はテンプレートのデフォルトエスケープ (`{{$...}}`) で出力し、`| safe`/`| raw` を使わない。
- 非公開 (`is_private != 0`)・一覧非掲載 (`show_list = 0`) の作品は本人がログインしていても表示しない
  (本人の作品管理は既存の `action=mypage` の役割とし、本ページは公開プロフィールに徹する)。

---

## 5. テスト計画 (`tests/Feature/UserViewTest.php`)

`ListLoginViewTest.php` に倣い、`n3s_user_get()` を対象に以下を検証する。

1. 指定ユーザーの公開作品のみが返る (他ユーザー・非ログイン投稿が混ざらない)。
2. `sort=mtime|view|fav` の並び順が正しい。不正な `sort` は `mtime` にフォールバックする。
3. `is_private != 0` / `show_list = 0` / `bad > 0` の作品が除外される。
4. `search_word` によるタイトル・説明・タグの絞り込みが機能する (ワイルドカード `*` を含む)。
5. `search_word` が1文字のときエラーメッセージが設定され、全件が返る。
6. 存在しないユーザーの扱い (エラー)。
7. `screen_name` 設定時のみ `x_url` が生成され、不正な形式では生成されない。
8. ページネーション (`total_count` / `prev_url` / `next_url`) の境界値。

---

## 6. 検証チェックリスト

1. `php -l app/action/user.inc.php` ほか変更ファイルの構文チェック。
2. `just test` が全件パスすること。
3. `php -S localhost:8000` で目視確認:
   - `index.php?action=user&user_id=1`: プロフィール・タブ・タイル・ページネーション。
   - ソートタブの切替、検索の絞り込みと解除。
   - `screen_name` 有無による X リンクの表示/非表示。
   - `index.php?user_id=1&action=list` が新ページへ 301 リダイレクトされること。
   - `index.php?action=list` (user_id なし) が従来どおり表示されること。
   - 作品ページ・検索結果・ライブラリ・素材ページの作者リンクが新URLになっていること。
   - 存在しない `user_id` でエラー表示になること。
4. テンプレート変更後は対応する `cache/*.html.php` のみ削除して反映を確認。
