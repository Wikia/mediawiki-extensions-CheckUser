-- This file is automatically generated using maintenance/generateSchemaChangeSql.php.
-- Source: schema/abstractSchemaChanges/patch-cu_changes-modify-cuc_id-bigint.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
ALTER TABLE /*_*/cu_changes
  CHANGE cuc_id cuc_id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL;
