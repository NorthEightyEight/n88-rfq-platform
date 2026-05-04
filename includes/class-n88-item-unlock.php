<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Commit 3.D.8B: Full-process item unlock (2 free per project + $149 display for additional).
 *
 * Stored flags — no recount on render. Production-only bypasses monetization/eligibility gates.
 */
class N88_Item_Unlock {

    const FULL_PROCESS_FREE_CAP = 2;

    /** Display-only price (instant unlock toggle; checkout not wired here). */
    const UNLOCK_PRICE_USD = 149;

    /** User meta holding paid slot grants when no project scoped column exists. */
    const OWNER_FP_PAID_SLOTS_META = 'n88_fp_owner_paid_slot_grants';

    /** Project column for paid slot grants when present. */
    const PROJECT_FP_PAID_SLOTS_COLUMN = 'fp_paid_item_slots_granted';

    /** Option key storing per-board granted paid slots `{ board_id_string: int }`. */
    const BOARD_FP_GRANTS_OPTION = 'n88_board_fp_paid_slots_v1';

    /** Option key: migrate ran once */
    const MIGRATION_OPTION_KEY = 'n88_migrated_3_d_8_b_item_unlock';

    /** Per-user denormalized slot when item has no project row (board_id 0, project unresolved). */
    private static function owner_fp_option_name( $owner_user_id ) {
        return '_n88_fp_slot_u' . (int) $owner_user_id;
    }

    /**
     * Effective project for full_process unlock counter and item.project_id.
     *
     * When board_id is set: uses requested project if it belongs to that board, else first project
     * on the board, else creates a minimal "Project" row. When board_id is 0: uses requested project
     * if the user can access its board.
     *
     * @param int $owner_user_id Item owner.
     * @param int $board_id      Board from create request (0 if none).
     * @param int $requested_id  Client project_id (0 if none).
     * @return int Project id or 0 (caller uses per-owner slot bucket).
     */
    public static function resolve_fp_project_for_new_item( $owner_user_id, $board_id, $requested_id ) {
        global $wpdb;
        $owner_user_id = absint( $owner_user_id );
        $board_id      = absint( $board_id );
        $requested     = absint( $requested_id );

        if ( ! self::project_fp_counter_column_exists() || ! class_exists( 'N88_Authorization' ) ) {
            return 0;
        }

        $t      = $wpdb->prefix . 'n88_projects';
        $t_safe = preg_replace( '/[^a-zA-Z0-9_]/', '', $t );

        if ( $requested > 0 ) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, board_id FROM {$t_safe} WHERE id = %d AND (deleted_at IS NULL OR deleted_at = '')",
                    $requested
                ),
                ARRAY_A
            );
            if ( $row && ! empty( $row['board_id'] ) ) {
                $pb = (int) $row['board_id'];
                if ( ! N88_Authorization::get_board_for_user( $pb, $owner_user_id ) ) {
                    $requested = 0;
                } elseif ( $board_id > 0 && $pb !== $board_id ) {
                    $requested = 0;
                } else {
                    return (int) $row['id'];
                }
            } else {
                $requested = 0;
            }
        }

        if ( $board_id <= 0 ) {
            return 0;
        }

        if ( ! N88_Authorization::get_board_for_user( $board_id, $owner_user_id ) ) {
            return 0;
        }

        $first = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$t_safe} WHERE board_id = %d AND (deleted_at IS NULL OR deleted_at = '') ORDER BY id ASC LIMIT 1",
                $board_id
            )
        );
        if ( $first ) {
            return (int) $first;
        }

        $now       = current_time( 'mysql' );
        $proj_cols = $wpdb->get_col( "DESCRIBE {$t_safe}" );
        $ins       = array(
            'board_id'    => $board_id,
            'name'        => 'Project',
            'description' => '',
            'status'      => 'draft',
            'created_at'  => $now,
            'updated_at'  => $now,
        );
        $fmt = array( '%d', '%s', '%s', '%s', '%s', '%s' );
        if ( is_array( $proj_cols ) && in_array( 'full_process_item_count', $proj_cols, true ) ) {
            $ins['full_process_item_count'] = 0;
            $fmt[]                          = '%d';
        }

        $ok = $wpdb->insert( $t_safe, $ins, $fmt );
        if ( ! $ok ) {
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * @return false|int New slot index after increment (>=1).
     */
    private static function increment_fp_slot_owner_bucket( $owner_user_id ) {
        global $wpdb;
        $owner_user_id = absint( $owner_user_id );
        if ( ! $owner_user_id || ! self::items_unlock_columns_exist() ) {
            return false;
        }
        $name = self::owner_fp_option_name( $owner_user_id );
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no')
                ON DUPLICATE KEY UPDATE option_value = CAST(option_value AS UNSIGNED) + 1",
                $name
            )
        );
        if ( ! empty( $wpdb->last_error ) ) {
            return false;
        }
        $v = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
        return max( 1, (int) $v );
    }

    /**
     * Best-effort decrement when a full_process item without project_id is removed.
     */
    private static function decrement_fp_slot_owner_bucket( $owner_user_id ) {
        global $wpdb;
        $owner_user_id = absint( $owner_user_id );
        if ( ! $owner_user_id ) {
            return;
        }
        $name = self::owner_fp_option_name( $owner_user_id );
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = GREATEST(0, CAST(option_value AS UNSIGNED) - 1) WHERE option_name = %s",
                $name
            )
        );
    }

    private static $items_cols_checked = false;

    private static $has_item_unlock_cols = false;

    private static $projects_col_checked = false;

    private static $has_projects_fp_col = false;

    /**
     * @return bool
     */
    public static function items_unlock_columns_exist() {
        if ( self::$items_cols_checked ) {
            return self::$has_item_unlock_cols;
        }
        global $wpdb;
        self::$items_cols_checked       = true;
        self::$has_item_unlock_cols     = false;
        $table                           = preg_replace( '/[^a-zA-Z0-9_]/', '', $wpdb->prefix . 'n88_items' );
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
            return false;
        }
        $cols = $wpdb->get_col( "DESCRIBE {$table}" );
        self::$has_item_unlock_cols = is_array( $cols )
            && in_array( 'is_free', $cols, true )
            && in_array( 'is_paid', $cols, true )
            && in_array( 'is_locked', $cols, true );
        return self::$has_item_unlock_cols;
    }

    /**
     * @return bool
     */
    public static function project_fp_counter_column_exists() {
        if ( self::$projects_col_checked ) {
            return self::$has_projects_fp_col;
        }
        global $wpdb;
        self::$projects_col_checked   = true;
        self::$has_projects_fp_col     = false;
        $projects_table               = preg_replace( '/[^a-zA-Z0-9_]/', '', $wpdb->prefix . 'n88_projects' );
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$projects_table}'" ) !== $projects_table ) {
            return false;
        }
        $cols                         = $wpdb->get_col( "DESCRIBE {$projects_table}" );
        self::$has_projects_fp_col    = is_array( $cols ) && in_array( 'full_process_item_count', $cols, true );
        return self::$has_projects_fp_col;
    }

    private static $projects_grant_col_checked = false;

    private static $has_projects_grant_col      = false;

    /**
     * @return bool
     */
    public static function project_fp_paid_slots_column_exists() {
        if ( self::$projects_grant_col_checked ) {
            return self::$has_projects_grant_col;
        }
        global $wpdb;
        self::$projects_grant_col_checked = true;
        self::$has_projects_grant_col     = false;
        $projects_table                   = preg_replace( '/[^a-zA-Z0-9_]/', '', $wpdb->prefix . 'n88_projects' );
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$projects_table}'" ) !== $projects_table ) {
            return false;
        }
        $cols = $wpdb->get_col( "DESCRIBE {$projects_table}" );
        self::$has_projects_grant_col = is_array( $cols ) && in_array( self::PROJECT_FP_PAID_SLOTS_COLUMN, $cols, true );
        return self::$has_projects_grant_col;
    }

    /**
     * Count live full-process workspace items attached to the board (excludes tracking-only/production_only meta).
     *
     * @param int $board_id Board id.
     * @return int
     */
    public static function count_full_process_items_on_board( $board_id ) {
        global $wpdb;
        $board_id = absint( $board_id );
        if ( $board_id <= 0 ) {
            return 0;
        }
        $bi_safe    = preg_replace( '/[^a-zA-Z0-9_]/', '', $wpdb->prefix . 'n88_board_items' );
        $items_safe = preg_replace( '/[^a-zA-Z0-9_]/', '', $wpdb->prefix . 'n88_items' );
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$bi_safe}'" ) !== $bi_safe || $wpdb->get_var( "SHOW TABLES LIKE '{$items_safe}'" ) !== $items_safe ) {
            return 0;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names sanitized; LIKE escaped
        $pat = '%' . $wpdb->esc_like( '"entry_mode":"production_only"' ) . '%';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- sanitized identifiers
        $n = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$bi_safe} bi
				INNER JOIN {$items_safe} i ON i.id = bi.item_id AND (i.deleted_at IS NULL OR i.deleted_at = '')
				WHERE bi.board_id = %d AND (bi.removed_at IS NULL OR bi.removed_at = '')
				AND (i.meta_json IS NULL OR i.meta_json = '' OR i.meta_json NOT LIKE %s)",
                $board_id,
                $pat
            )
        );

        return (int) max( 0, (int) $n );
    }

    /**
     * All live workspace rows on board (full_process + production_only) for unified 2-free + paid slot gate.
     *
     * @param int $board_id Board id.
     * @return int
     */
    public static function count_board_slot_items_all_modes( $board_id ) {
        global $wpdb;
        $board_id = absint( $board_id );
        if ( $board_id <= 0 ) {
            return 0;
        }
        $bi_safe    = preg_replace( '/[^a-zA-Z0-9_]/', '', $wpdb->prefix . 'n88_board_items' );
        $items_safe = preg_replace( '/[^a-zA-Z0-9_]/', '', $wpdb->prefix . 'n88_items' );
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$bi_safe}'" ) !== $bi_safe || $wpdb->get_var( "SHOW TABLES LIKE '{$items_safe}'" ) !== $items_safe ) {
            return 0;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- sanitized identifiers
        $n = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$bi_safe} bi
				INNER JOIN {$items_safe} i ON i.id = bi.item_id AND (i.deleted_at IS NULL OR i.deleted_at = '')
				WHERE bi.board_id = %d AND (bi.removed_at IS NULL OR bi.removed_at = '')",
                $board_id
            )
        );
        return (int) max( 0, (int) $n );
    }

    /**
     * @param int $board_id Board id.
     * @return int
     */
    public static function get_board_fp_paid_slots_granted( $board_id ) {
        $board_id = absint( $board_id );
        $map      = get_option( self::BOARD_FP_GRANTS_OPTION, array() );
        if ( ! is_array( $map ) ) {
            return 0;
        }
        $key = (string) $board_id;
        return (int) max( 0, (int) ( $map[ $key ] ?? 0 ) );
    }

    /**
     * Persist one purchased extra FP slot grant for this board ($149 approvals).
     *
     * @param int $board_id Board id.
     */
    public static function grant_one_additional_board_fp_slot( $board_id ) {
        $board_id = absint( $board_id );
        if ( $board_id <= 0 ) {
            return;
        }
        $map = get_option( self::BOARD_FP_GRANTS_OPTION, array() );
        if ( ! is_array( $map ) ) {
            $map = array();
        }
        $key         = (string) $board_id;
        $map[ $key ] = isset( $map[ $key ] ) ? (int) $map[ $key ] + 1 : 1;
        update_option( self::BOARD_FP_GRANTS_OPTION, $map, false );
    }

    /**
     * @param int $project_id Project row id.
     * @return int
     */
    public static function get_project_fp_stored_count( $project_id ) {
        global $wpdb;
        $project_id = absint( $project_id );
        if ( ! $project_id || ! self::project_fp_counter_column_exists() ) {
            return 0;
        }
        $t_safe = preg_replace( '/[^a-zA-Z0-9_]/', '', $wpdb->prefix . 'n88_projects' );
        $v      = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT full_process_item_count FROM {$t_safe} WHERE id = %d AND (deleted_at IS NULL OR deleted_at = '')",
                $project_id
            )
        );
        return (int) max( 0, (int) $v );
    }

    /**
     * @param int $project_id Project row id.
     * @return int
     */
    public static function get_project_fp_paid_slots_granted( $project_id ) {
        global $wpdb;
        $project_id = absint( $project_id );
        if ( ! $project_id || ! self::project_fp_paid_slots_column_exists() ) {
            return 0;
        }
        $t_safe = preg_replace( '/[^a-zA-Z0-9_]/', '', $wpdb->prefix . 'n88_projects' );
        $col    = preg_replace( '/[^a-zA-Z0-9_]/', '', self::PROJECT_FP_PAID_SLOTS_COLUMN );
        $v      = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT {$col} FROM {$t_safe} WHERE id = %d AND (deleted_at IS NULL OR deleted_at = '')",
                $project_id
            )
        );
        return (int) max( 0, (int) $v );
    }

    /**
     * @param int $owner_user_id Designer id.
     * @return int
     */
    public static function get_owner_fp_bucket_count( $owner_user_id ) {
        $owner_user_id = absint( $owner_user_id );
        if ( ! $owner_user_id ) {
            return 0;
        }
        return (int) max( 0, (int) get_option( self::owner_fp_option_name( $owner_user_id ), 0 ) );
    }

    /**
     * @param int $owner_user_id Designer id.
     * @return int
     */
    public static function get_owner_fp_paid_slots_granted( $owner_user_id ) {
        $owner_user_id = absint( $owner_user_id );
        if ( ! $owner_user_id ) {
            return 0;
        }
        return (int) max( 0, (int) get_user_meta( $owner_user_id, self::OWNER_FP_PAID_SLOTS_META, true ) );
    }

    /**
     * @param int $project_id         Resolved FP project bucket (unused when gate is board-scoped).
     * @param int $owner_user_id      Owner / designer user id (legacy bucket).
     * @param int $board_id_for_gate  When >0 counts items on board for slot math.
     * @return array{current:int,granted_extra:int,allowed_total:int,remaining:int,free_cap:int}
     */
    public static function get_fp_slot_gate( $project_id, $owner_user_id, $board_id_for_gate = 0 ) {
        $board_id_gate = absint( $board_id_for_gate );

        if ( ! self::items_unlock_columns_exist() ) {
            return array(
                'current'       => 0,
                'granted_extra' => 0,
                'allowed_total' => 9999,
                'remaining'     => 9999,
                'free_cap'      => self::FULL_PROCESS_FREE_CAP,
            );
        }

        if ( $board_id_gate > 0 ) {
            $current   = self::count_board_slot_items_all_modes( $board_id_gate );
            $granted   = self::get_board_fp_paid_slots_granted( $board_id_gate );
            $allowed   = self::FULL_PROCESS_FREE_CAP + max( 0, $granted );
            $remaining = max( 0, $allowed - $current );

            return array(
                'current'       => $current,
                'granted_extra' => max( 0, $granted ),
                'allowed_total' => $allowed,
                'remaining'     => $remaining,
                'free_cap'      => self::FULL_PROCESS_FREE_CAP,
            );
        }

        $project_id      = absint( $project_id );
        $owner_user_id = absint( $owner_user_id );

        if ( $project_id > 0 && self::project_fp_counter_column_exists() ) {
            $current   = self::get_project_fp_stored_count( $project_id );
            $granted   = self::get_project_fp_paid_slots_granted( $project_id );
            $allowed   = self::FULL_PROCESS_FREE_CAP + max( 0, $granted );
            $remaining = max( 0, $allowed - $current );

            return array(
                'current'       => $current,
                'granted_extra' => max( 0, $granted ),
                'allowed_total' => $allowed,
                'remaining'     => $remaining,
                'free_cap'      => self::FULL_PROCESS_FREE_CAP,
            );
        }

        $bucket    = self::get_owner_fp_bucket_count( $owner_user_id );
        $ogrants   = self::get_owner_fp_paid_slots_granted( $owner_user_id );
        $allowed_o = self::FULL_PROCESS_FREE_CAP + max( 0, $ogrants );

        return array(
            'current'       => max( 0, $bucket ),
            'granted_extra' => max( 0, $ogrants ),
            'allowed_total' => max( 0, $allowed_o ),
            'remaining'     => max( 0, $allowed_o - $bucket ),
            'free_cap'      => self::FULL_PROCESS_FREE_CAP,
        );
    }

    /**
     * @param int $project_id         FP project bucket.
     * @param int $owner_user_id      Designer.
     * @param int $board_id_for_gate  Board scope (>0 preferred on workspace boards).
     * @return bool
     */
    public static function full_process_slot_available( $project_id, $owner_user_id, $board_id_for_gate = 0 ) {
        $g = self::get_fp_slot_gate( $project_id, $owner_user_id, $board_id_for_gate );
        return $g['remaining'] > 0;
    }

    /**
     * Extend paid slots for legacy project / owner buckets (board uses grant_one_additional_board_fp_slot).
     *
     * @param int $project_id    Resolved project row.
     * @param int $owner_user_id Designer user id.
     */
    public static function grant_one_additional_fp_slot( $project_id, $owner_user_id ) {
        global $wpdb;
        $project_id      = absint( $project_id );
        $owner_user_id = absint( $owner_user_id );

        if ( $project_id > 0 && self::project_fp_paid_slots_column_exists() ) {
            $t_safe = preg_replace( '/[^a-zA-Z0-9_]/', '', $wpdb->prefix . 'n88_projects' );
            $col    = preg_replace( '/[^a-zA-Z0-9_]/', '', self::PROJECT_FP_PAID_SLOTS_COLUMN );
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$t_safe} SET {$col} = LEAST({$col} + 1, 10000) WHERE id = %d",
                    $project_id
                )
            );
            return;
        }

        if ( ! $owner_user_id ) {
            return;
        }
        $existing = absint( get_user_meta( $owner_user_id, self::OWNER_FP_PAID_SLOTS_META, true ) );
        update_user_meta( $owner_user_id, self::OWNER_FP_PAID_SLOTS_META, min( $existing + 1, 10000 ) );
    }

    /**
     * @param array<string,mixed>|null $meta Decoded meta_json.
     * @return string 'full_process'|'production_only'
     */
    public static function normalized_entry_mode( $meta ) {
        if ( ! is_array( $meta ) ) {
            return 'full_process';
        }
        $em = isset( $meta['entry_mode'] ) ? sanitize_key( (string) $meta['entry_mode'] ) : 'full_process';
        if ( ! in_array( $em, array( 'full_process', 'production_only' ), true ) ) {
            return 'full_process';
        }
        return $em;
    }

    /**
     * Workflow eligibility for RFQ, batch routing, samples, supplier visibility.
     *
     * Full-process monetization gates only at “Add Item”; cards stay eligible once created.
     *
     * @param array<string,mixed>|null $meta Meta array.
     * @param int|string|null          $is_free DB flag.
     * @param int|string|null          $is_paid DB flag.
     * @return bool
     */
    public static function workflow_eligible( $meta, $is_free = null, $is_paid = null ) {
        return true;
    }

    /**
     * Fields for designer board payload / React props.
     *
     * @param object|array|null        $db_row Row from JOIN (may contain is_*).
     * @param array<string,mixed>|null $meta   Decoded meta_json.
     * @return array<string,mixed>
     */
    public static function frontend_payload_from_row( $db_row, $meta ) {
        $meta = is_array( $meta ) ? $meta : array();
        $entry = self::normalized_entry_mode( $meta );

        $is_free   = isset( $db_row->is_free ) ? (int) $db_row->is_free : null;
        $is_paid   = isset( $db_row->is_paid ) ? (int) $db_row->is_paid : null;
        $is_locked = isset( $db_row->is_locked ) ? (int) $db_row->is_locked : null;
        if ( is_array( $db_row ) ) {
            $is_free   = isset( $db_row['is_free'] ) ? (int) $db_row['is_free'] : $is_free;
            $is_paid   = isset( $db_row['is_paid'] ) ? (int) $db_row['is_paid'] : $is_paid;
            $is_locked = isset( $db_row['is_locked'] ) ? (int) $db_row['is_locked'] : $is_locked;
        }

        if ( ! self::items_unlock_columns_exist() || null === $is_free ) {
            $is_free    = 'production_only' === $entry ? 0 : 1;
            $is_paid    = 'production_only' === $entry ? 1 : 0;
            $is_locked  = 0;
        }

        $eligible = true;

        return array(
            'entry_mode'          => $entry,
            'is_free'             => (bool) $is_free,
            'is_paid'             => (bool) $is_paid,
            'is_locked'           => (bool) intval( $is_locked ),
            'workflow_eligible'   => $eligible,
            'unlock_price_usd'    => self::UNLOCK_PRICE_USD,
        );
    }

    /**
     * @param string               $entry_mode 'full_process'|'production_only'.
     * @param int                  $project_id n88_projects.id for counter (0 = use owner bucket if owner_user_id set).
     * @param int                  $owner_user_id User id for per-owner fallback when project_id is 0.
     * @param int                  $board_id_for_gate Workspace board for 2-free + paid slot counter.
     * @return array{is_free:int,is_paid:int,is_locked:int}
     */
    public static function flags_for_new_item( $entry_mode, $project_id, $owner_user_id = 0, $board_id_for_gate = 0 ) {
        $entry_mode = sanitize_key( (string) $entry_mode );
        if ( ! in_array( $entry_mode, array( 'full_process', 'production_only' ), true ) ) {
            $entry_mode = 'full_process';
        }
        // Production-only without a board skips slot tiers (legacy); with board shares same gate as full_process.
        if ( 'production_only' === $entry_mode && absint( $board_id_for_gate ) <= 0 ) {
            return array(
                'is_free'   => 0,
                'is_paid'   => 1,
                'is_locked' => 0,
            );
        }
        if ( ! self::items_unlock_columns_exist() ) {
            return array( 'is_free' => 1, 'is_paid' => 0, 'is_locked' => 0 );
        }
        $project_id          = absint( $project_id );
        $owner_user_id       = absint( $owner_user_id );
        $board_id_for_gate = absint( $board_id_for_gate );

        if ( $board_id_for_gate > 0 ) {
            $next_slot = self::count_board_slot_items_all_modes( $board_id_for_gate ) + 1;
            $granted_b = self::get_board_fp_paid_slots_granted( $board_id_for_gate );
            $allowed   = self::FULL_PROCESS_FREE_CAP + max( 0, $granted_b );
            if ( 'full_process' === $entry_mode && $project_id > 0 && self::project_fp_counter_column_exists() ) {
                self::increment_fp_count_for_project( $project_id );
            }
            if ( $next_slot <= self::FULL_PROCESS_FREE_CAP ) {
                return array(
                    'is_free'   => 1,
                    'is_paid'   => 0,
                    'is_locked' => 0,
                );
            }
            if ( $next_slot <= $allowed ) {
                return array(
                    'is_free'   => 0,
                    'is_paid'   => 1,
                    'is_locked' => 0,
                );
            }
            return array(
                'is_free'   => 0,
                'is_paid'   => 1,
                'is_locked' => 0,
            );
        }

        if ( $project_id > 0 && self::project_fp_counter_column_exists() ) {
            $slot = self::increment_fp_count_for_project( $project_id );
            if ( false !== $slot ) {
                $granted = self::get_project_fp_paid_slots_granted( $project_id );
                $allowed = self::FULL_PROCESS_FREE_CAP + max( 0, $granted );
                if ( $slot <= self::FULL_PROCESS_FREE_CAP ) {
                    return array(
                        'is_free'   => 1,
                        'is_paid'   => 0,
                        'is_locked' => 0,
                    );
                }
                if ( $slot <= $allowed ) {
                    return array(
                        'is_free'   => 0,
                        'is_paid'   => 1,
                        'is_locked' => 0,
                    );
                }
                return array(
                    'is_free'   => 0,
                    'is_paid'   => 1,
                    'is_locked' => 0,
                );
            }
        }

        if ( $owner_user_id > 0 ) {
            $slot_o = self::increment_fp_slot_owner_bucket( $owner_user_id );
            if ( false !== $slot_o ) {
                $ogrants = self::get_owner_fp_paid_slots_granted( $owner_user_id );
                $allowed = self::FULL_PROCESS_FREE_CAP + max( 0, $ogrants );
                if ( $slot_o <= self::FULL_PROCESS_FREE_CAP ) {
                    return array(
                        'is_free'   => 1,
                        'is_paid'   => 0,
                        'is_locked' => 0,
                    );
                }
                if ( $slot_o <= $allowed ) {
                    return array(
                        'is_free'   => 0,
                        'is_paid'   => 1,
                        'is_locked' => 0,
                    );
                }
                return array(
                    'is_free'   => 0,
                    'is_paid'   => 1,
                    'is_locked' => 0,
                );
            }
        }

        return array(
            'is_free'   => 1,
            'is_paid'   => 0,
            'is_locked' => 0,
        );
    }

    /**
     * Bump denormalized full_process counter; returns slot index for this creation (>=1).
     *
     * @param int $project_id Active project row id.
     * @return false|int
     */
    public static function increment_fp_count_for_project( $project_id ) {
        global $wpdb;
        $project_id = absint( $project_id );
        $t          = $wpdb->prefix . 'n88_projects';
        $t_safe     = preg_replace( '/[^a-zA-Z0-9_]/', '', $t );
        $ok         = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$t_safe} WHERE id = %d AND (deleted_at IS NULL OR deleted_at = '')",
                $project_id
            )
        );
        if ( ! $ok ) {
            return false;
        }
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$t_safe} SET full_process_item_count = full_process_item_count + 1 WHERE id = %d",
                $project_id
            )
        );
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT full_process_item_count FROM {$t_safe} WHERE id = %d",
                $project_id
            )
        );
    }

    /**
     * Call before soft-delete of an active item row (still has deleted_at IS NULL).
     *
     * @param int                         $item_id Item id.
     * @param int                         $owner_user_id Item owner (for orphan bucket fallback).
     * @param int                         $project_id Item project_id column.
     * @param array<string,mixed>|string $meta_decoded_or_json Meta array or meta_json string.
     */
    public static function notify_full_process_item_deleted( $item_id, $owner_user_id, $project_id, $meta_decoded_or_json ) {
        if ( ! self::items_unlock_columns_exist() ) {
            return;
        }
        $meta = self::normalize_meta_input( $meta_decoded_or_json );
        if ( self::normalized_entry_mode( $meta ) !== 'full_process' ) {
            return;
        }
        $project_id      = absint( $project_id );
        $owner_user_id = absint( $owner_user_id );

        if ( $project_id > 0 && self::project_fp_counter_column_exists() ) {
            global $wpdb;
            $t_safe = preg_replace( '/[^a-zA-Z0-9_]/', '', $wpdb->prefix . 'n88_projects' );
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$t_safe} SET full_process_item_count = GREATEST(0, full_process_item_count - 1) WHERE id = %d",
                    $project_id
                )
            );
            return;
        }

        self::decrement_fp_slot_owner_bucket( $owner_user_id );
    }

    /**
     * @param array<string>|int[] $item_ids Item ids only.
     * @return array{blocked_ids:int[], eligible_ids:int[], price_usd:int}
     */
    public static function validate_item_ids_workflow_eligible( array $item_ids ) {
        global $wpdb;
        $price = self::UNLOCK_PRICE_USD;

        $item_ids = array_values( array_unique( array_filter( array_map( 'absint', $item_ids ) ) ) );
        if ( empty( $item_ids ) ) {
            return array(
                'blocked_ids'    => array(),
                'eligible_ids'   => array(),
                'price_usd'      => $price,
            );
        }
        $items_table = $wpdb->prefix . 'n88_items';

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- placeholders built from sanitized integers
        $in_list = implode( ',', array_map( 'absint', $item_ids ) );
        if ( self::items_unlock_columns_exist() ) {
            $rows = $wpdb->get_results(
                "SELECT id, meta_json, is_free, is_paid, is_locked FROM {$items_table} WHERE id IN ({$in_list}) AND deleted_at IS NULL",
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                "SELECT id, meta_json FROM {$items_table} WHERE id IN ({$in_list}) AND deleted_at IS NULL",
                ARRAY_A
            );
        }

        // phpcs:enable

        $blocked  = array();
        $eligible = array();
        foreach ( $rows as $row ) {
            $mid    = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
            $meta   = ! empty( $row['meta_json'] ) ? json_decode( $row['meta_json'], true ) : array();
            $meta   = is_array( $meta ) ? $meta : array();
            $is_f   = isset( $row['is_free'] ) ? $row['is_free'] : null;
            $is_p   = isset( $row['is_paid'] ) ? $row['is_paid'] : null;
            $ok     = self::workflow_eligible( $meta, $is_f, $is_p );
            if ( $ok ) {
                $eligible[] = $mid;
            } else {
                $blocked[] = $mid;
            }
        }
        foreach ( $item_ids as $asked ) {
            if ( ! in_array( $asked, $eligible, true ) && ! in_array( $asked, $blocked, true ) ) {
                $blocked[] = $asked;
            }
        }
        sort( $blocked );
        sort( $eligible );
        return array(
            'blocked_ids'    => $blocked,
            'eligible_ids'   => $eligible,
            'price_usd'      => $price,
        );
    }

    /**
     * @param mixed $input Meta array or JSON string.
     * @return array<string,mixed>
     */
    private static function normalize_meta_input( $input ) {
        if ( is_array( $input ) ) {
            return $input;
        }
        if ( is_string( $input ) && '' !== $input ) {
            $decoded = json_decode( $input, true );
            return is_array( $decoded ) ? $decoded : array();
        }
        return array();
    }
}
