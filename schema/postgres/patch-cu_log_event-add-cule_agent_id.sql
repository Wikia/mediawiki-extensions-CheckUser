-- This file is automatically generated using maintenance/generateSchemaChangeSql.php.
-- Source: schema/abstractSchemaChanges/patch-cu_log_event-add-cule_agent_id.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
ALTER TABLE cu_log_event
  ADD cule_agent_id BIGINT DEFAULT 0 NOT NULL;
