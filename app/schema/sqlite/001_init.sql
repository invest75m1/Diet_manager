CREATE TABLE IF NOT EXISTS settings (
  name VARCHAR(64) NOT NULL PRIMARY KEY,
  value TEXT NULL
);

CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username VARCHAR(32) NOT NULL UNIQUE,
  display_name VARCHAR(80) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  is_admin TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  must_change_password TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  last_login_at DATETIME NULL
);

CREATE TABLE IF NOT EXISTS auth_tokens (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  selector CHAR(24) NOT NULL UNIQUE,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS login_attempts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username VARCHAR(64) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  attempted_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS profiles (
  user_id INTEGER NOT NULL PRIMARY KEY,
  sex VARCHAR(8) NULL,
  birth_year SMALLINT NULL,
  height_cm DECIMAL(5,1) NULL,
  weight_kg DECIMAL(5,1) NULL,
  activity_level VARCHAR(16) NULL,
  goal_type VARCHAR(16) NULL,
  updated_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS goals (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  valid_from DATE NOT NULL,
  kcal DECIMAL(7,1) NOT NULL,
  protein DECIMAL(6,1) NOT NULL,
  carbs DECIMAL(6,1) NOT NULL,
  fat DECIMAL(6,1) NOT NULL,
  fiber DECIMAL(6,1) NOT NULL DEFAULT 0,
  water_ml INT NOT NULL DEFAULT 0,
  input_mode VARCHAR(8) NOT NULL DEFAULT 'grams',
  created_at DATETIME NOT NULL,
  UNIQUE (user_id, valid_from),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS foods (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  owner_id INTEGER NULL,
  source VARCHAR(12) NOT NULL,
  barcode VARCHAR(32) NULL,
  name VARCHAR(200) NOT NULL,
  brand VARCHAR(120) NULL,
  kcal DECIMAL(7,2) NOT NULL,
  protein DECIMAL(6,2) NOT NULL,
  carbs DECIMAL(6,2) NOT NULL,
  sugar DECIMAL(6,2) NULL,
  fat DECIMAL(6,2) NOT NULL,
  fiber DECIMAL(6,2) NOT NULL DEFAULT 0,
  serving_name VARCHAR(60) NULL,
  serving_g DECIMAL(7,1) NULL,
  is_recipe TINYINT(1) NOT NULL DEFAULT 0,
  recipe_total_g DECIMAL(8,1) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS recipe_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  recipe_id INTEGER NOT NULL,
  food_id INTEGER NOT NULL,
  grams DECIMAL(8,1) NOT NULL,
  FOREIGN KEY (recipe_id) REFERENCES foods(id) ON DELETE CASCADE,
  FOREIGN KEY (food_id) REFERENCES foods(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS favorites (
  user_id INTEGER NOT NULL,
  food_id INTEGER NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, food_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (food_id) REFERENCES foods(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS entries (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  entry_date DATE NOT NULL,
  eaten_time CHAR(5) NOT NULL,
  meal VARCHAR(12) NOT NULL,
  name VARCHAR(200) NOT NULL,
  food_id INTEGER NULL,
  amount_g DECIMAL(8,1) NULL,
  kcal DECIMAL(7,1) NOT NULL,
  protein DECIMAL(6,1) NOT NULL,
  carbs DECIMAL(6,1) NOT NULL,
  fat DECIMAL(6,1) NOT NULL,
  fiber DECIMAL(6,1) NOT NULL DEFAULT 0,
  source VARCHAR(12) NOT NULL,
  photo VARCHAR(120) NULL,
  note VARCHAR(500) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (food_id) REFERENCES foods(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS water_logs (
  user_id INTEGER NOT NULL,
  log_date DATE NOT NULL,
  ml INT NOT NULL DEFAULT 0,
  PRIMARY KEY (user_id, log_date),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS weights (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  log_date DATE NOT NULL,
  kg DECIMAL(5,1) NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE (user_id, log_date),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS activities (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  log_date DATE NOT NULL,
  name VARCHAR(120) NOT NULL,
  kcal DECIMAL(7,1) NOT NULL,
  duration_min INT NULL,
  photo VARCHAR(120) NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_attempts_user ON login_attempts (username, attempted_at);
CREATE INDEX IF NOT EXISTS idx_attempts_ip ON login_attempts (ip, attempted_at);
CREATE INDEX IF NOT EXISTS idx_foods_owner ON foods (owner_id);
CREATE INDEX IF NOT EXISTS idx_foods_barcode ON foods (barcode);
CREATE INDEX IF NOT EXISTS idx_foods_name ON foods (name);
CREATE INDEX IF NOT EXISTS idx_entries_user_date ON entries (user_id, entry_date);
CREATE INDEX IF NOT EXISTS idx_act_user_date ON activities (user_id, log_date);
