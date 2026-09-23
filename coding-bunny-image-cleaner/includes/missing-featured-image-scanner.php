<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CBIC_Missing_Featured_Image_Scanner {
	private $count_option_name            = 'cbic_missing_featured_total_count';
	private $last_scan_option_name        = 'cbic_missing_featured_last_scan';
	private $images_count_option_name     = 'cbic_total_images_count';
	private $images_last_scan_option_name = 'cbic_total_images_last_scan';

	public function get_posts_without_featured_image( $args = array() ) {
		$defaults = array(
			'post_types'  => array( 'post', 'page' ),
			'numberposts' => 200,
			'post_status' => array( 'publish', 'draft', 'private', 'future', 'pending' ),
		);

		$args        = wp_parse_args( $args, $defaults );
		$post_types  = (array) $args['post_types'];
		$post_status = (array) $args['post_status'];
		$numberposts = (int) $args['numberposts'];

		$query_args = array(
			'post_type'      => $post_types,
			'post_status'    => $post_status,
			'posts_per_page' => $numberposts,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'DESC',
		);

		$posts = get_posts( $query_args );

		$missing = array();
		foreach ( $posts as $pid ) {
			$thumb_id = get_post_thumbnail_id( $pid );
			if ( empty( $thumb_id ) || ! get_post( $thumb_id ) ) {
				$missing[] = $pid;
			}
		}

		return $missing;
	}

	public function get_all_public_post_types() {
		$args  = array( 'public' => true );
		$types = get_post_types( $args, 'objects' );
		$out   = array();

		foreach ( $types as $type ) {
			if ( 'attachment' === $type->name ) {
				continue;
			}
			$out[ $type->name ] = $type->labels->singular_name;
		}

		return $out;
	}

	public function scan_and_store_full_missing_featured_count( $statuses = null ) {
		global $wpdb;

		if ( null === $statuses ) {
			$statuses = array( 'publish', 'draft', 'private', 'future', 'pending' );
		}

		$public_types = get_post_types( array( 'public' => true ), 'names' );
		if ( isset( $public_types['attachment'] ) ) {
			unset( $public_types['attachment'] );
		}

		$db_run_id = null;
		if ( class_exists( 'CBIC_DB_Manager' ) ) {
			$db_run_id = CBIC_DB_Manager::instance()->start_run( 'missing_featured', array(), null );
		}

		if ( empty( $public_types ) ) {
			$missing_feat_count = 0;
			$missing_posts      = array();
		} else {
			$pt_count = count( $public_types );
			$st_count = count( $statuses );

			$pt_placeholders = implode( ', ', array_fill( 0, $pt_count, '%s' ) );
			$st_placeholders = implode( ', ', array_fill( 0, $st_count, '%s' ) );

			$sql = "
				SELECT p.ID FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_thumbnail_id'
				WHERE p.post_type IN ( {$pt_placeholders} )
				AND p.post_status IN ( {$st_placeholders} )
				AND ( pm.post_id IS NULL OR pm.meta_value = '' )
			";

			$params = array_merge( array_values( $public_types ), array_values( $statuses ) );

			if ( ! empty( $params ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$prepared = $wpdb->prepare( $sql, ...$params );
			} else {
				$prepared = $sql;
			}

			$blog_id    = function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0;
			$key_source = array( 'pt' => $public_types, 'st' => $statuses, 'blog' => $blog_id );
			$cache_key  = 'cbic_missing_featured_posts_' . md5( wp_json_encode( $key_source ) );
			$cache_ttl  = HOUR_IN_SECONDS;

			$missing_posts = wp_cache_get( $cache_key, 'cbic' );
			if ( false === $missing_posts ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
				$missing_posts = $wpdb->get_col( $prepared );
				if ( ! is_array( $missing_posts ) ) {
					$missing_posts = array();
				}
				wp_cache_set( $cache_key, $missing_posts, 'cbic', $cache_ttl );
			}

			$missing_feat_count = (int) count( $missing_posts );
		}

		$this->store_missing_featured_count( $missing_feat_count );

		$image_count = $this->count_media_images();
		$this->store_total_images_count( $image_count );
 
		if ( $db_run_id && class_exists( 'CBIC_DB_Manager' ) ) {
			$items = array();
			foreach ( $missing_posts as $pid ) {
				$items[] = array(
					'run_id'      => $db_run_id,
					'object_id'   => (int) $pid,
					'object_type' => 'post',
					'issue_type'  => 'missing_featured',
					'group_key'   => null,
					'data'        => array( 'post_id' => (int) $pid ),
				);
			}
			if ( ! empty( $items ) ) {
				CBIC_DB_Manager::instance()->bulk_insert_items( $items );
			}
			CBIC_DB_Manager::instance()->complete_run( $db_run_id, 'completed' );
		}

		return $missing_feat_count;
	}

	private function count_media_images() {
		global $wpdb;

		$blog_id   = function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0;
		$cache_key = 'cbic_total_images_count_' . $blog_id;
		$cache_ttl = HOUR_IN_SECONDS;

		$count = wp_cache_get( $cache_key, 'cbic' );
		if ( false !== $count ) {
			return (int) $count;
		}

		$sql = "
			SELECT COUNT( ID ) FROM {$wpdb->posts}
			WHERE post_type = 'attachment' AND post_mime_type LIKE %s
		";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared = $wpdb->prepare( $sql, 'image/%' );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
		$count    = (int) $wpdb->get_var( $prepared );

		wp_cache_set( $cache_key, $count, 'cbic', $cache_ttl );

		return $count;
	}

	private function store_missing_featured_count( $count ) {
		$count = (int) $count;
		$time  = time();

		if ( is_multisite() ) {
			update_site_option( $this->count_option_name, $count );
			update_site_option( $this->last_scan_option_name, $time );
		} else {
			update_option( $this->count_option_name, $count );
			update_option( $this->last_scan_option_name, $time );
		}
	}

	private function store_total_images_count( $count ) {
		$count = (int) $count;
		$time  = time();

		if ( is_multisite() ) {
			update_site_option( $this->images_count_option_name, $count );
			update_site_option( $this->images_last_scan_option_name, $time );
		} else {
			update_option( $this->images_count_option_name, $count );
			update_option( $this->images_last_scan_option_name, $time );
		}
	}

	public function get_stored_missing_featured_count() {
		$val = is_multisite()
			? get_site_option( $this->count_option_name, false )
			: get_option( $this->count_option_name, false );

		if ( false === $val ) {
			return false;
		}

		return is_numeric( $val ) ? (int) $val : 0;
	}

	public function get_last_missing_featured_scan_timestamp() {
		$val = is_multisite()
			? get_site_option( $this->last_scan_option_name, false )
			: get_option( $this->last_scan_option_name, false );

		return $val ? (int) $val : false;
	}

	public function get_stored_total_images_count() {
		$val = is_multisite()
			? get_site_option( $this->images_count_option_name, false )
			: get_option( $this->images_count_option_name, false );

		if ( false === $val ) {
			return false;
		}

		return is_numeric( $val ) ? (int) $val : 0;
	}

	public function get_last_images_scan_timestamp() {
		$val = is_multisite()
			? get_site_option( $this->images_last_scan_option_name, false )
			: get_option( $this->images_last_scan_option_name, false );

		return $val ? (int) $val : false;
	}

	public function clear_stored_missing_featured_count() {
		if ( is_multisite() ) {
			delete_site_option( $this->count_option_name );
			delete_site_option( $this->last_scan_option_name );
		} else {
			delete_option( $this->count_option_name );
			delete_option( $this->last_scan_option_name );
		}

		$blog_id = function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0;
		$pattern = 'cbic_missing_featured_posts_' . $blog_id;
	}

	public function clear_stored_total_images_count() {
		if ( is_multisite() ) {
			delete_site_option( $this->images_count_option_name );
			delete_site_option( $this->images_last_scan_option_name );
		} else {
			delete_option( $this->images_count_option_name );
			delete_option( $this->images_last_scan_option_name );
		}

		$blog_id   = function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0;
		$cache_key = 'cbic_total_images_count_' . $blog_id;
		wp_cache_delete( $cache_key, 'cbic' );
	}
}