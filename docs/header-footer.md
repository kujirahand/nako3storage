# ヘッダ・フッタの改良計画 (全ページ共通)

## 1. 目的と概要

全ページ共通のヘッダ (`app/template/parts_html_header.html`) とフッタ (`app/template/parts_html_footer.html`) を改良します。

- コンテンツの横幅を `action=list` (トップページ) の本文と同じ **max-width: 1180px + 左右 padding 16px** に揃える。
- トップページ・マイページで採用した「桜」デザインシステム (`.n3s-list-page` 系) とトーンを統一し、洗練された見た目にする。

ヘッダ・フッタは widget 系 (`widget.html` / `widget_frame.html` / `show_input_editkey.html`) を除く全テンプレートから include されているため、この2ファイル+CSSの変更だけでサイト全体に反映されます。

---

## 2. 現状の問題点

### ヘッダ (`parts_html_header.html` → `#n3s_header`)

- 内容が画面幅いっぱいに広がり、本文 (1180px) と幅が揃っていない。
- パンくず (`.setumei`) のリンクが黄色マーカー (`background-color: #ffffaa`) で、桜デザインから浮いている。
- `h1.title` の帯が `#fff0f0` のベタ塗り + `border-bottom: 1px dotted gray` で古い印象。
- メニュー (`parts_html_menu.html` の `.header-right`) が素の pure-button のままで、`line-height: 3` による不揃いな余白がある。
- CSS 中に `#n3s_header .search_box` の定義が残っているが、現在のテンプレートに検索ボックスは無い (デッドコード)。

### フッタ (`parts_html_footer.html` → `#n3s_footer`)

- `border-top: 1px dotted gray` のみの簡素な区切りで、ページ本文 (桜色背景) からの繋がりが唐突。
- メニューとバージョン表記 (`.applink`) が幅制限なしで漂っており、視覚的な終端感がない。

### デザイントークンの問題

- 桜パレットの CSS 変数 (`--n3s-sakura-accent` など) が `.n3s-list-page, .n3s-mypage-page` にしか定義されておらず、ヘッダ・フッタから参照できない。

---

## 3. デザイン方針

### 3-1. デザイントークンの共通化

桜パレットの CSS 変数定義を `:root` へ移動 (または複製) し、ヘッダ・フッタからも参照可能にする。

```css
:root {
  --n3s-sakura-bg: #fef7f9;
  --n3s-sakura-band: #fdf0f4;
  --n3s-sakura-accent: #d1477a;
  --n3s-sakura-soft: #e5a3b3;
  --n3s-sumi: #3b3134;
  --n3s-border: #e8d3da;
  --n3s-gold: #c9a86a;
}
```

既存の `.n3s-list-page, .n3s-mypage-page` 上の定義は互換のため残してもよいが、二重管理を避けるため `:root` に一本化するのが望ましい (値は同一なので挙動は変わらない)。

### 3-2. 横幅を揃える内側コンテナ

`.n3s-list-section` と同じ幅ルールを持つ共通コンテナ `.n3s-inner` を新設し、ヘッダ・フッタの中身をこれで包む。

```css
.n3s-inner {
  max-width: 1180px;
  margin: 0 auto;
  padding: 0 16px;
  box-sizing: border-box;
}
```

外側 (`#n3s_header` / `#n3s_footer`) は背景色・境界線を画面幅いっぱいに敷き、中身だけを 1180px に制限する「帯 + 内側制限」構成にする。

### 3-3. ヘッダのデザイン

```html
<div id="n3s_header">
  <div class="n3s-inner">
    <div class="n3s-header-breadcrumb">
      <a href="https://nadesi.com/">🌸 なでしこ</a> <span>&gt;</span>
      <a href="index.php">🍯 貯蔵庫</a>
    </div>
    <div class="n3s-header-main">
      <h1 class="title"><a href="./index.php">{{ $page_title }}</a></h1>
      {{ include parts_html_menu.html }}
    </div>
  </div>
</div>
```

- 背景: 白。下端に `border-bottom: 1px solid var(--n3s-border)`。ドット線は廃止。
- パンくず: 黄色マーカーをやめ、小さめ (0.78em) のグレー文字 + 桜アクセント色リンクに変更。
  - `.setumei a` の黄色マーカー自体は他ページ (`show.html` 等) でも使われているため、ヘッダ内だけ `#n3s_header` スコープで上書きする (グローバル定義は変更しない)。
- タイトル `h1.title`: `#fff0f0` のベタ塗り帯を廃止。明朝体 (`"Hiragino Mincho ProN", "Yu Mincho", serif`) + `::before` に "❀" を付け、`.n3s-list-section h1` と同じ書体感に統一する。文字色は `var(--n3s-sumi)`。
  - 注意: `$page_title` は show ページでは作品名になる (`show.inc.php:16`)。長いタイトルでも崩れないよう `overflow-wrap: anywhere` を指定する。
- メニューとタイトルは PC では横並び (flex, タイトル左・メニュー右)、狭い画面では縦積みにする。

### 3-4. メニューボタン (`parts_html_menu.html` の `.header-right`)

- pure-button を `#n3s_header` / `#n3s_footer` スコープで上書きし、`.n3s-top-user` と同系のピル型 (border-radius: 999px、白背景、`var(--n3s-border)` の枠線) に整える。
- hover 時は `var(--n3s-sakura-band)` 背景 + アクセント色枠線。
- `line-height: 3` をやめ、`display: flex; flex-wrap: wrap; gap: 8px;` で整列する (スマホ折り返し問題 #145 の再発防止)。
- テンプレート側 (`parts_html_menu.html`) は原則変更しない (ヘッダ・フッタ両方から include されているため、CSS スコープでの調整に留める)。

### 3-5. フッタのデザイン

```html
<div id="n3s_footer">
  <div class="n3s-inner">
    <div id="n3s_footer_menu">
      {{ include parts_html_menu.html }}
    </div>
    <div class="applink">
      <a href="index.php">なでしこ3貯蔵庫</a>
      <a href="https://github.com/kujirahand/nako3storage/">v.{{$nako3storage_version}}</a>
      <span class="n3s-footer-sep">・</span>
      <a href="index.php?action=kiyaku">利用規約</a>
      <span class="n3s-footer-sep">・</span>
      <a href="https://nadesi.com/cgi/kaizen3/">不具合の報告</a>
    </div>
  </div>
</div>
```

- 背景: `var(--n3s-sakura-band)` (#fdf0f4) の帯。上端は `border-top: 3px double var(--n3s-border)` で `.n3s-list-section h1` の二重線と呼応させる。
- フッタ内メニューは中央寄せ。`.applink` は現行どおり中央寄せで、区切りをハイフンから「・」に変更。
- 上下に十分な余白 (padding: 24px 0 32px 程度) を取り、ページの終端感を出す。
- リンク色は `var(--n3s-sakura-accent)`、下線なし・hover で下線。

### 3-6. レスポンシブ対応

既存のブレークポイント (767px) に合わせる。

- ≤767px: `.n3s-inner` の padding を 12px に。ヘッダはタイトルとメニューを縦積み、タイトル 1.1em 程度に縮小。メニューボタンは折り返し表示 (gap 6px)。
- フッタメニューも折り返しを許容し、`.applink` は文字 0.78em のまま中央寄せを維持。

---

## 4. 変更対象ファイル

| ファイル | 変更内容 |
|---|---|
| `app/template/parts_html_header.html` | `.n3s-inner` ラッパー追加、パンくず・タイトルの構造整理 |
| `app/template/parts_html_footer.html` | `.n3s-inner` ラッパー追加、区切り文字の変更 |
| `app/resource/basic.css` | `:root` トークン追加、`.n3s-inner` 新設、`#n3s_header` / `#n3s_footer` の再スタイル、`#n3s_header .search_box` (デッドコード) の削除 |
| `app/template/parts_html_menu.html` | 原則変更なし (必要ならクラス追加のみ) |

widget 系テンプレートはヘッダ・フッタを include していないため影響なし。

---

## 5. 実装しないこと (スコープ外)

- メニュー項目の増減・文言変更 (現行の 新規/一覧/🔌/検索/私の作品/ログイン等を維持)。
- ヘッダの sticky (追従) 化。
- `show.html` 等、各ページ本文側のレイアウト変更。
- `.setumei` のグローバル定義の変更 (ヘッダ内スコープの上書きのみ)。

---

## 6. 検証チェックリスト

1. `php -l` で構文チェック (テンプレートは対象外、CSS は目視)。
2. テンプレート変更後は `cache/parts_html_header.html.php` 等のコンパイルキャッシュ削除を忘れない (対象ファイルのみ)。
3. `php -S localhost:8000` で以下を目視確認する。
   - `index.php?action=list` (トップ): 本文 1180px とヘッダ・フッタ内容の左右端が揃うこと。
   - `index.php?action=show&page=1`: 長い作品タイトルでもヘッダが崩れないこと。
   - `index.php?action=edit&page=new`、`action=upload&mode=list`、`action=login`、`action=kiyaku`、エラーページ (`action=xxx`): 白背景ページでもヘッダ・フッタが破綻しないこと。
   - ログイン時/非ログイン時のメニュー表示 (私の作品・ログアウト/ログイン)。
   - ウィンドウ幅 767px 以下 (スマホ想定) での折り返し・余白。
   - `widget.php?<id>`: 影響がないこと (ヘッダ・フッタ非使用)。
4. `just test` が全件パスすること (表示のみの変更なので既存テストへの影響は想定しないが念のため)。
