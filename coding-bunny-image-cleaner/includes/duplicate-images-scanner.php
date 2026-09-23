<?php

if (!defined('ABSPATH')) {
	exit;
}

class CBIC_Duplicate_Images_Scanner {

	const TRANSIENT_KEY = 'cbic_duplicate_images_scan';
	const TOTAL_COUNT_OPTION = 'cbic_duplicate_total_count';
	const LAST_SCAN_OPTION = 'cbic_duplicate_last_scan';
	const TRANSIENT_TTL = 86400;
	const BATCH_SIZE = 500;
	const REMOTE_HEAD_TIMEOUT = 3;
	const REMOTE_GET_TIMEOUT = 10;
	const PARTIAL_BYTES = 65536;

	public static function register_hooks() {
		add_action( 'add_attachment', array( __CLASS__, 'on_attachment_changed' ) );
		add_action( 'edit_attachment', array( __CLASS__, 'on_attachment_changed' ) );
		add_action( 'delete_attachment', array( __CLASS__, 'on_attachment_deleted' ) );
	}

	public static function on_attachment_changed( $attachment_id ) {
		$scanner = new self();
		$scanner->clear_attachment_cache( $attachment_id );
		delete_transient( self::TRANSIENT_KEY );
		update_option( self::TOTAL_COUNT_OPTION, 0 );
		update_option( self::LAST_SCAN_OPTION, false );
	}

	public static function on_attachment_deleted( $attachment_id ) {
		delete_transient( self::TRANSIENT_KEY );
		$scanner = new self();
		$scanner->clear_attachment_cache( $attachment_id );
		update_option( self::TOTAL_COUNT_OPTION, 0 );
		update_option( self::LAST_SCAN_OPTION, false );
	}

	public function get_duplicate_images() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to run this scan.', 'coding-bunny-image-cleaner' ) );
		}

		// Leggi prima dall’ultimo run salvato su DB.
		if ( class_exists( 'CBIC_DB_Manager' ) ) {
			$db_items = CBIC_DB_Manager::instance()->get_latest_items( 'duplicate', 1000 );
			if ( ! empty( $db_items ) ) {
				return $this->map_db_duplicates( $db_items );
			}
		}

		$cached = get_transient( self::TRANSIENT_KEY );
		if ( false !== $cached ) {
			return $cached;
		}

		return array();
	}

	public function run_duplicate_scan( $force = false ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to run this scan.', 'coding-bunny-image-cleaner' ) );
		}

		$cache_key = self::TRANSIENT_KEY;
		if ( ! $force ) {
			$existing = get_transient( $cache_key );
			if ( false !== $existing ) {
				return $existing;
			}
		}

		$db_run_id = null;
		if ( class_exists( 'CBIC_DB_Manager' ) ) {
			$db_run_id = CBIC_DB_Manager::instance()->start_run( 'duplicate', array(), self::BATCH_SIZE );
		}

		$size_counts = array();
		$remote_header_cache = array();
		$per_page = (int) self::BATCH_SIZE;
		$paged = 1;

		while ( true ) {
			$args = array(
				'post_type'               => 'attachment',
				'post_mime_type'          => 'image',
				'post_status'             => 'inherit',
				'posts_per_page'          => $per_page,
				'paged'                   => $paged,
				'fields'                  => 'ids',
				'no_found_rows'           => true,
				'update_post_meta_cache'  => false,
				'update_post_term_cache'  => false,
			);

			$query = new WP_Query( $args );
			if ( empty( $query->posts ) ) {
				wp_reset_postdata();
				break;
			}

			foreach ( $query->posts as $attachment_id ) {
				$attachment_id = absint( $attachment_id );
				if ( $attachment_id <= 0 ) {
					continue;
				}

				$path = get_attached_file( $attachment_id );
				$size = -1;

				if ( $path && is_file( $path ) ) {
					$size = @filesize( $path ) ?: -1;
				} else {
					$url = wp_get_attachment_url( $attachment_id );
					if ( $url ) {
						if ( isset( $remote_header_cache[ $url ] ) ) {
							$hdr = $remote_header_cache[ $url ];
						} else {
							$response = wp_remote_head( $url, array( 'timeout' => self::REMOTE_HEAD_TIMEOUT ) );
							$hdr = is_wp_error( $response ) ? false : $response;
							$remote_header_cache[ $url ] = $hdr;
						}
						if ( $hdr && ! is_wp_error( $hdr ) ) {
							$cl = wp_remote_retrieve_header( $hdr, 'content-length' );
							if ( $cl !== '' && is_numeric( $cl ) ) {
								$size = intval( $cl );
							} else {
								$size = -1;
							}
						}
					}
				}

				$key = (string) $size;
				if ( $key === '' ) {
					$key = '-1';
				}
				if ( ! isset( $size_counts[ $key ] ) ) {
					$size_counts[ $key ] = 0;
				}
				$size_counts[ $key ]++;
			}

			$paged++;
			wp_reset_postdata();

			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
		}

		$candidate_sizes = array();
		foreach ( $size_counts as $size_key => $count ) {
			if ( $count > 1 ) {
				$candidate_sizes[ $size_key ] = true;
			}
		}

		if ( empty( $candidate_sizes ) ) {
			set_transient( $cache_key, array(), self::TRANSIENT_TTL );
			update_option( self::TOTAL_COUNT_OPTION, 0 );
			update_option( self::LAST_SCAN_OPTION, time() );
			if ( $db_run_id && class_exists( 'CBIC_DB_Manager' ) ) {
				CBIC_DB_Manager::instance()->complete_run( $db_run_id, 'completed' );
			}
			return array();
		}

		$hashes = array();
		$remote_header_cache = array();
		$remote_body_cache = array();
		$per_page = (int) self::BATCH_SIZE;
		$paged = 1;

		while ( true ) {
			$args = array(
				'post_type'               => 'attachment',
				'post_mime_type'          => 'image',
				'post_status'             => 'inherit',
				'posts_per_page'          => $per_page,
				'paged'                   => $paged,
				'fields'                  => 'ids',
				'no_found_rows'           => true,
				'update_post_meta_cache'  => false,
				'update_post_term_cache'  => false,
			);

			$query = new WP_Query( $args );
			if ( empty( $query->posts ) ) {
				wp_reset_postdata();
				break;
			}

			foreach ( $query->posts as $attachment_id ) {
				$attachment_id = absint( $attachment_id );
				if ( $attachment_id <= 0 ) {
					continue;
				}

				$path = get_attached_file( $attachment_id );
				$url = wp_get_attachment_url( $attachment_id );
				$size = -1;

				if ( $path && is_file( $path ) ) {
					$size = @filesize( $path ) ?: -1;
				} else {
					if ( $url ) {
						if ( isset( $remote_header_cache[ $url ] ) ) {
							$hdr = $remote_header_cache[ $url ];
						} else {
							$response = wp_remote_head( $url, array( 'timeout' => self::REMOTE_HEAD_TIMEOUT ) );
							$hdr = is_wp_error( $response ) ? false : $response;
							$remote_header_cache[ $url ] = $hdr;
						}
						if ( $hdr && ! is_wp_error( $hdr ) ) {
							$cl = wp_remote_retrieve_header( $hdr, 'content-length' );
							if ( $cl !== '' && is_numeric( $cl ) ) {
								$size = intval( $cl );
							} else {
								$size = -1;
							}
						}
					}
				}

				$key = (string) $size;
				if ( $key === '' ) {
					$key = '-1';
				}

				if ( ! isset( $candidate_sizes[ $key ] ) ) {
					continue;
				}

				$hash = '';

				if ( $path && is_file( $path ) ) {
					$hash = @hash_file( 'sha256', $path );
					if ( false === $hash || '' === $hash ) {
						$hash = 'local:' . md5( $path . '|' . @filesize( $path ) );
					}
				} else {
					$etag = '';
					if ( $url ) {
						if ( isset( $remote_header_cache[ $url ] ) ) {
							$hdr = $remote_header_cache[ $url ];
						} else {
							$response = wp_remote_head( $url, array( 'timeout' => self::REMOTE_HEAD_TIMEOUT ) );
							$hdr = is_wp_error( $response ) ? false : $response;
							$remote_header_cache[ $url ] = $hdr;
						}
						if ( $hdr && ! is_wp_error( $hdr ) ) {
							$etag = wp_remote_retrieve_header( $hdr, 'etag' );
							if ( empty( $etag ) ) {
								$etag = wp_remote_retrieve_header( $hdr, 'ETag' );
							}
						}
					}

					if ( $etag ) {
						$hash = 'etag:' . md5( $etag );
					} else {
						if ( $url ) {
							if ( isset( $remote_body_cache[ $url ] ) ) {
								$body = $remote_body_cache[ $url ];
							} else {
								$response = wp_remote_get( $url, array( 'timeout' => self::REMOTE_GET_TIMEOUT, 'headers' => array( 'Range' => 'bytes=0-' . ( self::PARTIAL_BYTES - 1 ) ) ) );
								$body = ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) < 200 || wp_remote_retrieve_response_code( $response ) >= 400 ) ? '' : wp_remote_retrieve_body( $response );
								$remote_body_cache[ $url ] = $body;
							}
							if ( $body !== '' ) {
								$hash = 'partial:' . hash( 'sha256', $body );
							}
						}
						if ( $hash === '' ) {
							$hash = 'url:' . md5( (string) $url . '|' . (string) $size );
						}
					}
				}

				$hashes[ $hash ][] = array(
					'ID'      => $attachment_id,
					'url'     => $url,
					'path'    => $path,
					'used_in' => $this->get_image_usage( $attachment_id ),
				);
			}

			$paged++;
			wp_reset_postdata();

			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
		}

		$duplicates = array_filter( $hashes, function( $images ) {
			return count( $images ) > 1;
		} );

		$ttl = (int) self::TRANSIENT_TTL;
		set_transient( $cache_key, $duplicates, $ttl );

		$total_found = 0;
		foreach ( $duplicates as $group ) {
			$total_found += count( $group );
		}
		update_option( self::TOTAL_COUNT_OPTION, (int) $total_found );
		update_option( self::LAST_SCAN_OPTION, time() );

		if ( $db_run_id && class_exists( 'CBIC_DB_Manager' ) ) {
			$items = array();
			foreach ( $duplicates as $hash => $group ) {
				foreach ( $group as $item ) {
					$items[] = array(
						'run_id'      => $db_run_id,
						'object_id'   => isset( $item['ID'] ) ? (int) $item['ID'] : null,
						'object_type' => 'attachment',
						'issue_type'  => 'duplicate',
						'group_key'   => $hash,
						'data'        => $item,
					);
				}
			}
			CBIC_DB_Manager::instance()->bulk_insert_items( $items );
			CBIC_DB_Manager::instance()->complete_run( $db_run_id, 'completed' );
		}

		return $duplicates;
	}

	private function map_db_duplicates( array $items ) {
		$out = array();
		foreach ( $items as $row ) {
			$gk = $row['group_key'] ?: 'unknown';
			if ( ! isset( $out[ $gk ] ) ) {
				$out[ $gk ] = array();
			}
			$out[ $gk ][] = $row['data'];
		}
		return $out;
	}

	private function get_theme_usage( $attachment_id ) {
		$usage = array();

		$custom_logo = get_theme_mod( 'custom_logo' );
		if ( $custom_logo && (int) $custom_logo === (int) $attachment_id ) {
			$usage[] = array(
				'type'      => 'theme_logo',
				'id'        => 0,
				'title'     => __( 'Site Logo', 'coding-bunny-image-cleaner' ),
				'edit_link' => admin_url( 'customize.php' ),
			);
		}

		$site_icon = get_option( 'site_icon' );
		if ( $site_icon && (int) $site_icon === (int) $attachment_id ) {
			$usage[] = array(
				'type'      => 'site_icon',
				'id'        => 0,
				'title'     => __( 'Site Icon', 'coding-bunny-image-cleaner' ),
				'edit_link' => admin_url( 'customize.php' ),
			);
		}

		return $usage;
	}

	public function get_stored_duplicate_count() {
		return (int) get_option( self::TOTAL_COUNT_OPTION, 0 );
	}

	public function get_last_scan_timestamp() {
		return get_option( self::LAST_SCAN_OPTION, false );
	}

	private function get_image_usage( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( $attachment_id <= 0 ) {
			return array();
		}

		$cache_key = 'cbic_usage_' . $attachment_id;
		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$usage = array();

		$usage = array_merge( $usage, $this->get_featured_image_usage( $attachment_id ) );
		$usage = array_merge( $usage, $this->get_content_usage( $attachment_id ) );
		$usage = array_merge( $usage, $this->get_parent_usage( $attachment_id ) );

		if ( class_exists( 'WooCommerce' ) ) {
			$usage = array_merge( $usage, $this->get_woocommerce_usage( $attachment_id ) );
		}

		$usage = array_merge( $usage, $this->get_theme_usage( $attachment_id ) );

		set_transient( $cache_key, $usage, self::TRANSIENT_TTL );

		return $usage;
	}

	private function get_featured_image_usage( $attachment_id ) {
		$transient_key = 'cbic_feat_' . $attachment_id;
		$cached = get_transient( $transient_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$usage = array();

		$post_types = get_post_types( array( 'public' => true ), 'names' );
		$post_types = array_diff( $post_types, array( 'attachment' ) );

		$batch_size = 100;
		$offset = 0;
		$found = 0;
		$max_results = 20;

		while ( $found < $max_results ) {
			$posts = get_posts( array(
				'post_type'      => $post_types,
				'post_status'    => 'any',
				'posts_per_page' => $batch_size,
				'offset'         => $offset,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
			) );

			if ( empty( $posts ) ) {
				break;
			}

			foreach ( $posts as $post_id ) {
				$thumbnail_id = get_post_thumbnail_id( $post_id );
				if ( $thumbnail_id && (int) $thumbnail_id === (int) $attachment_id ) {
					$usage[] = array(
						'type'      => 'featured_image',
						'id'        => $post_id,
						'title'     => get_the_title( $post_id ),
						'edit_link' => get_edit_post_link( $post_id ),
					);
					$found++;

					if ( $found >= $max_results ) {
						break 2;
					}
				}
			}

			$offset += $batch_size;

			if ( $offset >= 1000 ) {
				break;
			}
		}

		set_transient( $transient_key, $usage, self::TRANSIENT_TTL );
		return $usage;
	}

	private function get_content_usage( $attachment_id ) {
		global $wpdb;

		$attachment_id = (int) $attachment_id;

		if ( ! current_user_can( 'manage_options' ) ) {
			return array();
		}

		$transient_key = 'cbic_cont_' . $attachment_id;
		$cache_group   = 'cbic';
		$cache_ttl     = self::TRANSIENT_TTL;
		$limit         = 10;

		$cached = wp_cache_get( $transient_key, $cache_group );
		if ( false !== $cached ) {
			return $cached;
		}

		$cached = get_transient( $transient_key );
		if ( false !== $cached ) {
			wp_cache_set( $transient_key, $cached, $cache_group, $cache_ttl );
			return $cached;
		}

		$usage    = array();
		$file_url = wp_get_attachment_url( $attachment_id );

		if ( ! $file_url ) {
			return $usage;
		}

		$basename = basename( $file_url );

		$post_types = get_post_types( array( 'public' => true ), 'names' );
		$post_types = array_diff( (array) $post_types, array( 'attachment' ) );

		if ( empty( $post_types ) ) {
			return $usage;
		}

		$post_types = array_values( array_filter( array_map( 'sanitize_key', (array) $post_types ) ) );
		if ( empty( $post_types ) ) {
			return $usage;
		}

		$like  = '%' . $wpdb->esc_like( $basename ) . '%';
		$limit = (int) $limit;

		$where_filter = function( $where, $query ) use ( $like, $wpdb ) {
			$where .= $wpdb->prepare( " AND {$wpdb->posts}.post_content LIKE %s", $like );
			return $where;
		};

		add_filter( 'posts_where', $where_filter, 10, 2 );

		$found_posts = get_posts( array(
			'post_type'      => $post_types,
			'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
			'posts_per_page' => $limit,
			'orderby'        => 'post_modified_gmt',
			'order'          => 'DESC',
		) );

		remove_filter( 'posts_where', $where_filter, 10, 2 );

		if ( empty( $found_posts ) ) {
			wp_cache_set( $transient_key, $usage, $cache_group, $cache_ttl );
			set_transient( $transient_key, $usage, $cache_ttl );
			return $usage;
		}

		foreach ( $found_posts as $post ) {
			if ( $post && false !== strpos( $post->post_content, $basename ) ) {
				$usage[] = array(
					'type'      => 'content',
					'id'        => $post->ID,
					'title'     => $post->post_title,
					'edit_link' => get_edit_post_link( $post->ID ),
				);
			}
		}

		wp_cache_set( $transient_key, $usage, $cache_group, $cache_ttl );
		set_transient( $transient_key, $usage, $cache_ttl );

		return $usage;
	}

	private function get_parent_usage( $attachment_id ) {
		$usage = array();
		$attachment = get_post( $attachment_id );

		if ( $attachment && ! empty( $attachment->post_parent ) ) {
			$parent = get_post( $attachment->post_parent );
			if ( $parent ) {
				$usage[] = array(
					'type'      => 'attached',
					'id'        => $parent->ID,
					'title'     => get_the_title( $parent->ID ),
					'edit_link' => get_edit_post_link( $parent->ID ),
				);
			}
		}

		return $usage;
	}

	private function get_woocommerce_usage( $attachment_id ) {
		$transient_key = 'cbic_woo_' . $attachment_id;
		$cached = get_transient( $transient_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$usage = array();

		$batch_size = 100;
		$offset = 0;
		$found_featured = 0;
		$max_results = 20;

		while ( $found_featured < $max_results ) {
			$products = get_posts( array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'posts_per_page' => $batch_size,
				'offset'         => $offset,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
			) );

			if ( empty( $products ) ) {
				break;
			}

			foreach ( $products as $product_id ) {
				$thumbnail_id = get_post_thumbnail_id( $product_id );
				if ( $thumbnail_id && (int) $thumbnail_id === (int) $attachment_id ) {
					$usage[] = array(
						'type'      => 'product_featured',
						'id'        => $product_id,
						'title'     => get_the_title( $product_id ),
						'edit_link' => get_edit_post_link( $product_id ),
					);
					$found_featured++;

					if ( $found_featured >= $max_results ) {
						break 2;
					}
				}
			}

			$offset += $batch_size;
			if ( $offset >= 1000 ) {
				break;
			}
		}

		$offset = 0;
		$found_gallery = 0;

		while ( $found_gallery < $max_results ) {
			$products = get_posts( array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'posts_per_page' => $batch_size,
				'offset'         => $offset,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
			) );

			if ( empty( $products ) ) {
				break;
			}

			foreach ( $products as $product_id ) {
				$gallery = get_post_meta( $product_id, '_product_image_gallery', true );
				if ( ! empty( $gallery ) ) {
					$gallery_ids = array_map( 'absint', explode( ',', $gallery ) );
					if ( in_array( $attachment_id, $gallery_ids, true ) ) {
						$usage[] = array(
							'type'      => 'product_gallery',
							'id'        => $product_id,
							'title'     => get_the_title( $product_id ),
							'edit_link' => get_edit_post_link( $product_id ),
						);
						$found_gallery++;

						if ( $found_gallery >= $max_results ) {
							break 2;
						}
					}
				}
			}

			$offset += $batch_size;
			if ( $offset >= 1000 ) {
				break;
			}
		}

		set_transient( $transient_key, $usage, self::TRANSIENT_TTL );
		return $usage;
	}

	public function format_usage_display( $usage ) {
		if ( empty( $usage ) ) {
			return '<span style="color:#999;">' . esc_html__( 'Not used', 'coding-bunny-image-cleaner' ) . '</span>';
		}

		$output = array();
		$count = 0;
		$max_display = 10;

		foreach ( $usage as $item ) {
			if ( $count >= $max_display ) {
				$remaining = count( $usage ) - $max_display;
				$output[] = '<span style="color:#666;">+' . $remaining . ' ' . esc_html__( 'more', 'coding-bunny-image-cleaner' ) . '</span>';
				break;
			}

			$type_label = '';
			switch ( $item['type'] ) {
				case 'featured_image':
				$type_label = __( 'Featured', 'coding-bunny-image-cleaner' );
				break;
				case 'content':
				$type_label = __( 'Content', 'coding-bunny-image-cleaner' );
				break;
				case 'attached':
				$type_label = __( 'Attached', 'coding-bunny-image-cleaner' );
				break;
				case 'product_featured':
				$type_label = __( 'Product Image', 'coding-bunny-image-cleaner' );
				break;
				case 'product_gallery':
				$type_label = __( 'Product Gallery', 'coding-bunny-image-cleaner' );
				break;
				case 'theme_logo':
				case 'site_icon':
				$type_label = __( 'Theme', 'coding-bunny-image-cleaner' );
				break;
			}

			if ( ! empty( $item['edit_link'] ) ) {
				$output[] = '<a href="' . esc_url( $item['edit_link'] ) . '" target="_blank" title="' . esc_attr( $item['title'] ) . '">' 
					. esc_html( $type_label ) . ': ' . esc_html( wp_trim_words( $item['title'], 5 ) ) 
						. '</a>';
			} else {
				$output[] = '<span>' . esc_html( $type_label ) . ': ' . esc_html( $item['title'] ) . '</span>';
			}

			$count++;
		}

		return implode( '<br>', $output );
	}

	public function delete_duplicate_attachments( $ids, $force_delete = false ) {
		if ( ! is_array( $ids ) ) {
			return 0;
		}

		$ids = array_map( 'absint', $ids );
		$deleted = 0;
		$log = get_option( 'cbic_deletion_log', array() );

		foreach ( $ids as $id ) {
			if ( $id <= 0 ) {
				continue;
			}

			if ( current_user_can( 'delete_post', $id ) && get_post_type( $id ) === 'attachment' ) {
				if ( $force_delete ) {
					$result = wp_delete_attachment( $id, true );
					if ( $result ) {
						$deleted++;
						$this->clear_attachment_cache( $id );
						$log[] = array( 'id' => $id, 'time' => time(), 'action' => 'deleted' );
					}
				} else {
					$trashed = wp_trash_post( $id );
					if ( $trashed ) {
						$deleted++;
						$this->clear_attachment_cache( $id );
						$log[] = array( 'id' => $id, 'time' => time(), 'action' => 'trashed' );
					}
				}
			}
		}

		update_option( 'cbic_deletion_log', $log );
		delete_transient( self::TRANSIENT_KEY );

		$current_total = $this->get_stored_duplicate_count();
		$new_total = max( 0, $current_total - $deleted );
		update_option( self::TOTAL_COUNT_OPTION, $new_total );

		return $deleted;
	}

	private function clear_attachment_cache( $attachment_id ) {
		$keys = array(
			'cbic_usage_' . $attachment_id,
			'cbic_feat_' . $attachment_id,
			'cbic_cont_' . $attachment_id,
			'cbic_woo_' . $attachment_id,
		);

		foreach ( $keys as $k ) {
			delete_transient( $k );
		}
	}

	public function clear_all_caches( $per_page = null ) {
		$per_page = is_null( $per_page ) ? (int) self::BATCH_SIZE : (int) $per_page;
		$paged = 1;

		while ( true ) {
			$attachments = get_posts( array(
				'post_type'               => 'attachment',
				'post_mime_type'          => 'image',
				'post_status'             => 'inherit',
				'posts_per_page'          => $per_page,
				'paged'                   => $paged,
				'fields'                  => 'ids',
				'no_found_rows'           => true,
				'update_post_meta_cache'  => false,
				'update_post_term_cache'  => false,
			) );

			if ( empty( $attachments ) ) {
				break;
			}

			foreach ( $attachments as $attachment_id ) {
				$attachment_id = absint( $attachment_id );
				if ( $attachment_id <= 0 ) {
					continue;
				}
				delete_transient( 'cbic_usage_' . $attachment_id );
				delete_transient( 'cbic_feat_' . $attachment_id );
				delete_transient( 'cbic_cont_' . $attachment_id );
				delete_transient( 'cbic_woo_' . $attachment_id );
			}

			$paged++;

			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
		}

		delete_transient( self::TRANSIENT_KEY );
		update_option( self::TOTAL_COUNT_OPTION, 0 );
		update_option( self::LAST_SCAN_OPTION, false );

		return true;
	}

	public function rebuild_cache() {
		$this->clear_all_caches();
		return $this->run_duplicate_scan( true );
	}
}