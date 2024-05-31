CREATE TABLE items (
    item_id INTEGER PRIMARY KEY,
    app_id INTEGER NOT NULL,
    key TEXT NOT NULL,
    value TEXT NOT NULL,
    ctime INTEGER,
    mtime INTEGER
);

CREATE TABLE meta (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL,
    tag INTEGER DEFAULT 0,
    ctime INTEGER,
    mtime INTEGER
);
