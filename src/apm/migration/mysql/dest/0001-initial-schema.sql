-- 0001-initial-schema.sql for MySQL
CREATE TABLE IF NOT EXISTS apm_requests (
	id BIGINT AUTO_INCREMENT PRIMARY KEY,
	request_token VARCHAR(255) NOT NULL,
	request_dt DATETIME NOT NULL,
	request_method VARCHAR(10) NOT NULL,
	request_url VARCHAR(255) NOT NULL,
	total_time FLOAT NOT NULL,
	peak_memory INT NOT NULL,
	response_code INT NOT NULL,
	response_size INT NOT NULL,
	response_build_time FLOAT NOT NULL,
	is_bot TINYINT(1) DEFAULT 0,
	ip TEXT,
	user_agent TEXT,
	host TEXT,
	session_id TEXT,
	INDEX idx_apm_requests_ip (ip),
	INDEX idx_apm_requests_host (host),
	INDEX idx_apm_requests_session_id (session_id),
	INDEX idx_apm_requests_user_agent (user_agent),
	INDEX idx_apm_requests_token (request_token(32))
);

-- Routes table
CREATE TABLE IF NOT EXISTS apm_routes (
	id BIGINT AUTO_INCREMENT PRIMARY KEY,
	request_id BIGINT NOT NULL,
	route_pattern TEXT,
	execution_time FLOAT,
	memory_used BIGINT,
	FOREIGN KEY (request_id) REFERENCES apm_requests(id) ON DELETE CASCADE
);

CREATE INDEX idx_apm_routes_request_id ON apm_routes(request_id);
CREATE INDEX idx_apm_routes_pattern ON apm_routes(route_pattern(255));

-- Middleware table
CREATE TABLE IF NOT EXISTS apm_middleware (
	id BIGINT AUTO_INCREMENT PRIMARY KEY,
	request_id BIGINT NOT NULL,
	route_pattern TEXT,
	middleware_name TEXT,
	execution_time FLOAT,
	FOREIGN KEY (request_id) REFERENCES apm_requests(id) ON DELETE CASCADE
);

CREATE INDEX idx_apm_middleware_request_id ON apm_middleware(request_id);
CREATE INDEX idx_apm_middleware_name ON apm_middleware(middleware_name(255));

-- Views table
CREATE TABLE IF NOT EXISTS apm_views (
	id BIGINT AUTO_INCREMENT PRIMARY KEY,
	request_id BIGINT NOT NULL,
	view_file TEXT,
	render_time FLOAT,
	FOREIGN KEY (request_id) REFERENCES apm_requests(id) ON DELETE CASCADE
);

CREATE INDEX idx_apm_views_request_id ON apm_views(request_id);
CREATE INDEX idx_apm_views_file ON apm_views(view_file(255));

-- DB Connections table
CREATE TABLE IF NOT EXISTS apm_db_connections (
	id BIGINT AUTO_INCREMENT PRIMARY KEY,
	request_id BIGINT NOT NULL,
	engine TEXT,
	host TEXT,
	database_name TEXT,
	FOREIGN KEY (request_id) REFERENCES apm_requests(id) ON DELETE CASCADE
);

CREATE INDEX idx_apm_db_connections_request_id ON apm_db_connections(request_id);
CREATE INDEX idx_apm_db_connections_engine ON apm_db_connections(engine(255));

-- DB Queries table
CREATE TABLE IF NOT EXISTS apm_db_queries (
	id BIGINT AUTO_INCREMENT PRIMARY KEY,
	request_id BIGINT NOT NULL,
	query LONGTEXT,
	params LONGTEXT,
	execution_time FLOAT,
	row_count BIGINT,
	memory_usage BIGINT,
	FOREIGN KEY (request_id) REFERENCES apm_requests(id) ON DELETE CASCADE
);

CREATE INDEX idx_apm_db_queries_request_id ON apm_db_queries(request_id);
CREATE INDEX idx_apm_db_queries_execution_time ON apm_db_queries(execution_time);

-- Errors table
CREATE TABLE IF NOT EXISTS apm_errors (
	id BIGINT AUTO_INCREMENT PRIMARY KEY,
	request_id BIGINT NOT NULL,
	error_message LONGTEXT,
	error_code INT,
	error_trace LONGTEXT,
	FOREIGN KEY (request_id) REFERENCES apm_requests(id) ON DELETE CASCADE
);

CREATE INDEX idx_apm_errors_request_id ON apm_errors(request_id);
CREATE INDEX idx_apm_errors_code ON apm_errors(error_code);

-- Cache operations table
CREATE TABLE IF NOT EXISTS apm_cache (
	id BIGINT AUTO_INCREMENT PRIMARY KEY,
	request_id BIGINT NOT NULL,
	cache_key TEXT,
	hit TINYINT(1),
	execution_time FLOAT,
	FOREIGN KEY (request_id) REFERENCES apm_requests(id) ON DELETE CASCADE
);

CREATE INDEX idx_apm_cache_request_id ON apm_cache(request_id);
CREATE INDEX idx_apm_cache_key ON apm_cache(cache_key(255));
CREATE INDEX idx_apm_cache_hit ON apm_cache(hit);


CREATE TABLE IF NOT EXISTS apm_custom_events (
	id BIGINT AUTO_INCREMENT PRIMARY KEY,
	request_id BIGINT NOT NULL,
	event_type VARCHAR(255) NOT NULL,
	event_data JSON DEFAULT NULL,
	event_dt DATETIME NULL,
	FOREIGN KEY (request_id) REFERENCES apm_requests(id) ON DELETE CASCADE,
	INDEX idx_apm_custom_events_event_type (event_type)
);

CREATE TABLE IF NOT EXISTS apm_custom_event_data (
	id BIGINT AUTO_INCREMENT PRIMARY KEY,
	custom_event_id BIGINT NOT NULL,
	request_id BIGINT NOT NULL,
	json_key TEXT NOT NULL,
	json_value TEXT,
	FOREIGN KEY (custom_event_id) REFERENCES apm_custom_events(id) ON DELETE CASCADE,
	FOREIGN KEY (request_id) REFERENCES apm_requests(id) ON DELETE CASCADE,
	INDEX idx_apm_custom_event_data_event_id (custom_event_id),
	INDEX idx_apm_custom_event_data_request_id (request_id),
	INDEX idx_apm_custom_event_data_key (json_key)
);

CREATE TABLE IF NOT EXISTS apm_raw_metrics (
	id BIGINT AUTO_INCREMENT PRIMARY KEY,
	request_id BIGINT NOT NULL,
	metrics_json TEXT NOT NULL,
	FOREIGN KEY (request_id) REFERENCES apm_requests(id) ON DELETE CASCADE
);
