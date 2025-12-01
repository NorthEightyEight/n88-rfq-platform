<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class N88_RFQ_Audit {

    /**
     * Log an action in the audit trail
     *
     * @param int $project_id Project ID
     * @param int $user_id User ID performing the action
     * @param string $action Action type
     * @param string $field_name Field being changed (optional)
     * @param string $old_value Old value (optional)
     * @param string $new_value New value (optional)
     * @return int|false Audit ID on success
     */
    public static function log_action( $project_id, $user_id, $action, $field_name = '', $old_value = '', $new_value = '' ) {
        global $wpdb;

        $project_id = intval( $project_id );
        $user_id = intval( $user_id );
        $action = sanitize_text_field( $action );
        $field_name = sanitize_text_field( $field_name );
        $old_value = wp_kses_post( $old_value );
        $new_value = wp_kses_post( $new_value );

        $ip_address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

        $table = $wpdb->prefix . 'project_audit';
        $now = current_time( 'mysql' );

        $inserted = $wpdb->insert(
            $table,
            array(
                'project_id' => $project_id,
                'user_id' => $user_id,
                'action' => $action,
                'field_name' => $field_name,
                'old_value' => $old_value,
                'new_value' => $new_value,
                'ip_address' => $ip_address,
                'created_at' => $now,
            ),
            array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
        );

        if ( $inserted ) {
            return $wpdb->insert_id;
        }

        return false;
    }

    /**
     * Get audit trail for a project
     *
     * @param int $project_id Project ID
     * @param int $limit Limit results
     * @param int $offset Offset
     * @return array Array of audit records
     */
    public static function get_project_audit_trail( $project_id, $limit = 50, $offset = 0 ) {
        global $wpdb;

        $project_id = intval( $project_id );
        $limit = intval( $limit );
        $offset = intval( $offset );

        $table = $wpdb->prefix . 'project_audit';

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE project_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $project_id,
                $limit,
                $offset
            )
        );
    }

    /**
     * Get audit records for a user
     *
     * @param int $user_id User ID
     * @param int $limit Limit results
     * @param int $offset Offset
     * @return array Array of audit records
     */
    public static function get_user_audit_trail( $user_id, $limit = 100, $offset = 0 ) {
        global $wpdb;

        $user_id = intval( $user_id );
        $limit = intval( $limit );
        $offset = intval( $offset );

        $table = $wpdb->prefix . 'project_audit';

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $user_id,
                $limit,
                $offset
            )
        );
    }

    /**
     * Get audit records by action
     *
     * @param string $action Action type
     * @param int $limit Limit results
     * @return array Array of audit records
     */
    public static function get_audit_by_action( $action, $limit = 100 ) {
        global $wpdb;

        $action = sanitize_text_field( $action );
        $limit = intval( $limit );

        $table = $wpdb->prefix . 'project_audit';

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE action = %s ORDER BY created_at DESC LIMIT %d",
                $action,
                $limit
            )
        );
    }

    /**
     * Get audit record by ID
     *
     * @param int $audit_id Audit ID
     * @return object|null Audit record
     */
    public static function get_audit( $audit_id ) {
        global $wpdb;

        $audit_id = intval( $audit_id );
        $table = $wpdb->prefix . 'project_audit';

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d",
                $audit_id
            )
        );
    }

    /**
     * Delete audit trail for a project
     *
     * @param int $project_id Project ID
     * @return int Number of rows deleted
     */
    public static function delete_project_audit_trail( $project_id ) {
        global $wpdb;

        $project_id = intval( $project_id );
        $table = $wpdb->prefix . 'project_audit';

        return $wpdb->delete(
            $table,
            array( 'project_id' => $project_id ),
            array( '%d' )
        );
    }

    /**
     * Get audit summary for a project
     *
     * @param int $project_id Project ID
     * @return array Summary of actions
     */
    public static function get_audit_summary( $project_id ) {
        global $wpdb;

        $project_id = intval( $project_id );
        $table = $wpdb->prefix . 'project_audit';

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT action, COUNT(*) as count FROM {$table} WHERE project_id = %d GROUP BY action",
                $project_id
            )
        );

        $summary = array();
        foreach ( $results as $result ) {
            $summary[ $result->action ] = $result->count;
        }

        return $summary;
    }

    /**
     * Format audit record for display
     *
     * @param object $audit Audit record
     * @return array Formatted audit data
     */
    public static function format_audit( $audit ) {
        $user = get_userdata( $audit->user_id );

        return array(
            'id' => $audit->id,
            'project_id' => $audit->project_id,
            'user_id' => $audit->user_id,
            'user_name' => $user ? $user->display_name : 'Unknown',
            'user_email' => $user ? $user->user_email : '',
            'action' => $audit->action,
            'field_name' => $audit->field_name,
            'old_value' => $audit->old_value,
            'new_value' => $audit->new_value,
            'ip_address' => $audit->ip_address,
            'created_at' => $audit->created_at,
        );
    }

    /**
     * Get action label
     *
     * @param string $action Action type
     * @return string Human-readable action label
     */
    public static function get_action_label( $action ) {
        $labels = array(
            'project_created' => 'Project Created',
            'project_submitted' => 'Project Submitted',
            'project_updated' => 'Project Updated',
            'project_deleted' => 'Project Deleted',
            'item_added' => 'Item Added',
            'item_updated' => 'Item Updated',
            'item_deleted' => 'Item Deleted',
            'file_uploaded' => 'File Uploaded',
            'file_deleted' => 'File Deleted',
            'comment_added' => 'Comment Added',
            'comment_updated' => 'Comment Updated',
            'comment_deleted' => 'Comment Deleted',
            'quote_uploaded' => 'Quote Uploaded',
            'quote_sent' => 'Quote Sent',
            'quote_deleted' => 'Quote Deleted',
            'status_changed' => 'Status Changed',
        );

        return isset( $labels[ $action ] ) ? $labels[ $action ] : ucwords( str_replace( '_', ' ', $action ) );
    }

    /**
     * Get audit statistics
     *
     * @param int $days Number of days to look back
     * @return array Statistics
     */
    public static function get_audit_statistics( $days = 30 ) {
        global $wpdb;

        $days = intval( $days );
        $table = $wpdb->prefix . 'project_audit';
        $date_from = date( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

        // Most active users
        $active_users = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT user_id, COUNT(*) as count FROM {$table} WHERE created_at >= %s GROUP BY user_id ORDER BY count DESC LIMIT 10",
                $date_from
            )
        );

        // Most common actions
        $common_actions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT action, COUNT(*) as count FROM {$table} WHERE created_at >= %s GROUP BY action ORDER BY count DESC",
                $date_from
            )
        );

        return array(
            'active_users' => $active_users,
            'common_actions' => $common_actions,
            'total_actions' => $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s",
                    $date_from
                )
            ),
        );
    }
}
