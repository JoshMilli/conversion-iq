<?php
/**
 * Emergency Database Migration: Add initial_page_visit Column
 * 
 * Run this file directly by visiting:
 * https://yoursite.com/wp-content/plugins/conversion-iq/migrate-add-initial-page-visit.php
 * 
 * This will add the missing initial_page_visit column to both tables.
 */

// Load WordPress
require_once('../../../wp-load.php');

if (!current_user_can('manage_options')) {
    wp_die('Unauthorized');
}

global $wpdb;

echo "<h1>ConversionIQ Database Migration</h1>";
echo "<p>Adding initial_page_visit column...</p>";

$errors = [];
$success = [];

// Add to leads table
$table_leads = $wpdb->prefix . 'conversioniq_leads';
$leads_columns = $wpdb->get_col("DESCRIBE $table_leads");

if (!in_array('initial_page_visit', $leads_columns)) {
    $result = $wpdb->query("ALTER TABLE $table_leads ADD COLUMN initial_page_visit TEXT AFTER page_title");
    if ($result === false) {
        $errors[] = "Failed to add initial_page_visit to leads table: " . $wpdb->last_error;
    } else {
        $success[] = "✓ Added initial_page_visit column to leads table";
    }
} else {
    $success[] = "✓ initial_page_visit already exists in leads table";
}

// Add to visitor_sessions table
$table_sessions = $wpdb->prefix . 'conversioniq_visitor_sessions';
$sessions_columns = $wpdb->get_col("DESCRIBE $table_sessions");

if (!in_array('initial_page_visit', $sessions_columns)) {
    $result = $wpdb->query("ALTER TABLE $table_sessions ADD COLUMN initial_page_visit TEXT AFTER page_url");
    if ($result === false) {
        $errors[] = "Failed to add initial_page_visit to visitor_sessions table: " . $wpdb->last_error;
    } else {
        $success[] = "✓ Added initial_page_visit column to visitor_sessions table";
    }
} else {
    $success[] = "✓ initial_page_visit already exists in visitor_sessions table";
}

// Display results
echo "<h2>Results:</h2>";

if (!empty($success)) {
    echo "<div style='background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 15px; margin: 10px 0;'>";
    foreach ($success as $msg) {
        echo "<p>$msg</p>";
    }
    echo "</div>";
}

if (!empty($errors)) {
    echo "<div style='background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 15px; margin: 10px 0;'>";
    foreach ($errors as $msg) {
        echo "<p>$msg</p>";
    }
    echo "</div>";
} else {
    echo "<h3 style='color: green;'>✓ Migration Complete!</h3>";
    echo "<p>The initial_page_visit column has been successfully added. You can now:</p>";
    echo "<ul>";
    echo "<li>Delete this migration file for security</li>";
    echo "<li>Test webhooks from KnockKnock - they should now save visitor data correctly</li>";
    echo "<li>Check your Growth Machine tab for new leads and visitors</li>";
    echo "</ul>";
}

echo "<hr>";
echo "<p><a href='/wp-admin/admin.php?page=conversion-iq-dashboard'>← Back to Dashboard</a></p>";
