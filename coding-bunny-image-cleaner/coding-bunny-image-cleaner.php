<?php
/**
 * Plugin Name: CodingBunny Image Cleaner
 * Description: An add-on for CodingBunny Image Optimizer to keep your Media Library clean by finding and removing unused, orphaned, duplicate, and missing images.
 * Version:     1.2.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author:      CodingBunny
 * Text Domain: coding-bunny-image-cleaner
 * Domain Path: /languages
 * License:     GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Requires Plugins: coding-bunny-image-optimizer
 *
 * @package CodingBunny\ImageCleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CBIC_VERSION', '1.2.0' );
define( 'CBIC_PLUGIN_FILE', __FILE__ );
define( 'CBIC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CBIC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CBIC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

if ( ! defined( 'CBIC_DB_VERSION' ) ) {
	define( 'CBIC_DB_VERSION', '1.0.0' );
}

class CodingBunnyImageCleaner {

	private static $instance = null;

	private $admin_dir;

	private $includes_dir;

	private $is_loaded = false;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->set_paths();
		$this->register_hooks();
		$this->load_db_manager();
	}

	private function __clone() {}

	public function __wakeup() {
		throw new Exception( 'Cannot unserialize singleton' );
	}

	private function set_paths() {
		$this->admin_dir    = CBIC_PLUGIN_DIR . 'admin/';
		$this->includes_dir = CBIC_PLUGIN_DIR . 'includes/';
	}

	private function load_db_manager() {
		$db_file = $this->includes_dir . 'db-manager.php';
		if ( file_exists( $db_file ) ) {
			require_once $db_file;
		}
	}

	private function register_hooks() {
		add_action( 'plugins_loaded', array( $this, 'maybe_bootstrap' ), 20 );
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'plugins_loaded', array( $this, 'maybe_upgrade_db' ), 5 );
		add_action( 'admin_init', array( $this, 'maybe_deactivate' ) );
		add_action( 'admin_notices', array( $this, 'show_admin_notices' ) );
		add_action( 'deactivated_plugin', array( $this, 'handle_parent_deactivation' ), 10, 1 );
		add_filter( 'plugin_action_links_' . CBIC_PLUGIN_BASENAME, array( $this, 'add_action_links' ) );
	}

	public function maybe_upgrade_db() {
		if ( class_exists( 'CBIC_DB_Manager' ) ) {
			CBIC_DB_Manager::instance()->maybe_upgrade();
		}
	}

	public function maybe_bootstrap() {
		if ( $this->check_dependencies() ) {
			$this->load_dependencies();
			$this->is_loaded = true;

			do_action( 'cbic_loaded' );
		}
	}

	private function check_dependencies() {
		return class_exists( 'CodingBunnyImageOptimizer' );
	}

	private function load_dependencies() {
		$files_to_include = array(
			'image-cleaner.php',
		);

		foreach ( $files_to_include as $file ) {
			$file_path = $this->admin_dir . $file;
			if ( file_exists( $file_path ) ) {
				require_once $file_path;
			} else {
				$this->log_error( sprintf( 'Required file not found: %s', $file_path ) );
			}
		}
	}

	public function maybe_deactivate() {
		if ( ! $this->check_dependencies() && current_user_can( 'activate_plugins' ) && is_plugin_active( CBIC_PLUGIN_BASENAME ) ) {
			deactivate_plugins( CBIC_PLUGIN_BASENAME );

			if ( isset( $_GET['activate'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				unset( $_GET['activate'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
		}
	}

	public function show_admin_notices() {
		if ( ! is_admin() ) {
			return;
		}

		if ( get_transient( 'cbic_parent_deactivated' ) ) {
			delete_transient( 'cbic_parent_deactivated' );
			?>
			<div class="notice notice-warning is-dismissible">
				<p><?php esc_html_e( 'CodingBunny Image Cleaner has been deactivated because CodingBunny Image Optimizer is no longer active.', 'coding-bunny-image-cleaner' ); ?></p>
			</div>
			<?php
		}

		if ( ! $this->check_dependencies() && current_user_can( 'activate_plugins' ) ) {
			?>
			<div class="notice notice-error is-dismissible">
				<p>
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %s: Link to parent plugin */
							__( 'CodingBunny Image Cleaner requires CodingBunny Image Optimizer to be installed and active. Please <a href="%s" target="_blank" rel="noopener noreferrer">install and activate it first</a>.', 'coding-bunny-image-cleaner' ),
							esc_url( 'https://coding-bunny.com/image-optimizer/' )
						)
					);
					?>
				</p>
			</div>
			<?php
		}
	}

	public function handle_parent_deactivation( $plugin ) {
		if ( 'coding-bunny-image-optimizer/coding-bunny-image-optimizer.php' === $plugin ) {
			deactivate_plugins( CBIC_PLUGIN_BASENAME );
			set_transient( 'cbic_parent_deactivated', true, 30 );
		}
	}

	public function load_textdomain() {
		// phpcs:ignore
		load_plugin_textdomain( 'coding-bunny-image-cleaner', false, dirname( CBIC_PLUGIN_BASENAME ) . '/languages/' );
	}

	public function add_action_links( $links ) {
		if ( ! is_array( $links ) ) {
			return $links;
		}

		if ( $this->is_loaded ) {
			$settings_link = sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=coding-bunny-image-cleaner' ) ),
				esc_html__( 'Settings', 'coding-bunny-image-cleaner' )
			);
			array_unshift( $links, $settings_link );
		}

		return $links;
	}

	private function log_error( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'CodingBunny Image Cleaner: ' . $message );
		}
	}

	public function is_loaded() {
		return $this->is_loaded;
	}
}

function cbic_init() {
	return CodingBunnyImageCleaner::get_instance();
}

cbic_init();
