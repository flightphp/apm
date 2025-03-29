-- Add request metadata columns to apm_requests
ALTER TABLE apm_requests ADD COLUMN ip TEXT;
ALTER TABLE apm_requests ADD COLUMN user_agent TEXT;
ALTER TABLE apm_requests ADD COLUMN host TEXT;
ALTER TABLE apm_requests ADD COLUMN session_id TEXT;

-- Create indexes for the new columns
CREATE INDEX IF NOT EXISTS idx_apm_requests_ip ON apm_requests(ip);
CREATE INDEX IF NOT EXISTS idx_apm_requests_host ON apm_requests(host);
CREATE INDEX IF NOT EXISTS idx_apm_requests_session_id ON apm_requests(session_id);
-- Index on user_agent can help with bot filtering
CREATE INDEX IF NOT EXISTS idx_apm_requests_user_agent ON apm_requests(user_agent);
