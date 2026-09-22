CREATE TABLE IF NOT EXISTS cdn_download_logs (
    log_id  INTEGER PRIMARY KEY,
    file    TEXT NOT NULL,
    version TEXT NOT NULL,
    ctime   INTEGER NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_cdn_download_logs_ctime
    ON cdn_download_logs(ctime);

PRAGMA user_version = 1;
