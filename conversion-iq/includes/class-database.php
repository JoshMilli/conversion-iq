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
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY page_id (page_id),
            KEY created_at (created_at)
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
    }

    public static function insert_audit( $page_id, $page_title, $data ) {
        global $wpdb;
        $table = self::get_table_name();
        $wpdb->insert( $table, array(
            'page_id' => $page_id,
            'page_title' => $page_title,
            'data' => wp_json_encode( $data ),
            'created_at' => current_time( 'mysql', 1 ),
        ), array('%d','%s','%s','%s') );
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
}
