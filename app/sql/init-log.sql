CREATE TABLE logs (
    log_id INTEGER PRIMARY KEY,
    log_level INTEGER DEFAULT 0, /* 0:low, 1:normal 2:high */
    kind TEXT NOT NULL,
    body TEXT NOT NULL,
    ctime INTEGER
);

CREATE TABLE ip_check (
    key INTEGER DEFAULT 0, /* 0: login */
    ip TEXT DEFAULT NULL,
    memo TEXT DEFAULT '',
    ctime INTEGER
);

/* 2026/07 日別アクセス統計 (Issue #217) */
CREATE TABLE IF NOT EXISTS access_stats (
    stat_id INTEGER PRIMARY KEY,
    date    TEXT NOT NULL,       /* 'YYYY-MM-DD' */
    kind    TEXT NOT NULL,       /* 'show' | 'widget' | 'api' */
    app_id  INTEGER DEFAULT 0,   /* 0 = 全体合計 */
    count   INTEGER DEFAULT 0,
    UNIQUE(date, kind, app_id)
);

/* 2026/07/15 素材(image.php)アクセスの生ログ。scripts/image_count.php が定期的に
   (image_id, ip) の重複を除去して images.view へ集計し、処理済み行は削除する。 */
CREATE TABLE IF NOT EXISTS image_access_log (
    log_id   INTEGER PRIMARY KEY,
    image_id INTEGER NOT NULL,
    ip       TEXT NOT NULL,
    ctime    INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_image_access_log_image_id ON image_access_log(image_id);
