-- This file is automatically generated using maintenance/generateSchemaChangeSql.php.
-- Source: extensions/CheckUser/schema/abstractSchemaChanges/patch-cu_changes-add-cuc_only_for_read_old.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
ALTER TABLE  /*_*/cu_changes
ADD  cuc_only_for_read_old TINYINT(1) DEFAULT 0 NOT NULL;