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
