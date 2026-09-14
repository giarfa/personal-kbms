CREATE TABLE IF NOT EXISTS "migrations"(
  "id" integer primary key autoincrement not null,
  "migration" varchar not null,
  "batch" integer not null
);
CREATE TABLE IF NOT EXISTS "sessions"(
  "id" varchar not null,
  "user_id" integer,
  "ip_address" varchar,
  "user_agent" text,
  "payload" text not null,
  "last_activity" integer not null,
  primary key("id")
);
CREATE INDEX "sessions_user_id_index" on "sessions"("user_id");
CREATE INDEX "sessions_last_activity_index" on "sessions"("last_activity");
CREATE TABLE IF NOT EXISTS "cache"(
  "key" varchar not null,
  "value" text not null,
  "expiration" integer not null,
  primary key("key")
);
CREATE INDEX "cache_expiration_index" on "cache"("expiration");
CREATE TABLE IF NOT EXISTS "cache_locks"(
  "key" varchar not null,
  "owner" varchar not null,
  "expiration" integer not null,
  primary key("key")
);
CREATE INDEX "cache_locks_expiration_index" on "cache_locks"("expiration");
CREATE TABLE IF NOT EXISTS "jobs"(
  "id" integer primary key autoincrement not null,
  "queue" varchar not null,
  "payload" text not null,
  "attempts" integer not null,
  "reserved_at" integer,
  "available_at" integer not null,
  "created_at" integer not null
);
CREATE INDEX "jobs_queue_index" on "jobs"("queue");
CREATE TABLE IF NOT EXISTS "job_batches"(
  "id" varchar not null,
  "name" varchar not null,
  "total_jobs" integer not null,
  "pending_jobs" integer not null,
  "failed_jobs" integer not null,
  "failed_job_ids" text not null,
  "options" text,
  "cancelled_at" integer,
  "created_at" integer not null,
  "finished_at" integer,
  primary key("id")
);
CREATE TABLE IF NOT EXISTS "failed_jobs"(
  "id" integer primary key autoincrement not null,
  "uuid" varchar not null,
  "connection" varchar not null,
  "queue" varchar not null,
  "payload" text not null,
  "exception" text not null,
  "failed_at" datetime not null default CURRENT_TIMESTAMP
);
CREATE INDEX "failed_jobs_connection_queue_failed_at_index" on "failed_jobs"(
  "connection",
  "queue",
  "failed_at"
);
CREATE UNIQUE INDEX "failed_jobs_uuid_unique" on "failed_jobs"("uuid");
CREATE TABLE IF NOT EXISTS "calendar_events"(
  "id" varchar not null,
  "source_uid" varchar not null,
  "recurrence_id" varchar not null default '',
  "summary" varchar,
  "description" text,
  "location" varchar,
  "organizer" varchar,
  "attendees" text,
  "starts_at" datetime not null,
  "ends_at" datetime not null,
  "is_all_day" tinyint(1) not null default '0',
  "timezone" varchar,
  "join_url" varchar,
  "event_url" varchar,
  "content_hash" varchar not null,
  "last_seen_at" datetime not null,
  "cancelled_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  primary key("id")
);
CREATE UNIQUE INDEX "calendar_events_source_uid_recurrence_id_unique" on "calendar_events"(
  "source_uid",
  "recurrence_id"
);
CREATE INDEX "calendar_events_starts_at_index" on "calendar_events"(
  "starts_at"
);
CREATE INDEX "calendar_events_cancelled_at_index" on "calendar_events"(
  "cancelled_at"
);
CREATE TABLE IF NOT EXISTS "calendar_sync_runs"(
  "id" varchar not null,
  "started_at" datetime not null,
  "finished_at" datetime,
  "status" varchar not null,
  "http_status" integer,
  "etag" varchar,
  "last_modified" varchar,
  "events_upserted" integer not null default '0',
  "events_cancelled" integer not null default '0',
  "error" text,
  "created_at" datetime,
  "updated_at" datetime,
  primary key("id")
);
CREATE INDEX "calendar_sync_runs_started_at_index" on "calendar_sync_runs"(
  "started_at"
);
CREATE INDEX "calendar_sync_runs_status_finished_at_index" on "calendar_sync_runs"(
  "status",
  "finished_at"
);
CREATE TABLE IF NOT EXISTS "meeting_notes"(
  "id" varchar not null,
  "event_uid" varchar not null,
  "event_recurrence_id" varchar not null default '',
  "body" text not null default '',
  "created_at" datetime,
  "updated_at" datetime,
  primary key("id")
);
CREATE UNIQUE INDEX "meeting_notes_event_uid_event_recurrence_id_unique" on "meeting_notes"(
  "event_uid",
  "event_recurrence_id"
);
CREATE TABLE IF NOT EXISTS "meeting_transcripts"(
  "id" varchar not null,
  "event_uid" varchar not null,
  "event_recurrence_id" varchar not null default '',
  "path" varchar,
  "link_source" varchar not null,
  "file_size" integer,
  "file_mtime" datetime,
  "linked_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  primary key("id")
);
CREATE UNIQUE INDEX "meeting_transcripts_event_uid_event_recurrence_id_unique" on "meeting_transcripts"(
  "event_uid",
  "event_recurrence_id"
);
CREATE TABLE IF NOT EXISTS "prompt_launches"(
  "id" varchar not null,
  "event_uid" varchar not null,
  "event_recurrence_id" varchar not null default '',
  "context_path" varchar not null,
  "question" text not null,
  "command" text not null,
  "status" varchar not null,
  "exit_code" integer,
  "error" text,
  "launched_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  primary key("id")
);
CREATE INDEX "prompt_launches_event_uid_event_recurrence_id_created_at_index" on "prompt_launches"(
  "event_uid",
  "event_recurrence_id",
  "created_at"
);

INSERT INTO migrations VALUES(1,'0001_01_01_000000_create_sessions_table',1);
INSERT INTO migrations VALUES(2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO migrations VALUES(3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO migrations VALUES(4,'2026_09_07_230706_create_calendar_events_table',1);
INSERT INTO migrations VALUES(5,'2026_09_07_230837_create_calendar_sync_runs_table',1);
INSERT INTO migrations VALUES(6,'2026_09_09_090422_create_meeting_notes_table',1);
INSERT INTO migrations VALUES(7,'2026_09_09_214856_create_meeting_transcripts_table',1);
INSERT INTO migrations VALUES(8,'2026_09_10_082716_create_prompt_launches_table',1);
