CREATE TABLE items (
    item_id INTEGER PRIMARY KEY,
    app_id INTEGER NOT NULL,
    key TEXT NOT NULL,
    value TEXT NOT NULL,
    ctime INTEGER,
    mtime INTEGER,
    user_id INTEGER DEFAULT 0 /* 書き込んだユーザー (#194) */
);

CREATE TABLE keys (
    key_id INTEGER PRIMARY KEY,
    app_id INTEGER NOT NULL,
    key TEXT NOT NULL,
    value TEXT NOT NULL,
    ctime INTEGER,
    mtime INTEGER,
    user_id INTEGER DEFAULT 0 /* 書き込んだユーザー (#194) */
);

CREATE TABLE meta (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL,
    tag INTEGER DEFAULT 0,
    ctime INTEGER,
    mtime INTEGER
);
