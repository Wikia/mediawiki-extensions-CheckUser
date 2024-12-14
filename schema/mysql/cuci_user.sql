-- This file is automatically generated using maintenance/generateSchemaSql.php.
-- Source: schema/cuci_user.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
CREATE TABLE /*_*/cuci_user (
  ciu_central_id INT UNSIGNED NOT NULL,
  ciu_ciwm_id SMALLINT UNSIGNED NOT NULL,
  ciu_timestamp BINARY(14) NOT NULL,
  INDEX ciu_timestamp (ciu_timestamp),
  INDEX ciu_central_id_timestamp (ciu_central_id, ciu_timestamp),
  PRIMARY KEY(ciu_central_id, ciu_ciwm_id)
) /*$wgDBTableOptions*/;
