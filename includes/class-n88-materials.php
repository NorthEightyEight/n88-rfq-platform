<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Materials Management (Admin Only)
 * 
 * Phase 1.2.3: Material Bank Core
 * 
 * Admin-only CRUD operations for n88_materials.
 * All actions require manage_options capability.
 */
class N88_Materials {

    /**
     * Constructor - register AJAX endpoints
     */
    public function __construct() {
        // Admin-only AJAX endpoints
        add_action( 'wp_ajax_n88_create_material', array( $this, 'ajax_create_material' ) );
        add_action( 'wp_ajax_n88_update_material', array( $this, 'ajax_update_material' ) );
        add_action( 'wp_ajax_n88_activate_material', array( $this, 'ajax_activate_material' ) );
        add_action( 'wp_ajax_n88_deactivate_material', array( $this, 'ajax_deactivate_material' ) );
        add_action( 'wp_ajax_n88_delete_material', array( $this, 'ajax_delete_material' ) );
    }

    /**
     * Verify admin capability
     * 
     * @return void Exits with error if not admin
     */
    private function verify_admin() {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'Authentication required.' ), 401 );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Insufficient permissions. Admin access required.' ), 403 );
        }
    }

    /**
     * Create a new material
     * 
     * POST params:
     * - nonce: AJAX nonce
     * - name: Material name (required)
     * - description: Material description (optional)
     * - category: Material category (optional)
     * - material_code: Material code (optional)
     * - notes: Notes (optional)
     */
    public function ajax_create_material() {
        // Verify nonce
        N88_RFQ_Helpers::verify_ajax_nonce();

        // Verify admin capability
        $this->verify_admin();

        $user_id = get_current_user_id();

        // Validate required fields
        if ( ! isset( $_POST['name'] ) || empty( trim( $_POST['name'] ) ) ) {
            wp_send_json_error( array( 'message' => 'Material name is required.' ), 400 );
        }

        // Sanitize inputs
        $name = sanitize_text_field( wp_unslash( $_POST['name'] ) );
        $description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : null;
        $category = isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : null;
        $material_code = isset( $_POST['material_code'] ) ? sanitize_text_field( wp_unslash( $_POST['material_code'] ) ) : null;
        $notes = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : null;

        // Validate lengths
        if ( strlen( $name ) > 255 ) {
            wp_send_json_error( array( 'message' => 'Material name exceeds maximum length of 255 characters.' ), 400 );
        }
        if ( $category !== null && strlen( $category ) > 100 ) {
            wp_send_json_error( array( 'message' => 'Category exceeds maximum length of 100 characters.' ), 400 );
        }
        if ( $material_code !== null && strlen( $material_code ) > 100 ) {
            wp_send_json_error( array( 'message' => 'Material code exceeds maximum length of 100 characters.' ), 400 );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'n88_materials';
        $now = current_time( 'mysql' );

        // Insert material
        $inserted = $wpdb->insert(
            $table,
            array(
                'name' => $name,
                'description' => $description,
                'category' => $category,
                'material_code' => $material_code,
                'notes' => $notes,
                'is_active' => 1,
                'created_by_user_id' => $user_id,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', null )
        );

        if ( $inserted === false ) {
            wp_send_json_error( array( 'message' => 'Failed to create material.' ), 500 );
        }

        $material_id = $wpdb->insert_id;

        // Log event
        n88_log_event(
            'material_created',
            'material',
            array(
                'object_id' => $material_id,
                'material_id' => $material_id,
                'payload_json' => array(
                    'name' => $name,
                    'category' => $category,
                    'material_code' => $material_code,
                    'created_by_user_id' => $user_id,
                ),
            )
        );

        wp_send_json_success( array(
            'message' => 'Material created successfully.',
            'material_id' => $material_id,
        ) );
    }

    /**
     * Update an existing material
     * 
     * POST params:
     * - nonce: AJAX nonce
     * - material_id: Material ID (required)
     * - name: Material name (optional)
     * - description: Material description (optional)
     * - category: Material category (optional)
     * - material_code: Material code (optional)
     * - notes: Notes (optional)
     */
    public function ajax_update_material() {
        // Verify nonce
        N88_RFQ_Helpers::verify_ajax_nonce();

        // Verify admin capability
        $this->verify_admin();

        // Validate material_id
        if ( ! isset( $_POST['material_id'] ) ) {
            wp_send_json_error( array( 'message' => 'Material ID is required.' ), 400 );
        }

        $material_id = absint( $_POST['material_id'] );
        if ( $material_id === 0 ) {
            wp_send_json_error( array( 'message' => 'Invalid material ID.' ), 400 );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'n88_materials';

        // Verify material exists and is not deleted
        $material = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d AND deleted_at IS NULL",
                $material_id
            )
        );

        if ( ! $material ) {
            wp_send_json_error( array( 'message' => 'Material not found.' ), 404 );
        }

        // Build update array (only update provided fields)
        $update_data = array();
        $update_format = array();

        if ( isset( $_POST['name'] ) ) {
            $name = sanitize_text_field( wp_unslash( $_POST['name'] ) );
            if ( empty( trim( $name ) ) ) {
                wp_send_json_error( array( 'message' => 'Material name cannot be empty.' ), 400 );
            }
            if ( strlen( $name ) > 255 ) {
                wp_send_json_error( array( 'message' => 'Material name exceeds maximum length of 255 characters.' ), 400 );
            }
            $update_data['name'] = $name;
            $update_format[] = '%s';
        }

        if ( isset( $_POST['description'] ) ) {
            $update_data['description'] = sanitize_textarea_field( wp_unslash( $_POST['description'] ) );
            $update_format[] = '%s';
        }

        if ( isset( $_POST['category'] ) ) {
            $category = sanitize_text_field( wp_unslash( $_POST['category'] ) );
            if ( strlen( $category ) > 100 ) {
                wp_send_json_error( array( 'message' => 'Category exceeds maximum length of 100 characters.' ), 400 );
            }
            $update_data['category'] = $category;
            $update_format[] = '%s';
        }

        if ( isset( $_POST['material_code'] ) ) {
            $material_code = sanitize_text_field( wp_unslash( $_POST['material_code'] ) );
            if ( strlen( $material_code ) > 100 ) {
                wp_send_json_error( array( 'message' => 'Material code exceeds maximum length of 100 characters.' ), 400 );
            }
            $update_data['material_code'] = $material_code;
            $update_format[] = '%s';
        }

        if ( isset( $_POST['notes'] ) ) {
            $update_data['notes'] = sanitize_textarea_field( wp_unslash( $_POST['notes'] ) );
            $update_format[] = '%s';
        }

        // If no updates, return success
        if ( empty( $update_data ) ) {
            wp_send_json_success( array(
                'message' => 'No changes to update.',
                'material_id' => $material_id,
            ) );
        }

        // Add updated_at
        $update_data['updated_at'] = current_time( 'mysql' );
        $update_format[] = '%s';

        // Update material
        $updated = $wpdb->update(
            $table,
            $update_data,
            array( 'id' => $material_id ),
            $update_format,
            array( '%d' )
        );

        if ( $updated === false ) {
            wp_send_json_error( array( 'message' => 'Failed to update material.' ), 500 );
        }

        // Log event
        n88_log_event(
            'material_updated',
            'material',
            array(
                'object_id' => $material_id,
                'material_id' => $material_id,
                'payload_json' => $update_data,
            )
        );

        wp_send_json_success( array(
            'message' => 'Material updated successfully.',
            'material_id' => $material_id,
        ) );
    }

    /**
     * Activate a material
     * 
     * POST params:
     * - nonce: AJAX nonce
     * - material_id: Material ID (required)
     */
    public function ajax_activate_material() {
        // Verify nonce
        N88_RFQ_Helpers::verify_ajax_nonce();

        // Verify admin capability
        $this->verify_admin();

        // Validate material_id
        if ( ! isset( $_POST['material_id'] ) ) {
            wp_send_json_error( array( 'message' => 'Material ID is required.' ), 400 );
        }

        $material_id = absint( $_POST['material_id'] );
        if ( $material_id === 0 ) {
            wp_send_json_error( array( 'message' => 'Invalid material ID.' ), 400 );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'n88_materials';

        // Verify material exists and is not deleted
        $material = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d AND deleted_at IS NULL",
                $material_id
            )
        );

        if ( ! $material ) {
            wp_send_json_error( array( 'message' => 'Material not found.' ), 404 );
        }

        // Check if already active
        if ( $material->is_active == 1 ) {
            wp_send_json_success( array(
                'message' => 'Material is already active.',
                'material_id' => $material_id,
            ) );
        }

        // Activate material
        $updated = $wpdb->update(
            $table,
            array(
                'is_active' => 1,
                'updated_at' => current_time( 'mysql' ),
            ),
            array( 'id' => $material_id ),
            array( '%d', '%s' ),
            array( '%d' )
        );

        if ( $updated === false ) {
            wp_send_json_error( array( 'message' => 'Failed to activate material.' ), 500 );
        }

        // Log event
        n88_log_event(
            'material_activated',
            'material',
            array(
                'object_id' => $material_id,
                'material_id' => $material_id,
            )
        );

        wp_send_json_success( array(
            'message' => 'Material activated successfully.',
            'material_id' => $material_id,
        ) );
    }

    /**
     * Deactivate a material
     * 
     * POST params:
     * - nonce: AJAX nonce
     * - material_id: Material ID (required)
     */
    public function ajax_deactivate_material() {
        // Verify nonce
        N88_RFQ_Helpers::verify_ajax_nonce();

        // Verify admin capability
        $this->verify_admin();

        // Validate material_id
        if ( ! isset( $_POST['material_id'] ) ) {
            wp_send_json_error( array( 'message' => 'Material ID is required.' ), 400 );
        }

        $material_id = absint( $_POST['material_id'] );
        if ( $material_id === 0 ) {
            wp_send_json_error( array( 'message' => 'Invalid material ID.' ), 400 );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'n88_materials';

        // Verify material exists and is not deleted
        $material = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d AND deleted_at IS NULL",
                $material_id
            )
        );

        if ( ! $material ) {
            wp_send_json_error( array( 'message' => 'Material not found.' ), 404 );
        }

        // Check if already inactive
        if ( $material->is_active == 0 ) {
            wp_send_json_success( array(
                'message' => 'Material is already inactive.',
                'material_id' => $material_id,
            ) );
        }

        // Deactivate material
        $updated = $wpdb->update(
            $table,
            array(
                'is_active' => 0,
                'updated_at' => current_time( 'mysql' ),
            ),
            array( 'id' => $material_id ),
            array( '%d', '%s' ),
            array( '%d' )
        );

        if ( $updated === false ) {
            wp_send_json_error( array( 'message' => 'Failed to deactivate material.' ), 500 );
        }

        // Log event
        n88_log_event(
            'material_deactivated',
            'material',
            array(
                'object_id' => $material_id,
                'material_id' => $material_id,
            )
        );

        wp_send_json_success( array(
            'message' => 'Material deactivated successfully.',
            'material_id' => $material_id,
        ) );
    }

    /**
     * Soft delete a material (set deleted_at)
     * 
     * POST params:
     * - nonce: AJAX nonce
     * - material_id: Material ID (required)
     */
    public function ajax_delete_material() {
        // Verify nonce
        N88_RFQ_Helpers::verify_ajax_nonce();

        // Verify admin capability
        $this->verify_admin();

        // Validate material_id
        if ( ! isset( $_POST['material_id'] ) ) {
            wp_send_json_error( array( 'message' => 'Material ID is required.' ), 400 );
        }

        $material_id = absint( $_POST['material_id'] );
        if ( $material_id === 0 ) {
            wp_send_json_error( array( 'message' => 'Invalid material ID.' ), 400 );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'n88_materials';

        // Verify material exists and is not already deleted
        $material = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d AND deleted_at IS NULL",
                $material_id
            )
        );

        if ( ! $material ) {
            wp_send_json_error( array( 'message' => 'Material not found or already deleted.' ), 404 );
        }

        // Soft delete (set deleted_at)
        $updated = $wpdb->update(
            $table,
            array(
                'deleted_at' => current_time( 'mysql' ),
                'updated_at' => current_time( 'mysql' ),
            ),
            array( 'id' => $material_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );

        if ( $updated === false ) {
            wp_send_json_error( array( 'message' => 'Failed to delete material.' ), 500 );
        }

        // Note: No event logged for soft delete (per requirements, only activate/deactivate events)

        wp_send_json_success( array(
            'message' => 'Material deleted successfully.',
            'material_id' => $material_id,
        ) );
    }
}

