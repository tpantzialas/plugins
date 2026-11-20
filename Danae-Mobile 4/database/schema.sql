CREATE TABLE IF NOT EXISTS dm_users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY username_unique (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dm_sites (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  site_fingerprint CHAR(64) NOT NULL,
  site_name VARCHAR(190) NOT NULL,
  site_url VARCHAR(500) NOT NULL,
  api_url VARCHAR(700) NOT NULL,
  token_ciphertext MEDIUMTEXT NOT NULL,
  callback_secret_ciphertext MEDIUMTEXT NULL,
  plugin_version VARCHAR(32) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL,
  last_seen_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY user_site_unique (user_id, site_fingerprint),
  KEY user_status (user_id, status),
  CONSTRAINT dm_sites_user_fk FOREIGN KEY (user_id) REFERENCES dm_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dm_push_subscriptions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  endpoint_hash CHAR(64) NOT NULL,
  endpoint_ciphertext MEDIUMTEXT NOT NULL,
  p256dh VARCHAR(100) NOT NULL,
  auth_ciphertext MEDIUMTEXT NOT NULL,
  created_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY endpoint_unique (endpoint_hash),
  KEY user_idx (user_id),
  CONSTRAINT dm_push_user_fk FOREIGN KEY (user_id) REFERENCES dm_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dm_order_events (
  site_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (site_id, order_id),
  KEY created_idx (created_at),
  CONSTRAINT dm_order_site_fk FOREIGN KEY (site_id) REFERENCES dm_sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dm_push_keys (
  id TINYINT UNSIGNED NOT NULL,
  public_key VARCHAR(100) NOT NULL,
  private_key_ciphertext MEDIUMTEXT NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dm_pairings (
  code_hash CHAR(64) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  PRIMARY KEY (code_hash),
  KEY expires_at (expires_at),
  CONSTRAINT dm_pairings_user_fk FOREIGN KEY (user_id) REFERENCES dm_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dm_audit_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  site_id BIGINT UNSIGNED NULL,
  action VARCHAR(64) NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY user_created (user_id, created_at),
  CONSTRAINT dm_audit_user_fk FOREIGN KEY (user_id) REFERENCES dm_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dm_rate_limits (
  rate_key CHAR(64) NOT NULL,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  window_started_at DATETIME NOT NULL,
  PRIMARY KEY (rate_key),
  KEY window_started_at (window_started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
