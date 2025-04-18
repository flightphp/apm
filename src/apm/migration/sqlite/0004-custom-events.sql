-- Note: SQLite doesn't support column comments or CHANGE COLUMN syntax
-- Keeping event_data column as is, but it will be considered deprecated

-- Create a new table for custom event key-value data
CREATE TABLE IF NOT EXISTS apm_custom_event_data (
	id INTEGER PRIMARY KEY AUTOINCREMENT,
	custom_event_id INTEGER NOT NULL,
	request_id TEXT NOT NULL,
	json_key TEXT NOT NULL,
	json_value TEXT,
	FOREIGN KEY (custom_event_id) REFERENCES apm_custom_events(id) ON DELETE CASCADE,
	FOREIGN KEY (request_id) REFERENCES apm_requests(request_id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_apm_custom_event_data_event_id ON apm_custom_event_data(custom_event_id);
CREATE INDEX IF NOT EXISTS idx_apm_custom_event_data_request_id ON apm_custom_event_data(request_id);
CREATE INDEX IF NOT EXISTS idx_apm_custom_event_data_key ON apm_custom_event_data(json_key);

-- Migrate existing event_data JSON into the new apm_custom_event_data table
INSERT INTO apm_custom_event_data (custom_event_id, request_id, json_key, json_value)
SELECT 
    apm_custom_events.id AS custom_event_id,
    apm_custom_events.request_id,
    json_each.key AS json_key,
    json_each.value AS json_value
FROM 
    apm_custom_events,
    json_each(apm_custom_events.event_data);
