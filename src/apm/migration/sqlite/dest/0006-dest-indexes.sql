-- Restore lookup indexes dropped when 0005 rebuilt apm_requests.
-- Safe to re-run. This file only adds indexes. It does not rewrite rows.
-- On a production server: back up the destination database, then
-- `php vendor/bin/runway apm:migrate`. See the README section
-- "Upgrading a production server".

CREATE INDEX IF NOT EXISTS idx_apm_requests_request_dt ON apm_requests(request_dt);
CREATE INDEX IF NOT EXISTS idx_apm_requests_dt_total_time ON apm_requests(request_dt, total_time);
CREATE INDEX IF NOT EXISTS idx_apm_requests_dt_response_code ON apm_requests(request_dt, response_code);
CREATE INDEX IF NOT EXISTS idx_apm_requests_token ON apm_requests(request_token);
CREATE INDEX IF NOT EXISTS idx_apm_requests_ip ON apm_requests(ip);
CREATE INDEX IF NOT EXISTS idx_apm_requests_host ON apm_requests(host);
CREATE INDEX IF NOT EXISTS idx_apm_requests_session_id ON apm_requests(session_id);
CREATE INDEX IF NOT EXISTS idx_apm_requests_bot_dt ON apm_requests(is_bot, request_dt);

CREATE INDEX IF NOT EXISTS idx_apm_db_queries_request_time ON apm_db_queries(request_id, execution_time);
CREATE INDEX IF NOT EXISTS idx_apm_custom_events_type_request ON apm_custom_events(event_type, request_id);
CREATE INDEX IF NOT EXISTS idx_apm_custom_event_data_key_request ON apm_custom_event_data(json_key, request_id);
