<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * N88 RFQ Item Flags Management Class
 *
 * Manages item flags for tracking "Needs Review", "Urgent", and "Changed" statuses.
 */
class N88_RFQ_Item_Flags {

    const FLAG_NEEDS_REVIEW = 'needs_review';
    const FLAG_URGENT = 'urgent';
    const FLAG_CHANGED = 'changed';

    protected $meta_table;

    public function __construct() {
        global $wpdb;
        $this->meta_table = $wpdb->prefix . 'project_metadata';
    }

    /**
     * Add a flag to an item.
     *
     * @param int    $project_id Project ID.
     * @param int    $item_index Item index.
     * @param string $flag_type Flag type (needs_review, urgent, changed).
     * @param string $reason Optional reason for the flag.
     * @return bool True on success.
     */
    public function add_flag( $project_id, $item_index, $flag_type, $reason = '' ) {
        $flags = $this->get_item_flags( $project_id, $item_index );

        if ( ! isset( $flags[ $flag_type ] ) ) {
            $flags[ $flag_type ] = array(
                'added_at' => current_time( 'mysql' ),
                'reason'   => sanitize_text_field( $reason ),
                'resolved' => false,
            );

            return $this->save_item_flags( $project_id, $item_index, $flags );
        }

        return false;
    }

    /**
     * Remove a flag from an item.
     *
     * @param int    $project_id Project ID.
     * @param int    $item_index Item index.
     * @param string $flag_type Flag type.
     * @return bool True on success.
     */
    public function remove_flag( $project_id, $item_index, $flag_type ) {
        $flags = $this->get_item_flags( $project_id, $item_index );

        if ( isset( $flags[ $flag_type ] ) ) {
            unset( $flags[ $flag_type ] );
            return $this->save_item_flags( $project_id, $item_index, $flags );
        }

        return false;
    }

    /**
     * Get all flags for an item.
     *
     * @param int $project_id Project ID.
     * @param int $item_index Item index.
     * @return array Array of flags.
     */
    public function get_item_flags( $project_id, $item_index ) {
        global $wpdb;

        $flags_json = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT meta_value FROM {$this->meta_table} WHERE project_id = %d AND meta_key = %s",
                $project_id,
                "n88_item_{$item_index}_flags"
            )
        );

        if ( ! $flags_json ) {
            return array();
        }

        $flags = json_decode( $flags_json, true );
        return is_array( $flags ) ? $flags : array();
    }

    /**
     * Save flags for an item.
     *
     * @param int   $project_id Project ID.
     * @param int   $item_index Item index.
     * @param array $flags Flags array.
     * @return bool True on success.
     */
    private function save_item_flags( $project_id, $item_index, $flags ) {
        global $wpdb;

        $meta_key = "n88_item_{$item_index}_flags";

        // Check if exists
        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$this->meta_table} WHERE project_id = %d AND meta_key = %s",
                $project_id,
                $meta_key
            )
        );

        if ( $existing ) {
            return $wpdb->update(
                $this->meta_table,
                array( 'meta_value' => wp_json_encode( $flags ) ),
                array( 'project_id' => $project_id, 'meta_key' => $meta_key ),
                array( '%s' ),
                array( '%d', '%s' )
            );
        } else {
            return $wpdb->insert(
                $this->meta_table,
                array(
                    'project_id' => $project_id,
                    'meta_key'   => $meta_key,
                    'meta_value' => wp_json_encode( $flags ),
                ),
                array( '%d', '%s', '%s' )
            );
        }
    }

    /**
     * Get all items with specific flag.
     *
     * @param int    $project_id Project ID.
     * @param string $flag_type Flag type.
     * @return array Array of item indices with the flag.
     */
    public function get_items_with_flag( $project_id, $flag_type ) {
        global $wpdb;

        $results = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT meta_key FROM {$this->meta_table} 
                 WHERE project_id = %d AND meta_key LIKE %s",
                $project_id,
                'n88_item_%_flags'
            )
        );

        $flagged_items = array();

        foreach ( $results as $meta_key ) {
            $flags_json = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT meta_value FROM {$this->meta_table} WHERE project_id = %d AND meta_key = %s",
                    $project_id,
                    $meta_key
                )
            );

            $flags = json_decode( $flags_json, true );

            if ( is_array( $flags ) && isset( $flags[ $flag_type ] ) ) {
                preg_match( '/n88_item_(\d+)_flags/', $meta_key, $matches );
                if ( ! empty( $matches[1] ) ) {
                    $flagged_items[] = (int) $matches[1];
                }
            }
        }

        return $flagged_items;
    }

    /**
     * Get flag summary for project.
     *
     * @param int $project_id Project ID.
     * @return array Array with counts of each flag type.
     */
    public function get_flag_summary( $project_id ) {
        return array(
            'needs_review' => count( $this->get_items_with_flag( $project_id, self::FLAG_NEEDS_REVIEW ) ),
            'urgent'       => count( $this->get_items_with_flag( $project_id, self::FLAG_URGENT ) ),
            'changed'      => count( $this->get_items_with_flag( $project_id, self::FLAG_CHANGED ) ),
        );
    }
}
