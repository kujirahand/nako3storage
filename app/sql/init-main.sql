/* nako3storage main database */
CREATE TABLE info (
  info_id     INTEGER PRIMARY KEY,
  key         TEXT,
  value       INTEGER DEFAULT 0,
  tag         TEXT DEFAULT ''
);

CREATE TABLE apps (
  app_id      INTEGER PRIMARY KEY,
  app_name    TEXT DEFAULT '', /* ライブラリ名 | ユニークなアプリ名(不要な場合空欄にする) */
  title       TEXT DEFAULT '(no title)',
  author      TEXT DEFAULT '(no name)',
  user_id     INTEGER DEFAULT 0, /* 0:ユーザー登録なし */
  email       TEXT DEFAULT '',
  url         TEXT DEFAULT '', /* 関連URL */
  memo        TEXT DEFAULT '', /* プログラムのコメント */
  material_id INTEGER DEFAULT 0,
  version     TEXT DEFAULT '', /* なでしこのどのバージョンを使うか */
  nakotype    TEXT DEFAULT 'wnako', /* wnako/cnako/text/json/base64 */
  custom_head TEXT DEFAULT '', /* カスタムヘッダ */
  tag         TEXT DEFAULT '', /* (''|DNCL|library|w_noname)+ カンマで区切る */
  editkey     TEXT DEFAULT '', /* 編集用のキー(ハッシュ化されていない) */
  need_key    INTEGER DEFAULT 0, /* 0:不要 1: 見るには access_keyが必要 */
  access_key  TEXT DEFAULT '', /* 現在未使用 */
  is_private  INTEGER DEFAULT 0, /* 0:public 1:private 2:limited */
  ref_id      INTEGER DEFAULT 0,
  ip          TEXT DEFAULT '',
  fav         INTEGER DEFAULT 0, /* いいねの数 */
  fav_lastip  TEXT DEFAULT '', /* 最後にいいねした人のIP */
  view        INTEGER DEFAULT 0, /* 閲覧数 */
  canvas_w    INTEGER DEFAULT 300,
  canvas_h    INTEGER DEFAULT 300,
  copyright   TEXT DEFAULT '',
  bad         INTEGER DEFAULT 0,
  prog_hash   TEXT DEFAULT '', /* プログラムのハッシュ(公開プログラムで同一の投稿はできないようにする) */
  ctime       INTEGER DEFAULT 0,
  mtime       INTEGER DEFAULT 0
);

CREATE TABLE comments (
  comment_id    INTEGER PRIMARY KEY,
  user_id       INTEGER DEFAULT 0,
  app_id        INTEGER DEFAULT -1,
  name          TEXT DEFAULT '',
  body          TEXT DEFAULT '',
  ip            TEXT DEFAULT '',
  editkey       TEXT DEFAULT '',
  ctime         INTEGER DEFAULT 0,
  mtime         INTEGER DEFAULT 0
);

CREATE TABLE images (
  image_id      INTEGER PRIMARY KEY,
  title         TEXT DEFAULT '',
  filename      TEXT DEFAULT '',
  user_id       INTEGER DEFAULT 0,
  fav           INTEGER DEFAULT 0,
  fav_id        TEXT DEFAULT '',
  copyright     TEXT DEFAULT 'CC0',
  bad           INTEGER DEFAULT 0,
  ctime         INTEGER DEFAULT 0,
  mtime         INTEGER DEFAULT 0
);

CREATE TABLE bookmarks (
  bookmark_id   INTEGER PRIMARY KEY,
  user_id       INTEGER,
  app_id        INTEGER,
  ctime         INTEGER DEFAULT 0,
  UNIQUE(user_id, app_id)
);

/*
2024/01/28 ユーザー情報を分離

2023/05/07
ALTER TABLE users ADD COLUMN email TEXT DEFAULT '';
ALTER TABLE users ADD COLUMN password TEXT DEFAULT '';
ALTER TABLE users ADD COLUMN pass_token TEXT DEFAULT '';

2021/11/11
ALTER TABLE apps ADD COLUMN app_name TEXT DEFAULT '';
ALTER TABLE apps ADD COLUMN prog_hash TEXT DEFAULT '';

2021/04/30
ALTER TABLE apps ADD COLUMN copyright TEXT DEFAULT ''

2021/04/20
ALTER TABLE images ADD COLUMN copyright TEXT DEFAULT 'CC0'

2021/03/06
ALTER TABLE apps ADD COLUMN custom_head TEXT DEFAULT ''

2020/12/05
ALTER TABLE apps ADD COLUMN canvas_w INTEGER DEFAULT 300
ALTER TABLE apps ADD COLUMN canvas_h INTEGER DEFAULT 300
ALTER TABLE apps ADD COLUMN fav_lastip TEXT DEFAULT '' 
ALTER TABLE apps ADD COLUMN bad INTEGER DEFAULT 0
ALTER TABLE apps ADD COLUMN body TEXT DEFAULT ''

2020/12/08
ALTER TABLE apps ADD COLUMN user_id INTEGER DEFAULT 0
ALTER TABLE comments ADD COLUMN user_id INTEGER DEFAULT 0
*/

/* 2020/12/21 add images table (画像のアップロード機能) */
