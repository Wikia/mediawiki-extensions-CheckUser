-- This file is automatically generated using maintenance/generateSchemaSql.php.
-- Source: schema/cu_useragent_clienthints_map.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
CREATE TABLE /*_*/cu_useragent_clienthints_map (
  uachm_uach_id INT UNSIGNED NOT NULL,
  uachm_reference_id INT UNSIGNED NOT NULL,
  uachm_reference_type TINYINT(1) DEFAULT 0 NOT NULL,
  INDEX uachm_reference_id (uachm_reference_id),
  PRIMARY KEY(
    uachm_uach_id, uachm_reference_type,
    uachm_reference_id
  )
) /*$wgDBTableOptions*/;
