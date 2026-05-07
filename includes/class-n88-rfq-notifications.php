<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class N88_RFQ_Notifications {
    const EMAIL_QUEUE_HOOK = 'n88_process_async_action_email';
    const EMAIL_DEDUPE_TTL = 600;

    /**
     * Register universal action email hooks.
     *
     * @return void
     */
    public static function init_universal_action_email_system() {
        add_action( 'n88_event_logged', array( __CLASS__, 'handle_event_logged' ), 10, 5 );
        add_action( self::EMAIL_QUEUE_HOOK, array( __CLASS__, 'process_async_action_email' ), 10, 1 );
    }

    /**
     * Create a notification
     *
     * @param int $project_id Project ID
     * @param int $user_id User ID to notify
     * @param string $notification_type Notification type
     * @param string $message Notification message
     * @param int $related_id Related item ID (comment, quote, etc)
     * @return int|false Notification ID on success
     */
    public static function create_notification( $project_id, $user_id, $notification_type, $message, $related_id = null ) {
        global $wpdb;

        $project_id = intval( $project_id );
        $user_id = intval( $user_id );
        $notification_type = sanitize_text_field( $notification_type );
        $message = wp_kses_post( $message );

        if ( empty( $project_id ) || empty( $user_id ) ) {
            return false;
        }

        $table = $wpdb->prefix . 'project_notifications';
        $now = current_time( 'mysql' );

        // Append related ID to message if provided
        if ( $related_id ) {
            $message .= '|' . intval( $related_id );
        }

        $inserted = $wpdb->insert(
            $table,
            array(
                'project_id' => $project_id,
                'user_id' => $user_id,
                'notification_type' => $notification_type,
                'message' => $message,
                'is_read' => 0,
                'created_at' => $now,
            ),
            array( '%d', '%d', '%s', '%s', '%d', '%s' )
        );

        if ( $inserted ) {
            return $wpdb->insert_id;
        }

        return false;
    }

    /**
     * Get notifications for user
     *
     * @param int $user_id User ID
     * @param int $limit Limit results
     * @param int $offset Offset
     * @param bool $unread_only Only unread
     * @return array Array of notifications
     */
    public static function get_user_notifications( $user_id, $limit = 20, $offset = 0, $unread_only = false ) {
        global $wpdb;

        $user_id = intval( $user_id );
        $limit = intval( $limit );
        $offset = intval( $offset );

        $table = $wpdb->prefix . 'project_notifications';

        $query = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d",
            $user_id
        );

        if ( $unread_only ) {
            $query .= " AND is_read = 0";
        }

        $query .= " ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $query = $wpdb->prepare( $query, $limit, $offset );

        return $wpdb->get_results( $query );
    }

    /**
     * Get project notifications
     *
     * @param int $project_id Project ID
     * @param int $limit Limit results
     * @return array Array of notifications
     */
    public static function get_project_notifications( $project_id, $limit = 50 ) {
        global $wpdb;

        $project_id = intval( $project_id );
        $limit = intval( $limit );

        $table = $wpdb->prefix . 'project_notifications';

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE project_id = %d ORDER BY created_at DESC LIMIT %d",
                $project_id,
                $limit
            )
        );
    }

    /**
     * Mark notification as read
     *
     * @param int $notification_id Notification ID
     * @return bool True on success
     */
    public static function mark_as_read( $notification_id ) {
        global $wpdb;

        $notification_id = intval( $notification_id );
        $table = $wpdb->prefix . 'project_notifications';

        return $wpdb->update(
            $table,
            array( 'is_read' => 1 ),
            array( 'id' => $notification_id ),
            array( '%d' ),
            array( '%d' )
        );
    }

    /**
     * Mark all user notifications as read
     *
     * @param int $user_id User ID
     * @return int Number of rows updated
     */
    public static function mark_all_as_read( $user_id ) {
        global $wpdb;

        $user_id = intval( $user_id );
        $table = $wpdb->prefix . 'project_notifications';

        return $wpdb->update(
            $table,
            array( 'is_read' => 1 ),
            array( 'user_id' => $user_id, 'is_read' => 0 ),
            array( '%d' ),
            array( '%d', '%d' )
        );
    }

    /**
     * Get unread notification count for user
     *
     * @param int $user_id User ID
     * @return int Count of unread notifications
     */
    public static function get_unread_count( $user_id ) {
        global $wpdb;

        $user_id = intval( $user_id );
        $table = $wpdb->prefix . 'project_notifications';

        return intval(
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND is_read = 0",
                    $user_id
                )
            )
        );
    }

    /**
     * Delete notification
     *
     * @param int $notification_id Notification ID
     * @return bool True on success
     */
    public static function delete_notification( $notification_id ) {
        global $wpdb;

        $notification_id = intval( $notification_id );
        $table = $wpdb->prefix . 'project_notifications';

        return $wpdb->delete(
            $table,
            array( 'id' => $notification_id ),
            array( '%d' )
        );
    }

    /**
     * Delete all notifications for a project
     *
     * @param int $project_id Project ID
     * @return int Number of rows deleted
     */
    public static function delete_project_notifications( $project_id ) {
        global $wpdb;

        $project_id = intval( $project_id );
        $table = $wpdb->prefix . 'project_notifications';

        return $wpdb->delete(
            $table,
            array( 'project_id' => $project_id ),
            array( '%d' )
        );
    }

    /**
     * Format notification for display
     *
     * @param object $notification Notification object
     * @return array Formatted notification
     */
    public static function format_notification( $notification ) {
        $message_parts = explode( '|', $notification->message );
        $message = $message_parts[0];
        $related_id = isset( $message_parts[1] ) ? intval( $message_parts[1] ) : null;

        return array(
            'id' => $notification->id,
            'project_id' => $notification->project_id,
            'user_id' => $notification->user_id,
            'notification_type' => $notification->notification_type,
            'message' => $message,
            'related_id' => $related_id,
            'is_read' => $notification->is_read,
            'created_at' => $notification->created_at,
        );
    }

    /**
     * Queue event-driven emails for designer + supplier recipients.
     *
     * @param int    $event_id Event ID.
     * @param string $event_type Event type slug.
     * @param string $object_type Object type slug.
     * @param array  $args Event arguments.
     * @param int    $actor_user_id Actor ID.
     * @return void
     */
    public static function handle_event_logged( $event_id, $event_type, $object_type, $args, $actor_user_id ) {
        $event_id = absint( $event_id );
        if ( ! $event_id ) {
            return;
        }

        $context = self::build_universal_email_context( $event_id, $event_type, $object_type, $args, $actor_user_id );
        if ( empty( $context['recipients'] ) ) {
            return;
        }

        foreach ( $context['recipients'] as $recipient ) {
            $dedupe_key = self::build_email_dedupe_key( $context, $recipient );
            if ( get_transient( $dedupe_key ) ) {
                continue;
            }
            set_transient( $dedupe_key, 1, self::EMAIL_DEDUPE_TTL );
            wp_schedule_single_event( time() + 5, self::EMAIL_QUEUE_HOOK, array( array(
                'event_id'        => $context['event_id'],
                'event_type'      => $context['event_type'],
                'event_label'     => $context['event_label'],
                'actor_name'      => $context['actor_name'],
                'recipient'       => $recipient,
                'project_name'    => $context['project_name'],
                'item_id'         => $context['item_id'],
                'project_id'      => $context['project_id'],
                'deep_link'       => $context['deep_link'],
                'object_type'     => $context['object_type'],
                'email_detail_html' => isset( $context['email_detail_html'] ) ? $context['email_detail_html'] : '',
            ) ) );
        }
        if ( function_exists( 'spawn_cron' ) ) {
            spawn_cron();
        }
    }

    /**
     * Process one queued email job asynchronously.
     *
     * @param array $job Queued job payload.
     * @return void
     */
    public static function process_async_action_email( $job ) {
        if ( ! is_array( $job ) || empty( $job['recipient'] ) || ! is_array( $job['recipient'] ) ) {
            return;
        }

        $recipient = $job['recipient'];
        $email     = isset( $recipient['email'] ) ? sanitize_email( $recipient['email'] ) : '';
        if ( ! $email ) {
            return;
        }

        $subject = sprintf(
            '[N88 RFQ] %s: %s',
            self::to_human_label( isset( $job['event_label'] ) ? $job['event_label'] : 'Workflow Update' ),
            ! empty( $job['project_name'] ) ? sanitize_text_field( $job['project_name'] ) : 'Production Oversight'
        );

        $body = self::get_universal_action_email_template( array(
            'recipient_name' => ! empty( $recipient['name'] ) ? sanitize_text_field( $recipient['name'] ) : 'there',
            'recipient_role' => ! empty( $recipient['role'] ) ? sanitize_text_field( $recipient['role'] ) : 'participant',
            'actor_name'     => ! empty( $job['actor_name'] ) ? sanitize_text_field( $job['actor_name'] ) : 'A teammate',
            'event_label'    => self::to_human_label( isset( $job['event_label'] ) ? $job['event_label'] : 'Workflow Update' ),
            'project_name'   => ! empty( $job['project_name'] ) ? sanitize_text_field( $job['project_name'] ) : 'Production Oversight',
            'deep_link'      => ! empty( $job['deep_link'] ) ? esc_url_raw( $job['deep_link'] ) : home_url( '/' ),
            'item_id'        => ! empty( $job['item_id'] ) ? absint( $job['item_id'] ) : 0,
            'project_id'     => ! empty( $job['project_id'] ) ? absint( $job['project_id'] ) : 0,
            'event_type'     => ! empty( $job['event_type'] ) ? sanitize_key( $job['event_type'] ) : '',
            'email_detail_html' => ! empty( $job['email_detail_html'] ) ? $job['email_detail_html'] : '',
        ) );

        wp_mail(
            $email,
            $subject,
            $body,
            array( 'Content-Type: text/html; charset=UTF-8' )
        );
    }

    private static function build_universal_email_context( $event_id, $event_type, $object_type, $args, $actor_user_id ) {
        $item_id    = ! empty( $args['item_id'] ) ? absint( $args['item_id'] ) : 0;
        $project_id = ! empty( $args['project_id'] ) ? absint( $args['project_id'] ) : 0;
        $payload    = array();

        if ( isset( $args['payload_json'] ) && is_array( $args['payload_json'] ) ) {
            $payload = $args['payload_json'];
        } elseif ( ! empty( $args['payload_json'] ) && is_string( $args['payload_json'] ) ) {
            $decoded = json_decode( $args['payload_json'], true );
            if ( is_array( $decoded ) ) {
                $payload = $decoded;
            }
        }

        if ( ! $project_id && ! empty( $payload['project_id'] ) ) {
            $project_id = absint( $payload['project_id'] );
        }
        if ( ! $item_id && ! empty( $payload['item_id'] ) ) {
            $item_id = absint( $payload['item_id'] );
        }

        $recipient_ids = self::resolve_item_participant_user_ids( $item_id, $payload );
        if ( ! empty( $payload['supplier_id'] ) ) {
            $recipient_ids[] = absint( $payload['supplier_id'] );
        }
        $recipient_ids = array_values( array_unique( array_filter( $recipient_ids ) ) );

        $recipients = array();
        foreach ( $recipient_ids as $recipient_id ) {
            $user = get_userdata( $recipient_id );
            if ( ! $user || empty( $user->user_email ) ) {
                continue;
            }
            $recipients[] = array(
                'id'    => (int) $recipient_id,
                'email' => $user->user_email,
                'name'  => $user->display_name,
                'role'  => self::infer_user_workflow_role( $user ),
            );
        }

        $actor      = $actor_user_id ? get_userdata( $actor_user_id ) : null;
        $project    = $project_id && class_exists( 'N88_RFQ_Projects' ) ? ( new N88_RFQ_Projects() )->get_project_admin( $project_id ) : null;
        $project_name = is_array( $project ) && ! empty( $project['project_name'] ) ? $project['project_name'] : 'Production Oversight';

        return array(
            'event_id'     => absint( $event_id ),
            'event_type'   => sanitize_key( $event_type ),
            'event_label'  => self::map_event_label( $event_type ),
            'object_type'  => sanitize_key( $object_type ),
            'actor_name'   => $actor ? $actor->display_name : 'A teammate',
            'item_id'      => $item_id,
            'project_id'   => $project_id,
            'project_name' => $project_name,
            'deep_link'    => self::build_deep_link( $project_id, $item_id ),
            'recipients'   => $recipients,
            'email_detail_html' => self::build_email_detail_html( $event_type, $payload ),
        );
    }

    private static function resolve_item_participant_user_ids( $item_id, $payload = array() ) {
        global $wpdb;
        $ids = array();
        if ( ! $item_id ) {
            return $ids;
        }

        $items_table = $wpdb->prefix . 'n88_items';
        $item_row = $wpdb->get_row(
            $wpdb->prepare( "SELECT owner_user_id, meta_json FROM {$items_table} WHERE id = %d LIMIT 1", $item_id ),
            ARRAY_A
        );

        if ( is_array( $item_row ) ) {
            if ( ! empty( $item_row['owner_user_id'] ) ) {
                $ids[] = absint( $item_row['owner_user_id'] );
            }
            if ( ! empty( $item_row['meta_json'] ) ) {
                $meta = json_decode( $item_row['meta_json'], true );
                if ( is_array( $meta ) && ! empty( $meta['active_supplier_id'] ) ) {
                    $ids[] = absint( $meta['active_supplier_id'] );
                }
            }
        }

        if ( ! empty( $payload['supplier_id'] ) ) {
            $ids[] = absint( $payload['supplier_id'] );
        }

        return array_values( array_unique( array_filter( $ids ) ) );
    }

    private static function build_deep_link( $project_id, $item_id ) {
        if ( $item_id > 0 ) {
            return add_query_arg(
                array( 'item_id' => $item_id ),
                home_url( '/supplier-dashboard/' )
            );
        }
        if ( $project_id > 0 ) {
            return add_query_arg(
                array( 'project_id' => $project_id ),
                home_url( '/project-detail/' )
            );
        }
        return home_url( '/' );
    }

    private static function infer_user_workflow_role( $user ) {
        $roles = isset( $user->roles ) && is_array( $user->roles ) ? $user->roles : array();
        if ( in_array( 'n88_supplier', $roles, true ) || in_array( 'n88_supplier_admin', $roles, true ) || in_array( 'supplier', $roles, true ) ) {
            return 'supplier';
        }
        return 'designer';
    }

    /**
     * Human-readable Step 4 milestone keys (production oversight stages).
     *
     * @param string $stage_key Stage key from DB.
     * @return string
     */
    private static function humanize_step4_stage_key( $stage_key ) {
        $key = sanitize_key( (string) $stage_key );
        $map = array(
            '4_1_planning'    => '4.1 Planning / Approval',
            '4_2_preparation' => '4.2 Preparation',
            '4_3_core'        => '4.3 Core Development',
            '4_4_assembly'    => '4.4 Assembly / Integration',
            '4_5_refinement'  => '4.5 Refinement / Finishing',
            '4_6_completion'  => '4.6 Completion',
        );
        return isset( $map[ $key ] ) ? $map[ $key ] : $key;
    }

    /**
     * Optional extra lines in the universal email (stage, sample status).
     *
     * @param string $event_type Event slug.
     * @param array  $payload    Decoded payload_json.
     * @return string Safe HTML snippet or empty.
     */
    private static function build_email_detail_html( $event_type, $payload ) {
        if ( ! is_array( $payload ) || empty( $payload ) ) {
            return '';
        }
        $lines = array();
        if ( ! empty( $payload['stage_key'] ) ) {
            $lines[] = '<strong>Stage:</strong> ' . esc_html( self::humanize_step4_stage_key( $payload['stage_key'] ) );
        }
        if ( ! empty( $payload['sample_status'] ) ) {
            $lines[] = '<strong>Sample status:</strong> ' . esc_html( sanitize_text_field( $payload['sample_status'] ) );
        }
        if ( ! empty( $payload['state'] ) && in_array( $event_type, array( 'step4_stage_progress_submitted', 'step4_stage_progress_approved', 'step4_stage_revision_requested' ), true ) ) {
            $lines[] = '<strong>State:</strong> ' . esc_html( sanitize_text_field( $payload['state'] ) );
        }
        if ( empty( $lines ) ) {
            return '';
        }
        return '<div style="margin-top:8px;">' . implode( '<br>', $lines ) . '</div>';
    }

    private static function map_event_label( $event_type ) {
        $map = array(
            'designer_profile_created' => 'Welcome',
            'item_created' => 'Add item',
            'item_added_to_board' => 'Add Irene batch workflow',
            'rfq_field_updated' => 'RFQ sent / received',
            'item_message_sent' => 'Message received',
            'materials_requested' => 'Sample requested / shipped',
            'material_validation_requested' => 'Sample request recorded',
            'material_samples_received' => 'Samples received',
            'material_samples_approved' => 'Samples approved',
            'supplier_material_sample_updated' => 'Sample status update',
            'validation_po_uploaded' => 'Authorization documentation uploaded',
            'validation_com_submitted' => 'Logistics shipment recorded',
            'validation_deposit_submitted' => 'Production deposit record submitted',
            'production_deposit_sent_by_designer' => 'Production deposit sent',
            'bid_awarded' => 'Project awarded',
            'payment_submitted' => 'Execution activation payment submitted',
            'payment_confirmed' => 'Execution activation payment confirmed',
            'execution_unlocked' => 'Execution Activation Required',
            'cad_uploaded' => 'CAD submitted',
            'milestone_updated' => 'Stage submitted',
            'milestone_created' => 'Step 4 milestones configured',
            'stage_locked' => 'Step 4 stage status update',
            'stage_unlocked' => 'Step 4 stage unlocked',
            'step4_stage_progress_submitted' => 'Step 4 stage work submitted',
            'step4_stage_progress_approved' => 'Step 4 stage approved',
            'step4_stage_revision_requested' => 'Step 4 revision requested',
            'step4_stage_payment_submitted' => 'Step 4 milestone payment submitted',
            'step4_stage_payment_confirmed' => 'Step 4 milestone payment confirmed',
            'prototype_revision_requested' => 'Revision requested',
            'step5_qc_packing_submitted' => 'QC submitted',
            'step6_delivery_submitted' => 'Delivery update',
            'step6_delivery_confirmed_complete' => 'Delivery update',
        );
        return isset( $map[ $event_type ] ) ? $map[ $event_type ] : str_replace( '_', ' ', (string) $event_type );
    }

    private static function to_human_label( $label ) {
        return trim( ucwords( str_replace( '_', ' ', (string) $label ) ) );
    }

    private static function build_email_dedupe_key( $context, $recipient ) {
        return 'n88_evt_mail_' . md5(
            implode( '|', array(
                isset( $context['event_id'] ) ? (string) $context['event_id'] : '0',
                isset( $context['event_type'] ) ? (string) $context['event_type'] : '',
                isset( $recipient['id'] ) ? (string) $recipient['id'] : '0',
            ) )
        );
    }

    /**
     * One universal action email template for all event-driven updates.
     *
     * @param array $data Template data.
     * @return string
     */
    public static function get_universal_action_email_template( $data ) {
        $site_name = get_bloginfo( 'name' );
        $deep_link = ! empty( $data['deep_link'] ) ? esc_url( $data['deep_link'] ) : esc_url( home_url( '/' ) );
        $recipient_role = ! empty( $data['recipient_role'] ) ? self::to_human_label( $data['recipient_role'] ) : 'Participant';
        $detail_html      = ! empty( $data['email_detail_html'] ) ? $data['email_detail_html'] : '';

        return sprintf(
            '<div style="font-family:Arial,sans-serif;background:#f7f7f7;padding:20px;">
                <div style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #e2e2e2;border-radius:8px;overflow:hidden;">
                    <div style="background:#101828;color:#ffffff;padding:18px 20px;">
                        <h2 style="margin:0;font-size:20px;">%s - Production Oversight Update</h2>
                    </div>
                    <div style="padding:20px;color:#1f2937;">
                        <p style="margin:0 0 12px;">Hi %s,</p>
                        <p style="margin:0 0 12px;"><strong>%s</strong> has triggered a workflow update.</p>
                        <div style="background:#f4f6f8;border-left:4px solid #111827;padding:12px 14px;margin:14px 0;">
                            <div><strong>Action:</strong> %s</div>
                            <div><strong>Project:</strong> %s</div>
                            <div><strong>Your Role:</strong> %s</div>
                            %s
                        </div>
                        <p style="margin:0 0 12px;">This notification is part of the Production Oversight Only workflow.</p>
                        <p style="margin:18px 0 0;">
                            <a href="%s" style="display:inline-block;background:#111827;color:#ffffff;text-decoration:none;padding:10px 16px;border-radius:6px;">Open Update</a>
                        </p>
                    </div>
                </div>
            </div>',
            esc_html( $site_name ),
            esc_html( isset( $data['recipient_name'] ) ? $data['recipient_name'] : 'there' ),
            esc_html( isset( $data['actor_name'] ) ? $data['actor_name'] : 'A teammate' ),
            esc_html( isset( $data['event_label'] ) ? $data['event_label'] : 'Workflow Update' ),
            esc_html( isset( $data['project_name'] ) ? $data['project_name'] : 'Production Oversight' ),
            esc_html( $recipient_role ),
            $detail_html,
            $deep_link
        );
    }

    /**
     * Send project upload notification to admin
     *
     * @param int $project_id Project ID
     * @param object $project Project object
     * @return bool True on success
     */
    public static function notify_admin_project_upload( $project_id, $project ) {
        $admins = get_users( array( 'role' => 'administrator' ) );
        $project_owner = get_userdata( $project['user_id'] );

        foreach ( $admins as $admin ) {
            // Create in-app notification
            self::create_notification(
                $project_id,
                $admin->ID,
                'project_submitted',
                'New project submitted: ' . $project['project_name'],
                $project_id
            );

            // Send email notification
            $subject = '[N88 RFQ] New Project Submission: ' . $project['project_name'];
            $email_body = self::get_email_template( 'admin_project_upload', array(
                'project_name' => $project['project_name'],
                'project_type' => $project['quote_type'] === 'sourcing' ? 'Sourcing' : '24-Hour',
                'submitted_by' => $project_owner->display_name,
                'email' => $project_owner->user_email,
                'timeline' => $project['timeline'] ?? 'Not specified',
                'budget' => $project['budget_range'] ?? 'Not specified',
                'item_count' => $project['item_count'] ?? 0,
                'project_url' => admin_url( 'admin.php?page=n88-rfq-projects&project_id=' . $project_id ),
                'submitted_at' => current_time( 'mysql' ),
            ) );

            wp_mail(
                $admin->user_email,
                $subject,
                $email_body,
                array( 'Content-Type: text/html; charset=UTF-8' )
            );
        }

        return true;
    }

    /**
     * Send quote upload notification to user
     *
     * @param int $project_id Project ID
     * @param int $quote_id Quote ID
     * @param object $project Project object
     * @param object $user User object
     * @return bool True on success
     */
    public static function notify_user_quote_uploaded( $project_id, $quote_id, $project, $user ) {
        // Create in-app notification
        self::create_notification(
            $project_id,
            $user->ID,
            'quote_uploaded',
            'Quote received for your project: ' . $project['project_name'],
            $quote_id
        );

        // Send email notification
        $subject = '[N88 RFQ] Quote Ready: ' . $project['project_name'];
        $email_body = self::get_email_template( 'user_quote_uploaded', array(
            'user_name' => $user->display_name,
            'project_name' => $project['project_name'],
            'quote_id' => $quote_id,
            'project_url' => home_url( '/project-detail/?project_id=' . $project_id ),
            'message' => 'A quote has been uploaded for your project. Click below to view it.',
        ) );

        wp_mail(
            $user->user_email,
            $subject,
            $email_body,
            array( 'Content-Type: text/html; charset=UTF-8' )
        );

        return true;
    }

    /**
     * Send item edit notification (Phase 2B: Enhanced to distinguish admin vs user)
     *
     * @param int $project_id Project ID
     * @param int $item_index Item index
     * @param int $edited_by_user_id User ID of who edited
     * @return bool True on success
     */
    public static function notify_item_edited( $project_id, $item_index, $edited_by_user_id = null ) {
        $projects_class = new N88_RFQ_Projects();
        $project = $projects_class->get_project_admin( $project_id );
        
        if ( ! $project ) {
            return false;
        }

        // Get editor info
        $editor = $edited_by_user_id ? get_userdata( $edited_by_user_id ) : null;
        $edited_by = $editor ? $editor->display_name : 'Unknown';
        $is_admin = $editor && current_user_can( 'manage_options' );
        $is_project_owner = $editor && (int) $editor->ID === (int) $project['user_id'];

        // Phase 2B: Different notifications based on who edited
        if ( $is_admin ) {
            // Admin edited item - notify user
            $message = sprintf( 'Client updated Item #%d on your project: %s', $item_index + 1, $project['project_name'] );
            
            self::create_notification(
                $project_id,
                $project['user_id'],
                'admin_updated_item',
                $message,
                $item_index
            );

            // Send email to project owner
            $owner = get_userdata( $project['user_id'] );
            if ( $owner ) {
                $subject = '[N88 RFQ] Item Updated: ' . $project['project_name'];
                $email_body = self::get_email_template( 'admin_updated_item', array(
                    'user_name' => $owner->display_name,
                    'project_name' => $project['project_name'],
                    'item_number' => $item_index + 1,
                    'admin_name' => $edited_by,
                    'project_url' => home_url( '/project-detail/?project_id=' . $project_id ),
                ) );
                wp_mail(
                    $owner->user_email,
                    $subject,
                    $email_body,
                    array( 'Content-Type: text/html; charset=UTF-8' )
                );
            }
        } else {
            // User (non-admin) edited item - notify admins
            // This includes both project owners and other users who edit items
            $message = sprintf( 'User edited Item #%d on project: %s', $item_index + 1, $project['project_name'] );
            
            $admins = get_users( array( 'role' => 'administrator' ) );
            foreach ( $admins as $admin ) {
                self::create_notification(
                    $project_id,
                    $admin->ID,
                    'user_edited_item',
                    $message,
                    $item_index
                );

                // Send email to admin
                $subject = '[N88 RFQ] User Edited Item: ' . $project['project_name'];
                $email_body = self::get_email_template( 'user_edited_item', array(
                    'admin_name' => $admin->display_name,
                    'project_name' => $project['project_name'],
                    'item_number' => $item_index + 1,
                    'user_name' => $edited_by,
                    'admin_url' => admin_url( 'admin.php?page=n88-rfq-projects&project_id=' . $project_id ),
                ) );
                wp_mail(
                    $admin->user_email,
                    $subject,
                    $email_body,
                    array( 'Content-Type: text/html; charset=UTF-8' )
                );
            }
        }

        return true;
    }

    /**
     * Send comment notification (Phase 2B: Enhanced with urgent flag support)
     *
     * @param int $project_id Project ID
     * @param object $comment Comment object
     * @return void
     */
    public static function notify_comment_added( $project_id, $comment ) {
        $projects_class = new N88_RFQ_Projects();
        $project = $projects_class->get_project_admin( $project_id );
        if ( ! $project ) {
            return;
        }

        $commenter = get_userdata( $comment->user_id );
        $is_urgent = ! empty( $comment->is_urgent );
        $is_reply = ! empty( $comment->parent_comment_id );
        
        // Phase 2B: Enhanced message for urgent comments and replies
        if ( $is_urgent ) {
            $message = sprintf( '🚨 URGENT: New comment from %s', $commenter->display_name );
        } elseif ( $is_reply ) {
            $message = sprintf( 'New reply from %s', $commenter->display_name );
        } else {
            $message = sprintf( 'New comment from %s', $commenter->display_name );
        }

        // Phase 2B: If it's a reply, notify the parent commenter
        if ( $is_reply && $comment->parent_comment_id ) {
            $parent_comment = N88_RFQ_Comments::get_comment( $comment->parent_comment_id );
            if ( $parent_comment && $parent_comment->user_id != $comment->user_id ) {
                $parent_commenter = get_userdata( $parent_comment->user_id );
                
                // Create in-app notification for parent commenter
                $reply_message = $is_urgent 
                    ? sprintf( '🚨 URGENT: %s replied to your comment', $commenter->display_name )
                    : sprintf( '%s replied to your comment', $commenter->display_name );
                
                self::create_notification(
                    $project_id,
                    $parent_comment->user_id,
                    'comment_reply',
                    $reply_message,
                    $comment->id
                );
                
                // Send email to parent commenter
                $subject = $is_urgent 
                    ? '[N88 RFQ] 🚨 URGENT Reply on ' . $project['project_name']
                    : '[N88 RFQ] Reply to Your Comment on ' . $project['project_name'];
                
                $email_body = self::get_email_template( 'comment_reply', array(
                    'user_name' => $parent_commenter->display_name,
                    'commenter_name' => $commenter->display_name,
                    'project_name' => $project['project_name'],
                    'comment_text' => $comment->comment_text,
                    'parent_comment_text' => $parent_comment->comment_text,
                    'project_url' => home_url( '/project-detail/?project_id=' . $project_id ),
                    'is_urgent' => $is_urgent,
                ) );
                
                wp_mail(
                    $parent_commenter->user_email,
                    $subject,
                    $email_body,
                    array( 'Content-Type: text/html; charset=UTF-8' )
                );
            }
        }
        
        // Phase 2B: Check if commenter is admin or user
        $is_admin_comment = current_user_can( 'manage_options' ) || user_can( $comment->user_id, 'manage_options' );
        
        // Track admin updates when admin adds a comment (important note)
        if ( $is_admin_comment ) {
            $projects_class = new N88_RFQ_Projects();
            $projects_class->save_project_metadata( $project_id, array(
                'n88_has_admin_updates'   => '1',
                'n88_last_admin_update'   => current_time( 'mysql' ),
                'n88_last_admin_update_by'=> (string) $comment->user_id,
            ) );
        }
        
        // Phase 2B: If user (not admin) marks comment as urgent, notify admins
        if ( $is_urgent && ! $is_admin_comment && class_exists( 'N88_RFQ_Notifications' ) ) {
            self::notify_user_marked_urgent( $project_id, $comment );
        }
        
        // Notify project owner if commenter is not the owner (and it's not a reply to owner's comment)
        $should_notify_owner = ( $comment->user_id != $project['user_id'] ) && 
                               ( ! $is_reply || ! $parent_comment || $parent_comment->user_id != $project['user_id'] );
        
        if ( $should_notify_owner ) {
            // Phase 2B: Different notification type for admin comments
            if ( $is_admin_comment ) {
                $notification_type = $is_urgent ? 'admin_comment_urgent' : 'admin_commented';
                $message = $is_urgent 
                    ? sprintf( '🚨 URGENT: Admin %s commented', $commenter->display_name )
                    : sprintf( 'Admin %s commented', $commenter->display_name );
            } else {
                $notification_type = $is_urgent ? 'comment_urgent' : ( $is_reply ? 'comment_reply' : 'comment_added' );
            }
            
            self::create_notification(
                $project_id,
                $project['user_id'],
                $notification_type,
                $message,
                $comment->id
            );

            // Send email to project owner
            $owner = get_userdata( $project['user_id'] );
            if ( $owner ) {
                $subject = $is_urgent 
                    ? '[N88 RFQ] 🚨 URGENT Comment on ' . $project['project_name']
                    : ( $is_admin_comment 
                        ? '[N88 RFQ] Admin Commented on ' . $project['project_name']
                        : '[N88 RFQ] New Comment on ' . $project['project_name'] );
                
                $template_type = $is_urgent ? 'comment_urgent' : ( $is_admin_comment ? 'admin_commented' : 'comment_added' );
                $email_body = self::get_email_template( $template_type, array(
                    'user_name' => $owner->display_name,
                    'commenter_name' => $commenter->display_name,
                    'project_name' => $project['project_name'],
                    'comment_text' => $comment->comment_text,
                    'project_url' => home_url( '/project-detail/?project_id=' . $project_id ),
                    'is_urgent' => $is_urgent,
                    'is_admin' => $is_admin_comment,
                ) );
                
                wp_mail(
                    $owner->user_email,
                    $subject,
                    $email_body,
                    array( 'Content-Type: text/html; charset=UTF-8' )
                );
            }
        }

        // Notify admins (only if commenter is not admin)
        if ( ! $is_admin_comment ) {
            $admins = get_users( array( 'role' => 'administrator' ) );
            foreach ( $admins as $admin ) {
                if ( $admin->ID != $comment->user_id ) {
                    $notification_type = $is_urgent ? 'user_comment_urgent' : 'user_commented';
                    $admin_message = $is_urgent
                        ? sprintf( '🚨 URGENT: User %s commented on project: %s', $commenter->display_name, $project['project_name'] )
                        : sprintf( 'User %s commented on project: %s', $commenter->display_name, $project['project_name'] );
                    
                    self::create_notification(
                        $project_id,
                        $admin->ID,
                        $notification_type,
                        $admin_message,
                        $comment->id
                    );

                    // Send email to admin
                    $subject = $is_urgent
                        ? '[N88 RFQ] 🚨 URGENT User Comment on ' . $project['project_name']
                        : '[N88 RFQ] User Commented on ' . $project['project_name'];
                    $email_body = self::get_email_template( 'comment_added_admin', array(
                        'admin_name' => $admin->display_name,
                        'commenter_name' => $commenter->display_name,
                        'project_name' => $project['project_name'],
                        'comment_text' => $comment->comment_text,
                        'admin_url' => admin_url( 'admin.php?page=n88-rfq-projects&project_id=' . $project_id ),
                        'is_urgent' => $is_urgent,
                    ) );
                    wp_mail(
                        $admin->user_email,
                        $subject,
                        $email_body,
                        array( 'Content-Type: text/html; charset=UTF-8' )
                    );
                }
            }
        }
    }

    /**
     * Send quote ready notification
     *
     * @param int $project_id Project ID
     * @param int $quote_id Quote ID
     * @return void
     */
    public static function notify_quote_ready( $project_id, $quote_id ) {
        $projects_class = new N88_RFQ_Projects();
        $project = $projects_class->get_project_admin( $project_id );
        if ( ! $project ) {
            return;
        }

        self::create_notification(
            $project_id,
            $project['user_id'],
            'quote_ready',
            'Your quote is ready: ' . $project['project_name'],
            $quote_id
        );
    }

    /**
     * Notify user when urgent flag is triggered (Phase 2B)
     *
     * @param int $project_id Project ID
     * @param int $item_index Item index
     * @param string $reason Reason for urgent flag
     * @return bool True on success
     */
    public static function notify_urgent_flag_triggered( $project_id, $item_index, $reason = '' ) {
        $projects_class = new N88_RFQ_Projects();
        $project = $projects_class->get_project_admin( $project_id );
        if ( ! $project ) {
            return false;
        }

        $message = sprintf( '🚨 Urgent flag triggered for Item #%d on project: %s', $item_index + 1, $project['project_name'] );

        // Notify project owner
        self::create_notification(
            $project_id,
            $project['user_id'],
            'urgent_flag_triggered',
            $message,
            $item_index
        );

        // Send email to project owner
        $owner = get_userdata( $project['user_id'] );
        if ( $owner ) {
            $subject = '[N88 RFQ] 🚨 Urgent Flag: ' . $project['project_name'];
            $email_body = self::get_email_template( 'urgent_flag_triggered', array(
                'user_name' => $owner->display_name,
                'project_name' => $project['project_name'],
                'item_number' => $item_index + 1,
                'reason' => $reason ?: 'Urgent attention required',
                'project_url' => home_url( '/project-detail/?project_id=' . $project_id ),
            ) );
            wp_mail(
                $owner->user_email,
                $subject,
                $email_body,
                array( 'Content-Type: text/html; charset=UTF-8' )
            );
        }

        return true;
    }

    /**
     * Notify user when extraction requires review (Phase 2B)
     *
     * @param int $project_id Project ID
     * @param int $needs_review_count Number of items needing review
     * @param int $total_items Total items extracted
     * @return bool True on success
     */
    public static function notify_extraction_requires_review( $project_id, $needs_review_count, $total_items ) {
        $projects_class = new N88_RFQ_Projects();
        $project = $projects_class->get_project_admin( $project_id );
        if ( ! $project ) {
            return false;
        }

        $message = sprintf( 'PDF extraction completed: %d of %d items need review', $needs_review_count, $total_items );

        // Notify project owner
        self::create_notification(
            $project_id,
            $project['user_id'],
            'extraction_requires_review',
            $message,
            null
        );

        // Send email to project owner
        $owner = get_userdata( $project['user_id'] );
        if ( $owner ) {
            $subject = '[N88 RFQ] PDF Extraction Requires Review: ' . $project['project_name'];
            $email_body = self::get_email_template( 'extraction_requires_review', array(
                'user_name' => $owner->display_name,
                'project_name' => $project['project_name'],
                'needs_review_count' => $needs_review_count,
                'total_items' => $total_items,
                'project_url' => home_url( '/project-detail/?project_id=' . $project_id ),
            ) );
            wp_mail(
                $owner->user_email,
                $subject,
                $email_body,
                array( 'Content-Type: text/html; charset=UTF-8' )
            );
        }

        return true;
    }

    /**
     * Notify admin when user marks comment as urgent (Phase 2B)
     *
     * @param int $project_id Project ID
     * @param object $comment Comment object
     * @return bool True on success
     */
    public static function notify_user_marked_urgent( $project_id, $comment ) {
        $projects_class = new N88_RFQ_Projects();
        $project = $projects_class->get_project_admin( $project_id );
        if ( ! $project ) {
            return false;
        }

        $commenter = get_userdata( $comment->user_id );
        $message = sprintf( '🚨 User %s marked a comment as URGENT on project: %s', $commenter->display_name, $project['project_name'] );

        // Notify all admins
        $admins = get_users( array( 'role' => 'administrator' ) );
        foreach ( $admins as $admin ) {
            self::create_notification(
                $project_id,
                $admin->ID,
                'user_marked_urgent',
                $message,
                $comment->id
            );

            // Send email to admin
            $subject = '[N88 RFQ] 🚨 User Marked Comment as Urgent: ' . $project['project_name'];
            $email_body = self::get_email_template( 'comment_urgent', array(
                'user_name' => $admin->display_name,
                'commenter_name' => $commenter->display_name,
                'project_name' => $project['project_name'],
                'comment_text' => $comment->comment_text,
                'project_url' => admin_url( 'admin.php?page=n88-rfq-projects&project_id=' . $project_id ),
            ) );
            wp_mail(
                $admin->user_email,
                $subject,
                $email_body,
                array( 'Content-Type: text/html; charset=UTF-8' )
            );
        }

        return true;
    }

    /**
     * Notify admin when extraction fails (Phase 2B)
     *
     * @param int $project_id Project ID
     * @param string $error_message Error message
     * @return bool True on success
     */
    public static function notify_extraction_failed( $project_id, $error_message = '' ) {
        $projects_class = new N88_RFQ_Projects();
        $project = $projects_class->get_project_admin( $project_id );
        if ( ! $project ) {
            return false;
        }

        $project_owner = get_userdata( $project['user_id'] );
        $message = sprintf( 'PDF extraction failed for project: %s', $project['project_name'] );

        // Notify all admins
        $admins = get_users( array( 'role' => 'administrator' ) );
        foreach ( $admins as $admin ) {
            self::create_notification(
                $project_id,
                $admin->ID,
                'extraction_failed',
                $message,
                null
            );

            // Send email to admin
            $subject = '[N88 RFQ] PDF Extraction Failed: ' . $project['project_name'];
            $email_body = self::get_email_template( 'extraction_failed', array(
                'project_name' => $project['project_name'],
                'user_name' => $project_owner ? $project_owner->display_name : 'Unknown',
                'user_email' => $project_owner ? $project_owner->user_email : '',
                'error_message' => $error_message ?: 'Unknown error occurred during PDF extraction',
                'admin_url' => admin_url( 'admin.php?page=n88-rfq-projects&project_id=' . $project_id ),
            ) );
            wp_mail(
                $admin->user_email,
                $subject,
                $email_body,
                array( 'Content-Type: text/html; charset=UTF-8' )
            );
        }

        return true;
    }

    /**
     * Notify admin when needs-review items are pending (Phase 2B)
     *
     * @param int $project_id Project ID
     * @param int $needs_review_count Number of items needing review
     * @return bool True on success
     */
    public static function notify_needs_review_pending( $project_id, $needs_review_count ) {
        $projects_class = new N88_RFQ_Projects();
        $project = $projects_class->get_project_admin( $project_id );
        if ( ! $project ) {
            return false;
        }

        $project_owner = get_userdata( $project['user_id'] );
        $message = sprintf( 'Project %s has %d item(s) requiring review', $project['project_name'], $needs_review_count );

        // Notify all admins
        $admins = get_users( array( 'role' => 'administrator' ) );
        foreach ( $admins as $admin ) {
            self::create_notification(
                $project_id,
                $admin->ID,
                'needs_review_pending',
                $message,
                null
            );

            // Send email to admin
            $subject = '[N88 RFQ] Items Requiring Review: ' . $project['project_name'];
            $email_body = self::get_email_template( 'needs_review_pending', array(
                'project_name' => $project['project_name'],
                'user_name' => $project_owner ? $project_owner->display_name : 'Unknown',
                'needs_review_count' => $needs_review_count,
                'admin_url' => admin_url( 'admin.php?page=n88-rfq-projects&project_id=' . $project_id ),
            ) );
            wp_mail(
                $admin->user_email,
                $subject,
                $email_body,
                array( 'Content-Type: text/html; charset=UTF-8' )
            );
        }

        return true;
    }

    /**
     * Get email template for notifications
     *
     * @param string $template_type Type of template
     * @param array $data Data to use in template
     * @return string HTML email body
     */
    public static function get_email_template( $template_type, $data ) {
        $site_name = get_bloginfo( 'name' );
        $site_url = home_url();
        
        $header = sprintf(
            '<div style="background: #f9f9f9; padding: 20px; border-bottom: 3px solid #007cba;"><h1 style="margin: 0; color: #333; font-size: 24px;">%s</h1></div>',
            $site_name
        );

        $footer = '
        <div style="background: #f9f9f9; padding: 20px; margin-top: 30px; border-top: 1px solid #ddd; text-align: center; color: #999; font-size: 12px;">
            <p>This is an automated notification from ' . $site_name . '</p>
            <p><a href="' . $site_url . '" style="color: #007cba; text-decoration: none;">' . $site_url . '</a></p>
        </div>';

        $styles = '
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; color: #333; }
            .email-container { max-width: 600px; margin: 0 auto; background: #fff; }
            .content { padding: 20px; }
            .button { display: inline-block; background: #007cba; color: white; padding: 12px 30px; border-radius: 4px; text-decoration: none; margin-top: 15px; }
            .button:hover { background: #0056b3; }
            .info-box { background: #f0f7ff; padding: 15px; border-left: 4px solid #007cba; margin: 15px 0; }
            h2 { color: #007cba; margin-top: 0; }
            p { line-height: 1.6; }
        </style>';

        $body_html = '';

        switch ( $template_type ) {
            case 'admin_project_upload':
                $body_html = sprintf(
                    '<div class="content">
                        <h2>New Project Submission</h2>
                        <p>Hello,</p>
                        <p>A new project has been submitted and requires your attention.</p>
                        <div class="info-box">
                            <strong>Project:</strong> %s<br>
                            <strong>Type:</strong> %s<br>
                            <strong>Submitted by:</strong> %s<br>
                            <strong>Email:</strong> <a href="mailto:%s">%s</a><br>
                            <strong>Timeline:</strong> %s<br>
                            <strong>Budget:</strong> %s<br>
                            <strong>Items:</strong> %d<br>
                            <strong>Date:</strong> %s
                        </div>
                        <p><a href="%s" class="button">View Project</a></p>
                        <p>Best regards,<br>N88 RFQ Platform</p>
                    </div>',
                    esc_html( $data['project_name'] ),
                    esc_html( $data['project_type'] ),
                    esc_html( $data['submitted_by'] ),
                    esc_html( $data['email'] ),
                    esc_html( $data['email'] ),
                    esc_html( $data['timeline'] ),
                    esc_html( $data['budget'] ),
                    intval( $data['item_count'] ),
                    date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $data['submitted_at'] ) ),
                    esc_url( $data['project_url'] )
                );
                break;

            case 'user_quote_uploaded':
                $body_html = sprintf(
                    '<div class="content">
                        <h2>Quote Ready!</h2>
                        <p>Hello %s,</p>
                        <p>%s</p>
                        <div class="info-box">
                            <strong>Project:</strong> %s<br>
                            <strong>Quote ID:</strong> %s
                        </div>
                        <p><a href="%s" class="button">View Quote</a></p>
                        <p>Thank you for using N88 RFQ Platform!</p>
                    </div>',
                    esc_html( $data['user_name'] ),
                    $data['message'],
                    esc_html( $data['project_name'] ),
                    esc_html( $data['quote_id'] ),
                    esc_url( $data['project_url'] )
                );
                break;

            case 'comment_added':
                $body_html = sprintf(
                    '<div class="content">
                        <h2>New Comment on Your Project</h2>
                        <p>Hello %s,</p>
                        <p>%s has posted a new comment on your project <strong>%s</strong>.</p>
                        <div class="info-box">
                            <strong>Comment:</strong><br>
                            %s
                        </div>
                        <p><a href="%s" class="button">View Comment</a></p>
                        <p>Best regards,<br>N88 RFQ Platform</p>
                    </div>',
                    esc_html( $data['user_name'] ),
                    esc_html( $data['commenter_name'] ),
                    esc_html( $data['project_name'] ),
                    nl2br( esc_html( $data['comment_text'] ) ),
                    esc_url( $data['project_url'] )
                );
                break;

            case 'comment_added_admin':
                $body_html = sprintf(
                    '<div class="content">
                        <h2>New Comment on Project</h2>
                        <p>Hello %s,</p>
                        <p>%s has posted a comment on project <strong>%s</strong>.</p>
                        <div class="info-box">
                            <strong>Comment:</strong><br>
                            %s
                        </div>
                        <p><a href="%s" class="button">View Project</a></p>
                        <p>Best regards,<br>N88 RFQ Platform</p>
                    </div>',
                    esc_html( $data['admin_name'] ),
                    esc_html( $data['commenter_name'] ),
                    esc_html( $data['project_name'] ),
                    nl2br( esc_html( $data['comment_text'] ) ),
                    esc_url( $data['admin_url'] )
                );
                break;

            case 'comment_reply':
                $urgent_badge = ! empty( $data['is_urgent'] ) 
                    ? '<div style="background: #ffebee; color: #c62828; padding: 10px; border-left: 4px solid #c62828; margin: 15px 0; border-radius: 4px;"><strong>🚨 URGENT REPLY</strong></div>' 
                    : '';
                $body_html = sprintf(
                    '<div class="content">
                        <h2>Reply to Your Comment</h2>
                        <p>Hello %s,</p>
                        <p>%s has replied to your comment on project <strong>%s</strong>.</p>
                        %s
                        <div class="info-box">
                            <strong>Your Original Comment:</strong><br>
                            %s
                        </div>
                        <div class="info-box" style="background: #f0f7ff; border-left-color: #007cba;">
                            <strong>Reply:</strong><br>
                            %s
                        </div>
                        <p><a href="%s" class="button">View Reply</a></p>
                        <p>Best regards,<br>N88 RFQ Platform</p>
                    </div>',
                    esc_html( $data['user_name'] ),
                    esc_html( $data['commenter_name'] ),
                    esc_html( $data['project_name'] ),
                    $urgent_badge,
                    nl2br( esc_html( $data['parent_comment_text'] ?? '' ) ),
                    nl2br( esc_html( $data['comment_text'] ) ),
                    esc_url( $data['project_url'] )
                );
                break;

            case 'comment_urgent':
                $body_html = sprintf(
                    '<div class="content">
                        <h2 style="color: #c62828;">🚨 URGENT Comment on Your Project</h2>
                        <div style="background: #ffebee; color: #c62828; padding: 15px; border-left: 4px solid #c62828; margin: 15px 0; border-radius: 4px;">
                            <strong>This is an URGENT comment requiring immediate attention.</strong>
                        </div>
                        <p>Hello %s,</p>
                        <p>%s has posted an <strong>URGENT</strong> comment on your project <strong>%s</strong>.</p>
                        <div class="info-box">
                            <strong>Comment:</strong><br>
                            %s
                        </div>
                        <p><a href="%s" class="button" style="background: #c62828;">View Urgent Comment</a></p>
                        <p>Best regards,<br>N88 RFQ Platform</p>
                    </div>',
                    esc_html( $data['user_name'] ),
                    esc_html( $data['commenter_name'] ),
                    esc_html( $data['project_name'] ),
                    nl2br( esc_html( $data['comment_text'] ) ),
                    esc_url( $data['project_url'] )
                );
                break;

            case 'admin_commented':
                $body_html = sprintf(
                    '<div class="content">
                        <h2>Admin Commented on Your Project</h2>
                        <p>Hello %s,</p>
                        <p>An administrator has posted a comment on your project <strong>%s</strong>.</p>
                        <div class="info-box" style="background: #fff3cd; border-left-color: #ffc107;">
                            <strong>Admin Comment:</strong><br>
                            %s
                        </div>
                        <p><a href="%s" class="button">View Comment</a></p>
                        <p>Best regards,<br>N88 RFQ Platform</p>
                    </div>',
                    esc_html( $data['user_name'] ),
                    esc_html( $data['project_name'] ),
                    nl2br( esc_html( $data['comment_text'] ) ),
                    esc_url( $data['project_url'] )
                );
                break;

            case 'admin_updated_item':
                $body_html = sprintf(
                    '<div class="content">
                        <h2>Item Updated by Admin</h2>
                        <p>Hello %s,</p>
                        <p>An administrator has updated <strong>Item #%d</strong> on your project <strong>%s</strong>.</p>
                        <div class="info-box">
                            <strong>Updated by:</strong> %s<br>
                            <strong>Item Number:</strong> %d
                        </div>
                        <p><a href="%s" class="button">View Updated Item</a></p>
                        <p>Best regards,<br>N88 RFQ Platform</p>
                    </div>',
                    esc_html( $data['user_name'] ),
                    intval( $data['item_number'] ),
                    esc_html( $data['project_name'] ),
                    esc_html( $data['admin_name'] ),
                    intval( $data['item_number'] ),
                    esc_url( $data['project_url'] )
                );
                break;

            case 'user_edited_item':
                $body_html = sprintf(
                    '<div class="content">
                        <h2>User Edited Item</h2>
                        <p>Hello %s,</p>
                        <p>User <strong>%s</strong> has edited <strong>Item #%d</strong> on project <strong>%s</strong>.</p>
                        <p><a href="%s" class="button">Review Changes</a></p>
                        <p>Best regards,<br>N88 RFQ Platform</p>
                    </div>',
                    esc_html( $data['admin_name'] ),
                    esc_html( $data['user_name'] ),
                    intval( $data['item_number'] ),
                    esc_html( $data['project_name'] ),
                    esc_url( $data['admin_url'] )
                );
                break;

            case 'urgent_flag_triggered':
                $body_html = sprintf(
                    '<div class="content">
                        <h2 style="color: #c62828;">🚨 Urgent Flag Triggered</h2>
                        <div style="background: #ffebee; color: #c62828; padding: 15px; border-left: 4px solid #c62828; margin: 15px 0; border-radius: 4px;">
                            <strong>An urgent flag has been added to Item #%d</strong>
                        </div>
                        <p>Hello %s,</p>
                        <p>An urgent flag has been triggered for <strong>Item #%d</strong> on your project <strong>%s</strong>.</p>
                        <div class="info-box">
                            <strong>Reason:</strong> %s
                        </div>
                        <p><a href="%s" class="button" style="background: #c62828;">View Item</a></p>
                        <p>Best regards,<br>N88 RFQ Platform</p>
                    </div>',
                    intval( $data['item_number'] ),
                    esc_html( $data['user_name'] ),
                    intval( $data['item_number'] ),
                    esc_html( $data['project_name'] ),
                    esc_html( $data['reason'] ?? 'Urgent attention required' ),
                    esc_url( $data['project_url'] )
                );
                break;

            case 'extraction_requires_review':
                $body_html = sprintf(
                    '<div class="content">
                        <h2>PDF Extraction Requires Review</h2>
                        <p>Hello %s,</p>
                        <p>Your PDF extraction for project <strong>%s</strong> has completed, but some items require your review.</p>
                        <div class="info-box" style="background: #fff3cd; border-left-color: #ffc107;">
                            <strong>Items Needing Review:</strong> %d<br>
                            <strong>Total Items Extracted:</strong> %d
                        </div>
                        <p>Please review and complete the items marked as "Needs Review" before proceeding.</p>
                        <p><a href="%s" class="button">Review Items</a></p>
                        <p>Best regards,<br>N88 RFQ Platform</p>
                    </div>',
                    esc_html( $data['user_name'] ),
                    esc_html( $data['project_name'] ),
                    intval( $data['needs_review_count'] ),
                    intval( $data['total_items'] ),
                    esc_url( $data['project_url'] )
                );
                break;

            case 'extraction_failed':
                $body_html = sprintf(
                    '<div class="content">
                        <h2 style="color: #c62828;">PDF Extraction Failed</h2>
                        <div style="background: #ffebee; color: #c62828; padding: 15px; border-left: 4px solid #c62828; margin: 15px 0; border-radius: 4px;">
                            <strong>PDF extraction failed for project: %s</strong>
                        </div>
                        <p>Hello,</p>
                        <p>The PDF extraction process failed for project <strong>%s</strong> submitted by <strong>%s</strong>.</p>
                        <div class="info-box">
                            <strong>Error:</strong> %s<br>
                            <strong>Project:</strong> %s<br>
                            <strong>Submitted by:</strong> %s (%s)
                        </div>
                        <p><a href="%s" class="button">View Project</a></p>
                        <p>Best regards,<br>N88 RFQ Platform</p>
                    </div>',
                    esc_html( $data['project_name'] ),
                    esc_html( $data['project_name'] ),
                    esc_html( $data['user_name'] ),
                    esc_html( $data['error_message'] ?? 'Unknown error' ),
                    esc_html( $data['project_name'] ),
                    esc_html( $data['user_name'] ),
                    esc_html( $data['user_email'] ?? '' ),
                    esc_url( $data['admin_url'] )
                );
                break;

            case 'needs_review_pending':
                $body_html = sprintf(
                    '<div class="content">
                        <h2>Items Requiring Review</h2>
                        <p>Hello,</p>
                        <p>Project <strong>%s</strong> has <strong>%d item(s)</strong> that require review.</p>
                        <div class="info-box" style="background: #fff3cd; border-left-color: #ffc107;">
                            <strong>Project:</strong> %s<br>
                            <strong>Items Needing Review:</strong> %d<br>
                            <strong>Submitted by:</strong> %s
                        </div>
                        <p><a href="%s" class="button">Review Items</a></p>
                        <p>Best regards,<br>N88 RFQ Platform</p>
                    </div>',
                    esc_html( $data['project_name'] ),
                    intval( $data['needs_review_count'] ),
                    esc_html( $data['project_name'] ),
                    intval( $data['needs_review_count'] ),
                    esc_html( $data['user_name'] ),
                    esc_url( $data['admin_url'] )
                );
                break;

            default:
                $body_html = '<div class="content"><p>Notification from ' . get_bloginfo( 'name' ) . '</p></div>';
        }

        return $styles . $header . $body_html . $footer;
    }
}

add_action( 'plugins_loaded', array( 'N88_RFQ_Notifications', 'init_universal_action_email_system' ), 20 );
