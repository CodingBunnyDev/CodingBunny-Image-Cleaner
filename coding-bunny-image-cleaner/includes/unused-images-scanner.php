<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CBIC_Unused_Images_Scanner {

	private $used_attachments_cache = null;
	private $cache_group = 'cbic_image_cleaner';

	const TRANSIENT_KEY = 'cbic_unused_images_scan_v1';
	const TOTAL_COUNT_OPTION = 'cbic_unused_total_count';
	const LAST_SCAN_OPTION = 'cbic_unused_last_scan';

	public function get_unused_images( $args = array() ) {
		// Preferisci leggere dall’ultimo run salvato nel DB, poi fallback al transient legacy.
		if ( class_exists( 'CBIC_DB_Manager' ) ) {
			$db_items = CBIC_DB_Manager::instance()->get_latest_items( 'unused' );
			if ( ! empty( $db_items ) ) {
				return $this->map_db_items_to_unused( $db_items );
			}
		}

		$cached = get_transient( self::TRANSIENT_KEY );
		if ( false !== $cached ) {
			return (array) $cached;
		}
		return array();
	}

	public function run_unused_scan( $args = array() ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to run this scan.', 'coding-bunny-image-cleaner' ) );
		}

		if ( is_numeric( $args ) ) {
			$batch_size = (int) $args;
			$args = array( 'batch_size' => $batch_size );
		}

		$batch_size  = isset( $args['batch_size'] ) ? absint( $args['batch_size'] ) : 100;
		$batch_size  = max( 50, min( 1000, $batch_size ) );
		$max_results = isset( $args['max_results'] ) ? absint( $args['max_results'] ) : 5000;
		$max_results = (int) apply_filters( 'cbic_ic_unused_max_results', $max_results );

		$db_run_id = null;
		if ( class_exists( 'CBIC_DB_Manager' ) ) {
			$db_run_id = CBIC_DB_Manager::instance()->start_run( 'unused', array( 'max_results' => $max_results ), $batch_size );
		}

		$used_attachments = $this->get_all_used_attachments();

		$results    = array();
		$upload_dir = wp_get_upload_dir();
		$basedir    = trailingslashit( $upload_dir['basedir'] );

		foreach ( $this->get_all_image_attachments_paginated( $batch_size ) as $attachment_id ) {
			if ( count( $results ) >= $max_results ) {
				break;
			}

			if ( isset( $used_attachments[ $attachment_id ] ) ) {
				// Persisti usage come “used”.
				if ( $db_run_id && class_exists( 'CBIC_DB_Manager' ) ) {
					CBIC_DB_Manager::instance()->upsert_attachment_usage( $attachment_id, true, array(), $db_run_id );
				}
				continue;
			}

			$attachment = get_post( $attachment_id );
			if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
				continue;
			}

			$img_url = wp_get_attachment_url( $attachment_id );
			if ( ! $img_url ) {
				continue;
			}

			$file = get_post_meta( $attachment_id, '_wp_attached_file', true );
			if ( ! $file ) {
				continue;
			}

			$filepath = $basedir . ltrim( $file, '/\\' );
			$size     = is_file( $filepath ) ? filesize( $filepath ) : 0;
			$size     = false !== $size ? (int) $size : 0;

			$row = array(
				'ID'      => $attachment_id,
				'url'     => $img_url,
				'file'    => $file,
				'size'    => $size,
				'preview' => wp_get_attachment_image( $attachment_id, 'thumbnail' ),
			);

			$results[] = $row;

			if ( $db_run_id && class_exists( 'CBIC_DB_Manager' ) ) {
				CBIC_DB_Manager::instance()->upsert_attachment_usage( $attachment_id, false, array(), $db_run_id );
			}

			if ( function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}
		}

		if ( $db_run_id && class_exists( 'CBIC_DB_Manager' ) ) {
			$items = array_map(
				function ( $row ) use ( $db_run_id ) {
					return array(
						'run_id'      => $db_run_id,
						'object_id'   => $row['ID'],
						'object_type' => 'attachment',
						'issue_type'  => 'unused',
						'group_key'   => null,
						'data'        => $row,
					);
				},
				$results
			);
			CBIC_DB_Manager::instance()->bulk_insert_items( $items );
			CBIC_DB_Manager::instance()->complete_run( $db_run_id, 'completed' );
		}

		$ttl = (int) apply_filters( 'cbic_unused_scan_ttl', 12 * HOUR_IN_SECONDS );
		set_transient( self::TRANSIENT_KEY, $results, $ttl );

		update_option( self::TOTAL_COUNT_OPTION, count( $results ) );
		update_option( self::LAST_SCAN_OPTION, time() );

		return $results;
	}

	private function map_db_items_to_unused( array $items ) {
		return array_map(
			function ( $item ) {
				return is_array( $item['data'] ) ? $item['data'] : array();
			},
			$items
		);
	}

	public function delete_unused_attachments( array $attachment_ids ) {
		if ( empty( $attachment_ids ) ) {
			return 0;
		}

		$deleted = 0;

		foreach ( $attachment_ids as $id ) {
			$id = absint( $id );

			if ( $id <= 0 ) {
				continue;
			}

			if ( ! current_user_can( 'delete_post', $id ) ) {
				continue;
			}

			$attachment = get_post( $id );
			if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
				continue;
			}

			$used_attachments = $this->get_all_used_attachments();
			if ( isset( $used_attachments[ $id ] ) ) {
				continue;
			}

			if ( wp_delete_attachment( $id, true ) ) {
				++$deleted;
			}
		}

		$stored_total = (int) get_option( self::TOTAL_COUNT_OPTION, 0 );
		if ( $stored_total > 0 && $deleted > 0 ) {
			$new_total = max( 0, $stored_total - $deleted );
			update_option( self::TOTAL_COUNT_OPTION, $new_total );
		}

		$this->clear_cache();

		return $deleted;
	}

	private function get_all_image_attachments_paginated( $batch_size ) {
		$mime_types = array(
			'image/jpeg',
			'image/jpg',
			'image/png',
			'image/gif',
			'image/webp',
			'image/svg+xml',
			'image/avif',
			'image/bmp',
			'image/tiff',
		);

		$paged = 1;

		$query_args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => $mime_types,
			'posts_per_page' => $batch_size,
			'paged'          => $paged,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		);

		while ( true ) {
			$query_args['paged'] = $paged;
			$q = new WP_Query( $query_args );

			if ( empty( $q->posts ) ) {
				wp_reset_postdata();
				break;
			}

			foreach ( $q->posts as $id ) {
				yield (int) $id;
			}

			wp_reset_postdata();

			if ( function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}

			$paged++;
		}
	}

	private function get_all_used_attachments() {
		if ( null !== $this->used_attachments_cache ) {
			return $this->used_attachments_cache;
		}

		$cache_key = 'cbic_all_used_attachments';
		$cached    = wp_cache_get( $cache_key, $this->cache_group );

		if ( false !== $cached ) {
			$this->used_attachments_cache = $cached;
			return $this->used_attachments_cache;
		}

		$used_attachments = array();

		$used_attachments = array_merge( $used_attachments, $this->get_site_icons() );
		$used_attachments = array_merge( $used_attachments, $this->get_featured_images() );
		$used_attachments = array_merge( $used_attachments, $this->get_content_images() );
		$used_attachments = array_merge( $used_attachments, $this->get_attached_images() );

		if ( class_exists( 'WooCommerce' ) ) {
			$used_attachments = array_merge( $used_attachments, $this->get_woocommerce_images() );
		}
		$used_attachments = array_merge( $used_attachments, $this->get_widget_images() );
		$used_attachments = array_merge( $used_attachments, $this->get_theme_option_images() );
		$used_attachments = array_merge( $used_attachments, $this->get_taxonomy_images() );
		$used_attachments = array_merge( $used_attachments, $this->get_meta_images() );
		$used_attachments = array_merge( $used_attachments, $this->get_menu_images() );
		$used_attachments = apply_filters( 'cbic_ic_used_attachments', $used_attachments );
		$used_attachments = array_unique( array_filter( array_map( 'absint', $used_attachments ) ) );

		$this->used_attachments_cache = array_flip( $used_attachments );

		wp_cache_set( $cache_key, $this->used_attachments_cache, $this->cache_group, 300 );

		return $this->used_attachments_cache;
	}

	private function get_site_icons() {
		$icons = array();

		$custom_logo = get_theme_mod( 'custom_logo' );
		if ( $custom_logo ) {
			$icons[] = $custom_logo;
		}

		$site_icon = get_option( 'site_icon' );
		if ( $site_icon ) {
			$icons[] = $site_icon;
		}

		return $icons;
	}

	private function get_featured_images() {
		global $wpdb;

		$cache_key = 'cbic_featured_images';
		$cached    = wp_cache_get( $cache_key, $this->cache_group );

		if ( false !== $cached ) {
			return $cached;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$query = $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != %s AND meta_value != %s",
			'_thumbnail_id',
			'',
			'0'
		);

		$featured_images = $wpdb->get_col( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$featured_images = array_map( 'absint', $featured_images );

		wp_cache_set( $cache_key, $featured_images, $this->cache_group, 300 );

		return $featured_images;
	}

	private function get_content_images( $batch_size = 200 ) {
		global $wpdb;

		$cache_key = 'cbic_content_images';
		$cached    = wp_cache_get( $cache_key, $this->cache_group );

		if ( false !== $cached ) {
			return $cached;
		}

		$used_attachments = array();

		$statuses = array( 'publish', 'draft', 'private', 'future', 'pending' );

		$attachment_url_cache = array();

		$paged = 1;

		$query_args = array(
			'post_type'      => 'any',
			'post_status'    => $statuses,
			'posts_per_page' => (int) $batch_size,
			'paged'          => $paged,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		);

		while ( true ) {
			$query_args['paged'] = $paged;

			$q = new WP_Query( $query_args );

			if ( empty( $q->posts ) ) {
				wp_reset_postdata();
				break;
			}

			$ids = $q->posts;

			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$sql = "SELECT ID, post_content FROM {$wpdb->posts} WHERE ID IN ($placeholders)";

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$query_prepared = $wpdb->prepare( $sql, ...$ids ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$posts = $wpdb->get_results( $query_prepared, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			foreach ( $posts as $post ) {
				$content = isset( $post['post_content'] ) ? $post['post_content'] : '';

				if ( preg_match_all( '/"id":\s*(\d+)/', $content, $matches ) ) {
					foreach ( $matches[1] as $id ) {
						$id = absint( $id );
						if ( $id > 0 && $this->is_valid_attachment( $id ) ) {
							$used_attachments[] = $id;
						}
					}
				}

				if ( preg_match_all( '/wp-image-(\d+)/', $content, $matches ) ) {
					foreach ( $matches[1] as $id ) {
						$id = absint( $id );
						if ( $id > 0 && $this->is_valid_attachment( $id ) ) {
							$used_attachments[] = $id;
						}
					}
				}

				if ( preg_match_all( '/\[gallery[^\]]*ids=["\']([^"\']+)["\']/', $content, $matches ) ) {
					foreach ( $matches[1] as $ids_string ) {
						$ids_list = explode( ',', $ids_string );
						foreach ( $ids_list as $id ) {
							$id = absint( trim( $id ) );
							if ( $id > 0 && $this->is_valid_attachment( $id ) ) {
								$used_attachments[] = $id;
							}
						}
					}
				}

				if ( preg_match_all( '/\[caption[^\]]*id=["\']attachment_(\d+)["\']/', $content, $matches ) ) {
					foreach ( $matches[1] as $id ) {
						$id = absint( $id );
						if ( $id > 0 && $this->is_valid_attachment( $id ) ) {
							$used_attachments[] = $id;
						}
					}
				}

				$upload_dir = wp_get_upload_dir();
				$upload_url = $upload_dir['baseurl'];

				if ( preg_match_all(
					'/' . preg_quote( $upload_url, '/' ) . '\/[^\s"\'<>\[\]]+\.(jpe?g|png|gif|webp|svg|avif|bmp|tiff?)/i',
					$content,
					$matches
				) ) {
					foreach ( $matches[0] as $url ) {
						if ( isset( $attachment_url_cache[ $url ] ) ) {
							$attachment_id = $attachment_url_cache[ $url ];
						} else {
							$attachment_id = attachment_url_to_postid( $url );
							$attachment_url_cache[ $url ] = $attachment_id;
						}

						if ( $attachment_id > 0 ) {
							$used_attachments[] = $attachment_id;
						}
					}
				}
			}

			wp_reset_postdata();

			if ( function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}

			$paged++;
		}

		$used_attachments = array_unique( array_filter( array_map( 'absint', $used_attachments ) ) );

		$used_attachments = array_values( array_filter( $used_attachments, array( $this, 'is_valid_attachment' ) ) );

		wp_cache_set( $cache_key, $used_attachments, $this->cache_group, 300 );

		return $used_attachments;
	}

	private function get_attached_images() {
		global $wpdb;

		$cache_key = 'cbic_attached_images';
		$cached    = wp_cache_get( $cache_key, $this->cache_group );

		if ( false !== $cached ) {
			return $cached;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$query = $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_parent > %d",
			'attachment',
			0
		);

		$attached_images = $wpdb->get_col( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$attached_images = array_map( 'absint', $attached_images );

		wp_cache_set( $cache_key, $attached_images, $this->cache_group, 300 );

		return $attached_images;
	}

	private function get_woocommerce_images() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return array();
		}

		global $wpdb;

		$cache_key = 'cbic_woocommerce_images';
		$cached    = wp_cache_get( $cache_key, $this->cache_group );

		if ( false !== $cached ) {
			return $cached;
		}

		$used_attachments = array();

		$meta_keys              = array( '_thumbnail_id', '_product_image_gallery' );
		$meta_keys_placeholders = implode( ', ', array_fill( 0, count( $meta_keys ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT pm.meta_value 
		FROM {$wpdb->postmeta} pm 
		INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID 
		WHERE p.post_type = %s 
		AND pm.meta_key IN ($meta_keys_placeholders) 
		AND pm.meta_value != %s 
		AND pm.meta_value != %s";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$prepare_args = array_merge(
			array( 'product' ),
			$meta_keys,
			array( '', '0' )
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$query          = $wpdb->prepare( $sql, ...$prepare_args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$product_images = $wpdb->get_col( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( $product_images as $image_data ) {
			if ( false !== strpos( $image_data, ',' ) ) {
				// Handle gallery images (comma-separated IDs)
				$ids = explode( ',', $image_data );
				foreach ( $ids as $id ) {
					$id = absint( trim( $id ) );
					if ( $id > 0 ) {
						$used_attachments[] = $id;
					}
				}
			} else {
				$id = absint( $image_data );
				if ( $id > 0 ) {
					$used_attachments[] = $id;
				}
			}
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$query = $wpdb->prepare(
			"SELECT pm.meta_value 
			FROM {$wpdb->postmeta} pm 
			INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID 
			WHERE p.post_type = %s 
			AND pm.meta_key = %s 
			AND pm.meta_value != %s 
			AND pm.meta_value != %s",
			'product_variation',
			'_thumbnail_id',
			'',
			'0'
		);

		$variation_images = $wpdb->get_col( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( $variation_images as $id ) {
			$id = absint( $id );
			if ( $id > 0 ) {
				$used_attachments[] = $id;
			}
		}

		$product_types              = array( 'product', 'product_variation' );
		$product_types_placeholders = implode( ', ', array_fill( 0, count( $product_types ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT a.ID 
		FROM {$wpdb->posts} a 
		INNER JOIN {$wpdb->posts} p ON a.post_parent = p.ID 
		WHERE a.post_type = %s 
		AND p.post_type IN ($product_types_placeholders) 
		AND a.post_mime_type LIKE %s";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$prepare_args = array_merge(
			array( 'attachment' ),
			$product_types,
			array( 'image/%' )
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$query                   = $wpdb->prepare( $sql, ...$prepare_args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$attached_product_images = $wpdb->get_col( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( $attached_product_images as $id ) {
			$id = absint( $id );
			if ( $id > 0 ) {
				$used_attachments[] = $id;
			}
		}

		$used_attachments = array_unique( $used_attachments );

		wp_cache_set( $cache_key, $used_attachments, $this->cache_group, 300 );

		return $used_attachments;
	}

	private function get_widget_images() {
		$used_attachments = array();

		$widget_options = wp_load_alloptions();
		foreach ( $widget_options as $option_name => $option_value ) {
			if ( 0 === strpos( $option_name, 'widget_' ) ) {
				$widget_data = maybe_unserialize( $option_value );
				if ( is_array( $widget_data ) ) {
					$used_attachments = array_merge( $used_attachments, $this->extract_ids_from_data( $widget_data ) );
				}
			}
		}

		$sidebars = get_option( 'sidebars_widgets', array() );

		if ( ! is_array( $sidebars ) ) {
			$sidebars = array();
		}

		foreach ( $sidebars as $sidebar_id => $widget_ids ) {
			if ( 'wp_inactive_widgets' === $sidebar_id || 'array_version' === $sidebar_id ) {
				continue;
			}

			if ( is_array( $widget_ids ) ) {
				foreach ( $widget_ids as $widget_id ) {
					if ( 0 === strpos( $widget_id, 'block-' ) ) {
						$widget_content = get_option( 'widget_block', array() );
						$block_id       = (int) str_replace( 'block-', '', $widget_id );

						if ( isset( $widget_content[ $block_id ]['content'] ) ) {
							$block_content = $widget_content[ $block_id ]['content'];
							if ( preg_match_all( '/"id":(\d+)/', $block_content, $matches ) ) {
								foreach ( $matches[1] as $id ) {
									$id = absint( $id );
									if ( $id > 0 && $this->is_valid_attachment( $id ) ) {
										$used_attachments[] = $id;
									}
								}
							}
						}
					}
				}
			}
		}

		return $used_attachments;
	}

	private function get_theme_option_images() {
		$used_attachments = array();

		$theme_mods = get_theme_mods();
		if ( is_array( $theme_mods ) ) {
			$used_attachments = array_merge( $used_attachments, $this->extract_ids_from_data( $theme_mods ) );
		}

		$theme_options = get_option( get_option( 'stylesheet' ) . '_theme_options', array() );
		if ( is_array( $theme_options ) ) {
			$used_attachments = array_merge( $used_attachments, $this->extract_ids_from_data( $theme_options ) );
		}

		return $used_attachments;
	}

	private function get_taxonomy_images() {
		global $wpdb;

		$cache_key = 'cbic_taxonomy_images';
		$cached    = wp_cache_get( $cache_key, $this->cache_group );

		if ( false !== $cached ) {
			return $cached;
		}

		$used_attachments = array();

		$meta_patterns = array( '%image%', '%thumbnail%', '%attachment%', '%logo%', '%icon%' );
		$like_clauses  = array_fill( 0, count( $meta_patterns ), 'meta_key LIKE %s' );
		$like_clause   = implode( ' OR ', $like_clauses );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT meta_value FROM {$wpdb->termmeta} WHERE $like_clause";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$query     = $wpdb->prepare( $sql, ...$meta_patterns ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$term_meta = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( $term_meta as $meta ) {
			$meta_data        = maybe_unserialize( $meta['meta_value'] );
			$used_attachments = array_merge( $used_attachments, $this->extract_ids_from_data( $meta_data ) );
		}

		$used_attachments = array_unique( $used_attachments );

		wp_cache_set( $cache_key, $used_attachments, $this->cache_group, 300 );

		return $used_attachments;
	}

	private function get_meta_images() {
		global $wpdb;

		$cache_key = 'cbic_meta_images';
		$cached    = wp_cache_get( $cache_key, $this->cache_group );

		if ( false !== $cached ) {
			return $cached;
		}

		$used_attachments = array();

		$meta_patterns = array( '%image%', '%thumbnail%', '%attachment%', '%logo%', '%icon%', '%gallery%', '%media%' );
		$like_clauses  = array_fill( 0, count( $meta_patterns ), 'meta_key LIKE %s' );
		$like_clause   = implode( ' OR ', $like_clauses );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT meta_value FROM {$wpdb->postmeta} WHERE $like_clause";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$query        = $wpdb->prepare( $sql, ...$meta_patterns ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$meta_results = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( $meta_results as $meta ) {
			$meta_data        = maybe_unserialize( $meta['meta_value'] );
			$used_attachments = array_merge( $used_attachments, $this->extract_ids_from_data( $meta_data ) );
		}

		$used_attachments = array_unique( $used_attachments );

		wp_cache_set( $cache_key, $used_attachments, $this->cache_group, 300 );

		return $used_attachments;
	}

	private function get_menu_images() {
		$used_attachments = array();
		$menus            = wp_get_nav_menus();

		foreach ( $menus as $menu ) {
			$menu_items = wp_get_nav_menu_items( $menu );
			if ( $menu_items ) {
				foreach ( $menu_items as $item ) {
					$menu_item_meta = get_post_meta( $item->ID );
					foreach ( $menu_item_meta as $meta_values ) {
						foreach ( $meta_values as $meta_value ) {
							$meta_data        = maybe_unserialize( $meta_value );
							$used_attachments = array_merge( $used_attachments, $this->extract_ids_from_data( $meta_data ) );
						}
					}
				}
			}
		}

		return $used_attachments;
	}

	private function extract_ids_from_data( $data ) {
		$ids = array();

		if ( is_numeric( $data ) && absint( $data ) > 0 ) {
			if ( $this->is_valid_attachment( absint( $data ) ) ) {
				$ids[] = absint( $data );
			}
		} elseif ( is_string( $data ) ) {
			if ( preg_match_all( '/(?:^|\D)(\d+)(?:\D|$)/', $data, $matches ) ) {
				foreach ( $matches[1] as $potential_id ) {
					$id = absint( $potential_id );
					if ( $id > 0 && $this->is_valid_attachment( $id ) ) {
						$ids[] = $id;
					}
				}
			}
		} elseif ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				if ( in_array( $key, array( 'id', 'attachment_id', 'image_id', 'thumbnail_id', 'logo_id', 'icon_id', 'media_id' ), true ) ) {
					if ( is_numeric( $value ) && absint( $value ) > 0 ) {
						if ( $this->is_valid_attachment( absint( $value ) ) ) {
							$ids[] = absint( $value );
						}
					}
				}

				$ids = array_merge( $ids, $this->extract_ids_from_data( $value ) );
			}
		} elseif ( is_object( $data ) ) {
			$ids = array_merge( $ids, $this->extract_ids_from_data( (array) $data ) );
		}

		return $ids;
	}

	private function is_attachment_used( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( $attachment_id <= 0 ) {
			return false;
		}

		$this->clear_cache();
		$used_attachments = $this->get_all_used_attachments();

		return isset( $used_attachments[ $attachment_id ] );
	}

	private function is_valid_attachment( $id ) {
		$id = absint( $id );
		if ( $id <= 0 ) {
			return false;
		}

		$attachment = get_post( $id );
		return $attachment && 'attachment' === $attachment->post_type;
	}

	private function clear_cache() {
		$this->used_attachments_cache = null;
		wp_cache_delete( 'cbic_all_used_attachments', $this->cache_group );
		wp_cache_delete( 'cbic_all_image_attachments', $this->cache_group );
		wp_cache_delete( 'cbic_featured_images', $this->cache_group );
		wp_cache_delete( 'cbic_content_images', $this->cache_group );
		wp_cache_delete( 'cbic_attached_images', $this->cache_group );
		wp_cache_delete( 'cbic_woocommerce_images', $this->cache_group );
		wp_cache_delete( 'cbic_taxonomy_images', $this->cache_group );
		wp_cache_delete( 'cbic_meta_images', $this->cache_group );

		delete_transient( self::TRANSIENT_KEY );
	}

	public function get_stored_unused_count() {
		return (int) get_option( self::TOTAL_COUNT_OPTION, 0 );
	}

	public function get_last_scan_timestamp() {
		return get_option( self::LAST_SCAN_OPTION, false );
	}
}