/* nako3storage main database */
CREATE TABLE info (
  info_id     INTEGER PRIMARY KEY,
  key         TEXT,
  value       INTEGER DEFAULT 0,
  tag         TEXT DEFAULT ''
);

CREATE TABLE apps (
  app_id      INTEGER PRIMARY KEY,
  title       TEXT DEFAULT '(no title)',
  author      TEXT DEFAULT '(no name)',
  email       TEXT DEFAULT '',
  url         TEXT DEFAULT '',
  memo        TEXT DEFAULT '',
  material_id INTEGER DEFAULT 0,
  version     TEXT DEFAULT '',
  nakotype    TEXT DEFAULT 'wnako', /* wnako/cnako */
  tag         TEXT DEFAULT '',
  editkey     TEXT DEFAULT '',
  is_private  INTEGER DEFAULT 0, /* 0:public 1:private */
  ref_id      INTEGER DEFAULT 0,
  ip          TEXT DEFAULT '',
  ctime       INTEGER DEFAULT 0,
  mtime       INTEGER DEFAULT 0
);

CREATE TABLE comments (
  comment_id    INTEGER PRIMARY KEY,
  app_id       INTEGER DEFAULT -1,
  name          TEXT DEFAULT '',
  body          TEXT DEFAULT '',
  ip            TEXT DEFAULT '',
  editkey       TEXT DEFAULT '',
  ctime         INTEGER DEFAULT 0,
  mtime         INTEGER DEFAULT 0
);
