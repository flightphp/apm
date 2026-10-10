-- Indexes the dashboard filters on. 0001 indexed some TEXT columns without a
-- prefix and never indexed request_dt. These names do not collide with 0001.
-- This file only adds indexes. It does not rewrite rows.
-- On a production server: back up the destination database, then
-- `php vendor/bin/runway apm:migrate`. See the README section
-- "Upgrading a production server".
-- InnoDB builds these as secondary indexes. On a large table, run this
-- during a quiet period. The worker can keep running.

CREATE INDEX idx_apm_requests_request_dt ON apm_requests (request_dt);
CREATE INDEX idx_apm_requests_dt_total_time ON apm_requests (request_dt, total_time);
CREATE INDEX idx_apm_requests_dt_response_code ON apm_requests (request_dt, response_code);
CREATE INDEX idx_apm_requests_token_lookup ON apm_requests (request_token(64));
CREATE INDEX idx_apm_requests_ip_prefix ON apm_requests (ip(45));
CREATE INDEX idx_apm_requests_host_prefix ON apm_requests (host(255));
CREATE INDEX idx_apm_requests_session_prefix ON apm_requests (session_id(128));
CREATE INDEX idx_apm_requests_bot_dt ON apm_requests (is_bot, request_dt);

CREATE INDEX idx_apm_db_queries_request_time ON apm_db_queries (request_id, execution_time);
CREATE INDEX idx_apm_custom_events_type_request ON apm_custom_events (event_type, request_id);
CREATE INDEX idx_apm_custom_event_data_key_request ON apm_custom_event_data (json_key(255), request_id);
