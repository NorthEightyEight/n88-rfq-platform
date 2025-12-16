<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Board Layout Endpoints
 * 
 * Milestone 1.1: Board layout update endpoint.
 * Rate limit increased to 100 per minute to support smooth drag/resize UX.
 */
class N88_Board_Layout {

    /**
     * Allowed view modes for board layout
     * 
     * @var array
     */
    private static $allowed_view_modes = array(
        'grid',
        'list',
        '3d',
    );

    /**
     * Rate limit: 100 layout updates per minute per user
     * Increased from 20 to support smooth drag/resize UX without breaking.
     */
    const RATE_LIMIT_COUNT = 100;
    const RATE_LIMIT_WINDOW = MINUTE_IN_SECONDS;

    public function __construct() {
        // Register AJAX endpoint (logged-in users only)
        add_action( 'wp_ajax_n88_update_board_layout', array( $this, 'ajax_update_board_layout' ) );
    }

    /**
     * AJAX: Update Board Layout
     * 
     * Updates layout position/size for an item on a board.
     * Accepts only: position_x, position_y, position_z, size_width, size_height, view_mode
     */
    public function ajax_update_board_layout() {
        // Nonce verification
        N88_RFQ_Helpers::verify_ajax_nonce();

        // Login check
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'You must be logged in to update board layout.' ), 401 );
        }

        $user_id = get_current_user_id();

        // Rate limiting for layout updates (100 per minute per user)
        $rate_limit_result = N88_RFQ_Helpers::check_rate_limit( 'board_layout_update', self::RATE_LIMIT_COUNT, self::RATE_LIMIT_WINDOW, $user_id );
        if ( $rate_limit_result && isset( $rate_limit_result['throttled'] ) && $rate_limit_result['throttled'] ) {
            $retry_after = isset( $rate_limit_result['retry_after'] ) ? $rate_limit_result['retry_after'] : 60;
            wp_send_json_error(
                array(
                    'message'    => sprintf( 'Rate limit exceeded. Please try again in %d second(s).', $retry_after ),
                    'retry_after' => $retry_after,
                ),
                429
            );
        }

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

        // Verify item is on board
        global $wpdb;
        $board_items_table = $wpdb->prefix . 'n88_board_items';
        $board_item = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id FROM {$board_items_table} WHERE board_id = %d AND item_id = %d AND removed_at IS NULL",
                $board_id,
                $item_id
            )
        );

        if ( ! $board_item ) {
            wp_send_json_error( array( 'message' => 'Item is not on this board.' ), 404 );
        }

        // Sanitize and validate layout fields
        $position_x = isset( $_POST['position_x'] ) ? floatval( $_POST['position_x'] ) : 0.00;
        $position_y = isset( $_POST['position_y'] ) ? floatval( $_POST['position_y'] ) : 0.00;
        $position_z = isset( $_POST['position_z'] ) ? intval( $_POST['position_z'] ) : 0;
        $size_width = isset( $_POST['size_width'] ) ? floatval( $_POST['size_width'] ) : null;
        $size_height = isset( $_POST['size_height'] ) ? floatval( $_POST['size_height'] ) : null;
        $view_mode = isset( $_POST['view_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['view_mode'] ) ) : 'grid';

        // Validate view_mode against whitelist
        if ( ! in_array( $view_mode, self::$allowed_view_modes, true ) ) {
            wp_send_json_error( array( 'message' => 'Invalid view mode.' ), 400 );
        }

        // Validate numeric ranges (reasonable limits)
        // Position can be negative (for flexible layouts), but limit to reasonable range
        if ( abs( $position_x ) > 100000 || abs( $position_y ) > 100000 ) {
            wp_send_json_error( array( 'message' => 'Position values out of range.' ), 400 );
        }

        if ( $size_width !== null && ( $size_width < 0 || $size_width > 100000 ) ) {
            wp_send_json_error( array( 'message' => 'Size width out of range.' ), 400 );
        }

        if ( $size_height !== null && ( $size_height < 0 || $size_height > 100000 ) ) {
            wp_send_json_error( array( 'message' => 'Size height out of range.' ), 400 );
        }

        // Build update/insert data
        $layout_table = $wpdb->prefix . 'n88_board_layout';
        $now = current_time( 'mysql' );

        $layout_data = array(
            'board_id'   => $board_id,
            'item_id'    => $item_id,
            'position_x' => $position_x,
            'position_y' => $position_y,
            'position_z' => $position_z,
            'view_mode'  => $view_mode,
            'updated_at' => $now,
        );

        $layout_format = array( '%d', '%d', '%f', '%f', '%d', '%s', '%s' );

        if ( $size_width !== null ) {
            $layout_data['size_width'] = $size_width;
            $layout_format[] = '%f';
        }

        if ( $size_height !== null ) {
            $layout_data['size_height'] = $size_height;
            $layout_format[] = '%f';
        }

        // Check if layout exists
        $existing_layout = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$layout_table} WHERE board_id = %d AND item_id = %d",
                $board_id,
                $item_id
            )
        );

        if ( $existing_layout ) {
            // Update existing layout
            $updated = $wpdb->update(
                $layout_table,
                $layout_data,
                array(
                    'board_id' => $board_id,
                    'item_id'  => $item_id,
                ),
                $layout_format,
                array( '%d', '%d' )
            );

            if ( $updated === false ) {
                wp_send_json_error( array( 'message' => 'Failed to update board layout.' ), 500 );
            }
        } else {
            // Insert new layout
            $inserted = $wpdb->insert(
                $layout_table,
                $layout_data,
                $layout_format
            );

            if ( ! $inserted ) {
                wp_send_json_error( array( 'message' => 'Failed to create board layout.' ), 500 );
            }
        }

        // Log event
        n88_log_event(
            'board_layout_updated',
            'board_layout',
            array(
                'board_id' => $board_id,
                'item_id'  => $item_id,
                'payload_json' => array(
                    'position_x' => $position_x,
                    'position_y' => $position_y,
                    'position_z' => $position_z,
                    'view_mode'  => $view_mode,
                ),
            )
        );

        wp_send_json_success( array(
            'board_id' => $board_id,
            'item_id'  => $item_id,
            'message'  => 'Board layout updated successfully.',
        ) );
    }
}

