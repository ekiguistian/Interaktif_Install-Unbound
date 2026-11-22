# Additive database changes shared by installation and updates.
web_apply_schema() {
    local schema="$1" MYSQL_DB="$2" column definition exists
    [[ "$MYSQL_DB" =~ ^[A-Za-z0-9_]{1,64}$ ]] || die 'Nama database tidak valid.'
    case "${MYSQL_DB,,}" in mysql|sys|information_schema|performance_schema) die 'Gunakan database khusus aplikasi.' ;; esac
    mysql --protocol=socket --user=root "$MYSQL_DB" < "$schema"
    while IFS='|' read -r column definition; do
        [[ -n "$column" ]] || continue
        exists="$(mysql --protocol=socket --user=root --batch --skip-column-names "$MYSQL_DB" -e \
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$MYSQL_DB' AND TABLE_NAME='metrics' AND COLUMN_NAME='$column'")"
        if [[ "$exists" == 0 ]]; then
            mysql --protocol=socket --user=root "$MYSQL_DB" -e "ALTER TABLE metrics ADD COLUMN $column $definition"
        fi
    done <<'METRIC_COLUMNS'
query_types_json|LONGTEXT NULL
response_codes_json|LONGTEXT NULL
recursion_avg_ms|DECIMAL(14,4) NOT NULL DEFAULT 0
cpu_percent|DECIMAL(7,3) NOT NULL DEFAULT 0
load_1m|DECIMAL(9,3) NOT NULL DEFAULT 0
load_5m|DECIMAL(9,3) NOT NULL DEFAULT 0
load_15m|DECIMAL(9,3) NOT NULL DEFAULT 0
memory_used_mb|BIGINT UNSIGNED NOT NULL DEFAULT 0
memory_total_mb|BIGINT UNSIGNED NOT NULL DEFAULT 0
disk_used_gb|DECIMAL(12,3) NOT NULL DEFAULT 0
disk_total_gb|DECIMAL(12,3) NOT NULL DEFAULT 0
uptime_seconds|BIGINT UNSIGNED NOT NULL DEFAULT 0
METRIC_COLUMNS
}
