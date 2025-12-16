<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Boards Endpoints
 * 
 * Milestone 1.1: Board creation and item-board relationship endpoints.
 */
class N88_Boards {

    /**
     * Allowed view modes for boards
     * 
     * @var array
     */
    private static $allowed_view_modes = array(
        'grid',
        'list',
        '3d',
    );

    public function __construct() {
        // Register AJAX endpoints (logged-in users only)
        add_action( 'wp_ajax_n88_create_board', array( $this, 'ajax_create_board' ) );
        add_action( 'wp_ajax_n88_add_item_to_board', array( $this, 'ajax_add_item_to_board' ) );
    }

    /**
     * AJAX: Create Board
     * 
     * Creates a new board owned by the current user.
     */
    public function ajax_create_board() {
        // Nonce verification
        N88_RFQ_Helpers::verify_ajax_nonce();

        // Login check
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'You must be logged in to create boards.' ), 401 );
        }

        $user_id = get_current_user_id();

        // Sanitize and validate inputs
        $name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        $description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
        $view_mode = isset( $_POST['view_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['view_mode'] ) ) : 'grid';

        // Validate required fields
        if ( empty( $name ) ) {
            wp_send_json_error( array( 'message' => 'Board name is required.' ), 400 );
        }

        // Validate name length (max 255 chars per schema)
        if ( strlen( $name ) > 255 ) {
            wp_send_json_error( array( 'message' => 'Board name exceeds maximum length of 255 characters.' ), 400 );
        }

        // Validate view_mode against whitelist
        if ( ! in_array( $view_mode, self::$allowed_view_modes, true ) ) {
            wp_send_json_error( array( 'message' => 'Invalid view mode.' ), 400 );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'n88_boards';
        $now = current_time( 'mysql' );

        // Insert board
        $inserted = $wpdb->insert(
            $table,
            array(
                'owner_user_id' => $user_id,
                'name'         => $name,
                'description'  => $description,
                'view_mode'    => $view_mode,
                'created_at'   => $now,
                'updated_at'   => $now,
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s' )
        );

        if ( ! $inserted ) {
            wp_send_json_error( array( 'message' => 'Failed to create board.' ), 500 );
        }

        $board_id = $wpdb->insert_id;

        // Log event
        n88_log_event(
            'board_created',
            'board',
            array(
                'object_id' => $board_id,
                'board_id'  => $board_id,
                'payload_json' => array(
                    'name'      => $name,
                    'view_mode' => $view_mode,
                ),
            )
        );

        wp_send_json_success( array(
            'board_id' => $board_id,
            'message'  => 'Board created successfully.',
        ) );
    }

    /**
     * AJAX: Add Item to Board
     * 
     * Adds an item to a board (creates board-item relationship).
     */
    public function ajax_add_item_to_board() {
        // Nonce verification
        N88_RFQ_Helpers::verify_ajax_nonce();

        // Login check
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'You must be logged in to add items to boards.' ), 401 );
        }

        $user_id = get_current_user_id();

        // Sanitize and validate inputs
        $board_id = isset( $_POST['board_id'] ) ? absint( $_POST['board_id'] ) : 0;
        $item_id = isset( $_POST['item_id'] ) ? absint( $_POST['item_id'] ) : 0;

        if ( $board_id === 0 || $item_id === 0 ) {
            wp_send_json_error( array( 'message' => 'Invalid board ID or item ID.' ), 400 );
        }

        // Ownership validation: user must own the board (OR be admin)
        $board = N88_Authorization::get_board_for_user( $board_id, $user_id );
        if ( ! $board ) {
            wp_send_json_error( array( 'message' => 'Board not found or access denied.' ), 403 );
        }

        // Verify item exists and is not deleted
        global $wpdb;
        $items_table = $wpdb->prefix . 'n88_items';
        $item = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id FROM {$items_table} WHERE id = %d AND deleted_at IS NULL",
                $item_id
            )
        );

        if ( ! $item ) {
            wp_send_json_error( array( 'message' => 'Item not found or has been deleted.' ), 404 );
        }

        // Check if item is already on board (active relationship)
        $board_items_table = $wpdb->prefix . 'n88_board_items';
        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id FROM {$board_items_table} WHERE board_id = %d AND item_id = %d AND removed_at IS NULL",
                $board_id,
                $item_id
            )
        );

        if ( $existing ) {
            wp_send_json_error( array( 'message' => 'Item is already on this board.' ), 400 );
        }

        // Insert board-item relationship
        $now = current_time( 'mysql' );
        $inserted = $wpdb->insert(
            $board_items_table,
            array(
                'board_id'        => $board_id,
                'item_id'         => $item_id,
                'added_by_user_id' => $user_id,
                'added_at'        => $now,
            ),
            array( '%d', '%d', '%d', '%s' )
        );

        if ( ! $inserted ) {
            wp_send_json_error( array( 'message' => 'Failed to add item to board.' ), 500 );
        }

        // Log event
        n88_log_event(
            'item_added_to_board',
            'board',
            array(
                'object_id' => $board_id,
                'board_id'  => $board_id,
                'item_id'   => $item_id,
            )
        );

        wp_send_json_success( array(
            'board_id' => $board_id,
            'item_id'  => $item_id,
            'message'  => 'Item added to board successfully.',
        ) );
    }
}

