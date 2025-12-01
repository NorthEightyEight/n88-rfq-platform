<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class N88_RFQ_Installer {

    public static function activate() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $projects_table    = $wpdb->prefix . 'projects';
        $meta_table        = $wpdb->prefix . 'project_metadata';
        $comments_table    = $wpdb->prefix . 'project_comments';
        $quotes_table      = $wpdb->prefix . 'project_quotes';
        $notifications_table = $wpdb->prefix . 'project_notifications';
        $audit_table       = $wpdb->prefix . 'project_audit';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql_projects = "CREATE TABLE {$projects_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            project_name VARCHAR(255) NOT NULL,
            project_type VARCHAR(100) NOT NULL DEFAULT '',
            timeline VARCHAR(100) NOT NULL DEFAULT '',
            budget_range VARCHAR(100) NOT NULL DEFAULT '',
            status TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            submitted_at DATETIME NULL,
            updated_by BIGINT UNSIGNED NULL,
            quote_type VARCHAR(100) NULL,
            item_count INT UNSIGNED DEFAULT 0,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY status (status),
            KEY updated_by (updated_by)
        ) {$charset_collate};";

        $sql_meta = "CREATE TABLE {$meta_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id BIGINT UNSIGNED NOT NULL,
            meta_key VARCHAR(255) NOT NULL,
            meta_value LONGTEXT NULL,
            PRIMARY KEY  (id),
            KEY project_id (project_id),
            KEY meta_key (meta_key)
        ) {$charset_collate};";

        $sql_comments = "CREATE TABLE {$comments_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id BIGINT UNSIGNED NOT NULL,
            item_id VARCHAR(255) NULL,
            video_id VARCHAR(255) NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            comment_text LONGTEXT NOT NULL,
            is_urgent TINYINT(1) NOT NULL DEFAULT 0,
            parent_comment_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY project_id (project_id),
            KEY item_id (item_id),
            KEY video_id (video_id),
            KEY user_id (user_id),
            KEY is_urgent (is_urgent),
            KEY parent_comment_id (parent_comment_id),
            KEY created_at (created_at)
        ) {$charset_collate};";

        $sql_quotes = "CREATE TABLE {$quotes_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            quote_file_path VARCHAR(500) NULL,
            admin_notes LONGTEXT NULL,
            quote_status VARCHAR(50) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            sent_at DATETIME NULL,
            labor_cost DECIMAL(10,2) NULL DEFAULT 0.00,
            materials_cost DECIMAL(10,2) NULL DEFAULT 0.00,
            overhead_cost DECIMAL(10,2) NULL DEFAULT 0.00,
            margin_percentage DECIMAL(5,2) NULL DEFAULT 0.00,
            shipping_zone VARCHAR(100) NULL,
            unit_price DECIMAL(10,2) NULL DEFAULT 0.00,
            total_price DECIMAL(10,2) NULL DEFAULT 0.00,
            lead_time VARCHAR(50) NULL,
            cbm_volume DECIMAL(10,4) NULL DEFAULT 0.0000,
            volume_rules_applied TEXT NULL,
            client_message LONGTEXT NULL,
            quote_items LONGTEXT NULL,
            PRIMARY KEY  (id),
            KEY project_id (project_id),
            KEY user_id (user_id),
            KEY quote_status (quote_status)
        ) {$charset_collate};";

        $sql_notifications = "CREATE TABLE {$notifications_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            notification_type VARCHAR(100) NOT NULL,
            message LONGTEXT NOT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY project_id (project_id),
            KEY user_id (user_id),
            KEY notification_type (notification_type),
            KEY is_read (is_read)
        ) {$charset_collate};";

        $sql_audit = "CREATE TABLE {$audit_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(100) NOT NULL,
            field_name VARCHAR(255) NULL,
            old_value LONGTEXT NULL,
            new_value LONGTEXT NULL,
            ip_address VARCHAR(100) NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY project_id (project_id),
            KEY user_id (user_id),
            KEY action (action),
            KEY created_at (created_at)
        ) {$charset_collate};";

        dbDelta( $sql_projects );
        dbDelta( $sql_meta );
        dbDelta( $sql_comments );
        dbDelta( $sql_quotes );
        dbDelta( $sql_notifications );
        dbDelta( $sql_audit );

        self::maybe_upgrade();
    }

    /**
     * Ensure any new columns are added when the plugin is updated without reactivation.
     */
    public static function maybe_upgrade() {
        static $did_upgrade = false;

        if ( $did_upgrade ) {
            return;
        }

        $did_upgrade = true;

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $projects_table     = $wpdb->prefix . 'projects';
        $comments_table     = $wpdb->prefix . 'project_comments';
        $quotes_table       = $wpdb->prefix . 'project_quotes';
        $meta_table         = $wpdb->prefix . 'project_metadata';
        $notifications_table = $wpdb->prefix . 'project_notifications';
        $audit_table        = $wpdb->prefix . 'project_audit';
        $charset_collate    = $wpdb->get_charset_collate();

        // Ensure core tables exist (handles upgrades where plugin wasn't reactivated)
        $table_schemas = array(
            $projects_table => "CREATE TABLE {$projects_table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                project_name VARCHAR(255) NOT NULL,
                project_type VARCHAR(100) NOT NULL DEFAULT '',
                timeline VARCHAR(100) NOT NULL DEFAULT '',
                budget_range VARCHAR(100) NOT NULL DEFAULT '',
                status TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                submitted_at DATETIME NULL,
                updated_by BIGINT UNSIGNED NULL,
                quote_type VARCHAR(100) NULL,
                item_count INT UNSIGNED DEFAULT 0,
                PRIMARY KEY  (id),
                KEY user_id (user_id),
                KEY status (status),
                KEY updated_by (updated_by)
            ) {$charset_collate};",
            $meta_table => "CREATE TABLE {$meta_table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                project_id BIGINT UNSIGNED NOT NULL,
                meta_key VARCHAR(255) NOT NULL,
                meta_value LONGTEXT NULL,
                PRIMARY KEY  (id),
                KEY project_id (project_id),
                KEY meta_key (meta_key)
            ) {$charset_collate};",
            $comments_table => "CREATE TABLE {$comments_table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                project_id BIGINT UNSIGNED NOT NULL,
                item_id VARCHAR(255) NULL,
                video_id VARCHAR(255) NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                comment_text LONGTEXT NOT NULL,
                is_urgent TINYINT(1) NOT NULL DEFAULT 0,
                parent_comment_id BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY project_id (project_id),
                KEY item_id (item_id),
                KEY video_id (video_id),
                KEY user_id (user_id),
                KEY is_urgent (is_urgent),
                KEY parent_comment_id (parent_comment_id),
                KEY created_at (created_at)
            ) {$charset_collate};",
            $quotes_table => "CREATE TABLE {$quotes_table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                project_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                quote_file_path VARCHAR(500) NULL,
                admin_notes LONGTEXT NULL,
                quote_status VARCHAR(50) NOT NULL DEFAULT 'pending',
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                sent_at DATETIME NULL,
                labor_cost DECIMAL(10,2) NULL DEFAULT 0.00,
                materials_cost DECIMAL(10,2) NULL DEFAULT 0.00,
                overhead_cost DECIMAL(10,2) NULL DEFAULT 0.00,
                margin_percentage DECIMAL(5,2) NULL DEFAULT 0.00,
                shipping_zone VARCHAR(100) NULL,
                unit_price DECIMAL(10,2) NULL DEFAULT 0.00,
                total_price DECIMAL(10,2) NULL DEFAULT 0.00,
                lead_time VARCHAR(50) NULL,
                cbm_volume DECIMAL(10,4) NULL DEFAULT 0.0000,
                volume_rules_applied TEXT NULL,
                client_message LONGTEXT NULL,
                quote_items LONGTEXT NULL,
                PRIMARY KEY  (id),
                KEY project_id (project_id),
                KEY user_id (user_id),
                KEY quote_status (quote_status)
            ) {$charset_collate};",
            $notifications_table => "CREATE TABLE {$notifications_table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                project_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                notification_type VARCHAR(100) NOT NULL,
                message LONGTEXT NOT NULL,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY project_id (project_id),
                KEY user_id (user_id),
                KEY notification_type (notification_type),
                KEY is_read (is_read)
            ) {$charset_collate};",
            $audit_table => "CREATE TABLE {$audit_table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                project_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                action VARCHAR(100) NOT NULL,
                field_name VARCHAR(255) NULL,
                old_value LONGTEXT NULL,
                new_value LONGTEXT NULL,
                ip_address VARCHAR(100) NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY project_id (project_id),
                KEY user_id (user_id),
                KEY action (action),
                KEY created_at (created_at)
            ) {$charset_collate};",
        );

        foreach ( $table_schemas as $table_name => $schema ) {
            if ( ! self::table_exists( $table_name ) ) {
                dbDelta( $schema );
            }
        }

        // Comments table upgrades
        if ( self::table_exists( $comments_table ) ) {
            $comments_columns = $wpdb->get_col( "DESCRIBE {$comments_table}" );

            if ( ! in_array( 'video_id', $comments_columns, true ) ) {
                $wpdb->query( "ALTER TABLE {$comments_table} ADD COLUMN video_id VARCHAR(255) NULL AFTER item_id" );
                $wpdb->query( "ALTER TABLE {$comments_table} ADD KEY video_id (video_id)" );
            }

            if ( ! in_array( 'is_urgent', $comments_columns, true ) ) {
                $wpdb->query( "ALTER TABLE {$comments_table} ADD COLUMN is_urgent TINYINT(1) NOT NULL DEFAULT 0 AFTER comment_text" );
                $wpdb->query( "ALTER TABLE {$comments_table} ADD KEY is_urgent (is_urgent)" );
            }

            if ( ! in_array( 'parent_comment_id', $comments_columns, true ) ) {
                $wpdb->query( "ALTER TABLE {$comments_table} ADD COLUMN parent_comment_id BIGINT UNSIGNED NULL AFTER is_urgent" );
                $wpdb->query( "ALTER TABLE {$comments_table} ADD KEY parent_comment_id (parent_comment_id)" );
            }
        }

        // Projects table upgrades
        if ( self::table_exists( $projects_table ) ) {
            $projects_columns = $wpdb->get_col( "DESCRIBE {$projects_table}" );

            if ( ! in_array( 'item_count', $projects_columns, true ) ) {
                $wpdb->query( "ALTER TABLE {$projects_table} ADD COLUMN item_count INT UNSIGNED DEFAULT 0 AFTER quote_type" );
            }
        }

        // Quotes table upgrades
        if ( self::table_exists( $quotes_table ) ) {
            $quotes_columns = $wpdb->get_col( "DESCRIBE {$quotes_table}" );
            $pricing_columns = array(
                'labor_cost'          => "ALTER TABLE {$quotes_table} ADD COLUMN labor_cost DECIMAL(10,2) NULL DEFAULT 0.00 AFTER sent_at",
                'materials_cost'      => "ALTER TABLE {$quotes_table} ADD COLUMN materials_cost DECIMAL(10,2) NULL DEFAULT 0.00 AFTER labor_cost",
                'overhead_cost'       => "ALTER TABLE {$quotes_table} ADD COLUMN overhead_cost DECIMAL(10,2) NULL DEFAULT 0.00 AFTER materials_cost",
                'margin_percentage'   => "ALTER TABLE {$quotes_table} ADD COLUMN margin_percentage DECIMAL(5,2) NULL DEFAULT 0.00 AFTER overhead_cost",
                'shipping_zone'       => "ALTER TABLE {$quotes_table} ADD COLUMN shipping_zone VARCHAR(100) NULL AFTER margin_percentage",
                'unit_price'          => "ALTER TABLE {$quotes_table} ADD COLUMN unit_price DECIMAL(10,2) NULL DEFAULT 0.00 AFTER shipping_zone",
                'total_price'         => "ALTER TABLE {$quotes_table} ADD COLUMN total_price DECIMAL(10,2) NULL DEFAULT 0.00 AFTER unit_price",
                'lead_time'           => "ALTER TABLE {$quotes_table} ADD COLUMN lead_time VARCHAR(50) NULL AFTER total_price",
                'cbm_volume'          => "ALTER TABLE {$quotes_table} ADD COLUMN cbm_volume DECIMAL(10,4) NULL DEFAULT 0.0000 AFTER lead_time",
                'volume_rules_applied'=> "ALTER TABLE {$quotes_table} ADD COLUMN volume_rules_applied TEXT NULL AFTER cbm_volume",
            );

            foreach ( $pricing_columns as $column => $sql ) {
                if ( ! in_array( $column, $quotes_columns, true ) ) {
                    $wpdb->query( $sql );
                }
            }

            // Add client_message and quote_items columns
            if ( ! in_array( 'client_message', $quotes_columns, true ) ) {
                $wpdb->query( "ALTER TABLE {$quotes_table} ADD COLUMN client_message LONGTEXT NULL AFTER volume_rules_applied" );
            }
            if ( ! in_array( 'quote_items', $quotes_columns, true ) ) {
                $wpdb->query( "ALTER TABLE {$quotes_table} ADD COLUMN quote_items LONGTEXT NULL AFTER client_message" );
            }
        }
    }

    /**
     * Check if a table exists before attempting schema changes.
     */
    private static function table_exists( $table_name ) {
        global $wpdb;
        $like = $wpdb->esc_like( $table_name );
        $result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );

        return $result === $table_name;
    }
}