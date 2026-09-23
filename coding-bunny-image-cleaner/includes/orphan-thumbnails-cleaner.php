<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CBIC_Orphan_Thumbnails_Cleaner {

    const TRANSIENT_TTL         = 12 * HOUR_IN_SECONDS;
    const IMMEDIATE_SCAN_LIMIT  = 5000;
    const DIRS_PER_STEP         = 3;
    const RESCHEDULE_DELAY      = 5;

    protected $blog_id;
    protected $upload_basedir;
    protected $cache_key;
    protected $queue_option;
    protected $results_option;
    protected $in_progress_option;

    protected $count_option;
    protected $last_scan_option;

    public function __construct() {
        $this->blog_id            = is_multisite() ? get_current_blog_id() : 0;
        $upload_dir               = wp_get_upload_dir();
        $this->upload_basedir     = isset( $upload_dir['basedir'] ) ? $upload_dir['basedir'] : '';
        $this->cache_key          = 'cbic_orphan_thumbs_' . $this->blog_id . '_' . md5( $this->upload_basedir );
        $this->queue_option       = 'cbic_orphan_queue_' . $this->blog_id;
        $this->results_option     = 'cbic_orphan_results_' . $this->blog_id;
        $this->in_progress_option = 'cbic_orphan_in_progress_' . $this->blog_id;

        $this->count_option       = 'cbic_orphan_total_count_' . $this->blog_id;
        $this->last_scan_option   = 'cbic_orphan_last_scan_' . $this->blog_id;

        add_action( 'delete_attachment', [ $this, 'clear_orphan_cache' ] );
        add_action( 'edit_attachment',   [ $this, 'clear_orphan_cache' ] );

        add_action( 'cbic_orphan_scan_cron_step', [ $this, 'background_scan_step' ] );
    }

    public function get_orphan_thumbnails( $use_cache = true ) {
        if ( empty( $this->upload_basedir ) || ! is_dir( $this->upload_basedir ) ) {
            return [];
        }

        if ( $use_cache ) {
            $cached = get_transient( $this->cache_key );
            if ( false !== $cached ) {
                return (array) $cached;
            }
        }

        if ( get_option( $this->in_progress_option, false ) ) {
            $partial = get_option( $this->results_option, [] );
            return (array) $partial;
        }

        return [];
    }

    public function run_orphan_scan( $force = false ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'forbidden', __( 'You do not have permission to run this scan.', 'coding-bunny-image-cleaner' ) );
        }

        if ( get_option( $this->in_progress_option, false ) && ! $force ) {
            return (array) get_option( $this->results_option, [] );
        }

        $db_run_id = null;
        if ( class_exists( 'CBIC_DB_Manager' ) ) {
            $db_run_id = CBIC_DB_Manager::instance()->start_run( 'orphan', array(), self::IMMEDIATE_SCAN_LIMIT );
        }

        $total_files = $this->fast_count_files_in_uploads();

        if ( $total_files <= self::IMMEDIATE_SCAN_LIMIT ) {
            $dirs = [ $this->upload_basedir ];
            $orphans = $this->scan_dirs_and_find_orphans( $dirs );

            set_transient( $this->cache_key, $orphans, self::TRANSIENT_TTL );
            update_option( $this->results_option, $orphans );
            update_option( $this->count_option, count( $orphans ) );
            update_option( $this->last_scan_option, time() );

            delete_option( $this->queue_option );
            delete_option( $this->in_progress_option );

            if ( $db_run_id && class_exists( 'CBIC_DB_Manager' ) ) {
                $items = array();
                foreach ( $orphans as $row ) {
                    $items[] = array(
                        'run_id'      => $db_run_id,
                        'object_id'   => null,
                        'object_type' => 'file',
                        'issue_type'  => 'orphan',
                        'group_key'   => null,
                        'data'        => $row,
                    );
                }
                CBIC_DB_Manager::instance()->bulk_insert_items( $items );
                CBIC_DB_Manager::instance()->complete_run( $db_run_id, 'completed' );
            }

            return (array) $orphans;
        }

        $this->start_background_scan( $force );

        if ( $db_run_id && class_exists( 'CBIC_DB_Manager' ) ) {
            CBIC_DB_Manager::instance()->update_run_progress( $db_run_id, 10 );
        }

        return (array) get_option( $this->results_option, [] );
    }

    public function delete_orphan_thumbnail_files( array $relative_files ) {
        $upload_dir   = wp_get_upload_dir();
        $base_dir     = $upload_dir['basedir'];
        $real_basedir = realpath( $base_dir );
        if ( ! $real_basedir || ! is_dir( $real_basedir ) ) {
            return 0;
        }

        $deleted = 0;

        foreach ( $relative_files as $rel ) {
            $rel       = sanitize_text_field( $rel );
            $rel       = ltrim( $rel, '/\\' );
            if ( $rel === '' ) {
                continue;
            }
            $path      = $base_dir . '/' . $rel;
            $real_path = realpath( $path );
            if (
                $real_path
                && strpos( $real_path, $real_basedir ) === 0
                && is_file( $real_path )
            ) {
                if ( wp_delete_file( $real_path ) ) {
                    $deleted++;
                }
            }
        }

        $stored_total = (int) get_option( $this->count_option, 0 );
        if ( $stored_total > 0 && $deleted > 0 ) {
            $new_total = max( 0, $stored_total - $deleted );
            update_option( $this->count_option, $new_total );
        }

        $this->clear_orphan_cache();

        return $deleted;
    }

    public function clear_orphan_cache() {
        delete_transient( $this->cache_key );
        delete_option( $this->results_option );
        delete_option( $this->queue_option );
        delete_option( $this->in_progress_option );
    }

    protected function start_background_scan( $force = false ) {
        if ( get_option( $this->in_progress_option, false ) && ! $force ) {
            return;
        }

        $dirs = $this->list_top_level_dirs( $this->upload_basedir );

        if ( empty( $dirs ) ) {
            $dirs = [ $this->upload_basedir ];
        }

        update_option( $this->queue_option, $dirs );
        update_option( $this->results_option, [] );
        update_option( $this->in_progress_option, 1 );

        if ( ! wp_next_scheduled( 'cbic_orphan_scan_cron_step', [] ) ) {
            wp_schedule_single_event( time(), 'cbic_orphan_scan_cron_step', [] );
        } else {
            if ( $force ) {
                wp_clear_scheduled_hook( 'cbic_orphan_scan_cron_step' );
                wp_schedule_single_event( time(), 'cbic_orphan_scan_cron_step', [] );
            }
        }
    }

public function background_scan_step() {
    $queue = (array) get_option( $this->queue_option, [] );

    $db_run_cache_key = 'cbic_orphan_running_run_id';
    $db_run_id = wp_cache_get( $db_run_cache_key, 'cbic' );

    if ( false === $db_run_id && class_exists( 'CBIC_DB_Manager' ) ) {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}cbic_scan_runs WHERE type = %s AND status = %s ORDER BY started_at DESC LIMIT 1",
            'orphan',
            'running'
        );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
        $db_run_id = $wpdb->get_var( $sql );
        wp_cache_set( $db_run_cache_key, $db_run_id, 'cbic', 5 * MINUTE_IN_SECONDS );
    }

    if ( empty( $queue ) ) {
        update_option( $this->in_progress_option, 0 );
        $results = (array) get_option( $this->results_option, [] );
        set_transient( $this->cache_key, $results, self::TRANSIENT_TTL );

        update_option( $this->count_option, count( $results ) );
        update_option( $this->last_scan_option, time() );

        delete_option( $this->queue_option );
        delete_option( $this->in_progress_option );

        if ( $db_run_id && class_exists( 'CBIC_DB_Manager' ) ) {
            $items = array();
            foreach ( $results as $row ) {
                $items[] = array(
                    'run_id'      => $db_run_id,
                    'object_id'   => null,
                    'object_type' => 'file',
                    'issue_type'  => 'orphan',
                    'group_key'   => null,
                    'data'        => $row,
                );
            }
            CBIC_DB_Manager::instance()->bulk_insert_items( $items );
            CBIC_DB_Manager::instance()->complete_run( $db_run_id, 'completed' );
        }
        return;
    }

    $to_process = array_splice( $queue, 0, self::DIRS_PER_STEP );
    update_option( $this->queue_option, $queue );

    $accumulated = [];
    foreach ( $to_process as $dir ) {
        $accumulated = array_merge( $accumulated, $this->scan_dirs_and_find_orphans( [ $dir ] ) );
    }

    $existing = (array) get_option( $this->results_option, [] );
    $existing_rel = wp_list_pluck( $existing, 'relative' );

    foreach ( $accumulated as $item ) {
        if ( isset( $item['relative'] ) && ! in_array( $item['relative'], $existing_rel, true ) ) {
            $existing[] = $item;
            $existing_rel[] = $item['relative'];
        }
    }

    update_option( $this->results_option, $existing );

    if ( empty( $queue ) ) {
        set_transient( $this->cache_key, $existing, self::TRANSIENT_TTL );
        update_option( $this->count_option, count( $existing ) );
        update_option( $this->last_scan_option, time() );

        delete_option( $this->queue_option );
        delete_option( $this->in_progress_option );

        if ( $db_run_id && class_exists( 'CBIC_DB_Manager' ) ) {
            $items = array();
            foreach ( $existing as $row ) {
                $items[] = array(
                    'run_id'      => $db_run_id,
                    'object_id'   => null,
                    'object_type' => 'file',
                    'issue_type'  => 'orphan',
                    'group_key'   => null,
                    'data'        => $row,
                );
            }
            CBIC_DB_Manager::instance()->bulk_insert_items( $items );
            CBIC_DB_Manager::instance()->complete_run( $db_run_id, 'completed' );
        }
        return;
    }

    wp_schedule_single_event( time() + self::RESCHEDULE_DELAY, 'cbic_orphan_scan_cron_step', [] );
}

    protected function scan_dirs_and_find_orphans( array $dirs ) {
        $base_dir = $this->upload_basedir;
        $all_files = [];

        foreach ( $dirs as $dir ) {
            if ( empty( $dir ) || ! is_dir( $dir ) ) {
                continue;
            }
            $files = $this->scan_dir_recursive( $dir );
            $all_files = array_merge( $all_files, $files );
        }

        $main_files = [];
        foreach ( $all_files as $file ) {
            if (
                preg_match( '/\.(jpg|jpeg|png|webp|avif|gif)$/i', $file )
                && ! preg_match( '/-\d+x\d+\.(jpg|jpeg|png|webp|avif|gif)$/i', $file )
            ) {
                $no_based = $this->normalize_path( $file );
                $main_files[ $no_based ] = true;
            }
        }

        $orphans = [];
        foreach ( $all_files as $file ) {
            if ( preg_match( '/^(.*)-\d+x\d+\.(jpg|jpeg|png|webp|avif|gif)$/i', $file, $m ) ) {
                $base_no_size_full = $m[1];
                $base_no_size = $this->normalize_path( $base_no_size_full );
                $found_main = false;
                foreach ( [ 'jpg', 'jpeg', 'png', 'webp', 'avif', 'gif' ] as $ext ) {
                    if ( isset( $main_files[ "{$base_no_size}.{$ext}" ] ) ) {
                        $found_main = true;
                        break;
                    }
                }
                if ( $found_main ) {
                    continue;
                }
                $rel = ltrim( str_replace( $base_dir, '', $file ), '/\\' );
                $orphans[] = [ 'relative' => $rel ];
            }
        }

        return $orphans;
    }

    private function scan_dir_recursive( $dir ) {
        $files = [];
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
            );
            foreach ( $it as $f ) {
                if ( $f->isFile() ) {
                    $files[] = $f->getPathname();
                }
            }
        } catch ( Exception $e ) {
        }
        return $files;
    }

    protected function normalize_path( $path ) {
        $path = str_replace( $this->upload_basedir . '/', '', $path );
        $path = str_replace( '\\', '/', $path );
        return ltrim( $path, '/' );
    }

    protected function fast_count_files_in_uploads() {
        $count = 0;
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $this->upload_basedir, FilesystemIterator::SKIP_DOTS )
            );
            foreach ( $it as $f ) {
                if ( $f->isFile() ) {
                    $count++;
                    if ( $count > self::IMMEDIATE_SCAN_LIMIT ) {
                        return $count;
                    }
                }
            }
        } catch ( Exception $e ) {
            return $count;
        }
        return $count;
    }

    protected function list_top_level_dirs( $base ) {
        $dirs = [];
        try {
            $it = new DirectoryIterator( $base );
            foreach ( $it as $fileinfo ) {
                if ( $fileinfo->isDot() ) {
                    continue;
                }
                if ( $fileinfo->isDir() ) {
                    $dirs[] = $fileinfo->getPathname();
                }
            }
        } catch ( Exception $e ) {
        }
        return $dirs;
    }

    public function get_stored_orphan_count() {
        return (int) get_option( $this->count_option, 0 );
    }

    public function get_last_scan_timestamp() {
        return get_option( $this->last_scan_option, false );
    }
}

if ( ! function_exists( 'cbic_orphan_thumbs_cleaner_init' ) ) {
    function cbic_orphan_thumbs_cleaner_init() {
        static $instance = null;
        if ( null === $instance ) {
            $instance = new CBIC_Orphan_Thumbnails_Cleaner();
        }
        return $instance;
    }
}
cbic_orphan_thumbs_cleaner_init();