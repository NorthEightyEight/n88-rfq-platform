<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Items Endpoints
 * 
 * Milestone 1.1: Item creation and update endpoints.
 */
class N88_Items {

    /**
     * Allowed item statuses
     * 
     * @var array
     */
    private static $allowed_item_statuses = array(
        'draft',
        'active',
        'archived',
    );

    /**
     * Allowed item types
     * 
     * @var array
     */
    private static $allowed_item_types = array(
        'furniture',
        'lighting',
        'accessory',
        'art',
        'other',
    );

    /**
     * Allowed fields for item updates (strict whitelist)
     * 
     * @var array Field name => array('sanitizer' => callback, 'max_length' => int)
     */
    private static $allowed_update_fields = array(
        'title' => array(
            'sanitizer' => 'sanitize_text_field',
            'max_length' => 500,
        ),
        'description' => array(
            'sanitizer' => 'sanitize_textarea_field',
            'max_length' => null, // TEXT field, no limit
        ),
        'status' => array(
            'sanitizer' => 'sanitize_text_field',
            'max_length' => 50,
            'whitelist' => null, // Uses $allowed_item_statuses
        ),
        'item_type' => array(
            'sanitizer' => 'sanitize_text_field',
            'max_length' => 100,
            'whitelist' => null, // Uses $allowed_item_types
        ),
    );

    public function __construct() {
        // Register AJAX endpoints (logged-in users only)
        add_action( 'wp_ajax_n88_create_item', array( $this, 'ajax_create_item' ) );
        add_action( 'wp_ajax_n88_update_item', array( $this, 'ajax_update_item' ) );
    }

    /**
     * AJAX: Create Item
     * 
     * Creates a new item owned by the current user.
     */
    public function ajax_create_item() {
        // Nonce verification
        N88_RFQ_Helpers::verify_ajax_nonce();

        // Login check
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'You must be logged in to create items.' ), 401 );
        }

        $user_id = get_current_user_id();

        // Sanitize and validate inputs
        $title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
        $description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
        $item_type = isset( $_POST['item_type'] ) ? sanitize_text_field( wp_unslash( $_POST['item_type'] ) ) : 'furniture';
        $status = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : 'draft';

        // Validate required fields
        if ( empty( $title ) ) {
            wp_send_json_error( array( 'message' => 'Title is required.' ), 400 );
        }

        // Validate title length (max 500 chars per schema)
        if ( strlen( $title ) > 500 ) {
            wp_send_json_error( array( 'message' => 'Title exceeds maximum length of 500 characters.' ), 400 );
        }

        // Validate item_type against whitelist
        if ( ! in_array( $item_type, self::$allowed_item_types, true ) ) {
            wp_send_json_error( array( 'message' => 'Invalid item type.' ), 400 );
        }

        // Validate status against whitelist
        if ( ! in_array( $status, self::$allowed_item_statuses, true ) ) {
            wp_send_json_error( array( 'message' => 'Invalid status.' ), 400 );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'n88_items';
        $now = current_time( 'mysql' );

        // Insert item
        $inserted = $wpdb->insert(
            $table,
            array(
                'owner_user_id' => $user_id,
                'title'         => $title,
                'description'  => $description,
                'item_type'    => $item_type,
                'status'       => $status,
                'version'      => 1,
                'created_at'   => $now,
                'updated_at'   => $now,
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
        );

        if ( ! $inserted ) {
            wp_send_json_error( array( 'message' => 'Failed to create item.' ), 500 );
        }

        $item_id = $wpdb->insert_id;

        // Log event
        n88_log_event(
            'item_created',
            'item',
            array(
                'object_id' => $item_id,
                'item_id'   => $item_id,
                'payload_json' => array(
                    'title'      => $title,
                    'item_type'  => $item_type,
                    'status'     => $status,
                ),
            )
        );

        // Ensure designer profile exists (lazy creation)
        $this->ensure_designer_profile( $user_id );

        wp_send_json_success( array(
            'item_id' => $item_id,
            'message' => 'Item created successfully.',
        ) );
    }

    /**
     * AJAX: Update Item (core fields only)
     * 
     * Updates core fields of an item with strict allowed-fields whitelist.
     * Unknown payload fields are rejected with 400.
     */
    public function ajax_update_item() {
        // Nonce verification
        N88_RFQ_Helpers::verify_ajax_nonce();

        // Login check
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'You must be logged in to update items.' ), 401 );
        }

        $user_id = get_current_user_id();

        // Sanitize and validate item_id
        $item_id = isset( $_POST['item_id'] ) ? absint( $_POST['item_id'] ) : 0;
        if ( $item_id === 0 ) {
            wp_send_json_error( array( 'message' => 'Invalid item ID.' ), 400 );
        }

        // Ownership validation (owner OR admin)
        $item = N88_Authorization::get_item_for_user( $item_id, $user_id );
        if ( ! $item ) {
            wp_send_json_error( array( 'message' => 'Item not found or access denied.' ), 403 );
        }

        // STRICT ALLOWED-FIELDS WHITELIST: Reject any unknown fields
        $allowed_field_names = array_keys( self::$allowed_update_fields );
        $incoming_fields = array_keys( $_POST );
        
        // Remove system fields (item_id, nonce) from check
        $system_fields = array( 'item_id', 'nonce', 'action' );
        $incoming_fields = array_diff( $incoming_fields, $system_fields );
        
        // Check for unknown fields
        $unknown_fields = array_diff( $incoming_fields, $allowed_field_names );
        if ( ! empty( $unknown_fields ) ) {
            wp_send_json_error( array(
                'message' => 'Unknown fields not allowed: ' . implode( ', ', $unknown_fields ),
                'unknown_fields' => array_values( $unknown_fields ),
            ), 400 );
        }

        // Get current values for edit history
        $old_title = $item->title;
        $old_description = $item->description;
        $old_status = $item->status;
        $old_item_type = $item->item_type;

        // Build update array with strict validation
        $update_data = array();
        $update_format = array();
        $changed_fields = array();

        // Process each allowed field
        foreach ( self::$allowed_update_fields as $field_name => $field_config ) {
            if ( ! isset( $_POST[ $field_name ] ) ) {
                continue; // Field not provided, skip
            }

            $raw_value = wp_unslash( $_POST[ $field_name ] );
            
            // Apply sanitizer
            $sanitizer = $field_config['sanitizer'];
            if ( function_exists( $sanitizer ) ) {
                $sanitized_value = call_user_func( $sanitizer, $raw_value );
            } else {
                $sanitized_value = sanitize_text_field( $raw_value );
            }

            // Validate max length
            if ( $field_config['max_length'] !== null && strlen( $sanitized_value ) > $field_config['max_length'] ) {
                wp_send_json_error( array(
                    'message' => sprintf( 'Field "%s" exceeds maximum length of %d characters.', $field_name, $field_config['max_length'] ),
                ), 400 );
            }

            // Validate whitelist for status and item_type
            if ( 'status' === $field_name ) {
                if ( ! in_array( $sanitized_value, self::$allowed_item_statuses, true ) ) {
                    wp_send_json_error( array( 'message' => 'Invalid status value.' ), 400 );
                }
            } elseif ( 'item_type' === $field_name ) {
                if ( ! in_array( $sanitized_value, self::$allowed_item_types, true ) ) {
                    wp_send_json_error( array( 'message' => 'Invalid item type value.' ), 400 );
                }
            }

            // Check if value changed
            $old_value = null;
            switch ( $field_name ) {
                case 'title':
                    $old_value = $old_title;
                    break;
                case 'description':
                    $old_value = $old_description;
                    break;
                case 'status':
                    $old_value = $old_status;
                    break;
                case 'item_type':
                    $old_value = $old_item_type;
                    break;
            }

            if ( $sanitized_value !== $old_value ) {
                $update_data[ $field_name ] = $sanitized_value;
                $update_format[] = '%s';
                $changed_fields[] = array(
                    'field' => $field_name,
                    'old_value' => $old_value,
                    'new_value' => $sanitized_value,
                );
            }
        }

        // If no changes, return success
        if ( empty( $update_data ) ) {
            wp_send_json_success( array(
                'message' => 'No changes to update.',
                'item_id' => $item_id,
            ) );
        }

        // Update version and timestamp
        $update_data['version'] = $item->version + 1;
        $update_data['updated_at'] = current_time( 'mysql' );
        $update_format[] = '%d';
        $update_format[] = '%s';

        global $wpdb;
        $table = $wpdb->prefix . 'n88_items';

        // Update item
        $updated = $wpdb->update(
            $table,
            $update_data,
            array( 'id' => $item_id ),
            $update_format,
            array( '%d' )
        );

        if ( $updated === false ) {
            wp_send_json_error( array( 'message' => 'Failed to update item.' ), 500 );
        }

        // Log edit history for each changed field
        $edits_table = $wpdb->prefix . 'n88_item_edits';
        $user_role = current_user_can( 'manage_options' ) ? 'admin' : 'user';
        $now = current_time( 'mysql' );

        foreach ( $changed_fields as $change ) {
            $wpdb->insert(
                $edits_table,
                array(
                    'item_id'        => $item_id,
                    'field_name'     => $change['field'],
                    'old_value'      => $change['old_value'],
                    'new_value'      => $change['new_value'],
                    'editor_user_id' => $user_id,
                    'editor_role'    => $user_role,
                    'created_at'     => $now,
                ),
                array( '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
            );
        }

        // Log event
        n88_log_event(
            'item_field_changed',
            'item',
            array(
                'object_id' => $item_id,
                'item_id'   => $item_id,
                'payload_json' => array(
                    'changed_fields' => array_column( $changed_fields, 'field' ),
                    'version'        => $update_data['version'],
                ),
            )
        );

        wp_send_json_success( array(
            'item_id' => $item_id,
            'message' => 'Item updated successfully.',
            'changed_fields' => array_column( $changed_fields, 'field' ),
        ) );
    }

    /**
     * Ensure designer profile exists (lazy creation).
     * 
     * @param int $user_id User ID
     */
    private function ensure_designer_profile( $user_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'n88_designer_profiles';

        // Check if profile exists
        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE user_id = %d",
                $user_id
            )
        );

        if ( $existing ) {
            return;
        }

        // Create profile
        $user = get_userdata( $user_id );
        $display_name = $user ? $user->display_name : '';

        $wpdb->insert(
            $table,
            array(
                'user_id'     => $user_id,
                'display_name' => $display_name,
                'created_at'  => current_time( 'mysql' ),
                'updated_at'  => current_time( 'mysql' ),
            ),
            array( '%d', '%s', '%s', '%s' )
        );

        // Log event
        n88_log_event(
            'designer_profile_created',
            'designer_profile',
            array(
                'object_id' => $wpdb->insert_id,
            )
        );
    }
}

