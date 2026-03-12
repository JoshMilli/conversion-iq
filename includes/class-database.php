<?php
if (!defined('ABSPATH')) {
    exit;
}

class ConversionIQ_DB
{
    public static function get_table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'conversioniq_audits';
    }

    public static function create_tables()
    {
        global $wpdb;
        $table_name = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            page_id BIGINT(20) NULL,
            page_title TEXT NULL,
            page_url TEXT NULL,
            data LONGTEXT NOT NULL,
            ai_used TINYINT(1) DEFAULT 1,
            content_hash VARCHAR(64) NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY page_id (page_id),
            KEY created_at (created_at),
            KEY content_hash (content_hash)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Add missing columns if they don't exist (for existing installations)
        $columns = $wpdb->get_col("DESCRIBE $table_name");

        if (!in_array('page_url', $columns)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN page_url TEXT NULL AFTER page_title");
        }

        if (!in_array('ai_used', $columns)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN ai_used TINYINT(1) DEFAULT 1 AFTER data");
        }

        if (!in_array('content_hash', $columns)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN content_hash VARCHAR(64) NULL AFTER ai_used");
        }

        // Create KnockKnock tables
        self::create_knockknock_tables();
    }

    /**
     * Create KnockKnock webhook tables
     */
    public static function create_knockknock_tables()
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // Webhook logs table
        $table_logs = $wpdb->prefix . 'conversioniq_webhook_logs';
        $sql_logs = "CREATE TABLE IF NOT EXISTS $table_logs (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            event_type VARCHAR(50) NOT NULL,
            webhook_id VARCHAR(100) NOT NULL,
            company_id VARCHAR(100) NOT NULL,
            raw_payload LONGTEXT NOT NULL,
            verified TINYINT(1) DEFAULT 1,
            timestamp DATETIME NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY event_type (event_type),
            KEY company_id (company_id),
            KEY timestamp (timestamp)
        ) $charset_collate;";

        // Leads table
        $table_leads = $wpdb->prefix . 'conversioniq_leads';
        $sql_leads = "CREATE TABLE IF NOT EXISTS $table_leads (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            webhook_log_id BIGINT(20) UNSIGNED NOT NULL,
            first_name VARCHAR(100),
            last_name VARCHAR(100),
            email VARCHAR(255) NOT NULL,
            phone VARCHAR(50),
            page_url TEXT NOT NULL,
            page_title VARCHAR(255),
            initial_page_visit TEXT,
            user_session_id VARCHAR(100),
            converted_at DATETIME NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY webhook_log_id (webhook_log_id),
            KEY email (email),
            KEY page_url (page_url(255)),
            KEY converted_at (converted_at)
        ) $charset_collate;";

        // Visitor sessions table
        $table_sessions = $wpdb->prefix . 'conversioniq_visitor_sessions';
        $sql_sessions = "CREATE TABLE IF NOT EXISTS $table_sessions (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            webhook_log_id BIGINT(20) UNSIGNED NOT NULL,
            user_session_id VARCHAR(100) NOT NULL,
            first_name VARCHAR(100),
            last_name VARCHAR(100),
            email VARCHAR(255),
            page_url TEXT NOT NULL,
            initial_page_visit TEXT,
            identified_at DATETIME NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_session_id (user_session_id),
            KEY page_url (page_url(255))
        ) $charset_collate;";

        // Page analytics table
        $table_analytics = $wpdb->prefix . 'conversioniq_page_analytics';
        $sql_analytics = "CREATE TABLE IF NOT EXISTS $table_analytics (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            page_url TEXT NOT NULL,
            total_visitors INT(11) DEFAULT 0,
            identified_visitors INT(11) DEFAULT 0,
            total_leads INT(11) DEFAULT 0,
            conversion_rate DECIMAL(5,2) DEFAULT 0.00,
            last_updated DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY page_url (page_url(255))
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_logs);
        dbDelta($sql_leads);
        dbDelta($sql_sessions);
        dbDelta($sql_analytics);
    }

    public static function insert_audit($page_id, $page_title, $data, $content_hash = null)
    {
        global $wpdb;
        $table = self::get_table_name();
        $wpdb->insert($table, array(
            'page_id' => $page_id,
            'page_title' => $page_title,
            'data' => wp_json_encode($data),
            'content_hash' => $content_hash,
            'created_at' => current_time('mysql', 1),
        ), array('%d', '%s', '%s', '%s', '%s'));
        return $wpdb->insert_id;
    }

    public static function get_audits($limit = 50)
    {
        global $wpdb;
        $table = self::get_table_name();
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table ORDER BY created_at DESC LIMIT %d", $limit), ARRAY_A);
        foreach ($rows as &$r) {
            $r['data'] = json_decode($r['data'], true);
        }
        return $rows;
    }

    public static function get_audit($id)
    {
        global $wpdb;
        $table = self::get_table_name();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id), ARRAY_A);
        if ($row) {
            $row['data'] = json_decode($row['data'], true);
        }
        return $row;
    }

    /**
     * Get previous audit for a page
     */
    public static function get_previous_audit($page_id, $before_audit_id = null)
    {
        global $wpdb;
        $table = self::get_table_name();

        if ($before_audit_id) {
            // Get the audit immediately before this one
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table WHERE page_id = %d AND id < %d ORDER BY created_at DESC LIMIT 1",
                $page_id,
                $before_audit_id
            ), ARRAY_A);
        }
        else {
            // Get the most recent audit for this page
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table WHERE page_id = %d ORDER BY created_at DESC LIMIT 1",
                $page_id
            ), ARRAY_A);
        }

        if ($row) {
            $row['data'] = json_decode($row['data'], true);
        }
        return $row;
    }

    /**
     * Check if content changed between two audits
     */
    public static function has_content_changed($audit_id)
    {
        global $wpdb;
        $table = self::get_table_name();

        // Get current audit
        $current = $wpdb->get_row($wpdb->prepare("SELECT page_id, content_hash FROM $table WHERE id = %d", $audit_id), ARRAY_A);
        if (!$current || !$current['content_hash']) {
            return null; // Can't determine
        }

        // Get previous audit for same page
        $previous = $wpdb->get_row($wpdb->prepare(
            "SELECT content_hash FROM $table WHERE page_id = %d AND id < %d AND content_hash IS NOT NULL ORDER BY created_at DESC LIMIT 1",
            $current['page_id'],
            $audit_id
        ), ARRAY_A);

        if (!$previous) {
            return null; // No previous audit to compare
        }

        return $current['content_hash'] !== $previous['content_hash'];
    }
}
