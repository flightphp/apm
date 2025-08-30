CREATE TABLE IF NOT EXISTS apm_metrics_log (
	id INT AUTO_INCREMENT PRIMARY KEY,
	added_dt DATETIME DEFAULT CURRENT_TIMESTAMP,
	metrics_json TEXT NOT NULL,
	INDEX idx_added_dt (added_dt)
);
