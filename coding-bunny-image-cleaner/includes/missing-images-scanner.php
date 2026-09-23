<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CBIC_Missing_Images_Scanner {

	private $cache_group        = 'cbic_image_cleaner';
	private $missing_cache_key  = 'cbic_ic_missing_scan_cache_v1';
	private $missing_cache_ttl  = 600;
	private $detector           = null;

	private $count_option_name = 'cbic_ic_missing_total_count';
	private $count_last_scan_option_name = 'cbic_ic_missing_total_last_scan';

	public function __construct( $args = array() ) {
		if ( isset( $args['cache_group'] ) ) {
			$this->cache_group = $args['cache_group'];
		}
		if ( isset( $args['cache_key'] ) ) {
			$this->missing_cache_key = $args['cache_key'];
		}
		if ( isset( $args['ttl'] ) ) {
			$this->missing_cache_ttl = (int) $args['ttl'];
		}

		$detector_file = plugin_dir_path( __FILE__ ) . 'plugin-theme-detector.php';
		if ( file_exists( $detector_file ) ) {
			require_once $detector_file;
			$this->detector = new CBIC_Plugin_Theme_Detector();
		}
	}

	public function get_missing_images_cached( $force = false ) {
		$key = $this->missing_cache_key;

		if ( ! $force ) {
			$cached = wp_cache_get( $key, $this->cache_group );
			if ( false !== $cached ) {
				return $cached;
			}
			$transient = get_transient( $key );
			if ( false !== $transient ) {
				wp_cache_set( $key, $transient, $this->cache_group, $this->missing_cache_ttl );
				return $transient;
			}
		}

		$data = $this->find_missing_images();

		$this->store_missing_count( count( $data ) );

		wp_cache_set( $key, $data, $this->cache_group, $this->missing_cache_ttl );
		set_transient( $key, $data, $this->missing_cache_ttl );

		if ( class_exists( 'CBIC_DB_Manager' ) ) {
			$manager = CBIC_DB_Manager::instance();
			$run_id  = $manager->start_run( 'missing', array(), null );

			$items = array();
			foreach ( $data as $row ) {
				$items[] = array(
					'run_id'      => $run_id,
					'object_id'   => isset( $row['post_id'] ) ? (int) $row['post_id'] : null,
					'object_type' => isset( $row['post_id'] ) ? 'post' : null,
					'issue_type'  => 'missing',
					'group_key'   => null,
					'data'        => $row,
				);
			}
			if ( ! empty( $items ) ) {
				$manager->bulk_insert_items( $items );
			}
			$manager->complete_run( $run_id, 'completed' );
		}

		return $data;
	}

	public function invalidate_cache() {
		$key = $this->missing_cache_key;
		wp_cache_delete( $key, $this->cache_group );
		delete_transient( $key );

		foreach ( array( $this->missing_cache_key . '_basic', $this->missing_cache_key . '_deep' ) as $legacy_key ) {
			wp_cache_delete( $legacy_key, $this->cache_group );
			delete_transient( $legacy_key );
		}

		if ( $this->detector ) {
			$this->detector->clear_cache();
		}
	}

	private function store_missing_count( $count ) {
		$count = (int) $count;
		$time  = time();

		if ( is_multisite() ) {
			update_site_option( $this->count_option_name, $count );
			update_site_option( $this->count_last_scan_option_name, $time );
		} else {
			update_option( $this->count_option_name, $count );
			update_option( $this->count_last_scan_option_name, $time );
		}
	}

	public function get_stored_missing_count() {
		if ( is_multisite() ) {
			return (int) get_site_option( $this->count_option_name, 0 );
		}
		return (int) get_option( $this->count_option_name, 0 );
	}

	public function get_last_scan_timestamp() {
		if ( is_multisite() ) {
			return get_site_option( $this->count_last_scan_option_name, false );
		}
		return get_option( $this->count_last_scan_option_name, false );
	}

	private function find_missing_images() {
		$upload_dir = wp_get_upload_dir();
		$base_url   = $upload_dir['baseurl'];
		$base_dir   = $upload_dir['basedir'];

		$statuses = apply_filters( 'cbic_ic_missing_statuses', array( 'publish', 'draft', 'private', 'pending', 'future', 'inherit' ) );
		$excluded = apply_filters( 'cbic_ic_missing_excluded_types', array( 'revision', 'nav_menu_item', 'custom_css', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_glob[...]' ) );
		$batch    = (int) apply_filters( 'cbic_ic_missing_batch_size', 400 );

		$image_to_posts = array();
		$image_sources  = array();

		foreach ( $this->get_post_ids_in_batches( $statuses, $excluded, $batch ) as $ids ) {
			foreach ( $ids as $pid ) {
				$post = get_post( $pid );
				if ( ! $post ) {
					continue;
				}

				$thumb_id = get_post_thumbnail_id( $pid );
				if ( $thumb_id ) {
					$fu = wp_get_attachment_url( $thumb_id );
					if ( $fu ) {
						$image_to_posts[ $fu ][ $pid ] = true;
					}
				}

				$content = (string) $post->post_content;
				if ( '' !== $content ) {
					$urls = $this->extract_image_urls_from_content( $content );
					foreach ( $urls as $u ) {
						$image_to_posts[ $u ][ $pid ] = true;
					}

					if ( preg_match_all( '/\[gallery.*ids="([^"]+)"/', $content, $gm ) ) {
						foreach ( $gm[1] as $ids_str ) {
							foreach ( explode( ',', $ids_str ) as $aid ) {
								$aid = (int) $aid;
								$gu  = $aid ? wp_get_attachment_url( $aid ) : '';
								if ( $gu ) {
									$image_to_posts[ $gu ][ $pid ] = true;
								}
							}
						}
					}

					if ( preg_match_all( '/"id"\s*:\s*(\d+)/', $content, $idm ) ) {
						foreach ( $idm[1] as $aid ) {
							$aid = (int) $aid;
							$iu  = $aid ? wp_get_attachment_url( $aid ) : '';
							if ( $iu ) {
								$image_to_posts[ $iu ][ $pid ] = true;
							}
						}
					}
					if ( preg_match_all( '/"attachmentId"\s*:\s*(\d+)/i', $content, $am ) ) {
						foreach ( $am[1] as $aid ) {
							$aid = (int) $aid;
							$iu  = $aid ? wp_get_attachment_url( $aid ) : '';
							if ( $iu ) {
								$image_to_posts[ $iu ][ $pid ] = true;
							}
						}
					}
				}

				$this->collect_image_ids_from_meta( $pid, $image_to_posts );
			}
			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
		}

		$this->merge_from_meta_and_options( $image_to_posts, $image_sources );

		$widgets = get_option( 'widget_text' );
		if ( is_array( $widgets ) ) {
			foreach ( $widgets as $widget ) {
				if ( is_array( $widget ) && isset( $widget['text'] ) ) {
					$extra_urls = $this->extract_image_urls_from_content( $widget['text'] );
					foreach ( $extra_urls as $u ) {
						$image_to_posts[ $u ]['null'] = true;
						if ( ! isset( $image_sources[ $u ] ) ) {
							$image_sources[ $u ] = 'widget_text';
						}
					}
				}
			}
		}

		$custom_logo = get_theme_mod( 'custom_logo' );
		if ( $custom_logo ) {
			$lu = wp_get_attachment_url( $custom_logo );
			if ( $lu ) {
				$image_to_posts[ $lu ]['null'] = true;
				if ( ! isset( $image_sources[ $lu ] ) ) {
					$image_sources[ $lu ] = 'custom_logo';
				}
			}
		}

		$site_icon = get_option( 'site_icon' );
		if ( $site_icon ) {
			$si = wp_get_attachment_url( $site_icon );
			if ( $si ) {
				$image_to_posts[ $si ]['null'] = true;
				if ( ! isset( $image_sources[ $si ] ) ) {
					$image_sources[ $si ] = 'site_icon';
				}
			}
		}

		$taxonomies = get_taxonomies( array(), 'names' );
		foreach ( $taxonomies as $taxonomy ) {
			$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			continue;
		}
		foreach ( $terms as $term ) {
			$tid = get_term_meta( $term->term_id, 'thumbnail_id', true );
			if ( $tid ) {
				$tu = wp_get_attachment_url( $tid );
				if ( $tu ) {
					$image_to_posts[ $tu ]['null'] = true;
					if ( ! isset( $image_sources[ $tu ] ) ) {
						$image_sources[ $tu ] = 'taxonomy_' . $taxonomy;
					}
				}
			}
		}
	}

	if ( class_exists( 'WooCommerce' ) ) {
		foreach ( $this->get_post_ids_for_post_type_in_batches( 'product', $statuses, $batch ) as $product_ids ) {
			foreach ( $product_ids as $pid ) {
				$img_id = get_post_meta( $pid, '_thumbnail_id', true );
				if ( $img_id ) {
					$u = wp_get_attachment_url( (int) $img_id );
					if ( $u ) {
						$image_to_posts[ $u ][ $pid ] = true;
					}
				}

				$gallery_meta = get_post_meta( $pid, '_product_image_gallery', true );
				if ( $gallery_meta ) {
					$gallery_ids = array_filter( array_map( 'absint', explode( ',', $gallery_meta ) ) );
					foreach ( $gallery_ids as $gid ) {
						$u = wp_get_attachment_url( $gid );
						if ( $u ) {
							$image_to_posts[ $u ][ $pid ] = true;
						}
					}
				}
			}
			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
		}
	}

	foreach ( $image_to_posts as $url => &$assoc ) {
		if ( isset( $assoc['null'] ) && count( $assoc ) > 1 ) {
			unset( $assoc['null'] );
		}
	}
	unset( $assoc );

	$missing = array();
	foreach ( $image_to_posts as $img_url => $post_ids_assoc ) {
		if ( 0 !== strpos( $img_url, $base_url ) ) {
			continue;
		}
		$rel_path  = ltrim( str_replace( $base_url, '', $img_url ), '/\\' );
		$file_path = $base_dir . '/' . $rel_path;
		if ( ! is_file( $file_path ) ) {
			$pids = array_keys( $post_ids_assoc );
			if ( empty( $pids ) || ( count( $pids ) === 1 && 'null' === (string) $pids[0] ) ) {
				$source_name = isset( $image_sources[ $img_url ] ) ? $image_sources[ $img_url ] : 'Unknown';

				if ( $this->detector ) {
					$source_name = $this->detector->detect_from_source( $source_name );
				}

				$missing[] = array(
					'url'     => $img_url,
					'post_id' => null,
					'source'  => $source_name,
				);
			} else {
				foreach ( $pids as $pid ) {
					if ( 'null' !== (string) $pid ) {
						$missing[] = array(
							'url'     => $img_url,
							'post_id' => $pid,
							'source'  => null,
						);
					}
				}
			}
		}
	}

	return $missing;
}

private function get_post_ids_in_batches( array $statuses, array $excluded_types, $batch_size ) {
	global $wpdb;

	$cache_key = 'cbic_post_ids_' . md5( wp_json_encode( array( $statuses, $excluded_types ) ) );
	$cached    = wp_cache_get( $cache_key, $this->cache_group );

	if ( false === $cached ) {
		$status_placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$where_clause        = "WHERE post_status IN ($status_placeholders)";
		$params              = $statuses;

		if ( ! empty( $excluded_types ) ) {
			$excluded_placeholders = implode( ', ', array_fill( 0, count( $excluded_types ), '%s' ) );
			$where_clause         .= " AND post_type NOT IN ($excluded_placeholders)";
			$params                = array_merge( $params, $excluded_types );
		}

		$query = "SELECT COUNT(ID) FROM {$wpdb->posts} $where_clause";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$total = (int) $wpdb->get_var( $wpdb->prepare( $query, $params ) );

		wp_cache_set( $cache_key, $total, $this->cache_group, 300 );
	} else {
		$total = $cached;
	}

	$offset = 0;
	while ( $offset < $total ) {
		$batch_cache_key = $cache_key . '_batch_' . $offset;
		$ids             = wp_cache_get( $batch_cache_key, $this->cache_group );

		if ( false === $ids ) {
			$status_placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
			$where_clause        = "WHERE post_status IN ($status_placeholders)";
			$batch_params        = $statuses;

			if ( ! empty( $excluded_types ) ) {
				$excluded_placeholders = implode( ', ', array_fill( 0, count( $excluded_types ), '%s' ) );
				$where_clause         .= " AND post_type NOT IN ($excluded_placeholders)";
				$batch_params          = array_merge( $batch_params, $excluded_types );
			}

			$batch_params[] = $batch_size;
			$batch_params[] = $offset;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$query = "SELECT ID FROM {$wpdb->posts} $where_clause ORDER BY ID ASC LIMIT %d OFFSET %d";
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$ids   = $wpdb->get_col( $wpdb->prepare( $query, $batch_params ) );

			wp_cache_set( $batch_cache_key, $ids, $this->cache_group, 300 );
		}

		if ( empty( $ids ) ) {
			break;
		}

		yield $ids;
		$offset += $batch_size;
	}
}

private function get_post_ids_for_post_type_in_batches( $post_type, array $statuses, $batch_size ) {
	global $wpdb;

	$last_id = 0;
	$status_placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

	while ( true ) {
		$query = "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ($status_placeholders) AND ID > %d ORDER BY ID ASC LIMIT %d";
		$params = array_merge( array( $post_type ), $statuses, array( $last_id, $batch_size ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sql = $wpdb->prepare( $query, $params );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col( $sql );

		if ( empty( $ids ) ) {
			break;
		}

		yield $ids;

		$last_id = (int) end( $ids );
		if ( function_exists( 'wp_cache_flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}
	}
}

private function extract_image_urls_from_content( $content ) {
	$found = array();

	if ( preg_match_all( '/<(?:img|source)[^>]+(?:src|data-src|data-lazy|data-original)=["\']([^"\']+\.(?:jpe?g|png|gif|webp|svg|avif))["\']/i', $content, $m1 ) ) {
		$found = array_merge( $found, $m1[1] );
	}

	if ( preg_match_all( '/<(?:img|source)[^>]+srcset=["\']([^"\']+)["\']/i', $content, $m2 ) ) {
		foreach ( $m2[1] as $srcset ) {
			foreach ( preg_split( '/\s*,\s*/', $srcset ) as $candidate ) {
				$parts = preg_split( '/\s+/', trim( $candidate ) );
				if ( ! empty( $parts[0] ) && preg_match( '/\.(?:jpe?g|png|gif|webp|svg|avif)$/i', $parts[0] ) ) {
					$found[] = $parts[0];
				}
			}
		}
	}

	if ( preg_match_all( '/background-image\s*:\s*url\((["\']?)([^"\')]+?\.(?:jpe?g|png|gif|webp|svg|avif))\1\)/i', $content, $m3 ) ) {
		$found = array_merge( $found, $m3[2] );
	}

	if ( preg_match_all( '/data-[a-z0-9_-]*srcset=["\']([^"\']+)["\']/i', $content, $m4 ) ) {
		foreach ( $m4[1] as $srcset ) {
			foreach ( preg_split( '/\s*,\s*/', $srcset ) as $candidate ) {
				$parts = preg_split( '/\s+/', trim( $candidate ) );
				if ( ! empty( $parts[0] ) && preg_match( '/\.(?:jpe?g|png|gif|webp|svg|avif)$/i', $parts[0] ) ) {
					$found[] = $parts[0];
				}
			}
		}
	}

	if ( preg_match_all( '/https?:\/\/[^\s"\']+\.(?:jpe?g|png|gif|webp|svg|avif)/i', $content, $m5 ) ) {
		$found = array_merge( $found, $m5[0] );
	}

	return array_values( array_unique( $found ) );
}

private function merge_from_meta_and_options( array &$image_to_posts, array &$image_sources ) {
	global $wpdb;

	$regex = '\\\\.(jpg|jpeg|png|gif|webp|svg|avif)';

	$cache_key_meta = 'cbic_meta_images_' . get_current_blog_id();
	$meta_results   = wp_cache_get( $cache_key_meta, $this->cache_group );

	if ( false === $meta_results ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$query        = $wpdb->prepare( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_value REGEXP %s", $regex );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$meta_results = $wpdb->get_results( $query, ARRAY_A );

		wp_cache_set( $cache_key_meta, $meta_results, $this->cache_group, 300 );
	}

	foreach ( $meta_results as $meta ) {
		$meta_data = maybe_unserialize( $meta['meta_value'] );
		$urls      = $this->extract_image_urls_from_meta( $meta_data );
		foreach ( $urls as $u ) {
			$image_to_posts[ $u ][ (int) $meta['post_id'] ] = true;
		}
	}

	$cache_key_options = 'cbic_options_images_' . get_current_blog_id();
	$options           = wp_cache_get( $cache_key_options, $this->cache_group );

	if ( false === $options ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$query   = $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_value REGEXP %s", $regex );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$options = $wpdb->get_results( $query, ARRAY_A );

		wp_cache_set( $cache_key_options, $options, $this->cache_group, 300 );
	}

	foreach ( $options as $option ) {
		$opt_data = maybe_unserialize( $option['option_value'] );
		$urls     = $this->extract_image_urls_from_meta( $opt_data );
		foreach ( $urls as $u ) {
			$image_to_posts[ $u ]['null'] = true;
			if ( ! isset( $image_sources[ $u ] ) ) {
				$source_name = $option['option_name'];
				if ( $this->detector ) {
					$source_name = $this->detector->detect_from_option( $source_name );
				}
				$image_sources[ $u ] = $source_name;
			}
		}
	}
}

private function collect_image_ids_from_meta( $post_id, array &$image_to_posts ) {
	$scan_ids_meta = apply_filters( 'cbic_ic_missing_deep_meta_scan', true );
	if ( ! $scan_ids_meta ) {
		return;
	}
	$limit = (int) apply_filters( 'cbic_ic_missing_meta_id_scan_limit', 60 );
	$all   = get_post_meta( $post_id );
	$count = 0;
	foreach ( $all as $values ) {
		if ( ! is_array( $values ) ) {
			continue;
		}
		foreach ( $values as $val ) {
			if ( is_numeric( $val ) ) {
				$aid = (int) $val;
				if ( $aid > 0 && 0 === strpos( (string) get_post_mime_type( $aid ), 'image/' ) ) {
					$u = wp_get_attachment_url( $aid );
					if ( $u ) {
						$image_to_posts[ $u ][ $post_id ] = true;
					}
				}
				$count++;
			} elseif ( is_string( $val ) && preg_match_all( '/"id"\s*:\s*(\d+)/', $val, $idm ) ) {
				foreach ( $idm[1] as $aid ) {
					$aid = (int) $aid;
					if ( $aid > 0 && 0 === strpos( (string) get_post_mime_type( $aid ), 'image/' ) ) {
						$u = wp_get_attachment_url( $aid );
						if ( $u ) {
							$image_to_posts[ $u ][ $post_id ] = true;
						}
					}
					$count++;
					if ( $count >= $limit ) {
						break 3;
					}
				}
			}
			if ( $count >= $limit ) {
				break 2;
			}
		}
	}
}

private function extract_image_urls_from_meta( $data ) {
	$urls = array();
	if ( is_array( $data ) ) {
		foreach ( $data as $item ) {
			if ( is_string( $item ) && preg_match( '/https?:\/\/[^\s"\']+\.(?:jpe?g|png|gif|webp|svg|avif)/i', $item ) ) {
				$urls[] = $item;
			}
			if ( is_array( $item ) || is_object( $item ) ) {
				$urls = array_merge( $urls, $this->extract_image_urls_from_meta( (array) $item ) );
			}
		}
	}
	return $urls;
}
}