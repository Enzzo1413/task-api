-- Created automatically on first request (PostgreSQL).
CREATE TABLE IF NOT EXISTS tasks (
  id SERIAL PRIMARY KEY,
  api_key VARCHAR(100) NOT NULL,
  title VARCHAR(150) NOT NULL,
  description TEXT NULL,
  due_date DATE NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','done')),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_tasks_api_key ON tasks (api_key);
