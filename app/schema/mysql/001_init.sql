CREATE TABLE IF NOT EXISTS settings (
  name VARCHAR(64) NOT NULL PRIMARY KEY,
  value TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(32) NOT NULL UNIQUE,
  display_name VARCHAR(80) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  is_admin TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  must_change_password TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  last_login_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_tokens (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  selector CHAR(24) NOT NULL UNIQUE,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(64) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  attempted_at DATETIME NOT NULL,
  INDEX idx_attempts_user (username, attempted_at),
  INDEX idx_attempts_ip (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS profiles (
  user_id INT UNSIGNED NOT NULL PRIMARY KEY,
  sex VARCHAR(8) NULL,
  birth_year SMALLINT NULL,
  height_cm DECIMAL(5,1) NULL,
  weight_kg DECIMAL(5,1) NULL,
  activity_level VARCHAR(16) NULL,
  goal_type VARCHAR(16) NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS goals (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  valid_from DATE NOT NULL,
  kcal DECIMAL(7,1) NOT NULL,
  protein DECIMAL(6,1) NOT NULL,
  carbs DECIMAL(6,1) NOT NULL,
  fat DECIMAL(6,1) NOT NULL,
  fiber DECIMAL(6,1) NOT NULL DEFAULT 0,
  water_ml INT NOT NULL DEFAULT 0,
  input_mode VARCHAR(8) NOT NULL DEFAULT 'grams',
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_goals_user_date (user_id, valid_from),
  CONSTRAINT fk_goals_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS foods (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  owner_id INT UNSIGNED NULL,
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
  INDEX idx_foods_owner (owner_id),
  INDEX idx_foods_barcode (barcode),
  INDEX idx_foods_name (name),
  CONSTRAINT fk_foods_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recipe_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  recipe_id INT UNSIGNED NOT NULL,
  food_id INT UNSIGNED NOT NULL,
  grams DECIMAL(8,1) NOT NULL,
  CONSTRAINT fk_ri_recipe FOREIGN KEY (recipe_id) REFERENCES foods(id) ON DELETE CASCADE,
  CONSTRAINT fk_ri_food FOREIGN KEY (food_id) REFERENCES foods(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS favorites (
  user_id INT UNSIGNED NOT NULL,
  food_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, food_id),
  CONSTRAINT fk_fav_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_fav_food FOREIGN KEY (food_id) REFERENCES foods(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS entries (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  entry_date DATE NOT NULL,
  eaten_time CHAR(5) NOT NULL,
  meal VARCHAR(12) NOT NULL,
  name VARCHAR(200) NOT NULL,
  food_id INT UNSIGNED NULL,
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
  INDEX idx_entries_user_date (user_id, entry_date),
  CONSTRAINT fk_entries_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_entries_food FOREIGN KEY (food_id) REFERENCES foods(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS water_logs (
  user_id INT UNSIGNED NOT NULL,
  log_date DATE NOT NULL,
  ml INT NOT NULL DEFAULT 0,
  PRIMARY KEY (user_id, log_date),
  CONSTRAINT fk_water_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS weights (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  log_date DATE NOT NULL,
  kg DECIMAL(5,1) NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_weights_user_date (user_id, log_date),
  CONSTRAINT fk_weights_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activities (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  log_date DATE NOT NULL,
  name VARCHAR(120) NOT NULL,
  kcal DECIMAL(7,1) NOT NULL,
  duration_min INT NULL,
  photo VARCHAR(120) NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_act_user_date (user_id, log_date),
  CONSTRAINT fk_act_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
