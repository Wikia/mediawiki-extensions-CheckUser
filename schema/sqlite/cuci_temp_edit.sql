-- This file is automatically generated using maintenance/generateSchemaSql.php.
-- Source: extensions/CheckUser/schema/cuci_temp_edit.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
CREATE TABLE /*_*/cuci_temp_edit (
  cite_ip_hex BLOB NOT NULL,
  cite_ciwm_id SMALLINT UNSIGNED NOT NULL,
  cite_timestamp BLOB NOT NULL,
  PRIMARY KEY(cite_ip_hex, cite_ciwm_id)
);

CREATE INDEX cite_timestamp ON /*_*/cuci_temp_edit (cite_timestamp);

CREATE INDEX cite_ip_hex_timestamp ON /*_*/cuci_temp_edit (cite_ip_hex, cite_timestamp);
