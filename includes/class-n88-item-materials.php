<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Item-Material Attach/Detach
 * 
 * Phase 1.2.3: Material Bank Core
 * 
 * Handles attaching and detaching materials to items.
 * Designers can only attach/detach to their own items.
 * Admins can attach/detach to any item.
 */
class N88_Item_Materials {

    /**
     * Constructor - register AJAX endpoints
     */
    public function __construct() {
        add_action( 'wp_ajax_n88_attach_material', array( $this, 'ajax_attach_material' ) );
        add_action( 'wp_ajax_n88_detach_material', array( $this, 'ajax_detach_material' ) );
        add_action( 'wp_ajax_n88_upload_materials_in_mind', array( $this, 'ajax_upload_materials_in_mind' ) );
    }

    /**
     * Verify user can modify item (ownership or admin)
     * 
     * @param int $item_id Item ID
     * @param int $user_id User ID
     * @return object|null Item object if authorized, null otherwise
     */
    private function verify_item_access( $item_id, $user_id ) {
        // Use authorization helper (handles admin override)
        return N88_Authorization::get_item_for_user( $item_id, $user_id );
    }

    /**
     * Verify material exists, is active, and not deleted
     * 
     * @param int $material_id Material ID
     * @return object|null Material object if valid, null otherwise
     */
    private function verify_material( $material_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'n88_materials';

        $material = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d AND is_active = 1 AND deleted_at IS NULL",
                $material_id
            )
        );

        return $material;
    }

    /**
     * Attach a material to an item
     * 
     * POST params:
     * - nonce: AJAX nonce
     * - item_id: Item ID (required)
     * - material_id: Material ID (required)
     * - quantity: Quantity (optional, default 1.000)
     * - unit: Unit (optional, default 'unit')
     * - notes: Notes (optional)
     */
    public function ajax_attach_material() {
        // Verify nonce
        N88_RFQ_Helpers::verify_ajax_nonce();

        // Verify user is logged in
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'Authentication required.' ), 401 );
        }

        $user_id = get_current_user_id();

        // Validate required fields
        if ( ! isset( $_POST['item_id'] ) || ! isset( $_POST['material_id'] ) ) {
            wp_send_json_error( array( 'message' => 'Item ID and Material ID are required.' ), 400 );
        }

        $item_id = absint( $_POST['item_id'] );
        $material_id = absint( $_POST['material_id'] );

        if ( $item_id === 0 || $material_id === 0 ) {
            wp_send_json_error( array( 'message' => 'Invalid Item ID or Material ID.' ), 400 );
        }

        // Verify item access (ownership or admin)
        $item = $this->verify_item_access( $item_id, $user_id );
        if ( ! $item ) {
            wp_send_json_error( array( 'message' => 'Item not found or access denied.' ), 403 );
        }

        // Verify material exists, is active, and not deleted
        $material = $this->verify_material( $material_id );
        if ( ! $material ) {
            wp_send_json_error( array( 'message' => 'Material not found, inactive, or deleted.' ), 404 );
        }

        // Sanitize optional fields
        $quantity = isset( $_POST['quantity'] ) ? floatval( $_POST['quantity'] ) : 1.000;
        $unit = isset( $_POST['unit'] ) ? sanitize_text_field( wp_unslash( $_POST['unit'] ) ) : 'unit';
        $notes = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : null;

        // Validate quantity
        if ( $quantity <= 0 ) {
            wp_send_json_error( array( 'message' => 'Quantity must be greater than 0.' ), 400 );
        }

        // Validate unit length
        if ( strlen( $unit ) > 50 ) {
            wp_send_json_error( array( 'message' => 'Unit exceeds maximum length of 50 characters.' ), 400 );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'n88_item_materials';
        $now = current_time( 'mysql' );

        // Check if attachment already exists (for reattach)
        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE item_id = %d AND material_id = %d",
                $item_id,
                $material_id
            )
        );

        if ( $existing ) {
            // Reattach: reuse existing row
            if ( $existing->is_active == 1 ) {
                wp_send_json_error( array( 'message' => 'Material is already attached to this item.' ), 400 );
            }

            // Reattach by updating existing row
            $updated = $wpdb->update(
                $table,
                array(
                    'quantity' => $quantity,
                    'unit' => $unit,
                    'notes' => $notes,
                    'is_active' => 1,
                    'attached_by_user_id' => $user_id,
                    'attached_at' => $now,
                    'detached_at' => null,
                ),
                array( 'id' => $existing->id ),
                array( '%f', '%s', '%s', '%d', '%d', '%s', null ),
                array( '%d' )
            );

            if ( $updated === false ) {
                wp_send_json_error( array( 'message' => 'Failed to reattach material.' ), 500 );
            }

            $attachment_id = $existing->id;
        } else {
            // New attachment: insert new row
            $inserted = $wpdb->insert(
                $table,
                array(
                    'item_id' => $item_id,
                    'material_id' => $material_id,
                    'quantity' => $quantity,
                    'unit' => $unit,
                    'notes' => $notes,
                    'is_active' => 1,
                    'attached_by_user_id' => $user_id,
                    'attached_at' => $now,
                    'detached_at' => null,
                ),
                array( '%d', '%d', '%f', '%s', '%s', '%d', '%d', '%s', null )
            );

            if ( $inserted === false ) {
                wp_send_json_error( array( 'message' => 'Failed to attach material.' ), 500 );
            }

            $attachment_id = $wpdb->insert_id;
        }

        // Log event
        n88_log_event(
            'material_attached_to_item',
            'item',
            array(
                'object_id' => $item_id,
                'item_id' => $item_id,
                'material_id' => $material_id,
                'payload_json' => array(
                    'attachment_id' => $attachment_id,
                    'quantity' => $quantity,
                    'unit' => $unit,
                    'attached_by_user_id' => $user_id,
                    'is_reattach' => $existing !== null,
                ),
            )
        );

        wp_send_json_success( array(
            'message' => 'Material attached successfully.',
            'attachment_id' => $attachment_id,
            'item_id' => $item_id,
            'material_id' => $material_id,
        ) );
    }

    /**
     * Detach a material from an item
     * 
     * POST params:
     * - nonce: AJAX nonce
     * - item_id: Item ID (required)
     * - material_id: Material ID (required)
     */
    public function ajax_detach_material() {
        // Verify nonce
        N88_RFQ_Helpers::verify_ajax_nonce();

        // Verify user is logged in
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'Authentication required.' ), 401 );
        }

        $user_id = get_current_user_id();

        // Validate required fields
        if ( ! isset( $_POST['item_id'] ) || ! isset( $_POST['material_id'] ) ) {
            wp_send_json_error( array( 'message' => 'Item ID and Material ID are required.' ), 400 );
        }

        $item_id = absint( $_POST['item_id'] );
        $material_id = absint( $_POST['material_id'] );

        if ( $item_id === 0 || $material_id === 0 ) {
            wp_send_json_error( array( 'message' => 'Invalid Item ID or Material ID.' ), 400 );
        }

        // Verify item access (ownership or admin)
        $item = $this->verify_item_access( $item_id, $user_id );
        if ( ! $item ) {
            wp_send_json_error( array( 'message' => 'Item not found or access denied.' ), 403 );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'n88_item_materials';

        // Verify attachment exists and is active
        $attachment = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE item_id = %d AND material_id = %d AND is_active = 1",
                $item_id,
                $material_id
            )
        );

        if ( ! $attachment ) {
            wp_send_json_error( array( 'message' => 'Material is not attached to this item.' ), 404 );
        }

        // Detach: set is_active = 0 and detached_at = NOW()
        $now = current_time( 'mysql' );
        $updated = $wpdb->update(
            $table,
            array(
                'is_active' => 0,
                'detached_at' => $now,
            ),
            array( 'id' => $attachment->id ),
            array( '%d', '%s' ),
            array( '%d' )
        );

        if ( $updated === false ) {
            wp_send_json_error( array( 'message' => 'Failed to detach material.' ), 500 );
        }

        // Log event
        n88_log_event(
            'material_detached_from_item',
            'item',
            array(
                'object_id' => $item_id,
                'item_id' => $item_id,
                'material_id' => $material_id,
                'payload_json' => array(
                    'attachment_id' => $attachment->id,
                    'detached_by_user_id' => $user_id,
                ),
            )
        );

        wp_send_json_success( array(
            'message' => 'Material detached successfully.',
            'item_id' => $item_id,
            'material_id' => $material_id,
        ) );
    }

    /**
     * Upload materials-in-mind reference files
     * 
     * Reuses existing item-file relationship from Milestone 1.1.
     * These are reference-only uploads and do not modify the material bank.
     * 
     * POST params:
     * - nonce: AJAX nonce
     * - item_id: Item ID (required)
     * - file: File upload (via $_FILES)
     */
    public function ajax_upload_materials_in_mind() {
        // Verify nonce
        N88_RFQ_Helpers::verify_ajax_nonce();

        // Verify user is logged in
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'Authentication required.' ), 401 );
        }

        $user_id = get_current_user_id();

        // Validate item_id
        if ( ! isset( $_POST['item_id'] ) ) {
            wp_send_json_error( array( 'message' => 'Item ID is required.' ), 400 );
        }

        $item_id = absint( $_POST['item_id'] );
        if ( $item_id === 0 ) {
            wp_send_json_error( array( 'message' => 'Invalid Item ID.' ), 400 );
        }

        // Verify item access (ownership or admin)
        $item = $this->verify_item_access( $item_id, $user_id );
        if ( ! $item ) {
            wp_send_json_error( array( 'message' => 'Item not found or access denied.' ), 403 );
        }

        // Validate file upload
        if ( ! isset( $_FILES['file'] ) || $_FILES['file']['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( array( 'message' => 'File upload failed or no file provided.' ), 400 );
        }

        // Use WordPress media handling
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        require_once( ABSPATH . 'wp-admin/includes/media.php' );
        require_once( ABSPATH . 'wp-admin/includes/image.php' );

        // Handle file upload
        $upload = wp_handle_upload( $_FILES['file'], array( 'test_form' => false ) );

        if ( isset( $upload['error'] ) ) {
            wp_send_json_error( array( 'message' => 'File upload error: ' . $upload['error'] ), 400 );
        }

        // Create attachment post
        $attachment_id = wp_insert_attachment(
            array(
                'post_mime_type' => $upload['type'],
                'post_title' => sanitize_file_name( basename( $upload['file'] ) ),
                'post_content' => '',
                'post_status' => 'inherit',
            ),
            $upload['file']
        );

        if ( is_wp_error( $attachment_id ) ) {
            wp_send_json_error( array( 'message' => 'Failed to create attachment: ' . $attachment_id->get_error_message() ), 500 );
        }

        // Generate attachment metadata
        $attach_data = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
        wp_update_attachment_metadata( $attachment_id, $attach_data );

        // Link file to item via n88_item_files (reuse Milestone 1.1 relationship)
        global $wpdb;
        $files_table = $wpdb->prefix . 'n88_item_files';
        $now = current_time( 'mysql' );

        $inserted = $wpdb->insert(
            $files_table,
            array(
                'item_id' => $item_id,
                'file_id' => $attachment_id,
                'attachment_type' => 'materials_in_mind',
                'attached_by_user_id' => $user_id,
                'attached_at' => $now,
                'display_order' => 0,
            ),
            array( '%d', '%d', '%s', '%d', '%s', '%d' )
        );

        if ( $inserted === false ) {
            // Clean up attachment if linking failed
            wp_delete_attachment( $attachment_id, true );
            wp_send_json_error( array( 'message' => 'Failed to link file to item.' ), 500 );
        }

        // Log event
        n88_log_event(
            'materials_in_mind_uploaded',
            'item',
            array(
                'object_id' => $item_id,
                'item_id' => $item_id,
                'payload_json' => array(
                    'file_id' => $attachment_id,
                    'attachment_type' => 'materials_in_mind',
                    'attached_by_user_id' => $user_id,
                    'file_url' => wp_get_attachment_url( $attachment_id ),
                ),
            )
        );

        wp_send_json_success( array(
            'message' => 'Materials-in-mind file uploaded successfully.',
            'file_id' => $attachment_id,
            'item_id' => $item_id,
            'file_url' => wp_get_attachment_url( $attachment_id ),
        ) );
    }
}

