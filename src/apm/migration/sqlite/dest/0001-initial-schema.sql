-- Main requests table
CREATE TABLE IF NOT EXISTS apm_requests (
	request_id TEXT PRIMARY KEY,
	timestamp TEXT NOT NULL,
	request_method TEXT,
	request_url TEXT,
	total_time REAL,
	peak_memory INTEGER,
	response_code INTEGER,
	response_size INTEGER,
	response_build_time REAL
);

-- Create indexes for the main table
CREATE INDEX IF NOT EXISTS idx_apm_requests_timestamp ON apm_requests(timestamp);
CREATE INDEX IF NOT EXISTS idx_apm_requests_url ON apm_requests(request_url);
CREATE INDEX IF NOT EXISTS idx_apm_requests_response_code ON apm_requests(response_code);
CREATE INDEX IF NOT EXISTS idx_apm_requests_composite ON apm_requests(timestamp, response_code, request_method);

-- Routes table
CREATE TABLE IF NOT EXISTS apm_routes (
	id INTEGER PRIMARY KEY AUTOINCREMENT,
	request_id TEXT NOT NULL,
	route_pattern TEXT,
	execution_time REAL,
	memory_used INTEGER,
	FOREIGN KEY (request_id) REFERENCES apm_requests(request_id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_apm_routes_request_id ON apm_routes(request_id);
CREATE INDEX IF NOT EXISTS idx_apm_routes_pattern ON apm_routes(route_pattern);

-- Middleware table
CREATE TABLE IF NOT EXISTS apm_middleware (
	id INTEGER PRIMARY KEY AUTOINCREMENT,
	request_id TEXT NOT NULL,
	route_pattern TEXT,
	middleware_name TEXT,
	execution_time REAL,
	FOREIGN KEY (request_id) REFERENCES apm_requests(request_id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_apm_middleware_request_id ON apm_middleware(request_id);
CREATE INDEX IF NOT EXISTS idx_apm_middleware_name ON apm_middleware(middleware_name);

-- Views table
CREATE TABLE IF NOT EXISTS apm_views (
	id INTEGER PRIMARY KEY AUTOINCREMENT,
	request_id TEXT NOT NULL,
	view_file TEXT,
	render_time REAL,
	FOREIGN KEY (request_id) REFERENCES apm_requests(request_id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_apm_views_request_id ON apm_views(request_id);
CREATE INDEX IF NOT EXISTS idx_apm_views_file ON apm_views(view_file);

-- DB Connections table
CREATE TABLE IF NOT EXISTS apm_db_connections (
	id INTEGER PRIMARY KEY AUTOINCREMENT,
	request_id TEXT NOT NULL,
	engine TEXT,
	host TEXT,
	database_name TEXT,
	FOREIGN KEY (request_id) REFERENCES apm_requests(request_id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_apm_db_connections_request_id ON apm_db_connections(request_id);
CREATE INDEX IF NOT EXISTS idx_apm_db_connections_engine ON apm_db_connections(engine);

-- DB Queries table
CREATE TABLE IF NOT EXISTS apm_db_queries (
	id INTEGER PRIMARY KEY AUTOINCREMENT,
	request_id TEXT NOT NULL,
	query TEXT,
	params TEXT,
	execution_time REAL,
	row_count INTEGER,
	memory_usage INTEGER,
	FOREIGN KEY (request_id) REFERENCES apm_requests(request_id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_apm_db_queries_request_id ON apm_db_queries(request_id);
CREATE INDEX IF NOT EXISTS idx_apm_db_queries_execution_time ON apm_db_queries(execution_time);

-- Errors table
CREATE TABLE IF NOT EXISTS apm_errors (
	id INTEGER PRIMARY KEY AUTOINCREMENT,
	request_id TEXT NOT NULL,
	error_message TEXT,
	error_code INTEGER,
	error_trace TEXT,
	FOREIGN KEY (request_id) REFERENCES apm_requests(request_id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_apm_errors_request_id ON apm_errors(request_id);
CREATE INDEX IF NOT EXISTS idx_apm_errors_code ON apm_errors(error_code);

-- Cache operations table
CREATE TABLE IF NOT EXISTS apm_cache (
	id INTEGER PRIMARY KEY AUTOINCREMENT,
	request_id TEXT NOT NULL,
	cache_key TEXT,
	hit INTEGER,
	execution_time REAL,
	FOREIGN KEY (request_id) REFERENCES apm_requests(request_id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_apm_cache_request_id ON apm_cache(request_id);
CREATE INDEX IF NOT EXISTS idx_apm_cache_key ON apm_cache(cache_key);
CREATE INDEX IF NOT EXISTS idx_apm_cache_hit ON apm_cache(hit);

-- Custom events table
CREATE TABLE IF NOT EXISTS apm_custom_events (
	id INTEGER PRIMARY KEY AUTOINCREMENT,
	request_id TEXT NOT NULL,
	event_type TEXT NOT NULL,
	event_data TEXT,
	timestamp TEXT NOT NULL,
	FOREIGN KEY (request_id) REFERENCES apm_requests(request_id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_apm_custom_events_request_id ON apm_custom_events(request_id);
CREATE INDEX IF NOT EXISTS idx_apm_custom_events_type ON apm_custom_events(event_type);
CREATE INDEX IF NOT EXISTS idx_apm_custom_events_timestamp ON apm_custom_events(timestamp);

-- Raw metrics table for data not covered by the schema
CREATE TABLE IF NOT EXISTS apm_raw_metrics (
	request_id TEXT PRIMARY KEY,
	metrics_json TEXT NOT NULL,
	FOREIGN KEY (request_id) REFERENCES apm_requests(request_id) ON DELETE CASCADE
);