/* nako3storage material database */
CREATE TABLE materials (
  material_id   INTEGER PRIMARY KEY,
  body          TEXT DEFAULT '',
  type          TEXT DEFAULT 'nako3', /* nako3 / text / css / html ... */
  app_id        INTEGER DEFAULT 0 /* 現在 material_id=app_id にした */
);
