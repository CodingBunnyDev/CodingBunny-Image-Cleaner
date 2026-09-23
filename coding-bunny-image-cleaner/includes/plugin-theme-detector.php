<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CBIC_Plugin_Theme_Detector {

	private $cache_group = 'cbic_plugin_detector';

	private static $known_mappings = array(
		// SEO Plugins
		'rank-math'                    => 'Rank Math SEO',
		'rank_math'                    => 'Rank Math SEO',
		'yoast'                        => 'Yoast SEO',
		'wpseo'                        => 'Yoast SEO',
		'aioseo'                       => 'All in One SEO',
		'aioseop'                      => 'All in One SEO',
		
		// Page Builders
		'elementor'                    => 'Elementor',
		'_elementor'                   => 'Elementor',
		'wpbakery'                     => 'WPBakery',
		'vc_'                          => 'WPBakery',
		'divi'                         => 'Divi Builder',
		'et_'                          => 'Divi Builder',
		'beaver'                       => 'Beaver Builder',
		'fl_'                          => 'Beaver Builder',
		'oxygen'                       => 'Oxygen Builder',
		'bricks'                       => 'Bricks Builder',
		'gutenberg'                    => 'Gutenberg',
		'brizy'                        => 'Brizy',
		
		// WooCommerce
		'woocommerce'                  => 'WooCommerce',
		'wc_'                          => 'WooCommerce',
		'_product'                     => 'WooCommerce',
		
		// Forms
		'wpforms'                      => 'WPForms',
		'gravity'                      => 'Gravity Forms',
		'gform'                        => 'Gravity Forms',
		'ninja'                        => 'Ninja Forms',
		'formidable'                   => 'Formidable Forms',
		'contact-form-7'               => 'Contact Form 7',
		'wpcf7'                        => 'Contact Form 7',
		'fluentform'                   => 'Fluent Forms',
		
		// ACF
		'acf'                          => 'Advanced Custom Fields',
		'_acf'                         => 'Advanced Custom Fields',
		
		// Popular Plugins
		'slider'                       => 'Slider Revolution',
		'revslider'                    => 'Slider Revolution',
		'layerslider'                  => 'LayerSlider',
		'smartslider'                  => 'Smart Slider',
		'jetpack'                      => 'Jetpack',
		'wordfence'                    => 'Wordfence Security',
		'updraft'                      => 'UpdraftPlus',
		'wp-rocket'                    => 'WP Rocket',
		'litespeed'                    => 'LiteSpeed Cache',
		'w3-total-cache'               => 'W3 Total Cache',
		'wp-super-cache'               => 'WP Super Cache',
		'autoptimize'                  => 'Autoptimize',
		'smush'                        => 'Smush',
		'imagify'                      => 'Imagify',
		'shortpixel'                   => 'ShortPixel',
		'ewww'                         => 'EWWW Image Optimizer',
		'mailchimp'                    => 'Mailchimp',
		'newsletter'                   => 'Newsletter',
		'redirection'                  => 'Redirection',
		'all-in-one-wp-migration'      => 'All-in-One WP Migration',
		'duplicator'                   => 'Duplicator',
		'monarch'                      => 'Monarch Social Sharing',
		'social-warfare'               => 'Social Warfare',
		
		// Themes (Common)
		'avada'                        => 'Avada Theme',
		'enfold'                       => 'Enfold Theme',
		'flatsome'                     => 'Flatsome Theme',
		'astra'                        => 'Astra Theme',
		'oceanwp'                      => 'OceanWP Theme',
		'generatepress'                => 'GeneratePress Theme',
		'storefront'                   => 'Storefront Theme',
		'kadence'                      => 'Kadence Theme',
		'neve'                         => 'Neve Theme',
		'blocksy'                      => 'Blocksy Theme',
		'hello'                        => 'Hello Elementor Theme',
		
		// Membership & LMS
		'memberpress'                  => 'MemberPress',
		'pmpro'                        => 'Paid Memberships Pro',
		'learndash'                    => 'LearnDash',
		'lifterlms'                    => 'LifterLMS',
		'tutor'                        => 'Tutor LMS',
		
		// Other Popular
		'event'                        => 'Events Calendar',
		'tribe'                        => 'Events Calendar',
		'bbpress'                      => 'bbPress',
		'buddypress'                   => 'BuddyPress',
		'bp_'                          => 'BuddyPress',
		'polylang'                     => 'Polylang',
		'wpml'                         => 'WPML',
		'translatepress'               => 'TranslatePress',
	);

	public function detect_from_source( $source ) {
		if ( empty( $source ) || ! is_string( $source ) ) {
			return $source;
		}

		$cache_key = 'detected_' . md5( $source );
		$cached    = wp_cache_get( $cache_key, $this->cache_group );

		if ( false !== $cached ) {
			return $cached;
		}

		$detected = $this->perform_detection( $source );

		wp_cache_set( $cache_key, $detected, $this->cache_group, HOUR_IN_SECONDS );

		return $detected;
	}

	private function perform_detection( $source ) {
		$source_lower = strtolower( $source );

		foreach ( self::$known_mappings as $pattern => $name ) {
			if ( false !== strpos( $source_lower, $pattern ) ) {
				return $name;
			}
		}

		$active_plugin = $this->check_active_plugins( $source_lower );
		if ( $active_plugin ) {
			return $active_plugin;
		}

		$active_theme = $this->check_active_theme( $source_lower );
		if ( $active_theme ) {
			return $active_theme;
		}

		$cleaned = $this->clean_source_name( $source );
		
		return $cleaned;
	}

	private function check_active_plugins( $source_lower ) {
		static $plugins_cache = null;

		if ( null === $plugins_cache ) {
			$plugins_cache = array();
			
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$all_plugins    = get_plugins();
			$active_plugins = get_option( 'active_plugins', array() );

			foreach ( $active_plugins as $plugin_path ) {
				if ( isset( $all_plugins[ $plugin_path ] ) ) {
					$plugin_slug = dirname( $plugin_path );
					if ( '.' === $plugin_slug ) {
						$plugin_slug = basename( $plugin_path, '.php' );
					}
					
					$plugin_name = $all_plugins[ $plugin_path ]['Name'];
					$plugins_cache[ strtolower( $plugin_slug ) ] = $plugin_name;
				}
			}
		}

		foreach ( $plugins_cache as $slug => $name ) {
			if ( false !== strpos( $source_lower, $slug ) ) {
				return $name;
			}
		}

		return false;
	}

	private function check_active_theme( $source_lower ) {
		static $theme_cache = null;

		if ( null === $theme_cache ) {
			$theme_cache = array();
			
			$current_theme = wp_get_theme();
			$parent_theme  = $current_theme->parent();

			$theme_cache[ strtolower( $current_theme->get_stylesheet() ) ] = $current_theme->get( 'Name' );
			
			if ( $parent_theme ) {
				$theme_cache[ strtolower( $parent_theme->get_stylesheet() ) ] = $parent_theme->get( 'Name' );
			}
		}

		foreach ( $theme_cache as $slug => $name ) {
			if ( false !== strpos( $source_lower, $slug ) ) {
				return $name;
			}
		}

		return false;
	}

	private function clean_source_name( $source ) {
		$source = preg_replace( '/^(widget_|theme_mod_|option_|meta_|taxonomy_)/', '', $source );

		$source = str_replace( array( '_', '-' ), ' ', $source );

		$source = ucwords( $source );

		if ( strlen( $source ) > 50 ) {
			$source = substr( $source, 0, 47 ) . '...';
		}

		return $source;
	}

	public function detect_from_meta_key( $meta_key ) {
		return $this->detect_from_source( $meta_key );
	}

	public function detect_from_option( $option_name ) {
		return $this->detect_from_source( $option_name );
	}

	public function detect_from_taxonomy( $taxonomy ) {
		$source = preg_replace( '/^taxonomy_/', '', $taxonomy );
		return $this->detect_from_source( $source );
	}

	public function clear_cache() {
		wp_cache_flush_group( $this->cache_group );
	}

	public static function add_mapping( $pattern, $name ) {
		self::$known_mappings[ strtolower( $pattern ) ] = $name;
	}

	public static function get_mappings() {
		return self::$known_mappings;
	}
}