CREATE TABLE IF NOT EXISTS cdn_download_stats (
    stat_id INTEGER PRIMARY KEY,
    date    TEXT NOT NULL,       /* Asia/Tokyo の YYYY-MM-DD */
    hour    INTEGER NOT NULL,    /* 0-23 */
    file    TEXT NOT NULL,       /* release/ 以下の配信ファイル */
    version TEXT NOT NULL,       /* 3.x.y */
    count   INTEGER NOT NULL DEFAULT 0,
    UNIQUE(date, hour, file, version)
);

CREATE INDEX IF NOT EXISTS idx_cdn_download_stats_date
    ON cdn_download_stats(date);
CREATE INDEX IF NOT EXISTS idx_cdn_download_stats_file
    ON cdn_download_stats(file);
CREATE INDEX IF NOT EXISTS idx_cdn_download_stats_version
    ON cdn_download_stats(version);

CREATE TABLE IF NOT EXISTS cdn_download_meta (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL DEFAULT ''
);

PRAGMA user_version = 1;
