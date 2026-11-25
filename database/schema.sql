CREATE TABLE IF NOT EXISTS metrics (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    recorded_at DATETIME NOT NULL,
    total_queries BIGINT UNSIGNED NOT NULL DEFAULT 0,
    total_replies BIGINT UNSIGNED NOT NULL DEFAULT 0,
    cache_hits BIGINT UNSIGNED NOT NULL DEFAULT 0,
    cache_hit_ratio DECIMAL(7,4) NOT NULL DEFAULT 0,
    qps DECIMAL(14,4) NOT NULL DEFAULT 0,
    rx_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    tx_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    query_types_json LONGTEXT NULL,
    response_codes_json LONGTEXT NULL,
    recursion_avg_ms DECIMAL(14,4) NOT NULL DEFAULT 0,
    cpu_percent DECIMAL(7,3) NOT NULL DEFAULT 0,
    load_1m DECIMAL(9,3) NOT NULL DEFAULT 0,
    load_5m DECIMAL(9,3) NOT NULL DEFAULT 0,
    load_15m DECIMAL(9,3) NOT NULL DEFAULT 0,
    memory_used_mb BIGINT UNSIGNED NOT NULL DEFAULT 0,
    memory_total_mb BIGINT UNSIGNED NOT NULL DEFAULT 0,
    disk_used_gb DECIMAL(12,3) NOT NULL DEFAULT 0,
    disk_total_gb DECIMAL(12,3) NOT NULL DEFAULT 0,
    sample_json JSON NULL,
    uptime_seconds BIGINT UNSIGNED NOT NULL DEFAULT 0,
    INDEX idx_metrics_recorded_at (recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


CREATE TABLE IF NOT EXISTS blocklists (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    url VARCHAR(2048) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    domain_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_updated DATETIME NULL,
    last_error VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_blocklists_url (url(768))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS whitelists (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    domain VARCHAR(253) NOT NULL,
    note VARCHAR(255) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_whitelists_domain (domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS test_domains (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    domain VARCHAR(253) NOT NULL,
    query_type ENUM('A','AAAA','MX','TXT','NS','CNAME') NOT NULL DEFAULT 'A',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_test_domains_domain_type (domain, query_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS unbound_config (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    content MEDIUMTEXT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(80) NOT NULL PRIMARY KEY,
    setting_value VARCHAR(2048) NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    remote_addr VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempts_addr_time (remote_addr, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS app_releases (
    commit_hash CHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    version VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    checked_at DATETIME NULL,
    installed_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memdifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian. sesuai aturan opensource
