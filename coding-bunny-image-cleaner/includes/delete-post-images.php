<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CBIC_Delete_Post_Images {

	private $post_attachments_to_check = [];

	private $cache_group = 'cbic_delete_post_images';

	private $enabled = null;

	private $upload_baseurl = '';

	public function __construct() {
		add_action( 'before_delete_post', [ $this, 'store_post_attachments' ], 10, 2 );

		add_action( 'deleted_post', [ $this, 'cleanup_post_attachments' ], 10, 1 );
		
		add_action( 'wp_trash_post', [ $this, 'handle_trash_post' ], 10, 1 );

		$upload_dir = wp_get_upload_dir();
		$this->upload_baseurl = isset( $upload_dir['baseurl'] ) ? (string) $upload_dir['baseurl'] : '';
	}

	public function handle_trash_post( $post_id ) {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$post = get_post( $post_id );
		if ( $this->is_supported_post( $post ) ) {
			$this->store_post_attachments( $post_id, $post );
			$this->cleanup_post_attachments( $post_id );
		}
	}

	public function store_post_attachments( $post_id, $post = null ) {
		if ( ! $this->is_enabled() ) {
			return;
		}

		try {
			if ( ! $post ) {
				$post = get_post( $post_id );
			}

			if ( ! $this->is_supported_post( $post ) ) {
				return;
			}

			$attachments = $this->get_post_attachments( $post );
			if ( ! empty( $attachments ) ) {
				$this->post_attachments_to_check[ (int) $post_id ] = $attachments;
			}
		} catch ( Exception $e ) {
			do_action( 'cbic_delete_post_images_log', 'store_post_attachments_error', [ 'post_id' => $post_id, 'message' => $e->getMessage() ] );
		}
	}

	public function cleanup_post_attachments( $post_id ) {
		if ( ! $this->is_enabled() ) {
			return;
		}

		try {
			if ( ! isset( $this->post_attachments_to_check[ $post_id ] ) ) {
				$post = get_post( $post_id );
				if ( $this->is_supported_post( $post ) ) {
					$this->store_post_attachments( $post_id, $post );
				}
			}

			if ( empty( $this->post_attachments_to_check[ $post_id ] ) ) {
				return;
			}

			$attachments_to_check = $this->post_attachments_to_check[ $post_id ];
			unset( $this->post_attachments_to_check[ $post_id ] );

			if ( empty( $attachments_to_check ) ) {
				return;
			}

			$attachments_to_check = array_values( array_unique( array_filter( array_map( 'absint', (array) $attachments_to_check ) ) ) );
			$attachments_to_check = apply_filters( 'cbic_delete_post_images_attachments_to_check', $attachments_to_check, $post_id );

			if ( empty( $attachments_to_check ) ) {
				return;
			}

			$this->delete_post_unused_attachments( $attachments_to_check );
		} catch ( Exception $e ) {
			do_action( 'cbic_delete_post_images_log', 'cleanup_post_attachments_error', [ 'post_id' => $post_id, 'message' => $e->getMessage() ] );
		}
	}

	private function is_supported_post( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		if ( 'revision' === $post->post_type || 'auto-draft' === $post->post_status ) {
			return false;
		}

		$supported = in_array( $post->post_type, [ 'post', 'page' ], true );
		return (bool) apply_filters( 'cbic_delete_post_images_is_supported_post', $supported, $post );
	}

	private function get_post_attachments( WP_Post $post ) {
		$cache_key = 'post_attachments_' . $post->ID;

		$cached = wp_cache_get( $cache_key, $this->cache_group );
		if ( false !== $cached ) {
			return (array) $cached;
		}

		$attachments = [];

		try {
			$thumbnail_id = (int) get_post_thumbnail_id( $post->ID );
			if ( $thumbnail_id > 0 ) {
				$attachments[] = $thumbnail_id;
			}

			$attachments = array_merge( $attachments, $this->get_post_content_attachments( $post ) );

			$direct_attachments = get_attached_media( '', $post );
			if ( ! empty( $direct_attachments ) && is_array( $direct_attachments ) ) {
				foreach ( $direct_attachments as $attachment ) {
					if ( $attachment instanceof WP_Post ) {
						$attachments[] = (int) $attachment->ID;
					}
				}
			}

			$attachments = array_merge( $attachments, $this->get_post_meta_attachments( $post->ID ) );

			$attachments = array_values( array_unique( array_filter( array_map( 'absint', $attachments ) ) ) );
			$attachments = array_values( array_filter( $attachments, [ $this, 'is_valid_attachment' ] ) );

			$attachments = (array) apply_filters( 'cbic_delete_post_images_collected_attachments', $attachments, $post );
		} catch ( Exception $e ) {
			do_action( 'cbic_delete_post_images_log', 'get_post_attachments_error', [ 'post_id' => $post->ID, 'message' => $e->getMessage() ] );
		}

		wp_cache_set( $cache_key, $attachments, $this->cache_group, 10 * MINUTE_IN_SECONDS );

		return $attachments;
	}

	private function get_post_content_attachments( WP_Post $post ) {
		$attachments = [];
		$content     = is_string( $post->post_content ) ? $post->post_content : '';

		try {
			if ( function_exists( 'has_blocks' ) && has_blocks( $post ) && function_exists( 'parse_blocks' ) ) {
				$blocks = parse_blocks( $content );
				$attachments = array_merge( $attachments, $this->collect_ids_from_blocks( $blocks ) );
			}

			if ( preg_match_all( '/"(?:id|mediaId|backgroundMediaId)"\s*:\s*(\d+)/', $content, $matches ) ) {
				foreach ( $matches[1] as $id ) {
					$id = absint( $id );
					if ( $id > 0 ) {
						$attachments[] = $id;
					}
				}
			}

			if ( preg_match_all( '/wp-image-(\d+)/', $content, $matches ) ) {
				foreach ( $matches[1] as $id ) {
					$id = absint( $id );
					if ( $id > 0 ) {
						$attachments[] = $id;
					}
				}
			}

			if ( preg_match_all( '/\[gallery[^\]]*ids=["\']([^"\']+)["\']/', $content, $matches ) ) {
				foreach ( $matches[1] as $ids_string ) {
					$ids = array_map( 'absint', array_map( 'trim', explode( ',', $ids_string ) ) );
					foreach ( $ids as $id ) {
						if ( $id > 0 ) {
							$attachments[] = $id;
						}
					}
				}
			}

			if ( preg_match_all( '/\[caption[^\]]*id=["\']attachment_(\d+)["\']/', $content, $matches ) ) {
				foreach ( $matches[1] as $id ) {
					$id = absint( $id );
					if ( $id > 0 ) {
						$attachments[] = $id;
					}
				}
			}

			if ( $this->upload_baseurl ) {
				$pattern = '/' . preg_quote( $this->upload_baseurl, '/' ) . '\/[^\s"\'<>\[\]]+\.(?:jpe?g|png|gif|webp|svg|avif|bmp|tiff?|pdf|mp4|mov|m4v|webm|mp3|wav)/i';

				if ( preg_match_all( $pattern, $content, $matches ) ) {
					static $url_to_id = [];

					foreach ( $matches[0] as $url ) {
						if ( isset( $url_to_id[ $url ] ) ) {
							$aid = (int) $url_to_id[ $url ];
						} else {
							$aid               = (int) attachment_url_to_postid( $url );
							$url_to_id[ $url ] = $aid;
						}

						if ( $aid > 0 ) {
							$attachments[] = $aid;
						}
					}
				}
			}
		} catch ( Exception $e ) {
			do_action( 'cbic_delete_post_images_log', 'get_post_content_attachments_error', [ 'post_id' => $post->ID, 'message' => $e->getMessage() ] );
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $attachments ) ) ) );
	}

	private function collect_ids_from_blocks( array $blocks ) {
		$ids = [];

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$block_name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
			$attrs      = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [];

			switch ( $block_name ) {
				case 'core/image':
				case 'core/video':
				case 'core/audio':
				case 'core/file':
					if ( ! empty( $attrs['id'] ) && absint( $attrs['id'] ) > 0 ) {
						$ids[] = absint( $attrs['id'] );
					}
					break;

				case 'core/media-text':
					if ( ! empty( $attrs['mediaId'] ) && absint( $attrs['mediaId'] ) > 0 ) {
						$ids[] = absint( $attrs['mediaId'] );
					}
					break;

				case 'core/cover':
					if ( ! empty( $attrs['id'] ) && absint( $attrs['id'] ) > 0 ) {
						$ids[] = absint( $attrs['id'] );
					}
					if ( ! empty( $attrs['backgroundMediaId'] ) && absint( $attrs['backgroundMediaId'] ) > 0 ) {
						$ids[] = absint( $attrs['backgroundMediaId'] );
					}
					break;

				case 'core/gallery':
					if ( ! empty( $attrs['ids'] ) && is_array( $attrs['ids'] ) ) {
						foreach ( $attrs['ids'] as $id ) {
							$id = absint( $id );
							if ( $id > 0 ) {
								$ids[] = $id;
							}
						}
					}
					if ( ! empty( $attrs['images'] ) && is_array( $attrs['images'] ) ) {
						foreach ( $attrs['images'] as $img ) {
							if ( is_array( $img ) && ! empty( $img['id'] ) && absint( $img['id'] ) > 0 ) {
								$ids[] = absint( $img['id'] );
							}
						}
					}
					break;
			}

			foreach ( [ 'id', 'mediaId', 'imageId', 'attachmentId', 'thumbnailId', 'logoId', 'iconId' ] as $key ) {
				if ( ! empty( $attrs[ $key ] ) && absint( $attrs[ $key ] ) > 0 ) {
					$ids[] = absint( $attrs[ $key ] );
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$ids = array_merge( $ids, $this->collect_ids_from_blocks( $block['innerBlocks'] ) );
			}
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}

	private function get_post_meta_attachments( $post_id ) {
		$cache_key = 'meta_attachments_' . $post_id;

		$cached = wp_cache_get( $cache_key, $this->cache_group );
		if ( false !== $cached ) {
			return (array) $cached;
		}

		$attachments = [];

		try {
			$all_meta = get_post_meta( $post_id );
			if ( ! empty( $all_meta ) && is_array( $all_meta ) ) {
				foreach ( $all_meta as $meta_key => $values ) {
					if ( ! $this->looks_like_media_meta_key( (string) $meta_key ) ) {
						continue;
					}

					if ( ! is_array( $values ) ) {
						$values = [ $values ];
					}

					foreach ( $values as $raw_value ) {
						$meta_data   = maybe_unserialize( $raw_value );
						$attachments = array_merge( $attachments, $this->extract_ids_from_data( $meta_data ) );
					}
				}
			}
		} catch ( Exception $e ) {
			do_action( 'cbic_delete_post_images_log', 'get_post_meta_attachments_error', [ 'post_id' => $post_id, 'message' => $e->getMessage() ] );
		}

		$attachments = array_values( array_unique( array_filter( array_map( 'absint', $attachments ) ) ) );

		wp_cache_set( $cache_key, $attachments, $this->cache_group, 5 * MINUTE_IN_SECONDS );

		return $attachments;
	}

	private function looks_like_media_meta_key( $key ) {
		static $needles = [ 'image', 'thumbnail', 'attachment', 'logo', 'icon', 'gallery', 'media' ];

		$key_lc = strtolower( $key );
		foreach ( $needles as $needle ) {
			if ( false !== strpos( $key_lc, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	private function extract_ids_from_data( $data ) {
		$ids = [];

		try {
			if ( is_numeric( $data ) ) {
				$id = absint( $data );
				if ( $id > 0 && $this->is_valid_attachment( $id ) ) {
					$ids[] = $id;
				}
			} elseif ( is_string( $data ) ) {
				if ( preg_match( '/^\s*\d+(?:\s*,\s*\d+)*\s*$/', $data ) ) {
					foreach ( array_map( 'absint', array_map( 'trim', explode( ',', $data ) ) ) as $id ) {
						if ( $id > 0 && $this->is_valid_attachment( $id ) ) {
							$ids[] = $id;
						}
					}
				} else {
					if ( preg_match_all( '/(?:^|\D)(\d+)(?:\D|$)/', $data, $matches ) ) {
						foreach ( $matches[1] as $potential_id ) {
							$id = absint( $potential_id );
							if ( $id > 0 && $this->is_valid_attachment( $id ) ) {
								$ids[] = $id;
							}
						}
					}
				}
			} elseif ( is_array( $data ) ) {
				foreach ( [ 'id', 'attachment_id', 'image_id', 'thumbnail_id', 'logo_id', 'icon_id', 'media_id' ] as $k ) {
					if ( isset( $data[ $k ] ) && is_numeric( $data[ $k ] ) ) {
						$id = absint( $data[ $k ] );
						if ( $id > 0 && $this->is_valid_attachment( $id ) ) {
							$ids[] = $id;
						}
					}
				}

				foreach ( [ 'ids', 'image_ids', 'attachment_ids', 'media_ids' ] as $k ) {
					if ( ! empty( $data[ $k ] ) ) {
						if ( is_array( $data[ $k ] ) ) {
							foreach ( $data[ $k ] as $v ) {
								if ( is_numeric( $v ) ) {
                                    $id = absint( $v );
									if ( $id > 0 && $this->is_valid_attachment( $id ) ) {
										$ids[] = $id;
									}
								}
							}
						} elseif ( is_string( $data[ $k ] ) ) {
							foreach ( array_map( 'absint', array_map( 'trim', explode( ',', $data[ $k ] ) ) ) as $id ) {
								if ( $id > 0 && $this->is_valid_attachment( $id ) ) {
									$ids[] = $id;
								}
							}
						}
					}
				}

				foreach ( $data as $value ) {
					if ( is_array( $value ) || is_object( $value ) ) {
						$ids = array_merge( $ids, $this->extract_ids_from_data( $value ) );
					}
				}
			} elseif ( is_object( $data ) ) {
				$ids = array_merge( $ids, $this->extract_ids_from_data( (array) $data ) );
			}
		} catch ( Exception $e ) {
			do_action( 'cbic_delete_post_images_log', 'extract_ids_from_data_error', [ 'message' => $e->getMessage() ] );
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}

	private function delete_post_unused_attachments( array $attachments_to_check ) {
		try {
			if ( ! class_exists( 'CBIC_Unused_Images_Scanner' ) ) {
				$scanner_file = plugin_dir_path( __FILE__ ) . 'unused-images-scanner.php';
				if ( ! file_exists( $scanner_file ) ) {
					return 0;
				}
				include_once $scanner_file;
			}

			if ( ! class_exists( 'CBIC_Unused_Images_Scanner' ) ) {
				return 0;
			}

			$scanner = new CBIC_Unused_Images_Scanner();

			$attachments_to_check = array_values( array_unique( array_filter( array_map( 'absint', $attachments_to_check ) ) ) );
			$attachments_to_check = array_values( array_filter( $attachments_to_check, [ $this, 'is_valid_attachment' ] ) );

			if ( empty( $attachments_to_check ) ) {
				return 0;
			}
			
			$result = (int) $scanner->delete_unused_attachments( $attachments_to_check );

			foreach ( $attachments_to_check as $aid ) {
				wp_cache_delete( 'attachment_' . $aid, $this->cache_group );
			}

			return max( 0, $result );
		} catch ( Exception $e ) {
			do_action( 'cbic_delete_post_images_log', 'delete_post_unused_attachments_error', [ 'message' => $e->getMessage() ] );
			return 0;
		}
	}

	private function is_valid_attachment( $id ) {
		static $memo = [];

		$id = absint( $id );
		if ( $id <= 0 ) {
			return false;
		}

		if ( array_key_exists( $id, $memo ) ) {
			return $memo[ $id ];
		}

		try {
			$type           = get_post_type( $id );
			$memo[ $id ]    = ( 'attachment' === $type );
			return $memo[ $id ];
		} catch ( Exception $e ) {
			$memo[ $id ] = false;
			return false;
		}
	}

	private function is_enabled() {
		if ( null !== $this->enabled ) {
			return $this->enabled;
		}

		try {
			$option_on  = ( '1' === get_option( 'cbic_delete_post_images', '0' ) );
			$enabled    = $option_on;

			$this->enabled = (bool) apply_filters( 'cbic_delete_post_images_is_enabled', $enabled );
			return $this->enabled;
		} catch ( Exception $e ) {
			$this->enabled = false;
			return false;
		}
	}
}

if ( is_admin() && ! isset( $GLOBALS['cbic_delete_post_images_instance'] ) ) {
	$GLOBALS['cbic_delete_post_images_instance'] = new CBIC_Delete_Post_Images();
}