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
