-- This file is automatically generated using maintenance/generateSchemaChangeSql.php.
-- Source: schema/abstractSchemaChanges/patch-cu_log-actor.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
ALTER TABLE cu_log
  ADD cul_actor BIGINT DEFAULT 0 NOT NULL;

CREATE INDEX cul_actor_time ON cu_log (cul_actor, cul_timestamp);
