'use strict';
const fs = require('fs');
const path = require('path');
const p = path.join(__dirname, 'class-n88-item-unlock.php');
let c = fs.readFileSync(p, 'utf8');
if (!c.includes('OWNER_FP_PAID_SLOTS_META')) {
	c = c.replace(
		"const MIGRATION_OPTION_KEY = 'n88_migrated_3_d_8_b_item_unlock';\r\n\r\n    /** Per-user",
		"const MIGRATION_OPTION_KEY = 'n88_migrated_3_d_8_b_item_unlock';\r\n\r\n    const OWNER_FP_PAID_SLOTS_META = 'n88_fp_owner_paid_slot_grants';\r\n\r\n    const PROJECT_FP_PAID_SLOTS_COLUMN = 'fp_paid_item_slots_granted';\r\n\r\n    /** Per-user"
	);
	c = c.replace(
		"const MIGRATION_OPTION_KEY = 'n88_migrated_3_d_8_b_item_unlock';\n\n    /** Per-user",
		"const MIGRATION_OPTION_KEY = 'n88_migrated_3_d_8_b_item_unlock';\n\n    const OWNER_FP_PAID_SLOTS_META = 'n88_fp_owner_paid_slot_grants';\n\n    const PROJECT_FP_PAID_SLOTS_COLUMN = 'fp_paid_item_slots_granted';\n\n    /** Per-user"
	);
}
const markerNeedle = `        return self::$has_projects_fp_col;
    }

    /**
     * @param array<string,mixed>|null $meta Decoded meta_json.`;
const insertBlock = `        return self::$has_projects_fp_col;
    }

    private static $projects_grant_col_checked = false;

    private static $has_projects_grant_col = false;

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
        self::$has_projects_grant_col    = is_array( $cols ) && in_array( self::PROJECT_FP_PAID_SLOTS_COLUMN, $cols, true );
        return self::$has_projects_grant_col;
    }

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

    public static function get_owner_fp_bucket_count( $owner_user_id ) {
        $owner_user_id = absint( $owner_user_id );
        if ( ! $owner_user_id ) {
            return 0;
        }
        return (int) max( 0, (int) get_option( self::owner_fp_option_name( $owner_user_id ), 0 ) );
    }

    public static function get_owner_fp_paid_slots_granted( $owner_user_id ) {
        $owner_user_id = absint( $owner_user_id );
        if ( ! $owner_user_id ) {
            return 0;
        }
        return (int) max( 0, (int) get_user_meta( $owner_user_id, self::OWNER_FP_PAID_SLOTS_META, true ) );
    }

    public static function get_fp_slot_gate( $project_id, $owner_user_id ) {
        $project_id      = absint( $project_id );
        $owner_user_id = absint( $owner_user_id );

        if ( ! self::items_unlock_columns_exist() ) {
            return array(
                'current'        => 0,
                'granted_extra'  => 0,
                'allowed_total'  => 9999,
                'remaining'      => 9999,
                'free_cap'       => self::FULL_PROCESS_FREE_CAP,
            );
        }

        if ( $project_id > 0 && self::project_fp_counter_column_exists() ) {
            $current   = self::get_project_fp_stored_count( $project_id );
            $granted   = self::get_project_fp_paid_slots_granted( $project_id );
            $allowed   = self::FULL_PROCESS_FREE_CAP + max( 0, $granted );
            $remaining = max( 0, $allowed - $current );

            return array(
                'current'        => $current,
                'granted_extra'  => max( 0, $granted ),
                'allowed_total'  => $allowed,
                'remaining'      => $remaining,
                'free_cap'       => self::FULL_PROCESS_FREE_CAP,
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

    public static function full_process_slot_available( $project_id, $owner_user_id ) {
        $g = self::get_fp_slot_gate( $project_id, $owner_user_id );
        return $g['remaining'] > 0;
    }

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
     * @param array<string,mixed>|null $meta Decoded meta_json.`;
if (!c.includes('project_fp_paid_slots_column_exists')) {
	if (!c.includes(markerNeedle)) {
		console.error('marker needle not found');
		process.exit(1);
	}
	c = c.replace(markerNeedle, insertBlock);
}
const oldProjBranch = `        if ( $project_id > 0 && self::project_fp_counter_column_exists() ) {
            $slot = self::increment_fp_count_for_project( $project_id );
            if ( false !== $slot ) {
                if ( $slot <= self::FULL_PROCESS_FREE_CAP ) {
                    return array(
                        'is_free'   => 1,
                        'is_paid'   => 0,
                        'is_locked' => 0,
                    );
                }
                return array(
                    'is_free'   => 0,
                    'is_paid'   => 0,
                    'is_locked' => 1,
                );
            }
        }

        if ( $owner_user_id > 0 ) {
            $slot_o = self::increment_fp_slot_owner_bucket( $owner_user_id );
            if ( false !== $slot_o ) {
                if ( $slot_o <= self::FULL_PROCESS_FREE_CAP ) {
                    return array(
                        'is_free'   => 1,
                        'is_paid'   => 0,
                        'is_locked' => 0,
                    );
                }
                return array(
                    'is_free'   => 0,
                    'is_paid'   => 0,
                    'is_locked' => 1,
                );
            }
        }`;
const newProjBranch = `        if ( $project_id > 0 && self::project_fp_counter_column_exists() ) {
            $slot = self::increment_fp_count_for_project( $project_id );
            if ( false !== $slot ) {
                $allowed_here = self::FULL_PROCESS_FREE_CAP + max(
                    0,
                    self::get_project_fp_paid_slots_granted( $project_id )
                );
                if ( $slot <= self::FULL_PROCESS_FREE_CAP ) {
                    return array(
                        'is_free'   => 1,
                        'is_paid'   => 0,
                        'is_locked' => 0,
                    );
                }
                if ( $slot <= max( self::FULL_PROCESS_FREE_CAP, $allowed_here ) ) {
                    return array(
                        'is_free'   => 0,
                        'is_paid'   => 1,
                        'is_locked' => 0,
                    );
                }
                return array(
                    'is_free'   => 0,
                    'is_paid'   => 0,
                    'is_locked' => 1,
                );
            }
        }

        if ( $owner_user_id > 0 ) {
            $slot_o = self::increment_fp_slot_owner_bucket( $owner_user_id );
            if ( false !== $slot_o ) {
                $ogrants     = max( 0, self::get_owner_fp_paid_slots_granted( $owner_user_id ) );
                $allowed_own = self::FULL_PROCESS_FREE_CAP + $ogrants;

                if ( $slot_o <= self::FULL_PROCESS_FREE_CAP ) {
                    return array(
                        'is_free'   => 1,
                        'is_paid'   => 0,
                        'is_locked' => 0,
                    );
                }
                if ( $slot_o <= max( self::FULL_PROCESS_FREE_CAP, $allowed_own ) ) {
                    return array(
                        'is_free'   => 0,
                        'is_paid'   => 1,
                        'is_locked' => 0,
                    );
                }
                return array(
                    'is_free'   => 0,
                    'is_paid'   => 0,
                    'is_locked' => 1,
                );
            }
        }`;
if (c.includes(oldProjBranch)) {
	c = c.replace(oldProjBranch, newProjBranch);
} else if (!c.includes('get_project_fp_paid_slots_granted')) {
	console.error('flags branch pattern not found');
	process.exit(1);
}
fs.writeFileSync(p, c);
console.log('OK');
