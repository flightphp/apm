-- Migration: Change primary key from request_id to id (auto-increment), make request_id a token and indexed

DROP TABLE IF EXISTS apm_requests_new;

-- IMPORTANT: The old request_id was TEXT (UUID/token). After migration, all child tables must reference the new INTEGER id from apm_requests.
-- To do this, create a mapping table during migration, then update all child tables to use the new id.

-- 0. Create a mapping table to store old request_id (TEXT) and new id (INTEGER)
CREATE TABLE apm_requests_id_map (
    old_request_id TEXT PRIMARY KEY,
    new_id INTEGER
);

-- 1. Create new apm_requests table and insert data
CREATE TABLE apm_requests_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_token TEXT NOT NULL,
    request_dt TEXT NOT NULL,
    request_method TEXT,
    request_url TEXT,
    total_time REAL,
    peak_memory INTEGER,
    response_code INTEGER,
    response_size INTEGER,
    response_build_time REAL,
    is_bot INTEGER DEFAULT 0,
    ip TEXT,
    user_agent TEXT,
    host TEXT,
    session_id TEXT
);

INSERT INTO apm_requests_new (
    request_token,
    request_dt,
    request_method,
    request_url,
    total_time,
    peak_memory,
    response_code,
    response_size,
    response_build_time,
    is_bot,
    ip,
    user_agent,
    host,
    session_id
)
SELECT 
    request_id,
    timestamp,
    request_method,
    request_url,
    total_time,
    peak_memory,
    response_code,
    response_size,
    response_build_time,
    is_bot,
    ip,
    user_agent,
    host,
    session_id
FROM apm_requests;

-- 2. Populate mapping table
INSERT INTO apm_requests_id_map (old_request_id, new_id)
SELECT request_token, id FROM apm_requests_new;

-- 3. Drop old table and rename new table
DROP TABLE apm_requests;
ALTER TABLE apm_requests_new RENAME TO apm_requests;

-- 4. Update all child tables: Convert request_id from old TEXT token to new INTEGER id using the mapping table
-- This must be done before migrating child tables to INTEGER request_id

-- apm_custom_events
UPDATE apm_custom_events SET request_id = (
  SELECT new_id FROM apm_requests_id_map WHERE old_request_id = apm_custom_events.request_id
) WHERE request_id IN (SELECT old_request_id FROM apm_requests_id_map);

-- apm_routes
UPDATE apm_routes SET request_id = (
  SELECT new_id FROM apm_requests_id_map WHERE old_request_id = apm_routes.request_id
) WHERE request_id IN (SELECT old_request_id FROM apm_requests_id_map);

-- apm_middleware
UPDATE apm_middleware SET request_id = (
  SELECT new_id FROM apm_requests_id_map WHERE old_request_id = apm_middleware.request_id
) WHERE request_id IN (SELECT old_request_id FROM apm_requests_id_map);

-- apm_views
UPDATE apm_views SET request_id = (
  SELECT new_id FROM apm_requests_id_map WHERE old_request_id = apm_views.request_id
) WHERE request_id IN (SELECT old_request_id FROM apm_requests_id_map);

-- apm_db_connections
UPDATE apm_db_connections SET request_id = (
  SELECT new_id FROM apm_requests_id_map WHERE old_request_id = apm_db_connections.request_id
) WHERE request_id IN (SELECT old_request_id FROM apm_requests_id_map);

-- apm_db_queries
UPDATE apm_db_queries SET request_id = (
  SELECT new_id FROM apm_requests_id_map WHERE old_request_id = apm_db_queries.request_id
) WHERE request_id IN (SELECT old_request_id FROM apm_requests_id_map);

-- apm_errors
UPDATE apm_errors SET request_id = (
  SELECT new_id FROM apm_requests_id_map WHERE old_request_id = apm_errors.request_id
) WHERE request_id IN (SELECT old_request_id FROM apm_requests_id_map);

-- apm_cache
UPDATE apm_cache SET request_id = (
  SELECT new_id FROM apm_requests_id_map WHERE old_request_id = apm_cache.request_id
) WHERE request_id IN (SELECT old_request_id FROM apm_requests_id_map);

-- apm_raw_metrics
UPDATE apm_raw_metrics SET request_id = (
  SELECT new_id FROM apm_requests_id_map WHERE old_request_id = apm_raw_metrics.request_id
) WHERE request_id IN (SELECT old_request_id FROM apm_requests_id_map);

-- apm_custom_event_data
UPDATE apm_custom_event_data SET request_id = (
  SELECT new_id FROM apm_requests_id_map WHERE old_request_id = apm_custom_event_data.request_id
) WHERE request_id IN (SELECT old_request_id FROM apm_requests_id_map);

-- Now proceed with child table migrations

-- And now we need to fix custom requests
CREATE TABLE IF NOT EXISTS apm_custom_events (
	id INTEGER PRIMARY KEY AUTOINCREMENT,
	request_id INTEGER NOT NULL,
	event_type TEXT NOT NULL,
	event_data TEXT,
	event_dt TEXT NOT NULL,
	FOREIGN KEY (request_id) REFERENCES apm_requests(id) ON DELETE CASCADE
);

-- Migration: Change timestamp column to event_dt in apm_custom_events
-- 1. Create new table with correct schema
CREATE TABLE apm_custom_events_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id INTEGER NOT NULL,
    event_type TEXT NOT NULL,
    event_data TEXT,
    event_dt TEXT NOT NULL,
    FOREIGN KEY (request_id) REFERENCES apm_requests(id) ON DELETE CASCADE
);

-- 2. Copy data from old table to new table
INSERT INTO apm_custom_events_new (
    request_id,
    event_type,
    event_data,
    event_dt
)
SELECT 
    request_id,
    event_type,
    event_data,
    timestamp
FROM apm_custom_events;

-- 3. Drop old table
DROP TABLE apm_custom_events;

-- 4. Rename new table to original name
ALTER TABLE apm_custom_events_new RENAME TO apm_custom_events;

-- 5. Recreate indexes
CREATE INDEX IF NOT EXISTS idx_apm_custom_events_request_id ON apm_custom_events(request_id);
CREATE INDEX IF NOT EXISTS idx_apm_custom_events_type ON apm_custom_events(event_type);
CREATE INDEX IF NOT EXISTS idx_apm_custom_events_event_dt ON apm_custom_events(event_dt);

--
-- Routes table migration
--
CREATE TABLE apm_routes_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id INTEGER NOT NULL,
    route_pattern TEXT,
    execution_time REAL,
    memory_used INTEGER,
    FOREIGN KEY (request_id) REFERENCES apm_requests(id) ON DELETE CASCADE
);
INSERT INTO apm_routes_new (
    id, request_id, route_pattern, execution_time, memory_used
)
SELECT id, request_id, route_pattern, execution_time, memory_used FROM apm_routes;
DROP TABLE apm_routes;
ALTER TABLE apm_routes_new RENAME TO apm_routes;
CREATE INDEX IF NOT EXISTS idx_apm_routes_request_id ON apm_routes(request_id);
CREATE INDEX IF NOT EXISTS idx_apm_routes_pattern ON apm_routes(route_pattern);

--
-- Middleware table migration
--
CREATE TABLE apm_middleware_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id INTEGER NOT NULL,
    route_pattern TEXT,
    middleware_name TEXT,
    execution_time REAL,
    FOREIGN KEY (request_id) REFERENCES apm_requests(id) ON DELETE CASCADE
);
INSERT INTO apm_middleware_new (
    id, request_id, route_pattern, middleware_name, execution_time
)
SELECT id, request_id, route_pattern, middleware_name, execution_time FROM apm_middleware;
DROP TABLE apm_middleware;
ALTER TABLE apm_middleware_new RENAME TO apm_middleware;
CREATE INDEX IF NOT EXISTS idx_apm_middleware_request_id ON apm_middleware(request_id);
CREATE INDEX IF NOT EXISTS idx_apm_middleware_name ON apm_middleware(middleware_name);

--
-- Views table migration
--
CREATE TABLE apm_views_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id INTEGER NOT NULL,
    view_file TEXT,
    render_time REAL,
    FOREIGN KEY (request_id) REFERENCES apm_requests(id) ON DELETE CASCADE
);
INSERT INTO apm_views_new (
    id, request_id, view_file, render_time
)
SELECT id, request_id, view_file, render_time FROM apm_views;
DROP TABLE apm_views;
ALTER TABLE apm_views_new RENAME TO apm_views;
CREATE INDEX IF NOT EXISTS idx_apm_views_request_id ON apm_views(request_id);
CREATE INDEX IF NOT EXISTS idx_apm_views_file ON apm_views(view_file);

--
-- DB Connections table migration
--
CREATE TABLE apm_db_connections_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id INTEGER NOT NULL,
    engine TEXT,
    host TEXT,
    database_name TEXT,
    FOREIGN KEY (request_id) REFERENCES apm_requests(id) ON DELETE CASCADE
);
INSERT INTO apm_db_connections_new (
    id, request_id, engine, host, database_name
)
SELECT id, request_id, engine, host, database_name FROM apm_db_connections;
DROP TABLE apm_db_connections;
ALTER TABLE apm_db_connections_new RENAME TO apm_db_connections;
CREATE INDEX IF NOT EXISTS idx_apm_db_connections_request_id ON apm_db_connections(request_id);
CREATE INDEX IF NOT EXISTS idx_apm_db_connections_engine ON apm_db_connections(engine);

--
-- DB Queries table migration
--
CREATE TABLE apm_db_queries_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id INTEGER NOT NULL,
    query TEXT,
    params TEXT,
    execution_time REAL,
    row_count INTEGER,
    memory_usage INTEGER,
    FOREIGN KEY (request_id) REFERENCES apm_requests(id) ON DELETE CASCADE
);
INSERT INTO apm_db_queries_new (
    id, request_id, query, params, execution_time, row_count, memory_usage
)
SELECT id, request_id, query, params, execution_time, row_count, memory_usage FROM apm_db_queries;
DROP TABLE apm_db_queries;
ALTER TABLE apm_db_queries_new RENAME TO apm_db_queries;
CREATE INDEX IF NOT EXISTS idx_apm_db_queries_request_id ON apm_db_queries(request_id);
CREATE INDEX IF NOT EXISTS idx_apm_db_queries_execution_time ON apm_db_queries(execution_time);

--
-- Errors table migration
--
CREATE TABLE apm_errors_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id INTEGER NOT NULL,
    error_message TEXT,
    error_code INTEGER,
    error_trace TEXT,
    FOREIGN KEY (request_id) REFERENCES apm_requests(id) ON DELETE CASCADE
);
INSERT INTO apm_errors_new (
    id, request_id, error_message, error_code, error_trace
)
SELECT id, request_id, error_message, error_code, error_trace FROM apm_errors;
DROP TABLE apm_errors;
ALTER TABLE apm_errors_new RENAME TO apm_errors;
CREATE INDEX IF NOT EXISTS idx_apm_errors_request_id ON apm_errors(request_id);
CREATE INDEX IF NOT EXISTS idx_apm_errors_code ON apm_errors(error_code);

--
-- Cache table migration
--
CREATE TABLE apm_cache_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id INTEGER NOT NULL,
    cache_key TEXT,
    hit INTEGER,
    execution_time REAL,
    FOREIGN KEY (request_id) REFERENCES apm_requests(id) ON DELETE CASCADE
);
INSERT INTO apm_cache_new (
    id, request_id, cache_key, hit, execution_time
)
SELECT id, request_id, cache_key, hit, execution_time FROM apm_cache;
DROP TABLE apm_cache;
ALTER TABLE apm_cache_new RENAME TO apm_cache;
CREATE INDEX IF NOT EXISTS idx_apm_cache_request_id ON apm_cache(request_id);
CREATE INDEX IF NOT EXISTS idx_apm_cache_key ON apm_cache(cache_key);
CREATE INDEX IF NOT EXISTS idx_apm_cache_hit ON apm_cache(hit);

--
-- Raw metrics table migration
--
CREATE TABLE apm_raw_metrics_new (
	id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id INTEGER NOT NULL,
    metrics_json TEXT NOT NULL,
    FOREIGN KEY (request_id) REFERENCES apm_requests(id) ON DELETE CASCADE
);
INSERT INTO apm_raw_metrics_new (
    request_id, metrics_json
)
SELECT request_id, metrics_json FROM apm_raw_metrics;
DROP TABLE apm_raw_metrics;
ALTER TABLE apm_raw_metrics_new RENAME TO apm_raw_metrics;
CREATE INDEX IF NOT EXISTS idx_apm_raw_metrics_request_id ON apm_raw_metrics(request_id);

-- Migration for apm_custom_event_data table to use INTEGER request_id
CREATE TABLE apm_custom_event_data_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    custom_event_id INTEGER NOT NULL,
    request_id INTEGER NOT NULL,
    json_key TEXT NOT NULL,
    json_value TEXT,
    FOREIGN KEY (custom_event_id) REFERENCES apm_custom_events(id) ON DELETE CASCADE,
    FOREIGN KEY (request_id) REFERENCES apm_requests(id) ON DELETE CASCADE
);
INSERT INTO apm_custom_event_data_new (
    id, custom_event_id, request_id, json_key, json_value
)
SELECT id, custom_event_id, request_id, json_key, json_value FROM apm_custom_event_data;
DROP TABLE apm_custom_event_data;
ALTER TABLE apm_custom_event_data_new RENAME TO apm_custom_event_data;
CREATE INDEX IF NOT EXISTS idx_apm_custom_event_data_event_id ON apm_custom_event_data(custom_event_id);
CREATE INDEX IF NOT EXISTS idx_apm_custom_event_data_request_id ON apm_custom_event_data(request_id);
CREATE INDEX IF NOT EXISTS idx_apm_custom_event_data_key ON apm_custom_event_data(json_key);

DROP TABLE apm_requests_id_map;

VACUUM;