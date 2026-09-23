<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function cbic_delete_product_images_is_enabled() {
	$enabled = '1' === get_option( 'cbic_delete_product_images', '0' );

	return (bool) apply_filters( 'cbic_delete_product_images_enabled', $enabled );
}

add_action( 'before_delete_post', 'cbic_conditional_delete_product_images', 10, 1 );
add_action( 'wp_trash_post', 'cbic_conditional_delete_product_images', 10, 1 );

function cbic_conditional_delete_product_images( $post_id ) {
	if ( ! cbic_delete_product_images_is_enabled() ) {
		return;
	}

	if ( ! function_exists( 'wc_get_product' ) ) {
		return;
	}

	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post ) {
		return;
	}

	if ( 'product' !== $post->post_type ) {
		return;
	}

	if ( ! current_user_can( 'delete_post', $post_id ) ) {
		return;
	}

	$product = wc_get_product( $post_id );
	if ( ! $product ) {
		return;
	}

	$featured = (int) $product->get_image_id();
	$gallery  = array_map( 'intval', (array) $product->get_gallery_image_ids() );

	$all_ids = array_filter(
		array_unique(
			array_merge(
				$featured ? array( $featured ) : array(),
				$gallery
			)
		)
	);

	$additional_ids = apply_filters( 'cbic_product_additional_image_ids', array(), $post_id, $product );
	if ( ! empty( $additional_ids ) ) {
		$all_ids = array_unique( array_merge( $all_ids, array_map( 'intval', (array) $additional_ids ) ) );
	}

	$all_ids = apply_filters( 'cbic_product_image_ids_to_consider', $all_ids, $post_id, $product );

	if ( empty( $all_ids ) ) {
		return;
	}

	$used_elsewhere = cbic_get_used_product_image_ids( $all_ids, $post_id );

	foreach ( $all_ids as $img_id ) {
		$img_id = (int) $img_id;

		if ( in_array( $img_id, $used_elsewhere, true ) ) {
			continue;
		}

		if ( ! cbic_is_product_image_deletable( $img_id, $post_id, $product ) ) {
			continue;
		}

		$can_delete = apply_filters( 'cbic_can_delete_product_image', true, $img_id, $post_id, $product );
		if ( ! $can_delete ) {
			continue;
		}

		wp_delete_attachment( $img_id, true );
	}
}

function cbic_get_used_product_image_ids( $img_ids, $current_id ) {
	$img_ids    = array_values( array_unique( array_filter( array_map( 'intval', (array) $img_ids ) ) ) );
	$current_id = (int) $current_id;

	if ( empty( $img_ids ) ) {
		return array();
	}

	$cache_group = 'cbic_product_images';
	$cache_key   = 'used_ids_' . $current_id . '_' . md5( implode( ',', $img_ids ) );

	$cached = wp_cache_get( $cache_key, $cache_group );
	if ( false !== $cached ) {
		return (array) $cached;
	}

	global $wpdb;
	$used = array();
	
	$placeholders   = implode( ',', array_fill( 0, count( $img_ids ), '%d' ) );
	$prepare_values = array_merge( $img_ids, array( $current_id ) );
	
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows_featured = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT p.ID, p.post_type, p.post_parent, pm.meta_value
			   FROM {$wpdb->postmeta} pm
			   INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			  WHERE pm.meta_key = '_thumbnail_id'
			    AND pm.meta_value IN ($placeholders)
			    AND p.post_type IN ('product','product_variation')
			    AND p.ID <> %d",
			$prepare_values
		)
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	if ( ! empty( $rows_featured ) ) {
		foreach ( $rows_featured as $row ) {
			$pid         = (int) $row->ID;
			$post_type   = (string) $row->post_type;
			$post_parent = (int) $row->post_parent;
			$thumb_id    = (int) $row->meta_value;

			if ( 'product_variation' === $post_type && $post_parent === $current_id ) {
				continue;
			}

			if ( $thumb_id > 0 && in_array( $thumb_id, $img_ids, true ) ) {
				$used[] = $thumb_id;
			}
		}
	}

	$ids_pattern = implode( '|', array_map( 'intval', $img_ids ) );
	$regex       = '(^|,)(' . $ids_pattern . ')(,|$)';

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows_gallery = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT pm.meta_value
			   FROM {$wpdb->postmeta} pm
			   INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			  WHERE pm.meta_key = '_product_image_gallery'
			    AND pm.meta_value <> ''
			    AND p.post_type = 'product'
			    AND pm.post_id <> %d
			    AND pm.meta_value REGEXP %s",
			$current_id,
			$regex
		)
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	if ( ! empty( $rows_gallery ) ) {
		foreach ( $rows_gallery as $row ) {
			$g_ids = array_map( 'intval', array_filter( array_map( 'trim', explode( ',', (string) $row->meta_value ) ) ) );
			if ( ! empty( $g_ids ) ) {
				$used = array_merge( $used, array_values( array_intersect( $img_ids, $g_ids ) ) );
			}
		}
	}

	$used = array_values( array_unique( $used ) );

	wp_cache_set( $cache_key, $used, $cache_group, 5 * MINUTE_IN_SECONDS );

	return $used;
}

function cbic_is_product_image_deletable( $attachment_id, $product_id, $product ) {
	$attachment_id = (int) $attachment_id;

	$attachment = get_post( $attachment_id );
	if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
		return false;
	}

	$mime = get_post_mime_type( $attachment );
	if ( ! is_string( $mime ) ) {
		return false;
	}

	$allowed_mimes = apply_filters(
		'cbic_delete_product_images_allowed_mime_types',
		array(
			'image/jpeg',
			'image/png',
			'image/gif',
			'image/webp',
			'image/svg+xml',
			'image/avif',
			'image/bmp',
			'image/tiff',
		),
		$attachment_id,
		$product_id,
		$product
	);

	if ( empty( $allowed_mimes ) ) {
		return (bool) wp_attachment_is_image( $attachment_id );
	}

	return in_array( $mime, (array) $allowed_mimes, true );
}