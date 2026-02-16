CREATE TABLE users (
  user_id     INTEGER PRIMARY KEY,
  email       TEXT UNIQUE, /* メールアドレス */
  password    TEXT DEFAULT '', /* ハッシュ化して保存 */
  pass_token  TEXT DEFAULT '', /* パスワードリセット用のトークン */
  name        TEXT DEFAULT '', /* ユーザーの名前 */
  login_token TEXT UNIQUE, /* ログイン用のトークン */
  screen_name TEXT DEFAULT '',
  description TEXT DEFAULT '',
  twitter_id  INTEGER DEFAULT 0,
  profile_url TEXT DEFAULT '',
  salt        TEXT DEFAULT '',
  ctime       INTEGER DEFAULT 0,
  mtime       INTEGER DEFAULT 0
);
