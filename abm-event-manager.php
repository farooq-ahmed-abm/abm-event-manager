<?php
/**
 * Plugin Name: ABM Event Manager
 * Plugin URI:  https://abmreading.org
 * Description: Internal event management tool for Abu Bakr Masjid administrators. Adds a private admin page with a full event creation, editing, deletion and image upload interface.
 * Version:     1.2.0
 * Author:      Abu Bakr Masjid
 * License:     Private
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class ABM_Event_Manager {

    public function __construct() {
        add_action( 'init', [ $this, 'register_post_type' ] );
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'wp_ajax_abm_save_event', [ $this, 'ajax_save_event' ] );
        add_action( 'wp_ajax_abm_delete_event', [ $this, 'ajax_delete_event' ] );
        add_action( 'wp_ajax_abm_get_events', [ $this, 'ajax_get_events' ] );
        add_action( 'wp_ajax_abm_upload_media', [ $this, 'ajax_upload_media' ] );
        add_action( 'wp_ajax_abm_get_media', [ $this, 'ajax_get_media' ] );
        add_action( 'plugins_loaded', [ $this, 'create_table' ] );
        register_activation_hook( __FILE__, [ $this, 'create_table' ] );
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
        add_action( 'wp_ajax_abm_delete_series', array( $this, 'ajax_delete_series' ) );
        add_shortcode( 'abm_events', [ $this, 'render_shortcode' ] );
        add_action( 'wp_head', [ $this, 'shortcode_styles' ] );
        add_filter( 'single_template', array( $this, 'event_detail_template' ) );
        add_filter( 'rest_endpoints', array( $this, 'disable_user_endpoints' ) );
    }

    /* ── Database table ─────────────────────────────────────────────────── */
    public function create_table() {
        global $wpdb;
        $table   = $wpdb->prefix . 'abm_events';
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  title varchar(255) NOT NULL DEFAULT '',
  event_date date DEFAULT NULL,
  event_time time DEFAULT NULL,
  end_date date DEFAULT NULL,
  end_time time DEFAULT NULL,
  location varchar(255) DEFAULT '',
  category varchar(100) DEFAULT '',
  status varchar(20) DEFAULT 'publish',
  description text DEFAULT '',
  image_url varchar(500) DEFAULT '',
  image_id bigint(20) DEFAULT NULL,
  wp_post_id bigint(20) DEFAULT NULL,
  recurrence varchar(20) DEFAULT 'none',
  recurrence_every int(11) DEFAULT NULL,
  recurrence_end date DEFAULT NULL,
  parent_event_id bigint(20) DEFAULT NULL,
  created_at datetime DEFAULT NULL,
  updated_at datetime DEFAULT NULL,
  PRIMARY KEY  (id)
) $charset;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public function on_activate() {
        $this->create_table();
        $this->add_rewrite_rules();
        flush_rewrite_rules();
    }

    /* ── Custom post type (optional, for front-end display) ─────────────── */
    public function register_post_type() {
        register_post_type( 'abm_event', [
            'labels'   => [ 'name' => 'ABM Events', 'singular_name' => 'ABM Event' ],
            'public'   => true,
            'supports' => [ 'title', 'editor', 'thumbnail', 'excerpt' ],
            'show_in_rest' => true,
        ]);
    }

    /* ── Admin menu ─────────────────────────────────────────────────────── */
    public function add_admin_menu() {
        add_menu_page(
            'ABM Event Manager',
            'ABM Events',
            'edit_posts',
            'abm-event-manager',
            [ $this, 'render_admin_page' ],
            'dashicons-calendar-alt',
            30
        );
    }

    /* ── AJAX: Get events ───────────────────────────────────────────────── */
    public function ajax_get_events() {
        check_ajax_referer( 'abm_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) wp_die( 'Unauthorized', 403 );
        global $wpdb;
        $table = $wpdb->prefix . 'abm_events';
        $events = $wpdb->get_results( "SELECT * FROM $table ORDER BY event_date ASC, created_at DESC" );
        wp_send_json_success( $events );
    }

    /* ── AJAX: Save event ───────────────────────────────────────────────── */
    public function ajax_save_event() {
        check_ajax_referer( 'abm_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) wp_die( 'Unauthorized', 403 );
        global $wpdb;
        $table = $wpdb->prefix . 'abm_events';

        $id          = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        $title       = sanitize_text_field( $_POST['title'] ?? '' );
        $event_date  = sanitize_text_field( $_POST['event_date'] ?? '' );
        $event_time  = sanitize_text_field( $_POST['event_time'] ?? '' );
        $end_date    = sanitize_text_field( $_POST['end_date'] ?? '' );
        $end_time    = sanitize_text_field( $_POST['end_time'] ?? '' );
        $location    = sanitize_text_field( $_POST['location'] ?? '' );
        $category    = sanitize_text_field( $_POST['category'] ?? '' );
        $status      = sanitize_text_field( $_POST['status'] ?? 'publish' );
        $description = sanitize_textarea_field( $_POST['description'] ?? '' );
        $image_url   = esc_url_raw( $_POST['image_url'] ?? '' );
        $image_id         = intval( $_POST['image_id'] ?? 0 );
        $recurrence       = sanitize_text_field( $_POST['recurrence'] ?? 'none' );
        $recurrence_every = intval( $_POST['recurrence_every'] ?? 0 );
        $recurrence_end   = sanitize_text_field( $_POST['recurrence_end'] ?? '' );

        if ( empty( $title ) ) {
            wp_send_json_error( 'Title is required' );
            return;
        }

        // Build post content for WP
        $content  = '<p>' . esc_html( $description ) . '</p>';
        $content .= "\n<p>📅 " . esc_html( $event_date ) . ' ' . esc_html( $event_time ) . '</p>';
        $content .= "\n<p>📍 " . esc_html( $location ) . '</p>';
        if ( $image_url ) {
            $content .= "\n<img src=\"" . esc_url( $image_url ) . "\" alt=\"" . esc_attr( $title ) . "\" style=\"max-width:100%;border-radius:8px;margin-top:12px;\"/>";
        }

        $now = current_time( 'mysql' );
        $data = [
            'title'       => $title,
            'event_date'  => $event_date ?: null,
            'event_time'  => $event_time ?: null,
            'end_date'    => $end_date ?: null,
            'end_time'    => $end_time ?: null,
            'location'    => $location,
            'category'    => $category,
            'status'      => $status,
            'description' => $description,
            'image_url'   => $image_url,
            'image_id'    => $image_id ?: null,
            'updated_at'  => $now,
        ];

        if ( $id ) {
            // Update existing
            $wpdb->update( $table, $data, [ 'id' => $id ] );
            $existing = $wpdb->get_row( $wpdb->prepare( "SELECT wp_post_id FROM $table WHERE id = %d", $id ) );
            if ( $existing && $existing->wp_post_id ) {
                wp_update_post([
                    'ID'           => $existing->wp_post_id,
                    'post_title'   => $title,
                    'post_content' => $content,
                    'post_status'  => $status,
                    'post_excerpt' => substr( $description, 0, 120 ),
                ]);
            }
            wp_send_json_success( [ 'id' => $id, 'action' => 'updated' ] );
        } else {
            // Create new WP post
            $post_id = wp_insert_post([
                'post_title'   => $title,
                'post_content' => $content,
                'post_status'  => $status,
                'post_type'    => 'abm_event',
                'post_excerpt' => substr( $description, 0, 120 ),
            ]);
            if ( $image_id && ! is_wp_error( $post_id ) ) {
                set_post_thumbnail( $post_id, $image_id );
            }
            $data['wp_post_id'] = is_wp_error( $post_id ) ? null : $post_id;
            $data['created_at']  = $now;
            $wpdb->insert( $table, $data );
            $new_id = $wpdb->insert_id;
            if ( 'none' !== $recurrence && ! empty( $event_date ) ) {
                $this->generate_occurrences( $new_id, $data, $recurrence, $recurrence_every, $recurrence_end );
            }
            wp_send_json_success( [ 'id' => $new_id, 'wp_post_id' => $data['wp_post_id'], 'action' => 'created' ] );
        }
    }

    /* ── AJAX: Delete event ─────────────────────────────────────────────── */
    public function ajax_delete_event() {
        check_ajax_referer( 'abm_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) wp_die( 'Unauthorized', 403 );
        global $wpdb;
        $table = $wpdb->prefix . 'abm_events';
        $id = intval( $_POST['id'] ?? 0 );
        $existing = $wpdb->get_row( $wpdb->prepare( "SELECT wp_post_id FROM $table WHERE id = %d", $id ) );
        if ( $existing && $existing->wp_post_id ) {
            wp_delete_post( $existing->wp_post_id, true );
        }
        $wpdb->delete( $table, [ 'id' => $id ] );
        wp_send_json_success( [ 'deleted' => $id ] );
    }

    /* ── AJAX: Upload media ─────────────────────────────────────────────── */
    public function ajax_upload_media() {
        check_ajax_referer( 'abm_nonce', 'nonce' );
        if ( ! current_user_can( 'upload_files' ) ) wp_die( 'Unauthorized', 403 );
        if ( empty( $_FILES['file'] ) ) {
            wp_send_json_error( 'No file uploaded' );
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        $attachment_id = media_handle_upload( 'file', 0 );
        if ( is_wp_error( $attachment_id ) ) {
            wp_send_json_error( $attachment_id->get_error_message() );
            return;
        }
        wp_send_json_success([
            'id'  => $attachment_id,
            'url' => wp_get_attachment_url( $attachment_id ),
        ]);
    }

    /* ── AJAX: Get media ────────────────────────────────────────────────── */
    public function ajax_get_media() {
        check_ajax_referer( 'abm_nonce', 'nonce' );
        if ( ! current_user_can( 'upload_files' ) ) wp_die( 'Unauthorized', 403 );
        $search = sanitize_text_field( $_GET['search'] ?? '' );
        $args = [
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'post_status'    => 'inherit',
            'posts_per_page' => 24,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];
        if ( $search ) $args['s'] = $search;
        $query = new WP_Query( $args );
        $items = [];
        foreach ( $query->posts as $post ) {
            $items[] = [
                'id'    => $post->ID,
                'url'   => wp_get_attachment_url( $post->ID ),
                'title' => $post->post_title,
                'thumb' => wp_get_attachment_image_url( $post->ID, 'thumbnail' ),
            ];
        }
        wp_send_json_success( $items );
    }


    /* ── AJAX: Update single event field (for external tools) ───────────── */
    public function ajax_update_event_field() {
        check_ajax_referer( 'abm_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) wp_die( 'Unauthorized', 403 );
        global $wpdb;
        $table = $wpdb->prefix . 'abm_events';

        $id    = intval( $_POST['id'] );
        $field = sanitize_key( $_POST['field'] );
        $value = sanitize_textarea_field( $_POST['value'] );

        $allowed = array( 'title', 'description', 'location', 'category', 'status', 'event_date', 'event_time', 'end_date', 'end_time', 'image_url' );
        if ( ! in_array( $field, $allowed, true ) ) {
            wp_send_json_error( 'Field not allowed: ' . $field );
            return;
        }
        $result = $wpdb->update( $table, array( $field => $value, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ) );
        if ( false === $result ) {
            wp_send_json_error( 'Database update failed' );
            return;
        }
        wp_send_json_success( array( 'id' => $id, 'field' => $field, 'updated' => true ) );
    }


    /* ── REST API ───────────────────────────────────────────────────────── */
    public function register_rest_routes() {
        register_rest_route( 'abm/v1', '/events', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'rest_get_events' ),
                'permission_callback' => array( $this, 'rest_permission' ),
            ),
        ) );
        register_rest_route( 'abm/v1', '/events/(?P<id>[\d]+)', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'rest_get_event' ),
                'permission_callback' => array( $this, 'rest_permission' ),
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_update_event' ),
                'permission_callback' => array( $this, 'rest_permission' ),
            ),
            array(
                'methods'             => 'DELETE',
                'callback'            => array( $this, 'rest_delete_event' ),
                'permission_callback' => array( $this, 'rest_permission' ),
            ),
        ) );
        register_rest_route( 'abm/v1', '/events/recurring', array(
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_create_recurring_event' ),
                'permission_callback' => array( $this, 'rest_permission' ),
            ),
        ) );
        register_rest_route( 'abm/v1', '/events', array(
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_create_event' ),
                'permission_callback' => array( $this, 'rest_permission' ),
            ),
        ) );
    }

    public function rest_permission( $request ) {
        if ( current_user_can( 'edit_posts' ) ) {
            return true;
        }
        return new WP_Error( 'rest_forbidden', 'Insufficient permissions.', array( 'status' => 401 ) );
    }

    public function rest_get_events( $request ) {
        global $wpdb;
        $table    = $wpdb->prefix . 'abm_events';
        $upcoming = $request->get_param( 'upcoming' );
        $category = $request->get_param( 'category' );
        $where    = array( '1=1' );
        if ( $upcoming === 'yes' ) {
            $where[] = "(event_date IS NULL OR event_date >= CURDATE())";
        }
        if ( $category ) {
            $where[] = $wpdb->prepare( "category = %s", $category );
        }
        $sql    = "SELECT * FROM $table WHERE " . implode( ' AND ', $where ) . " ORDER BY event_date ASC, event_time ASC";
        $events = $wpdb->get_results( $sql );
        return rest_ensure_response( $events );
    }

    public function rest_get_event( $request ) {
        global $wpdb;
        $table = $wpdb->prefix . 'abm_events';
        $id    = intval( $request['id'] );
        $event = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
        if ( ! $event ) {
            return new WP_Error( 'not_found', 'Event not found', array( 'status' => 404 ) );
        }
        return rest_ensure_response( $event );
    }

    public function rest_create_event( $request ) {
        global $wpdb;
        $table  = $wpdb->prefix . 'abm_events';
        $params = $request->get_json_params();
        if ( empty( $params['title'] ) ) {
            return new WP_Error( 'missing_title', 'Title is required', array( 'status' => 400 ) );
        }
        $now  = current_time( 'mysql' );
        $data = array(
            'title'       => sanitize_text_field( $params['title'] ),
            'event_date'  => isset( $params['event_date'] ) ? sanitize_text_field( $params['event_date'] ) : null,
            'event_time'  => isset( $params['event_time'] ) ? sanitize_text_field( $params['event_time'] ) : null,
            'end_date'    => isset( $params['end_date'] ) ? sanitize_text_field( $params['end_date'] ) : null,
            'end_time'    => isset( $params['end_time'] ) ? sanitize_text_field( $params['end_time'] ) : null,
            'location'    => sanitize_text_field( $params['location'] ?? '' ),
            'category'    => sanitize_text_field( $params['category'] ?? '' ),
            'status'      => sanitize_text_field( $params['status'] ?? 'publish' ),
            'description' => sanitize_textarea_field( $params['description'] ?? '' ),
            'image_url'   => esc_url_raw( $params['image_url'] ?? '' ),
            'image_id'    => intval( $params['image_id'] ?? 0 ) ?: null,
            'created_at'  => $now,
            'updated_at'  => $now,
        );
        // Insert into custom table first
        $wpdb->insert( $table, $data );
        $new_id = $wpdb->insert_id;

        // Create corresponding abm_event custom post type entry
        $description = $data['description'] ?? '';
        $post_content  = '<p>' . esc_html( $description ) . '</p>';
        $post_content .= '<p>' . esc_html( $data['event_date'] ?? '' ) . ' ' . esc_html( $data['event_time'] ?? '' ) . '</p>';
        $post_content .= '<p>' . esc_html( $data['location'] ?? '' ) . '</p>';
        if ( ! empty( $data['image_url'] ) ) {
            $post_content .= '<img src="' . esc_url( $data['image_url'] ) . '" alt="' . esc_attr( $data['title'] ) . '" style="max-width:100%;border-radius:8px;margin-top:12px;"/>';
        }

        $post_id = wp_insert_post( array(
            'post_title'   => $data['title'],
            'post_content' => $post_content,
            'post_status'  => $data['status'],
            'post_type'    => 'abm_event',
            'post_excerpt' => mb_substr( $description, 0, 120 ),
        ) );

        if ( ! is_wp_error( $post_id ) && $post_id ) {
            if ( ! empty( $data['image_id'] ) ) {
                set_post_thumbnail( $post_id, intval( $data['image_id'] ) );
            }
            $wpdb->update( $table, array( 'wp_post_id' => $post_id ), array( 'id' => $new_id ) );
        }

        $event = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $new_id ) );
        return rest_ensure_response( $event );
    }

    public function rest_update_event( $request ) {
        global $wpdb;
        $table  = $wpdb->prefix . 'abm_events';
        $id     = intval( $request['id'] );
        $params = $request->get_json_params();

        $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
        if ( ! $existing ) {
            return new WP_Error( 'not_found', 'Event not found', array( 'status' => 404 ) );
        }

        $allowed = array( 'title', 'event_date', 'event_time', 'end_date', 'end_time', 'location', 'category', 'status', 'description', 'image_url', 'image_id' );
        $data    = array( 'updated_at' => current_time( 'mysql' ) );
        foreach ( $allowed as $field ) {
            if ( isset( $params[ $field ] ) ) {
                if ( $field === 'image_url' ) {
                    $data[ $field ] = esc_url_raw( $params[ $field ] );
                } elseif ( $field === 'description' ) {
                    $data[ $field ] = sanitize_textarea_field( $params[ $field ] );
                } elseif ( $field === 'image_id' ) {
                    $data[ $field ] = intval( $params[ $field ] ) ?: null;
                } else {
                    $data[ $field ] = sanitize_text_field( $params[ $field ] );
                }
            }
        }

        $wpdb->update( $table, $data, array( 'id' => $id ) );

        // Also update the WP post if it exists
        if ( $existing->wp_post_id ) {
            $post_data = array( 'ID' => $existing->wp_post_id );
            if ( isset( $data['title'] ) )       { $post_data['post_title']   = $data['title']; }
            if ( isset( $data['status'] ) )       { $post_data['post_status']  = $data['status']; }
            if ( isset( $data['description'] ) )  { $post_data['post_content'] = '<p>' . esc_html( $data['description'] ) . '</p>'; $post_data['post_excerpt'] = substr( $data['description'], 0, 120 ); }
            if ( count( $post_data ) > 1 )        { wp_update_post( $post_data ); }
        }

        $updated = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
        return rest_ensure_response( $updated );
    }

    public function rest_delete_event( $request ) {
        global $wpdb;
        $table    = $wpdb->prefix . 'abm_events';
        $id       = intval( $request['id'] );
        $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
        if ( ! $existing ) {
            return new WP_Error( 'not_found', 'Event not found', array( 'status' => 404 ) );
        }
        if ( $existing->wp_post_id ) {
            wp_delete_post( $existing->wp_post_id, true );
        }
        $wpdb->delete( $table, array( 'id' => $id ) );
        return rest_ensure_response( array( 'deleted' => true, 'id' => $id ) );
    }


    /* ── Generate recurring occurrences ────────────────────────────────── */
    private function generate_occurrences( $parent_id, $data, $recurrence, $every, $end_date ) {
        global $wpdb;
        $table     = $wpdb->prefix . 'abm_events';
        $start     = new DateTime( $data['event_date'] );
        $end_limit = $end_date ? new DateTime( $end_date ) : null;
        $max       = 52; // safety cap
        $count     = 0;

        // Step interval in days
        switch ( $recurrence ) {
            case 'weekly':    $interval = 7;           break;
            case 'biweekly':  $interval = 14;          break;
            case 'monthly':   $interval = null;        break; // special handling
            case 'custom':    $interval = max(1, intval( $every ) ); break;
            default:          return;
        }

        $current = clone $start;

        while ( $count < $max ) {
            // Advance to next occurrence
            if ( 'monthly' === $recurrence ) {
                $current->modify( '+1 month' );
            } else {
                $current->modify( "+{$interval} days" );
            }

            // Stop if past end date
            if ( $end_limit && $current > $end_limit ) {
                break;
            }

            // Build occurrence data
            $occ = $data;
            $occ['event_date']      = $current->format( 'Y-m-d' );
            $occ['parent_event_id'] = $parent_id;
            $occ['created_at']      = current_time( 'mysql' );
            $occ['updated_at']      = current_time( 'mysql' );
            unset( $occ['recurrence'], $occ['recurrence_every'], $occ['recurrence_end'] );
            $occ['recurrence']       = 'none'; // occurrences are not themselves recurring
            $occ['recurrence_every'] = null;
            $occ['recurrence_end']   = null;

            // Create WP post for occurrence
            $post_content  = '<p>' . esc_html( $occ['description'] ?? '' ) . '</p>';
            $post_content .= '<p>' . esc_html( $occ['event_date'] ) . ' ' . esc_html( $occ['event_time'] ?? '' ) . '</p>';
            $post_content .= '<p>' . esc_html( $occ['location'] ?? '' ) . '</p>';
            $post_id = wp_insert_post( array(
                'post_title'   => $occ['title'],
                'post_content' => $post_content,
                'post_status'  => $occ['status'],
                'post_type'    => 'abm_event',
                'post_excerpt' => mb_substr( $occ['description'] ?? '', 0, 120 ),
            ) );
            $occ['wp_post_id'] = is_wp_error( $post_id ) ? null : $post_id;

            $wpdb->insert( $table, $occ );
            $count++;

            // If no end date, stop after a sensible default
            if ( ! $end_limit && $count >= 12 ) {
                break;
            }
        }
    }

    /* ── AJAX: Delete entire series ─────────────────────────────────────── */
    public function ajax_delete_series() {
        check_ajax_referer( 'abm_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) wp_die( 'Unauthorized', 403 );
        global $wpdb;
        $table     = $wpdb->prefix . 'abm_events';
        $parent_id = intval( $_POST['parent_id'] ?? 0 );
        if ( ! $parent_id ) {
            wp_send_json_error( 'No parent ID provided' );
            return;
        }
        // Get all events in series (parent + children)
        $series = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, wp_post_id FROM $table WHERE id = %d OR parent_event_id = %d",
            $parent_id, $parent_id
        ) );
        foreach ( $series as $ev ) {
            if ( $ev->wp_post_id ) {
                wp_delete_post( $ev->wp_post_id, true );
            }
            $wpdb->delete( $table, array( 'id' => $ev->id ) );
        }
        wp_send_json_success( array( 'deleted_count' => count( $series ) ) );
    }

    /* ── REST: Create recurring event ───────────────────────────────────── */
    public function rest_create_recurring_event( $request ) {
        $params     = $request->get_json_params();
        $recurrence = sanitize_text_field( $params['recurrence'] ?? 'none' );
        $every      = intval( $params['recurrence_every'] ?? 0 );
        $end_date   = sanitize_text_field( $params['recurrence_end'] ?? '' );

        // Create parent event via existing method
        $response = $this->rest_create_event( $request );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $parent = $response->get_data();
        $parent_id = $parent->id;

        // Generate occurrences
        global $wpdb;
        $table = $wpdb->prefix . 'abm_events';
        $data  = (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $parent_id ) );
        if ( 'none' !== $recurrence && ! empty( $data['event_date'] ) ) {
            $this->generate_occurrences( $parent_id, $data, $recurrence, $every, $end_date );
        }

        // Return parent + count of occurrences
        $occ_count = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE parent_event_id = %d", $parent_id
        ) );
        $parent->occurrences_created = intval( $occ_count );
        return rest_ensure_response( $parent );
    }

    /* ── Admin page HTML ────────────────────────────────────────────────── */
    public function render_admin_page() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( 'You do not have permission to access this page.' );
        }
        $nonce = wp_create_nonce( 'abm_nonce' );
        $ajax_url = admin_url( 'admin-ajax.php' );
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<link href="https://fonts.googleapis.com/css2?family=Amiri:wght@400;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
<style>
:root{--green:#2a486c;--green-dark:#1a2f47;--green-light:#e8eef5;--gold:#d1ad3c;--gold-light:#fdf6e3;--gold-border:#e8d48a;--danger:#C0392B;--warn:#D97706;--border:#E2DDD5;--surface:#fff;--text:#1A1A1A;--muted:#7A7266;}
#abm-wrap{font-family:'DM Sans',sans-serif;max-width:960px;padding:1.5rem 0 4rem;}
.hdr{display:flex;align-items:center;gap:14px;padding-bottom:1.25rem;border-bottom:1px solid var(--border);margin-bottom:1.75rem;}
.hdr-icon{width:46px;height:46px;border-radius:12px;background:var(--green);display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0;}
.hdr h1{font-family:'Amiri',serif;font-size:24px;font-weight:700;margin:0;padding:0;color:var(--text);}
.hdr p{font-size:12px;color:var(--muted);margin:2px 0 0;}
.abm-badge{margin-left:auto;background:var(--gold-light);color:#7a5a00;font-size:10px;font-weight:600;padding:3px 10px;border-radius:20px;letter-spacing:.5px;text-transform:uppercase;border:1px solid var(--gold-border);}
.toolbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;}
.toolbar span{font-size:13px;font-weight:600;color:var(--text);}
.abmbtn{display:inline-flex;align-items:center;gap:5px;padding:8px 15px;border-radius:8px;font-size:13px;font-weight:500;cursor:pointer;border:none;font-family:inherit;transition:all .15s;text-decoration:none;}
.abmbtn-p{background:var(--green);color:#fff!important;}.abmbtn-p:hover{background:var(--green-dark);}
.abmbtn-s{background:var(--surface);color:var(--text)!important;border:1px solid var(--border);}.abmbtn-s:hover{background:#F0EDE6;}
.abmbtn-d{background:#FDF0EF;color:var(--danger)!important;border:1px solid #F5C6C2;}.abmbtn-d:hover{background:#FBDBD9;}
.abmbtn-w{background:#FFF8EC;color:#92400E!important;border:1px solid #FCD34D;}
.abmbtn-sm{padding:5px 10px;font-size:12px;}.abmbtn:disabled{opacity:.5;cursor:not-allowed;}
.abm-events{display:flex;flex-direction:column;gap:10px;}
.abm-card{background:var(--surface);border:1px solid var(--border);border-radius:12px;display:flex;overflow:hidden;transition:box-shadow .15s;}
.abm-card:hover{box-shadow:0 2px 12px rgba(0,0,0,.07);}
.card-thumb{width:80px;flex-shrink:0;background:#F0EDE6;overflow:hidden;display:flex;align-items:center;justify-content:center;font-size:26px;min-height:80px;}
.card-thumb img{width:100%;height:100%;object-fit:cover;}
.card-body{flex:1;padding:.85rem 1rem;display:flex;align-items:flex-start;gap:12px;min-width:0;}
.date-bdg{flex-shrink:0;width:46px;text-align:center;background:var(--green-light);border-radius:8px;padding:5px 3px;}
.date-bdg .dd{font-size:19px;font-weight:700;color:var(--green);line-height:1;}
.date-bdg .dm{font-size:9px;font-weight:600;color:var(--gold);text-transform:uppercase;letter-spacing:.5px;margin-top:2px;}
.card-info{flex:1;min-width:0;}
.card-ttl{font-size:14px;font-weight:600;margin-bottom:3px;display:flex;align-items:center;flex-wrap:wrap;gap:6px;color:var(--text);}
.card-meta{display:flex;flex-wrap:wrap;gap:10px;font-size:11px;color:var(--muted);}
.card-dsc{font-size:12px;color:#5A5550;margin-top:4px;line-height:1.5;}
.card-acts{display:flex;gap:6px;flex-shrink:0;align-items:flex-start;padding-top:2px;}
.spill{font-size:9px;font-weight:600;padding:2px 7px;border-radius:20px;text-transform:uppercase;letter-spacing:.3px;}
.spill-publish{background:var(--gold-light);color:#7a5a00;border:1px solid var(--gold-border);}
.spill-draft{background:#FEF3E2;color:#7A5000;}
.spill-private{background:#EEF2FF;color:#3730A3;}
.abm-empty{text-align:center;padding:3.5rem 1rem;background:var(--surface);border-radius:12px;border:1px solid var(--border);}
.ov{position:fixed;inset:0;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;z-index:99999;padding:1rem;}
.abm-modal{background:var(--surface);border-radius:16px;width:100%;max-width:580px;max-height:92vh;overflow-y:auto;animation:abmUp .2s ease;}
.abm-modal-lg{max-width:760px;}
@keyframes abmUp{from{transform:translateY(14px);opacity:0}to{transform:translateY(0);opacity:1}}
.mhdr{padding:1rem 1.4rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;background:var(--surface);z-index:1;}
.mhdr h2{font-family:'Amiri',serif;font-size:20px;font-weight:700;margin:0;padding:0;color:var(--text);}
.mc{background:none;border:none;font-size:22px;color:var(--muted);cursor:pointer;padding:2px 6px;line-height:1;}
.mbody{padding:1.25rem 1.4rem;}
.mfoot{padding:.9rem 1.4rem;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:8px;position:sticky;bottom:0;background:var(--surface);}
.abm-tabs{display:flex;border-bottom:1px solid var(--border);margin-bottom:1.2rem;}
.abm-tab{padding:8px 16px;font-size:13px;font-weight:500;color:var(--muted);cursor:pointer;border:none;border-bottom:2px solid transparent;margin-bottom:-1px;background:none;font-family:inherit;transition:all .15s;}
.abm-tab.on{color:var(--gold);border-bottom-color:var(--gold);}
.fg{margin-bottom:.95rem;}
.fl{display:block;font-size:11px;font-weight:600;color:#3A3530;text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;}
.fi,.ft,.fs{width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit;color:var(--text);background:var(--surface);transition:border-color .15s;box-shadow:none;}
.fi:focus,.ft:focus,.fs:focus{outline:none;border-color:var(--green);box-shadow:0 0 0 3px rgba(27,122,94,.1);}
.ft{resize:vertical;min-height:80px;line-height:1.5;}
.frow{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.ai-box{background:linear-gradient(135deg,#e8eef5,#fdf6e3);border:1px solid #b8c8d8;border-radius:9px;padding:.85rem 1rem;margin-bottom:1.1rem;}
.ai-lbl{font-size:11px;font-weight:600;color:var(--green);margin-bottom:7px;}
.ai-row{display:flex;gap:8px;}
.ai-row input{flex:1;padding:7px 10px;border:1px solid #B8DDD2;border-radius:6px;font-size:13px;font-family:inherit;background:#fff;}
.dz{border:2px dashed var(--border);border-radius:9px;padding:2rem 1rem;text-align:center;cursor:pointer;transition:all .15s;background:#FAFAF8;}
.dz:hover,.dz.drag{border-color:var(--green);background:var(--green-light);}
.dz .di{font-size:32px;margin-bottom:6px;}
.iprev{position:relative;border-radius:9px;overflow:hidden;border:1px solid var(--border);}
.iprev img{width:100%;max-height:210px;object-fit:cover;display:block;}
.iacts{position:absolute;top:8px;right:8px;display:flex;gap:6px;}
.ibtn{background:rgba(0,0,0,.6);color:#fff;border:none;border-radius:6px;padding:5px 10px;font-size:12px;cursor:pointer;font-family:inherit;}
.prog{height:4px;background:var(--border);border-radius:4px;overflow:hidden;margin-top:6px;}
.prog-fill{height:100%;background:var(--green);border-radius:4px;transition:width .3s;}
.ordiv{display:flex;align-items:center;gap:10px;color:#B4AFA8;font-size:12px;margin:12px 0;}
.ordiv::before,.ordiv::after{content:'';flex:1;height:1px;background:var(--border);}
.ms-row{display:flex;gap:8px;margin-bottom:1rem;flex-wrap:wrap;}
.ms-row input{flex:1;min-width:140px;padding:8px 10px;border:1px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit;}
.mgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(100px,1fr));gap:8px;}
.mi{border-radius:8px;overflow:hidden;cursor:pointer;border:2px solid transparent;aspect-ratio:1;background:#F0EDE6;transition:all .15s;}
.mi:hover{border-color:var(--green);}.mi.sel{border-color:var(--green);box-shadow:0 0 0 3px var(--green-light);}
.mi img{width:100%;height:100%;object-fit:cover;display:block;}
.cbox{background:#FDF0EF;border:1px solid #F5C6C2;border-radius:9px;padding:.9rem;margin-bottom:1rem;font-size:13px;color:#7A1A10;line-height:1.6;}
.abm-toast{position:fixed;bottom:1.5rem;right:1.5rem;z-index:999999;color:#fff;padding:10px 16px;border-radius:8px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:8px;max-width:320px;animation:abmUp .2s ease;}
.t-ok{background:var(--green);}.t-err{background:var(--danger);}.t-warn{background:var(--warn);}
.spin{display:inline-block;width:13px;height:13px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:rot .7s linear infinite;}
.spin-dk{border-color:#D9D4C8;border-top-color:var(--green);}
@keyframes rot{to{transform:rotate(360deg)}}
.abm-hidden{display:none!important;}
</style>
</head>
<body style="background:#F7F5F0">
<div id="abm-wrap" class="wrap">

  <div class="hdr">
    <div class="hdr-icon">🕌</div>
    <div><h1>ABM Event Manager</h1><p>Abu Bakr Masjid & Islamic Center</p></div>
    <span class="abm-badge">Internal Admin</span>
  </div>

  <div class="toolbar">
    <span id="abm-cnt">Loading…</span>
    <button class="abmbtn abmbtn-p" onclick="abmOpenCreate()">+ New Event</button>
  </div>

  <div id="abm-list" class="abm-events"></div>
  <div id="abm-empty" class="abm-empty abm-hidden">
    <div style="font-size:36px;margin-bottom:10px">📅</div>
    <p style="font-size:15px;font-weight:600;margin-bottom:5px;color:var(--text)">No events yet</p>
    <p style="font-size:13px;color:var(--muted)">Click "+ New Event" to get started.</p>
  </div>
</div>

<!-- Form Modal -->
<div id="abm-fmod" class="ov abm-hidden" onclick="abmOvClick(event,'abm-fmod')">
  <div class="abm-modal">
    <div class="mhdr"><h2 id="abm-ftitle">New Event</h2><button class="mc" onclick="abmClose('abm-fmod')">×</button></div>
    <div class="mbody">
      <div class="abm-tabs">
        <button class="abm-tab on" onclick="abmTab('details')" id="abm-td">Details</button>
        <button class="abm-tab" onclick="abmTab('image')" id="abm-ti">Image <span id="abm-ic"></span></button>
      </div>
      <div id="abm-tdetails">
        <div class="ai-box">
          <div class="ai-lbl">✨ AI Assist — describe the event in plain English</div>
          <div class="ai-row">
            <input id="abm-ai" type="text" placeholder='e.g. "Sisters Quran circle every Sunday 10am in the main hall"' onkeydown="if(event.key==='Enter')abmAI()"/>
            <button class="abmbtn abmbtn-p abmbtn-sm" onclick="abmAI()" id="abm-aibtn">Fill ↗</button>
          </div>
        </div>
        <div class="fg"><label class="fl">Event title *</label><input class="fi" id="abm-title" placeholder="e.g. Friday Jummah Prayer"/></div>
        <div class="frow">
          <div class="fg"><label class="fl">Start date</label><input type="date" class="fi" id="abm-date"/></div>
          <div class="fg"><label class="fl">Start time</label><input type="time" class="fi" id="abm-time"/></div>
        </div>
        <div class="frow">
          <div class="fg"><label class="fl">End date</label><input type="date" class="fi" id="abm-edate"/></div>
          <div class="fg"><label class="fl">End time</label><input type="time" class="fi" id="abm-etime"/></div>
        </div>
        <div class="fg"><label class="fl">Location</label><input class="fi" id="abm-loc" placeholder="e.g. Main Prayer Hall, 330 Oxford Road"/></div>
        <div class="frow">
          <div class="fg"><label class="fl">Category</label>
            <select class="fs" id="abm-cat">
              <option value="">Select…</option>
              <option>Prayer</option><option>Education</option><option>Community</option>
              <option>Youth</option><option>Sisters</option><option>Fundraising</option>
              <option>Lecture</option><option>Other</option>
            </select>
          </div>
          <div class="fg"><label class="fl">Status</label>
            <select class="fs" id="abm-stat">
              <option value="publish">Published</option>
              <option value="draft">Draft</option>
              <option value="private">Private</option>
            </select>
          </div>
        </div>
        <div class="fg"><label class="fl">Description</label><textarea class="ft" id="abm-desc" placeholder="Brief description of the event…"></textarea></div>
        <div class="fg" style="background:#f5f5f0;border-radius:9px;padding:.9rem 1rem;border:1px solid var(--border);">
          <label class="fl" style="margin-bottom:8px;">Recurrence</label>
          <div class="frow" style="margin-bottom:.75rem;">
            <div class="fg" style="margin-bottom:0">
              <label class="fl">Pattern</label>
              <select class="fs" id="abm-recurrence" onchange="abmToggleRecurrence()">
                <option value="none">Does not repeat</option>
                <option value="weekly">Weekly</option>
                <option value="biweekly">Bi-weekly (every 2 weeks)</option>
                <option value="monthly">Monthly</option>
                <option value="custom">Custom (every N days)</option>
              </select>
            </div>
            <div class="fg" id="abm-rec-every-wrap" style="margin-bottom:0;display:none">
              <label class="fl">Every (days)</label>
              <input type="number" class="fi" id="abm-rec-every" min="1" max="365" value="7" placeholder="e.g. 14"/>
            </div>
          </div>
          <div id="abm-rec-end-wrap" style="display:none">
            <div class="fg" style="margin-bottom:0">
              <label class="fl">Repeat until</label>
              <input type="date" class="fi" id="abm-rec-end"/>
            </div>
            <p style="font-size:11px;color:var(--muted);margin-top:5px;">Leave blank to generate 12 occurrences.</p>
          </div>
        </div>
      </div>
      <div id="abm-timage" class="abm-hidden">
        <div id="abm-dz" class="dz" onclick="document.getElementById('abm-file').click()" ondragover="abmDO(event)" ondragleave="abmDL()" ondrop="abmDrop(event)">
          <div class="di">📷</div>
          <p style="font-weight:500;font-size:14px;color:#3A3530">Drop an image or click to browse</p>
          <p style="font-size:12px;color:var(--muted);margin-top:4px">JPG, PNG, WebP</p>
        </div>
        <div id="abm-prev" class="abm-hidden">
          <div class="iprev">
            <img id="abm-pimg" src="" alt=""/>
            <div class="iacts">
              <button class="ibtn" onclick="abmClearImg()">Remove</button>
              <button class="ibtn" onclick="document.getElementById('abm-file').click()">Replace</button>
            </div>
          </div>
        </div>
        <input type="file" id="abm-file" accept="image/*" class="abm-hidden" onchange="abmFileSelected(event)"/>
        <div id="abm-prog" class="abm-hidden" style="margin-top:8px">
          <p style="font-size:11px;color:var(--muted)">Uploading…</p>
          <div class="prog"><div class="prog-fill" id="abm-pf" style="width:0%"></div></div>
        </div>
        <div class="ordiv">or</div>
        <button class="abmbtn abmbtn-s" style="width:100%;justify-content:center" onclick="abmOpenMedia()">🖼 Browse Media Library</button>
        <div id="abm-ilink" class="abm-hidden" style="margin-top:10px;padding:8px 10px;background:#F0FAF6;border-radius:8px;font-size:11px;color:var(--green-dark);word-break:break-all"></div>
      </div>
    </div>
    <div class="mfoot">
      <button class="abmbtn abmbtn-s" onclick="abmClose('abm-fmod')">Cancel</button>
      <button class="abmbtn abmbtn-p" onclick="abmSave()" id="abm-sbtn">Create Event</button>
    </div>
  </div>
</div>

<!-- Media Modal -->
<div id="abm-mmod" class="ov abm-hidden" onclick="abmOvClick(event,'abm-mmod')">
  <div class="abm-modal abm-modal-lg">
    <div class="mhdr"><h2>Media Library</h2><button class="mc" onclick="abmClose('abm-mmod')">×</button></div>
    <div class="mbody">
      <div class="ms-row">
        <input id="abm-ms" type="text" placeholder="Search images…" onkeydown="if(event.key==='Enter')abmLoadMedia()"/>
        <button class="abmbtn abmbtn-s" onclick="abmLoadMedia()">Search</button>
        <button class="abmbtn abmbtn-p abmbtn-sm" onclick="document.getElementById('abm-mu').click()">Upload new</button>
        <input type="file" id="abm-mu" accept="image/*" class="abm-hidden" onchange="abmUpFromMedia(event)"/>
      </div>
      <div id="abm-ml" class="abm-hidden" style="text-align:center;padding:2.5rem;color:var(--muted);font-size:13px"><span class="spin spin-dk"></span> Loading…</div>
      <div id="abm-me" class="abm-hidden" style="text-align:center;padding:2.5rem;color:var(--muted);font-size:13px">No images found. Upload one above.</div>
      <div id="abm-mg" class="mgrid"></div>
      <div id="abm-msel" class="abm-hidden" style="margin-top:12px;padding:8px 12px;background:var(--green-light);border-radius:8px;font-size:12px;color:var(--green-dark)"></div>
    </div>
    <div class="mfoot">
      <button class="abmbtn abmbtn-s" onclick="abmClose('abm-mmod')">Back</button>
      <button class="abmbtn abmbtn-p" onclick="abmConfirmMedia()" id="abm-umBtn" disabled>Use selected image</button>
    </div>
  </div>
</div>

<!-- Delete Modal -->
<div id="abm-dmod" class="ov abm-hidden" onclick="abmOvClick(event,'abm-dmod')">
  <div class="abm-modal" style="max-width:420px">
    <div class="mhdr"><h2>Delete Event</h2><button class="mc" onclick="abmClose('abm-dmod')">×</button></div>
    <div class="mbody">
      <div class="cbox">You are about to permanently delete <strong id="abm-dtitle"></strong>. This cannot be undone.</div>
    </div>
    <div class="mfoot">
      <button class="abmbtn abmbtn-s" onclick="abmClose('abm-dmod')">Cancel</button>
      <button class="abmbtn abmbtn-d" onclick="abmConfirmDel()">Yes, delete</button>
    </div>
  </div>
</div>

<div id="abm-toast" class="abm-toast t-ok abm-hidden"></div>

<script>
const ABM_AJAX = '<?php echo esc_js( $ajax_url ); ?>';
const ABM_NONCE = '<?php echo esc_js( $nonce ); ?>';
let abmEvents = [], abmEditId = null, abmDelId = null, abmSelMedia = null, abmTT = null;

// ── Init ──────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', abmLoadEvents);

async function abmPost(action, data) {
  const fd = new FormData();
  fd.append('action', action);
  fd.append('nonce', ABM_NONCE);
  for (const [k,v] of Object.entries(data || {})) fd.append(k, v ?? '');
  const r = await fetch(ABM_AJAX, { method: 'POST', body: fd });
  return r.json();
}

async function abmGet(action, params) {
  const qs = new URLSearchParams({ action, nonce: ABM_NONCE, ...params }).toString();
  const r = await fetch(ABM_AJAX + '?' + qs);
  return r.json();
}

// ── Load & render events ──────────────────────────────────────────────────
async function abmLoadEvents() {
  const res = await abmPost('abm_get_events');
  if (res.success) { abmEvents = res.data; abmRender(); }
}

function abmEsc(s) { const d = document.createElement('div'); d.textContent = String(s||''); return d.innerHTML; }

function abmRender() {
  const l = document.getElementById('abm-list');
  const e = document.getElementById('abm-empty');
  document.getElementById('abm-cnt').textContent = abmEvents.length + ' event' + (abmEvents.length !== 1 ? 's' : '');
  if (!abmEvents.length) { l.innerHTML = ''; e.classList.remove('abm-hidden'); return; }
  e.classList.add('abm-hidden');
  l.innerHTML = abmEvents.map(ev => {
    const dt = ev.event_date ? new Date(ev.event_date + 'T12:00:00') : null;
    const day = dt ? dt.getDate() : '—';
    const mon = dt ? dt.toLocaleString('en-GB', { month: 'short' }) : '—';
    const thumb = ev.image_url ? `<img src="${abmEsc(ev.image_url)}" alt=""/>` : '📸';
    const t = ev.event_time ? ev.event_time.slice(0,5) : '';
    const et = ev.end_time ? ev.end_time.slice(0,5) : '';
    return `<div class="abm-card">
      <div class="card-thumb">${thumb}</div>
      <div class="card-body">
        <div class="date-bdg"><div class="dd">${day}</div><div class="dm">${mon}</div></div>
        <div class="card-info">
          <div class="card-ttl">${abmEsc(ev.title)}<span class="spill spill-${abmEsc(ev.status)}">${abmEsc(ev.status)}</span>${ev.recurrence && ev.recurrence !== 'none' ? '<span class="spill" style="background:#fdf6e3;color:#7a5a00;border:1px solid #e8d48a">&#x21bb; '+abmEsc(ev.recurrence)+'</span>' : ''}${ev.parent_event_id ? '<span class="spill" style="background:#f0f0f0;color:#666">series</span>' : ''}</div>
          <div class="card-meta">
            ${t ? `<span>🕐 ${abmEsc(t)}${et ? ' – ' + abmEsc(et) : ''}</span>` : ''}
            ${ev.location ? `<span>📍 ${abmEsc(ev.location)}</span>` : ''}
            ${ev.category ? `<span>🏷 ${abmEsc(ev.category)}</span>` : ''}
          </div>
          ${ev.description ? `<p class="card-dsc">${abmEsc(ev.description.slice(0,100))}${ev.description.length > 100 ? '…' : ''}</p>` : ''}
        </div>
        <div class="card-acts">
          <button class="abmbtn abmbtn-s abmbtn-sm" onclick="abmOpenEdit(${ev.id})">Edit</button>
          <button class="abmbtn abmbtn-d abmbtn-sm" onclick="abmOpenDel(${ev.id})">Delete</button>
          ${ev.recurrence && ev.recurrence !== 'none' ? '<button class="abmbtn abmbtn-sm" style="background:#fff8ec;color:#92400E;border:1px solid #fcd34d;font-size:11px" onclick="abmDelSeries('+ev.id+')">Del series</button>' : ''}
        </div>
      </div>
    </div>`;
  }).join('');
}

// ── Form helpers ──────────────────────────────────────────────────────────
function abmGetForm() {
  return {
    title: document.getElementById('abm-title').value.trim(),
    event_date: document.getElementById('abm-date').value,
    event_time: document.getElementById('abm-time').value,
    end_date: document.getElementById('abm-edate').value,
    end_time: document.getElementById('abm-etime').value,
    location: document.getElementById('abm-loc').value.trim(),
    category: document.getElementById('abm-cat').value,
    status: document.getElementById('abm-stat').value,
    description: document.getElementById('abm-desc').value.trim(),
    image_url: window._abmImgUrl || '',
    image_id: window._abmImgId || '',
    recurrence: document.getElementById('abm-recurrence')?.value || 'none',
    recurrence_every: document.getElementById('abm-rec-every')?.value || '',
    recurrence_end: document.getElementById('abm-rec-end')?.value || ''
  };
}

function abmFillForm(ev) {
  document.getElementById('abm-title').value = ev.title || '';
  document.getElementById('abm-date').value = ev.event_date ? ev.event_date.split(' ')[0] : '';
  document.getElementById('abm-time').value = ev.event_time ? ev.event_time.slice(0,5) : '';
  document.getElementById('abm-edate').value = ev.end_date ? ev.end_date.split(' ')[0] : '';
  document.getElementById('abm-etime').value = ev.end_time ? ev.end_time.slice(0,5) : '';
  document.getElementById('abm-loc').value = ev.location || '';
  document.getElementById('abm-cat').value = ev.category || '';
  document.getElementById('abm-stat').value = ev.status || 'publish';
  document.getElementById('abm-desc').value = ev.description || '';
  if (ev.image_url) { abmSetImg(ev.image_url, ev.image_id); } else { abmClearImg(); }
}

function abmClearForm() {
  ['abm-title','abm-date','abm-time','abm-edate','abm-etime','abm-loc','abm-desc'].forEach(id => document.getElementById(id).value = '');
  document.getElementById('abm-cat').value = '';
  document.getElementById('abm-stat').value = 'publish';
  document.getElementById('abm-ai').value = '';
  if(ev.recurrence){document.getElementById('abm-recurrence').value=ev.recurrence;abmToggleRecurrence();}
  if(ev.recurrence_every){document.getElementById('abm-rec-every').value=ev.recurrence_every;}
  if(ev.recurrence_end){document.getElementById('abm-rec-end').value=ev.recurrence_end.split(' ')[0];}
  abmClearImg();
}

function abmSetImg(url, id) {
  window._abmImgUrl = url; window._abmImgId = id || '';
  document.getElementById('abm-pimg').src = url;
  document.getElementById('abm-dz').classList.add('abm-hidden');
  document.getElementById('abm-prev').classList.remove('abm-hidden');
  document.getElementById('abm-ic').textContent = '✓';
  const il = document.getElementById('abm-ilink');
  il.textContent = '✓ ' + url; il.classList.remove('abm-hidden');
}

function abmClearImg() {
  window._abmImgUrl = ''; window._abmImgId = '';
  document.getElementById('abm-pimg').src = '';
  document.getElementById('abm-dz').classList.remove('abm-hidden');
  document.getElementById('abm-prev').classList.add('abm-hidden');
  document.getElementById('abm-ilink').classList.add('abm-hidden');
  document.getElementById('abm-ic').textContent = '';
}

// ── Modals ────────────────────────────────────────────────────────────────
function abmOpenCreate() { abmEditId = null; abmClearForm(); abmTab('details'); document.getElementById('abm-ftitle').textContent = 'New Event'; document.getElementById('abm-sbtn').textContent = 'Create Event'; document.getElementById('abm-fmod').classList.remove('abm-hidden'); }
function abmOpenEdit(id) { abmEditId = id; const ev = abmEvents.find(e => e.id == id); if (!ev) return; abmFillForm(ev); abmTab('details'); document.getElementById('abm-ftitle').textContent = 'Edit Event'; document.getElementById('abm-sbtn').textContent = 'Save Changes'; document.getElementById('abm-fmod').classList.remove('abm-hidden'); }
function abmOpenDel(id) { abmDelId = id; const ev = abmEvents.find(e => e.id == id); document.getElementById('abm-dtitle').textContent = '"' + (ev?.title || 'this event') + '"'; document.getElementById('abm-dmod').classList.remove('abm-hidden'); }
function abmClose(id) { document.getElementById(id).classList.add('abm-hidden'); }
function abmOvClick(e, id) { if (e.target === e.currentTarget) abmClose(id); }
function abmTab(t) {
  ['details','image'].forEach(n => {
    document.getElementById('abm-t' + n).classList.toggle('abm-hidden', n !== t);
    document.getElementById('abm-t' + n.charAt(0)).classList.toggle('on', n === t);
  });
}

// ── AI Assist ─────────────────────────────────────────────────────────────
async function abmAI() {
  const p = document.getElementById('abm-ai').value.trim();
  if (!p) return;
  const btn = document.getElementById('abm-aibtn');
  btn.innerHTML = '<span class="spin"></span>'; btn.disabled = true;
  try {
    const r = await fetch('https://api.anthropic.com/v1/messages', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        model: 'claude-sonnet-4-20250514', max_tokens: 800,
        system: 'Assistant for Abu Bakr Masjid Reading UK. Extract event info and return ONLY raw JSON: {title,date(YYYY-MM-DD),time(HH:MM),endDate,endTime,location,description(2-3 sentences),category(Prayer|Education|Community|Youth|Sisters|Fundraising|Lecture|Other)}. No markdown.',
        messages: [{ role: 'user', content: p }]
      })
    });
    const d = await r.json();
    const text = (d.content || []).filter(b => b.type === 'text').map(b => b.text).join('');
    const x = JSON.parse(text.replace(/```json|```/g, '').trim());
    if (x.title) document.getElementById('abm-title').value = x.title;
    if (x.date) document.getElementById('abm-date').value = x.date;
    if (x.time) document.getElementById('abm-time').value = x.time;
    if (x.endDate) document.getElementById('abm-edate').value = x.endDate;
    if (x.endTime) document.getElementById('abm-etime').value = x.endTime;
    if (x.location) document.getElementById('abm-loc').value = x.location;
    if (x.description) document.getElementById('abm-desc').value = x.description;
    if (x.category) document.getElementById('abm-cat').value = x.category;
    abmToast('AI filled in event details ✓');
  } catch (e) { abmToast('AI assist unavailable — fill in manually', 'warn'); }
  btn.innerHTML = 'Fill ↗'; btn.disabled = false;
}

// ── Image upload (via WP AJAX — no separate credentials needed!) ──────────
function abmDO(e) { e.preventDefault(); document.getElementById('abm-dz').classList.add('drag'); }
function abmDL() { document.getElementById('abm-dz').classList.remove('drag'); }
function abmDrop(e) { e.preventDefault(); abmDL(); const f = e.dataTransfer.files[0]; if (f) abmUpload(f); }
function abmFileSelected(e) { const f = e.target.files[0]; if (f) abmUpload(f); e.target.value = ''; }

async function abmUpload(file) {
  if (!file.type.startsWith('image/')) { abmToast('Please select an image file', 'err'); return; }
  const prog = document.getElementById('abm-prog'), pf = document.getElementById('abm-pf');
  prog.classList.remove('abm-hidden'); pf.style.width = '20%';
  try {
    const fd = new FormData();
    fd.append('action', 'abm_upload_media');
    fd.append('nonce', ABM_NONCE);
    fd.append('file', file);
    pf.style.width = '60%';
    const r = await fetch(ABM_AJAX, { method: 'POST', body: fd });
    const d = await r.json();
    pf.style.width = '100%';
    if (!d.success) throw new Error(d.data || 'Upload failed');
    abmSetImg(d.data.url, d.data.id);
    abmToast('Image uploaded ✓');
  } catch (e) { abmToast('Upload failed: ' + e.message, 'err'); }
  setTimeout(() => { prog.classList.add('abm-hidden'); pf.style.width = '0%'; }, 700);
}

// ── Media library ─────────────────────────────────────────────────────────
function abmOpenMedia() {
  abmSelMedia = null;
  document.getElementById('abm-mg').innerHTML = '';
  document.getElementById('abm-msel').classList.add('abm-hidden');
  document.getElementById('abm-umBtn').disabled = true;
  document.getElementById('abm-ms').value = '';
  document.getElementById('abm-mmod').classList.remove('abm-hidden');
  abmLoadMedia();
}

async function abmLoadMedia() {
  const s = document.getElementById('abm-ms').value.trim();
  const ml = document.getElementById('abm-ml'), me = document.getElementById('abm-me'), mg = document.getElementById('abm-mg');
  ml.classList.remove('abm-hidden'); me.classList.add('abm-hidden'); mg.innerHTML = '';
  try {
    const res = await abmGet('abm_get_media', s ? { search: s } : {});
    ml.classList.add('abm-hidden');
    if (!res.success || !res.data.length) { me.classList.remove('abm-hidden'); return; }
    mg.innerHTML = res.data.map(i => `<div class="mi" data-id="${i.id}" data-url="${abmEsc(i.url)}" data-title="${abmEsc(i.title)}" onclick="abmSelMi(this)"><img src="${abmEsc(i.thumb || i.url)}" alt=""/></div>`).join('');
  } catch (e) { ml.classList.add('abm-hidden'); me.classList.remove('abm-hidden'); }
}

function abmSelMi(el) {
  document.querySelectorAll('.mi').forEach(m => m.classList.remove('sel'));
  el.classList.add('sel');
  abmSelMedia = { id: el.dataset.id, url: el.dataset.url, title: el.dataset.title };
  const ms = document.getElementById('abm-msel');
  ms.textContent = 'Selected: ' + abmSelMedia.title; ms.classList.remove('abm-hidden');
  document.getElementById('abm-umBtn').disabled = false;
}

function abmConfirmMedia() {
  if (!abmSelMedia) return;
  abmSetImg(abmSelMedia.url, abmSelMedia.id);
  abmToast('Image selected ✓');
  abmClose('abm-mmod');
}

async function abmUpFromMedia(e) { const f = e.target.files[0]; if (f) { await abmUpload(f); await abmLoadMedia(); } e.target.value = ''; }

// ── Save event ────────────────────────────────────────────────────────────
async function abmSave() {
  const f = abmGetForm();
  if (!f.title) { abmToast('Title is required', 'err'); return; }
  const btn = document.getElementById('abm-sbtn');
  btn.innerHTML = '<span class="spin"></span> Saving…'; btn.disabled = true;
  try {
    const data = { ...f };
    if (abmEditId) data.id = abmEditId;
    const res = await abmPost('abm_save_event', data);
    if (!res.success) throw new Error(res.data || 'Save failed');
    abmToast(abmEditId ? 'Event updated ✓' : 'Event created ✓');
    abmClose('abm-fmod');
    await abmLoadEvents();
  } catch (e) { abmToast('Save failed: ' + e.message, 'err'); }
  btn.innerHTML = abmEditId ? 'Save Changes' : 'Create Event'; btn.disabled = false;
}

// ── Delete event ──────────────────────────────────────────────────────────
async function abmDelSeries(id) {
  if (!confirm('Delete ALL events in this series? This cannot be undone.')) return;
  try {
    const res = await abmPost('abm_delete_series', { parent_id: id });
    if (!res.success) throw new Error(res.data);
    abmToast('Series deleted (' + (res.data?.deleted_count || '?') + ' events) ✓');
    await abmLoadEvents();
  } catch(e) { abmToast('Delete series failed: ' + e.message, 'err'); }
}

async function abmConfirmDel() {
  try {
    const res = await abmPost('abm_delete_event', { id: abmDelId });
    if (!res.success) throw new Error(res.data);
    abmToast('Event deleted ✓');
    abmClose('abm-dmod');
    await abmLoadEvents();
  } catch (e) { abmToast('Delete failed: ' + e.message, 'err'); }
}

// ── Toast ─────────────────────────────────────────────────────────────────
function abmToggleRecurrence() {
  const val = document.getElementById('abm-recurrence').value;
  const everyWrap = document.getElementById('abm-rec-every-wrap');
  const endWrap   = document.getElementById('abm-rec-end-wrap');
  everyWrap.style.display = val === 'custom' ? '' : 'none';
  endWrap.style.display   = val !== 'none' ? '' : 'none';
}

function abmToast(msg, type = 'ok') {
  const el = document.getElementById('abm-toast');
  el.textContent = (type === 'ok' ? '✓' : type === 'warn' ? '⚠' : '✕') + ' ' + msg;
  el.className = 'abm-toast t-' + (type === 'ok' ? 'ok' : type === 'err' ? 'err' : 'warn');
  el.classList.remove('abm-hidden');
  clearTimeout(abmTT);
  abmTT = setTimeout(() => el.classList.add('abm-hidden'), 4000);
}
</script>
</body>
</html>
        <?php
    }

    /* ── Front-end styles ──────────────────────────────────────────────── */
    public function shortcode_styles() {
        echo '<style>
.abm-filter-bar{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:1.25rem;}
.abm-filter-btn{padding:6px 14px;border-radius:20px;border:1px solid #E2DDD5;background:#fff;font-size:12px;font-weight:500;cursor:pointer;color:#5A5550;transition:all .15s;font-family:inherit;}
.abm-filter-btn:hover,.abm-filter-btn.active{background:#2a486c;color:#fff;border-color:#2a486c;}
.abm-events-list{display:flex;flex-direction:column;gap:16px;margin:1.5rem 0;}
.abm-event-card{display:flex;border-radius:12px;overflow:hidden;border:1px solid #E2DDD5;background:#fff;transition:box-shadow .15s;align-items:stretch;}
.abm-event-card:hover{box-shadow:0 4px 18px rgba(42,72,108,.12);}
.abm-event-img{width:140px;flex-shrink:0;background:#F0EDE6;display:flex;align-items:stretch;justify-content:center;overflow:hidden;}
.abm-event-img img{width:140px;height:auto;object-fit:contain;display:block;align-self:stretch;}
.abm-event-body{display:flex;gap:14px;padding:1rem 1.25rem;flex:1;min-width:0;}
.abm-event-date-badge{flex-shrink:0;width:52px;text-align:center;background:#2a486c;border-radius:8px;padding:6px 4px;align-self:flex-start;}
.abm-event-date-badge .abm-day{font-size:22px;font-weight:700;color:#fff;line-height:1;}
.abm-event-date-badge .abm-mon{font-size:10px;font-weight:600;color:#d1ad3c;text-transform:uppercase;letter-spacing:.5px;margin-top:2px;}
.abm-event-content{flex:1;min-width:0;}
.abm-event-cat{display:inline-block;font-size:10px;font-weight:600;padding:2px 8px;border-radius:20px;background:#fdf6e3;color:#7a5a00;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px;border:1px solid #e8d48a;}
.abm-event-title{font-size:16px;font-weight:600;color:#1A1A1A;margin:0 0 5px;padding:0;}
.abm-event-meta{display:flex;flex-wrap:wrap;gap:8px;font-size:12px;color:#7A7266;margin-bottom:6px;}
.abm-event-desc{font-size:13px;color:#5A5550;line-height:1.6;margin:0;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;}
.abm-no-events{text-align:center;padding:3rem 1rem;color:#7A7266;font-size:14px;background:#F7F5F0;border-radius:12px;}
@media(max-width:600px){
  .abm-event-card{flex-direction:column;}
  .abm-event-img{width:100%;font-size:48px;}
  .abm-event-img img{width:100%;height:auto;max-height:280px;object-fit:contain;}
  .abm-event-body{flex-direction:row;gap:10px;padding:.85rem 1rem;}
  .abm-event-date-badge{width:44px;flex-shrink:0;}
  .abm-event-date-badge .abm-day{font-size:18px;}
  .abm-event-title{font-size:14px;}
  .abm-event-desc{display:none;}
  .abm-view-link{font-size:11px !important;padding:4px 10px !important;}
}
</style>';
    }

    /* ── Shortcode renderer ─────────────────────────────────────────────── */
    public function render_shortcode( $atts ) {
        global $wpdb;
        $atts  = shortcode_atts( array(
            'category' => '',
            'limit'    => 20,
            'upcoming' => 'yes',
        ), $atts );

        $table     = $wpdb->prefix . 'abm_events';
        $where     = array( "status = 'publish'" );
        if ( 'yes' === $atts['upcoming'] ) {
            $where[] = "(event_date IS NULL OR event_date >= CURDATE())";
        }
        if ( ! empty( $atts['category'] ) ) {
            $where[] = $wpdb->prepare( "category = %s", $atts['category'] );
        }
        $where_sql = implode( ' AND ', $where );
        $limit     = intval( $atts['limit'] );
        $events    = $wpdb->get_results( "SELECT * FROM $table WHERE $where_sql ORDER BY event_date ASC, event_time ASC LIMIT $limit" );
        $cats      = $wpdb->get_col( "SELECT DISTINCT category FROM $table WHERE status='publish' AND category != '' ORDER BY category ASC" );

        ob_start();
        echo '<div class="abm-events-wrap">';
        if ( ! empty( $cats ) ) {
            echo '<div class="abm-filter-bar">';
            echo '<button class="abm-filter-btn active" onclick="abmFilter(this,\'\')">All</button>';
            foreach ( $cats as $cat ) {
                echo '<button class="abm-filter-btn" onclick="abmFilter(this,\'' . esc_attr( $cat ) . '\')">' . esc_html( $cat ) . '</button>';
            }
            echo '</div>';
        }
        if ( empty( $events ) ) {
            echo '<div class="abm-no-events">No upcoming events at the moment. Check back soon!</div>';
        } else {
            echo '<div class="abm-events-list" id="abm-events-list">';
            foreach ( $events as $ev ) {
                $dt       = ! empty( $ev->event_date ) ? date_create( $ev->event_date ) : null;
                $day      = $dt ? date_format( $dt, 'j' ) : '';
                $mon      = $dt ? date_format( $dt, 'M' ) : '';
                $date_fmt = $dt ? date_format( $dt, 'l, j F Y' ) : '';
                $time_str = '';
                if ( ! empty( $ev->event_time ) ) {
                    $t = date_create_from_format( 'H:i:s', $ev->event_time );
                    if ( $t ) {
                        $time_str = date_format( $t, 'g:i A' );
                    }
                    if ( ! empty( $ev->end_time ) ) {
                        $et = date_create_from_format( 'H:i:s', $ev->end_time );
                        if ( $et ) {
                            $time_str .= ' - ' . date_format( $et, 'g:i A' );
                        }
                    }
                }
                echo '<div class="abm-event-card" data-category="' . esc_attr( $ev->category ) . '">';
                echo '<div class="abm-event-img">';
                if ( ! empty( $ev->image_url ) ) {
                    echo '<img src="' . esc_url( $ev->image_url ) . '" alt="' . esc_attr( $ev->title ) . '"/>';
                } else {
                    echo '&#128197;';
                }
                echo '</div>';
                echo '<div class="abm-event-body">';
                if ( $dt ) {
                    echo '<div class="abm-event-date-badge">';
                    echo '<div class="abm-day">' . esc_html( $day ) . '</div>';
                    echo '<div class="abm-mon">' . esc_html( $mon ) . '</div>';
                    echo '</div>';
                }
                echo '<div class="abm-event-content">';
                if ( ! empty( $ev->category ) ) {
                    echo '<span class="abm-event-cat">' . esc_html( $ev->category ) . '</span>';
                }
                echo '<h3 class="abm-event-title">' . esc_html( $ev->title ) . '</h3>';
                echo '<div class="abm-event-meta">';
                if ( $date_fmt ) {
                    echo '<span>' . esc_html( $date_fmt ) . '</span>';
                }
                if ( $time_str ) {
                    echo '<span>' . esc_html( $time_str ) . '</span>';
                }
                if ( ! empty( $ev->location ) ) {
                    echo '<span>' . esc_html( $ev->location ) . '</span>';
                }
                echo '</div>';
                if ( ! empty( $ev->description ) ) {
                    $snippet = mb_strlen( $ev->description ) > 120 ? mb_substr( $ev->description, 0, 120 ) . '...' : $ev->description;
                    echo '<p class="abm-event-desc">' . esc_html( $snippet ) . '</p>';
                }
                $detail_url = $ev->wp_post_id ? get_permalink( intval( $ev->wp_post_id ) ) : home_url( '/abm_event/' . sanitize_title( $ev->title ) . '/' );
                echo '<a href="' . esc_url( $detail_url ) . '" class="abm-view-link" style="display:inline-flex;align-items:center;gap:5px;margin-top:10px;font-size:12px;font-weight:600;color:#2a486c;text-decoration:none;padding:5px 12px;border:1px solid #2a486c;border-radius:20px;background:#e8eef5;transition:all .15s;">View details &rarr;</a>';
                echo '</div></div></div>';
            }
            echo '</div>';
            echo '<script>function abmFilter(b,c){document.querySelectorAll(".abm-filter-btn").forEach(function(x){x.classList.remove("active");});b.classList.add("active");document.querySelectorAll(".abm-event-card").forEach(function(d){d.style.display=(!c||d.dataset.category===c)?"flex":"none";});}</script>';
        }
        echo '</div>';
        return ob_get_clean();
    }

    /* ── Rewrite rules for /event/{slug}/ ──────────────────────────────── */
    public function add_rewrite_rules() {
        add_rewrite_rule( '^event/([^/]+)/?$', 'index.php?abm_event_slug=$matches[1]', 'top' );
    }

    public function add_query_vars( $vars ) {
        $vars[] = 'abm_event_slug';
        return $vars;
    }

    public function handle_event_detail_rewrite() {
        // flush rewrite rules once after activation
        if ( get_option( 'abm_flush_rewrite' ) ) {
            flush_rewrite_rules();
            delete_option( 'abm_flush_rewrite' );
        }
    }

    public function event_detail_template( $template ) {
        global $post;
        if ( ! $post || $post->post_type !== 'abm_event' ) {
            return $template;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'abm_events';
        // Look up by wp_post_id
        $ev = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE wp_post_id = %d LIMIT 1", $post->ID ) );
        // Fallback: match by title slug
        if ( ! $ev ) {
            $all = $wpdb->get_results( "SELECT * FROM $table WHERE status = 'publish'" );
            foreach ( $all as $row ) {
                if ( sanitize_title( $row->title ) === $post->post_name ) {
                    $ev = $row;
                    break;
                }
            }
        }
        if ( ! $ev ) {
            return $template;
        }
        $this->render_event_detail( $ev );
        exit;
    }

    private function render_event_detail( $ev ) {
        $dt       = ! empty( $ev->event_date ) ? date_create( $ev->event_date ) : null;
        $day      = $dt ? date_format( $dt, 'j' ) : '';
        $mon      = $dt ? date_format( $dt, 'M' ) : '';
        $year     = $dt ? date_format( $dt, 'Y' ) : '';
        $date_fmt = $dt ? date_format( $dt, 'l, j F Y' ) : '';
        $time_str = '';
        if ( ! empty( $ev->event_time ) ) {
            $t = date_create_from_format( 'H:i:s', $ev->event_time );
            if ( $t ) { $time_str = date_format( $t, 'g:i A' ); }
            if ( ! empty( $ev->end_time ) ) {
                $et = date_create_from_format( 'H:i:s', $ev->end_time );
                if ( $et ) { $time_str .= ' - ' . date_format( $et, 'g:i A' ); }
            }
        }
        $events_url = home_url( '/events/' );
        $site_name  = get_bloginfo( 'name' );
        $logo_url   = get_site_icon_url( 80 );
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title><?php echo esc_html( $ev->title ); ?> — <?php echo esc_html( $site_name ); ?></title>
<?php wp_head(); ?>
<link href="https://fonts.googleapis.com/css2?family=Amiri:wght@400;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans',sans-serif;background:#F7F5F0;color:#1A1A1A;min-height:100vh;}
.abm-detail-wrap{max-width:780px;margin:0 auto;padding:2rem 1.25rem 4rem;}
.abm-back{display:inline-flex;align-items:center;gap:6px;font-size:13px;color:#2a486c;text-decoration:none;font-weight:500;margin-bottom:1.75rem;padding:7px 14px;border:1px solid #2a486c;border-radius:20px;background:#e8eef5;transition:all .15s;}
.abm-back:hover{background:#2a486c;color:#fff;border-color:#2a486c;}
.abm-detail-hero{border-radius:16px;overflow:hidden;margin-bottom:2rem;background:#e8eef5;width:100%;}
.abm-detail-hero img{width:100%;height:auto;display:block;object-fit:contain;}
.abm-detail-hero-placeholder{font-size:64px;padding:3rem;display:flex;align-items:center;justify-content:center;min-height:220px;}
@media(min-width:600px){.abm-detail-hero img{max-height:600px;width:100%;object-fit:contain;}}
@media(max-width:599px){.abm-detail-hero img{max-height:80vh;width:100%;object-fit:contain;}}
.abm-detail-card{background:#fff;border-radius:16px;border:1px solid #E2DDD5;overflow:hidden;}
.abm-detail-header{padding:1.75rem 2rem;border-bottom:1px solid #E2DDD5;}
.abm-detail-cat{display:inline-block;font-size:11px;font-weight:600;padding:3px 10px;border-radius:20px;background:#fdf6e3;color:#7a5a00;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;border:1px solid #e8d48a;}
.abm-detail-title{font-family:'Amiri',serif;font-size:32px;font-weight:700;color:#2a486c;line-height:1.2;margin-bottom:1.25rem;}
.abm-detail-meta{display:flex;flex-direction:column;gap:10px;}
.abm-detail-meta-row{display:flex;align-items:flex-start;gap:12px;font-size:14px;color:#5A5550;}
.abm-detail-meta-icon{width:36px;height:36px;border-radius:8px;background:#e8eef5;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;}
.abm-detail-meta-label{font-size:11px;font-weight:600;color:#7A7266;text-transform:uppercase;letter-spacing:.4px;margin-bottom:1px;}
.abm-detail-meta-value{font-size:14px;color:#1A1A1A;font-weight:500;}
.abm-detail-body{padding:1.75rem 2rem;}
.abm-detail-body p{font-size:15px;line-height:1.8;color:#3A3530;margin-bottom:1rem;}
.abm-detail-body p:last-child{margin-bottom:0;}
.abm-detail-date-strip{display:flex;align-items:center;gap:1rem;background:#e8eef5;border-radius:10px;padding:.85rem 1rem;margin-bottom:1.5rem;}
.abm-detail-date-badge{text-align:center;background:#2a486c;border-radius:8px;padding:8px 12px;min-width:54px;}
.abm-detail-date-badge .dd{font-size:24px;font-weight:700;color:#fff;line-height:1;}
.abm-detail-date-badge .dm{font-size:10px;font-weight:600;color:#d1ad3c;text-transform:uppercase;letter-spacing:.5px;margin-top:2px;}
.abm-detail-date-badge .dy{font-size:10px;color:#d1ad3c;margin-top:1px;}
.abm-detail-date-info{flex:1;}
.abm-detail-date-full{font-size:15px;font-weight:600;color:#2a486c;}
.abm-detail-date-time{font-size:13px;color:#d1ad3c;margin-top:2px;}
@media(max-width:560px){
  .abm-detail-header{padding:1.25rem;}
  .abm-detail-body{padding:1.25rem;}
  .abm-detail-title{font-size:24px;}
}
</style>
</head>
<body>
<?php wp_body_open(); ?>
<div class="abm-detail-wrap">
  <a href="<?php echo esc_url( $events_url ); ?>" class="abm-back">&#8592; Back to events</a>

  <?php if ( ! empty( $ev->image_url ) ) : ?>
  <div class="abm-detail-hero">
    <img src="<?php echo esc_url( $ev->image_url ); ?>" alt="<?php echo esc_attr( $ev->title ); ?>"/>
  </div>
  <?php else : ?>
  <div class="abm-detail-hero"><div class="abm-detail-hero-placeholder">&#128197;</div></div>
  <?php endif; ?>

  <div class="abm-detail-card">
    <div class="abm-detail-header">
      <?php if ( ! empty( $ev->category ) ) : ?>
        <span class="abm-detail-cat"><?php echo esc_html( $ev->category ); ?></span>
      <?php endif; ?>
      <h1 class="abm-detail-title"><?php echo esc_html( $ev->title ); ?></h1>

      <?php if ( $dt ) : ?>
      <div class="abm-detail-date-strip">
        <div class="abm-detail-date-badge">
          <div class="dd"><?php echo esc_html( $day ); ?></div>
          <div class="dm"><?php echo esc_html( $mon ); ?></div>
          <div class="dy"><?php echo esc_html( $year ); ?></div>
        </div>
        <div class="abm-detail-date-info">
          <div class="abm-detail-date-full"><?php echo esc_html( $date_fmt ); ?></div>
          <?php if ( $time_str ) : ?>
          <div class="abm-detail-date-time">&#128336; <?php echo esc_html( $time_str ); ?></div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <div class="abm-detail-meta">
        <?php if ( ! empty( $ev->location ) ) : ?>
        <div class="abm-detail-meta-row">
          <div class="abm-detail-meta-icon">&#128205;</div>
          <div>
            <div class="abm-detail-meta-label">Location</div>
            <div class="abm-detail-meta-value"><?php echo esc_html( $ev->location ); ?></div>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ( ! empty( $ev->description ) ) : ?>
    <div class="abm-detail-body">
      <?php
      $paragraphs = explode( "
", $ev->description );
      foreach ( $paragraphs as $para ) {
          $para = trim( $para );
          if ( $para ) {
              echo '<p>' . esc_html( $para ) . '</p>';
          }
      }
      ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php wp_footer(); ?>
</body>
</html>
        <?php
    }


    /* ── Block public user enumeration via REST API ─────────────────────── */
    public function disable_user_endpoints( $endpoints ) {
        if ( ! is_user_logged_in() ) {
            if ( isset( $endpoints['/wp/v2/users'] ) ) {
                unset( $endpoints['/wp/v2/users'] );
            }
            if ( isset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] ) ) {
                unset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
            }
        }
        return $endpoints;
    }


}

new ABM_Event_Manager();

/**
 * GitHub Plugin Updater
 * Checks the GitHub repo for new releases and enables one-click updates from WP Admin.
 */
class ABM_GitHub_Updater {

    private $slug;
    private $plugin_data;
    private $repo    = 'farooq-ahmed-abm/abm-event-manager';
    private $api_url = 'https://api.github.com/repos/farooq-ahmed-abm/abm-event-manager/releases/latest';

    public function __construct( $plugin_file ) {
        $this->slug = plugin_basename( $plugin_file );
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
        add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
        add_filter( 'upgrader_post_install', array( $this, 'after_install' ), 10, 3 );
    }

    private function get_plugin_data() {
        if ( ! $this->plugin_data ) {
            $this->plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $this->slug );
        }
        return $this->plugin_data;
    }

    private function get_latest_release() {
        $response = get_transient( 'abm_github_release' );
        if ( false === $response ) {
            $response = wp_remote_get( $this->api_url, array(
                'headers' => array(
                    'Accept'     => 'application/vnd.github.v3+json',
                    'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ),
                ),
                'timeout' => 10,
            ) );
            if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
                set_transient( 'abm_github_release', $response, 6 * HOUR_IN_SECONDS );
            }
        }
        if ( is_wp_error( $response ) ) {
            return null;
        }
        return json_decode( wp_remote_retrieve_body( $response ) );
    }

    public function check_for_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }
        $release = $this->get_latest_release();
        if ( ! $release ) {
            return $transient;
        }
        $latest_version  = ltrim( $release->tag_name, 'v' );
        $current_version = $this->get_plugin_data()['Version'];
        if ( version_compare( $latest_version, $current_version, '>' ) ) {
            // Use zipball_url for private repos (authenticated download)
            $download_url = isset( $release->zipball_url ) ? $release->zipball_url : 'https://api.github.com/repos/' . $this->repo . '/zipball/' . $release->tag_name;
            $transient->response[ $this->slug ] = (object) array(
                'slug'        => dirname( $this->slug ),
                'plugin'      => $this->slug,
                'new_version' => $latest_version,
                'url'         => 'https://github.com/' . $this->repo,
                'package'     => $download_url,
            );
        }
        return $transient;
    }

    public function plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action ) {
            return $result;
        }
        if ( $args->slug !== dirname( $this->slug ) ) {
            return $result;
        }
        $release = $this->get_latest_release();
        if ( ! $release ) {
            return $result;
        }
        $data = $this->get_plugin_data();
        $download_url = isset( $release->zipball_url ) ? $release->zipball_url : 'https://api.github.com/repos/' . $this->repo . '/zipball/' . $release->tag_name;
        return (object) array(
            'name'          => $data['Name'],
            'slug'          => dirname( $this->slug ),
            'version'       => ltrim( $release->tag_name, 'v' ),
            'author'        => $data['Author'],
            'homepage'      => 'https://github.com/' . $this->repo,
            'download_link' => $download_url,
            'sections'      => array(
                'description' => $data['Description'],
                'changelog'   => isset( $release->body ) ? nl2br( esc_html( $release->body ) ) : 'See GitHub releases.',
            ),
            'last_updated'  => isset( $release->published_at ) ? $release->published_at : '',
            'requires'      => '5.0',
            'tested'        => '6.7',
        );
    }

    public function after_install( $response, $hook_extra, $result ) {
        global $wp_filesystem;
        $plugin_folder = WP_PLUGIN_DIR . '/' . dirname( $this->slug );
        $wp_filesystem->move( $result['destination'], $plugin_folder );
        $result['destination'] = $plugin_folder;
        if ( is_plugin_active( $this->slug ) ) {
            activate_plugin( $this->slug );
        }
        return $result;
    }
}

new ABM_GitHub_Updater( __FILE__ );

