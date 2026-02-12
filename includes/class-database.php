<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ConversionIQ_DB {
    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'conversioniq_audits';
    }

    public static function create_tables() {
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
        dbDelta( $sql );
        
        // Add missing columns if they don't exist (for existing installations)
        $columns = $wpdb->get_col( "DESCRIBE $table_name" );
        
        if ( ! in_array( 'page_url', $columns ) ) {
            $wpdb->query( "ALTER TABLE $table_name ADD COLUMN page_url TEXT NULL AFTER page_title" );
        }
        
        if ( ! in_array( 'ai_used', $columns ) ) {
            $wpdb->query( "ALTER TABLE $table_name ADD COLUMN ai_used TINYINT(1) DEFAULT 1 AFTER data" );
        }
        
        if ( ! in_array( 'content_hash', $columns ) ) {
            $wpdb->query( "ALTER TABLE $table_name ADD COLUMN content_hash VARCHAR(64) NULL AFTER ai_used" );
        }
    }

    public static function insert_audit( $page_id, $page_title, $data, $content_hash = null ) {
        global $wpdb;
        $table = self::get_table_name();
        $wpdb->insert( $table, array(
            'page_id' => $page_id,
            'page_title' => $page_title,
            'data' => wp_json_encode( $data ),
            'content_hash' => $content_hash,
            'created_at' => current_time( 'mysql', 1 ),
        ), array('%d','%s','%s','%s','%s') );
        return $wpdb->insert_id;
    }

    public static function get_audits( $limit = 50 ) {
        global $wpdb;
        $table = self::get_table_name();
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table ORDER BY created_at DESC LIMIT %d", $limit ), ARRAY_A );
        foreach ( $rows as &$r ) {
            $r['data'] = json_decode( $r['data'], true );
        }
        return $rows;
    }

    public static function get_audit( $id ) {
        global $wpdb;
        $table = self::get_table_name();
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ), ARRAY_A );
        if ( $row ) {
            $row['data'] = json_decode( $row['data'], true );
        }
        return $row;
    }
    
    /**
     * Get previous audit for a page
     */
    public static function get_previous_audit( $page_id, $before_audit_id = null ) {
        global $wpdb;
        $table = self::get_table_name();
        
        if ( $before_audit_id ) {
            // Get the audit immediately before this one
            $row = $wpdb->get_row( $wpdb->prepare( 
                "SELECT * FROM $table WHERE page_id = %d AND id < %d ORDER BY created_at DESC LIMIT 1", 
                $page_id, 
                $before_audit_id 
            ), ARRAY_A );
        } else {
            // Get the most recent audit for this page
            $row = $wpdb->get_row( $wpdb->prepare( 
                "SELECT * FROM $table WHERE page_id = %d ORDER BY created_at DESC LIMIT 1", 
                $page_id 
            ), ARRAY_A );
        }
        
        if ( $row ) {
            $row['data'] = json_decode( $row['data'], true );
        }
        return $row;
    }
    
    /**
     * Check if content changed between two audits
     */
    public static function has_content_changed( $audit_id ) {
        global $wpdb;
        $table = self::get_table_name();
        
        // Get current audit
        $current = $wpdb->get_row( $wpdb->prepare( "SELECT page_id, content_hash FROM $table WHERE id = %d", $audit_id ), ARRAY_A );
        if ( ! $current || ! $current['content_hash'] ) {
            return null; // Can't determine
        }
        
        // Get previous audit for same page
        $previous = $wpdb->get_row( $wpdb->prepare( 
            "SELECT content_hash FROM $table WHERE page_id = %d AND id < %d AND content_hash IS NOT NULL ORDER BY created_at DESC LIMIT 1", 
            $current['page_id'], 
            $audit_id 
        ), ARRAY_A );
        
        if ( ! $previous ) {
            return null; // No previous audit to compare
        }
        
        return $current['content_hash'] !== $previous['content_hash'];
    }
}
