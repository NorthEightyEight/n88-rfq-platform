<?php
/**
 * Commit 3.D.8C: Paid full-process slot requests (beyond 2 free / project-scope).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class N88_FP_Slot_Requests {

    /** @var string */
    private static $did_create_table_option = 'n88_fp_slot_requests_schema_v1';

    private static $did_pm_col_check = false;

    public function __construct() {
        add_action( 'wp_ajax_n88_fp_slot_status', array( $this, 'ajax_status' ) );
        add_action( 'wp_ajax_n88_fp_slot_submit_request', array( $this, 'ajax_submit_request' ) );
        add_action( 'wp_ajax_n88_fp_slot_operator_list', array( $this, 'ajax_operator_list' ) );
        add_action( 'wp_ajax_n88_fp_slot_operator_decide', array( $this, 'ajax_operator_decide' ) );
        add_action( 'wp_ajax_n88_fp_slot_supplier_list', array( $this, 'ajax_supplier_list' ) );
    }

    /**
     * @return string Table name including prefix (sanitized for DESCRIBE/SHOW only via prefix).
     */
    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'n88_fp_slot_requests';
    }

    /**
     * @param int $board_id Board id.
     * @param int $user_id  User id.
     * @return bool
     */
    public static function supplier_can_view_board_slot_activity( $board_id, $user_id ) {
        $board_id = absint( $board_id );
        $user_id  = absint( $user_id );
        if ( $board_id <= 0 || $user_id <= 0 ) {
            return false;
        }
        if ( N88_Authorization::get_board_for_user( $board_id, $user_id ) ) {
            return true;
        }
        global $wpdb;
        $items_table       = preg_replace( '/[^a-zA-Z0-9_]/', '', $wpdb->prefix . 'n88_items' );
        $bi_table          = preg_replace( '/[^a-zA-Z0-9_]/', '', $wpdb->prefix . 'n88_board_items' );
        $routes_table      = preg_replace( '/[^a-zA-Z0-9_]/', '', $wpdb->prefix . 'n88_rfq_routes' );
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$routes_table}'" ) !== $routes_table ) {
            return false;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- sanitized table identifiers
        $n = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$bi_table} bi
				INNER JOIN {$routes_table} rr ON rr.item_id = bi.item_id AND rr.supplier_id = %d
				WHERE bi.board_id = %d AND bi.removed_at IS NULL
				LIMIT 1",
                $user_id,
                $board_id
            )
        );

        return (int) $n > 0;
    }

    /**
     * @return bool True if acting operator (explicit role or WP admin).
     */
    private static function is_operator_user() {
        $u = wp_get_current_user();
        return $u && (
            current_user_can( 'manage_options' )
            || in_array( 'n88_system_operator', (array) $u->roles, true )
        );
    }

    /**
     * @return bool
     */
    private static function is_supplier_user() {
        $u = wp_get_current_user();
        return $u && (
            in_array( 'n88_supplier_admin', (array) $u->roles, true )
            || in_array( 'supplier_admin', (array) $u->roles, true )
        );
    }

    /**
     * Default Wireframe (OS) account may approve FP slot payouts (alongside operators).
     *
     * @return bool
     */
    private static function is_wireframe_slot_reviewer() {
        $u = wp_get_current_user();
        if ( ! $u || empty( $u->user_email ) ) {
            return false;
        }
        if ( strtolower( (string) $u->user_email ) !== 'wireframestudioos@gmail.com' ) {
            return false;
        }
        $roles = (array) $u->roles;

        return in_array( 'n88_supplier_admin', $roles, true )
            || in_array( 'supplier_admin', $roles, true )
            || current_user_can( 'manage_options' );
    }

    /**
     * @return bool
     */
    private static function can_review_slot_requests() {
        return self::is_operator_user() || self::is_wireframe_slot_reviewer();
    }

    public function ajax_status() {
        N88_RFQ_Helpers::verify_ajax_nonce();

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'You must be logged in.' ), 401 );
        }

        if ( ! class_exists( 'N88_Item_Unlock' ) ) {
            wp_send_json_success( self::neutral_payload() );
        }

        $user_id            = get_current_user_id();
        $board_id           = isset( $_POST['board_id'] ) ? absint( $_POST['board_id'] ) : 0;
        $requested_project = isset( $_POST['project_id'] ) ? absint( $_POST['project_id'] ) : 0;

        $board = $board_id > 0 ? N88_Authorization::get_board_for_user( $board_id, $user_id ) : null;
        if ( $board_id > 0 && ! $board ) {
            wp_send_json_error( array( 'message' => 'Access denied for this board.' ), 403 );
        }

        if ( ! N88_Item_Unlock::items_unlock_columns_exist() ) {
            wp_send_json_success( self::neutral_payload() );
        }

        $resolved_project = $board_id > 0
            ? N88_Item_Unlock::resolve_fp_project_for_new_item( $user_id, $board_id, $requested_project )
            : 0;

        $gate = N88_Item_Unlock::get_fp_slot_gate( $resolved_project, $user_id, absint( $board_id ) );

        global $wpdb;
        $pending = array();
        if ( ! self::requests_table_ready() ) {
            self::install_tables();
        }
        if ( self::requests_table_ready() ) {
            $tbl    = preg_replace( '/[^a-zA-Z0-9_]/', '', self::table_name() );
            $proj_q = isset( $_POST['project_id'] ) ? absint( wp_unslash( $_POST['project_id'] ) ) : 0;
            $pending = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, status, created_at, attachment_id FROM {$tbl}
					WHERE designer_user_id = %d AND board_id = %d AND status = %s ORDER BY id DESC LIMIT 3",
                    $user_id,
                    absint( $board_id ),
                    'pending'
                ),
                ARRAY_A
            );
        }

        $pending_summary = '';
        if ( is_array( $pending ) && ! empty( $pending ) ) {
            $pending_summary = 'A payment proof is pending operator review.';
        }

        wp_send_json_success(
            array(
                'fp_add_blocked'   => isset( $gate['remaining'] ) && (int) $gate['remaining'] < 1,
                'unlock_price_usd' => N88_Item_Unlock::UNLOCK_PRICE_USD,
                'current'          => isset( $gate['current'] ) ? (int) $gate['current'] : 0,
                'free_cap'         => isset( $gate['free_cap'] ) ? (int) $gate['free_cap'] : 2,
                'granted_slots'    => isset( $gate['granted_extra'] ) ? (int) $gate['granted_extra'] : 0,
                'allowed_total'    => isset( $gate['allowed_total'] ) ? (int) $gate['allowed_total'] : 0,
                'remaining'        => isset( $gate['remaining'] ) ? (int) $gate['remaining'] : 0,
                'resolved_project' => absint( $resolved_project ),
                'pending_notice'   => $pending_summary,
            )
        );
    }

    /**
     * @return array<string,mixed>
     */
    private static function neutral_payload() {
        return array(
            'fp_add_blocked'   => false,
            'unlock_price_usd'   => class_exists( 'N88_Item_Unlock' ) ? N88_Item_Unlock::UNLOCK_PRICE_USD : 149,
            'current'          => 0,
            'free_cap'         => class_exists( 'N88_Item_Unlock' ) ? N88_Item_Unlock::FULL_PROCESS_FREE_CAP : 2,
            'granted_slots'    => 0,
            'allowed_total'    => 999,
            'remaining'        => 999,
            'resolved_project' => 0,
            'pending_notice'   => '',
        );
    }

    /**
     * @return bool
     */
    public static function requests_table_ready() {
        global $wpdb;
        $tbl  = preg_replace( '/[^a-zA-Z0-9_]/', '', self::table_name() );
        $exists = $wpdb->get_var( "SHOW TABLES LIKE '{$tbl}'" ) === $tbl;
        return (bool) $exists;
    }

    public function ajax_submit_request() {
        N88_RFQ_Helpers::verify_ajax_nonce();

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'You must be logged in.' ), 401 );
        }

        if ( ! class_exists( 'N88_Item_Unlock' ) || ! N88_Item_Unlock::items_unlock_columns_exist() ) {
            wp_send_json_error( array( 'message' => 'Slot requests are not available.' ), 400 );
        }

        if ( ! self::requests_table_ready() ) {
            self::install_tables();
        }
        if ( ! self::requests_table_ready() ) {
            wp_send_json_error( array( 'message' => 'Database upgrade pending. Reload in a minute.' ), 503 );
        }

        self::ensure_designer_payment_method_column();

        $user_id             = get_current_user_id();
        $board_id            = isset( $_POST['board_id'] ) ? absint( $_POST['board_id'] ) : 0;
        $posted_project_only = isset( $_POST['project_id'] ) ? absint( $_POST['project_id'] ) : 0;

        $board = $board_id > 0 ? N88_Authorization::get_board_for_user( $board_id, $user_id ) : null;
        if ( $board_id <= 0 || ! $board ) {
            wp_send_json_error( array( 'message' => 'Select a workspace board first.' ), 400 );
        }

        $resolved_project = N88_Item_Unlock::resolve_fp_project_for_new_item( $user_id, $board_id, $posted_project_only );
        $gate             = N88_Item_Unlock::get_fp_slot_gate( $resolved_project, $user_id, absint( $board_id ) );
        if ( $gate['remaining'] > 0 ) {
            wp_send_json_error( array( 'message' => 'You still have full-process slots available — add items directly.' ), 400 );
        }

        global $wpdb;
        $tbl       = preg_replace( '/[^a-zA-Z0-9_]/', '', self::table_name() );
        $duplicate = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$tbl} WHERE designer_user_id = %d AND board_id = %d AND status = %s LIMIT 1",
                $user_id,
                absint( $board_id ),
                'pending'
            )
        );
        if ( $duplicate ) {
            wp_send_json_error( array( 'message' => 'You already have a pending payment approval for this board.' ), 400 );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        if ( empty( $_FILES['proof_file']['tmp_name'] ) || ! is_uploaded_file( wp_unslash( $_FILES['proof_file']['tmp_name'] ) ) ) {
            wp_send_json_error( array( 'message' => 'Attach a JPG, PNG, or PDF payment confirmation.' ), 400 );
        }

        $mimes       = array(
            'jpg|jpeg|jpe' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
        );
        $file_field  = array(
            'name' => isset( $_FILES['proof_file']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['proof_file']['name'] ) ) : '',
            'type' => isset( $_FILES['proof_file']['type'] ) ? sanitize_text_field( wp_unslash( $_FILES['proof_file']['type'] ) ) : '',
            'tmp_name' => isset( $_FILES['proof_file']['tmp_name'] ) ? wp_unslash( $_FILES['proof_file']['tmp_name'] ) : '',
            'error' => isset( $_FILES['proof_file']['error'] ) ? absint( $_FILES['proof_file']['error'] ) : UPLOAD_ERR_NO_FILE,
            'size' => isset( $_FILES['proof_file']['size'] ) ? absint( $_FILES['proof_file']['size'] ) : 0,
        );
        $upload      = wp_handle_upload(
            $file_field,
            array(
                'test_form' => false,
                'mimes' => $mimes,
            )
        );
        if ( isset( $upload['error'] ) ) {
            wp_send_json_error( array( 'message' => (string) $upload['error'] ), 400 );
        }

        $attachment = array(
            'post_mime_type' => $upload['type'],
            'post_title' => sanitize_text_field( pathinfo( wp_basename( (string) $upload['file'] ), PATHINFO_FILENAME ) ),
            'post_content' => '',
            'post_status' => 'inherit',
        );
        $attach_id   = wp_insert_attachment( $attachment, $upload['file'] );
        if ( ! $attach_id || is_wp_error( $attach_id ) ) {
            wp_send_json_error( array( 'message' => 'Failed to store upload.' ), 500 );
        }
        $meta = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
        wp_update_attachment_metadata( $attach_id, $meta );
        wp_update_post(
            array(
                'ID' => $attach_id,
                'post_author' => $user_id,
            )
        );

        $payment_method = isset( $_POST['payment_method'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_method'] ) ) : '';
        if ( strlen( $payment_method ) > 120 ) {
            $payment_method = substr( $payment_method, 0, 120 );
        }

        $now = current_time( 'mysql' );

        $row_data = array(
            'designer_user_id'    => $user_id,
            'board_id'            => absint( $board_id ),
            'project_id'          => absint( $posted_project_only ),
            'resolved_project_id' => absint( $resolved_project ),
            'status'              => 'pending',
            'attachment_id'       => absint( $attach_id ),
            'created_at'          => $now,
            'updated_at'          => $now,
        );
        $row_fmt = array( '%d', '%d', '%d', '%d', '%s', '%d', '%s', '%s' );

        $cols = $wpdb->get_col( "DESCRIBE {$tbl}" );
        if ( is_array( $cols ) && in_array( 'designer_payment_method', $cols, true ) ) {
            $row_data['designer_payment_method'] = $payment_method;
            $row_fmt[]                         = '%s';
        }

        $ins = $wpdb->insert( $tbl, $row_data, $row_fmt );

        if ( ! $ins ) {
            wp_send_json_error( array( 'message' => 'Could not queue request.' ), 500 );
        }

        wp_send_json_success(
            array(
                'request_id' => (int) $wpdb->insert_id,
                'message' => 'Submitted. Operator will review your payment confirmation.',
            )
        );
    }

    public function ajax_operator_list() {
        N88_RFQ_Helpers::verify_ajax_nonce();

        if ( ! self::can_review_slot_requests() ) {
            wp_send_json_error( array( 'message' => 'Forbidden.' ), 403 );
        }

        self::ensure_designer_payment_method_column();

        if ( ! self::requests_table_ready() ) {
            wp_send_json_success( array( 'rows' => array() ) );
        }

        global $wpdb;
        $tbl = preg_replace( '/[^a-zA-Z0-9_]/', '', self::table_name() );

        $pm_sel = '';
        $cols_list = $wpdb->get_col( "DESCRIBE {$tbl}" );
        if ( is_array( $cols_list ) && in_array( 'designer_payment_method', $cols_list, true ) ) {
            $pm_sel = ', r.designer_payment_method';
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name sanitized
        $rows = $wpdb->get_results(
            "SELECT r.id, r.designer_user_id, r.board_id, r.project_id, r.resolved_project_id, r.status, r.attachment_id{$pm_sel}, r.created_at, r.reviewer_user_id, r.reviewed_at, r.reviewer_note 
			FROM {$tbl} AS r WHERE r.status = 'pending' ORDER BY r.created_at ASC LIMIT 100",
            ARRAY_A
        );

        $out = array();
        foreach ( (array) $rows as $rw ) {
            $uid = isset( $rw['designer_user_id'] ) ? absint( $rw['designer_user_id'] ) : 0;
            $u   = $uid ? get_userdata( $uid ) : false;
            $aid = isset( $rw['attachment_id'] ) ? absint( $rw['attachment_id'] ) : 0;
            $out[] = array(
                'id' => isset( $rw['id'] ) ? absint( $rw['id'] ) : 0,
                'designer_display' => $u ? $u->display_name : ( 'User ' . $uid ),
                'board_id' => isset( $rw['board_id'] ) ? absint( $rw['board_id'] ) : 0,
                'project_scope' => isset( $rw['project_id'] ) ? absint( $rw['project_id'] ) : 0,
                'resolved_project_id' => isset( $rw['resolved_project_id'] ) ? absint( $rw['resolved_project_id'] ) : 0,
                'created_at' => isset( $rw['created_at'] ) ? (string) $rw['created_at'] : '',
                'proof_url' => $aid ? wp_get_attachment_url( $aid ) : '',
                'payment_method' => isset( $rw['designer_payment_method'] ) ? (string) $rw['designer_payment_method'] : '',
                'status' => isset( $rw['status'] ) ? (string) $rw['status'] : '',
            );
        }

        wp_send_json_success( array( 'rows' => $out ) );
    }

    public function ajax_operator_decide() {
        N88_RFQ_Helpers::verify_ajax_nonce();

        if ( ! self::can_review_slot_requests() ) {
            wp_send_json_error( array( 'message' => 'Forbidden.' ), 403 );
        }

        $request_id = isset( $_POST['request_id'] ) ? absint( $_POST['request_id'] ) : 0;
        $action     = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
        if ( ! $request_id || ! in_array( $action, array( 'approve', 'reject' ), true ) ) {
            wp_send_json_error( array( 'message' => 'Invalid request.' ), 400 );
        }

        if ( ! self::requests_table_ready() ) {
            wp_send_json_error( array( 'message' => 'Table missing.' ), 500 );
        }

        global $wpdb;
        $tbl = preg_replace( '/[^a-zA-Z0-9_]/', '', self::table_name() );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name sanitized
        $req = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id = %d LIMIT 1", $request_id ), ARRAY_A );
        if ( ! $req || ( isset( $req['status'] ) && $req['status'] !== 'pending' ) ) {
            wp_send_json_error( array( 'message' => 'Request not pending.' ), 400 );
        }

        $designer_user_id       = isset( $req['designer_user_id'] ) ? absint( $req['designer_user_id'] ) : 0;
        $resolved_project_grant = isset( $req['resolved_project_id'] ) ? absint( $req['resolved_project_id'] ) : 0;

        $now = current_time( 'mysql' );
        $op  = get_current_user_id();

        if ( 'reject' === $action ) {
            $wpdb->update(
                $tbl,
                array(
                    'status' => 'rejected',
                    'reviewed_at' => $now,
                    'reviewer_user_id' => absint( $op ),
                    'updated_at' => $now,
                ),
                array( 'id' => $request_id ),
                array( '%s', '%s', '%d', '%s' ),
                array( '%d' )
            );
            wp_send_json_success( array( 'status' => 'rejected' ) );
        }

        $board_grant = isset( $req['board_id'] ) ? absint( $req['board_id'] ) : 0;
        if ( $board_grant > 0 ) {
            N88_Item_Unlock::grant_one_additional_board_fp_slot( $board_grant );
        } else {
            N88_Item_Unlock::grant_one_additional_fp_slot( $resolved_project_grant, $designer_user_id );
        }

        $wpdb->update(
            $tbl,
            array(
                'status' => 'approved',
                'reviewed_at' => $now,
                'reviewer_user_id' => absint( $op ),
                'updated_at' => $now,
            ),
            array( 'id' => $request_id ),
            array( '%s', '%s', '%d', '%s' ),
            array( '%d' )
        );

        wp_send_json_success( array( 'status' => 'approved' ) );
    }

    public function ajax_supplier_list() {
        N88_RFQ_Helpers::verify_ajax_nonce();

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'You must be logged in.' ), 401 );
        }

        $user_id = get_current_user_id();
        if ( ! self::is_supplier_user() && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Forbidden.' ), 403 );
        }

        $board_id = isset( $_POST['board_id'] ) ? absint( $_POST['board_id'] ) : 0;
        if ( $board_id <= 0 ) {
            wp_send_json_error( array( 'message' => 'Board required.' ), 400 );
        }

        if ( ! self::supplier_can_view_board_slot_activity( $board_id, $user_id ) ) {
            wp_send_json_error( array( 'message' => 'Access denied.' ), 403 );
        }

        if ( ! self::requests_table_ready() ) {
            wp_send_json_success( array( 'rows' => array() ) );
        }

        global $wpdb;
        $tbl = preg_replace( '/[^a-zA-Z0-9_]/', '', self::table_name() );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- sanitized table/board id
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT r.id, r.designer_user_id, r.resolved_project_id, r.created_at, r.reviewed_at, r.attachment_id
				FROM {$tbl} AS r
				WHERE r.board_id = %d AND r.status = %s
				AND r.reviewed_at >= (NOW() - INTERVAL 45 DAY)
				ORDER BY r.reviewed_at DESC LIMIT 30",
                $board_id,
                'approved'
            ),
            ARRAY_A
        );

        $out = array();
        foreach ( (array) $rows as $rw ) {
            $uid        = isset( $rw['designer_user_id'] ) ? absint( $rw['designer_user_id'] ) : 0;
            $u          = $uid ? get_userdata( $uid ) : false;
            $approved_at = isset( $rw['reviewed_at'] ) ? mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (string) $rw['reviewed_at'], true ) : '';
            $out[]      = array(
                'id' => isset( $rw['id'] ) ? absint( $rw['id'] ) : 0,
                'designer_display' => $u ? $u->display_name : ( 'Designer ' . $uid ),
                'resolved_project_id' => isset( $rw['resolved_project_id'] ) ? absint( $rw['resolved_project_id'] ) : 0,
                'reviewed_display' => $approved_at,
            );
        }

        wp_send_json_success( array( 'rows' => $out ) );
    }

    /**
     * Legacy installs: column added in 3.D.8C DDL; ALTER if missing.
     */
    private static function ensure_designer_payment_method_column() {
        global $wpdb;
        static $did_check = false;
        if ( $did_check ) {
            return;
        }
        if ( ! self::requests_table_ready() ) {
            return;
        }
        $tbl = preg_replace( '/[^a-zA-Z0-9_]/', '', self::table_name() );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- sanitized table name
        $cols = $wpdb->get_col( "DESCRIBE {$tbl}", 0 );
        if ( ! is_array( $cols ) || in_array( 'designer_payment_method', $cols, true ) ) {
            $did_check = true;
            return;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- sanitized table name
        $wpdb->query( "ALTER TABLE {$tbl} ADD COLUMN designer_payment_method VARCHAR(120) NOT NULL DEFAULT '' AFTER attachment_id" );
        $did_check = true;
    }

    /**
     * Called by installer once.
     */
    public static function install_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $tbl             = preg_replace( '/[^a-zA-Z0-9_]/', '', self::table_name() );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- DDL with sanitized name
        $sql = "CREATE TABLE {$tbl} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			designer_user_id BIGINT UNSIGNED NOT NULL,
			board_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			project_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			resolved_project_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			designer_payment_method VARCHAR(120) NOT NULL DEFAULT '',
			reviewer_user_id BIGINT UNSIGNED NULL,
			reviewed_at DATETIME NULL,
			reviewer_note TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY designer_user_id (designer_user_id),
			KEY board_id (board_id),
			KEY project_id (project_id),
			KEY resolved_project_id (resolved_project_id),
			KEY status_created (status, created_at)
		) {$charset_collate};";

        dbDelta( $sql );
        update_option( self::$did_create_table_option, '1' );
    }
}
