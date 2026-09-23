<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

include_once plugin_dir_path( __DIR__ ) . 'includes/delete-product-images.php';
include_once plugin_dir_path( __DIR__ ) . 'includes/missing-images-scanner.php';
include_once plugin_dir_path( __DIR__ ) . 'includes/orphan-thumbnails-cleaner.php';
include_once plugin_dir_path( __DIR__ ) . 'includes/unused-images-scanner.php';
include_once plugin_dir_path( __DIR__ ) . 'includes/duplicate-images-scanner.php';
include_once plugin_dir_path( __DIR__ ) . 'includes/delete-post-images.php';
include_once plugin_dir_path( __DIR__ ) . 'includes/plugin-theme-detector.php';
include_once plugin_dir_path( __DIR__ ) . 'includes/missing-featured-image-scanner.php';

if ( class_exists( 'CBIC_Duplicate_Images_Scanner' ) && method_exists( 'CBIC_Duplicate_Images_Scanner', 'register_hooks' ) ) {
	CBIC_Duplicate_Images_Scanner::register_hooks();
}

if ( ! class_exists( 'CBIC_DB_Manager' ) && file_exists( CBIC_PLUGIN_DIR . 'includes/db-manager.php' ) ) {
	include_once CBIC_PLUGIN_DIR . 'includes/db-manager.php';
}

class CBIC_Image_Cleaner_Page {

	private $missing_scanner;
	private $orphan_cleaner;
	private $unused_scanner;
	private $duplicate_scanner;
	private $missing_featured_scanner;

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
		add_action( 'admin_post_cbic_clean_orphan_images', [ $this, 'handle_clean_orphan_action' ] );
		add_action( 'admin_post_cbic_delete_unused_images', [ $this, 'handle_delete_unused_action' ] );
		add_action( 'admin_post_cbic_update_unused_settings', [ $this, 'handle_unused_settings' ] );
		add_action( 'admin_post_cbic_delete_duplicate_images', [ $this, 'handle_delete_duplicate_action' ] );

		add_action( 'save_post', [ $this, 'invalidate_missing_cache' ] );
		add_action( 'delete_post', [ $this, 'invalidate_missing_cache' ] );
		add_action( 'created_term', [ $this, 'invalidate_missing_cache' ] );
		add_action( 'edited_terms', [ $this, 'invalidate_missing_cache' ] );
		add_action( 'delete_term', [ $this, 'invalidate_missing_cache' ] );
		add_action( 'update_option', [ $this, 'maybe_invalidate_option_cache' ], 10, 3 );
	}

	private function init_scanners() {
		if ( null === $this->missing_scanner ) {
			$this->missing_scanner = new CBIC_Missing_Images_Scanner();
		}
		if ( null === $this->duplicate_scanner ) {
			$this->duplicate_scanner = new CBIC_Duplicate_Images_Scanner();
		}
		if ( null === $this->orphan_cleaner ) {
			$this->orphan_cleaner = new CBIC_Orphan_Thumbnails_Cleaner();
		}
		if ( null === $this->unused_scanner ) {
			$this->unused_scanner = new CBIC_Unused_Images_Scanner();
		}
		if ( null === $this->missing_featured_scanner ) {
			$this->missing_featured_scanner = new CBIC_Missing_Featured_Image_Scanner();
		}
	}

	public function add_admin_menu() {
		add_submenu_page(
		'coding-bunny-image-optimizer',
		esc_html__( 'Image Optimizer', 'coding-bunny-image-cleaner' ),
		esc_html__( 'Cleaner', 'coding-bunny-image-cleaner' ),
		'manage_options',
		'coding-bunny-image-cleaner',
		[ $this, 'render_page' ]
	);
}

public function render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'coding-bunny-image-cleaner' ) );
	}

	$this->init_scanners();

	$active_tab = $this->get_active_tab();
	?>
	<div class="wrap cbio-dashboard">
		<h1 class="screen-reader-text">CodingBunny Image Cleaner</h1>
		<div class="cbio-header">
			<?php $logo_url = plugins_url( 'assets/images/cbio-logo.svg', WP_PLUGIN_DIR . '/coding-bunny-image-optimizer/coding-bunny-image-optimizer.php' ); ?>
			<div class="cbio-header-left">
				<img src="<?php echo esc_url( $logo_url ); ?>"
				alt="<?php echo esc_attr__( 'CodingBunny logo', 'coding-bunny-image-cleaner' ); ?>"
				class="cbio-logo" />
				<div class="cbio-title">
					<p>
						<?php esc_html_e( 'CodingBunny Image Cleaner', 'coding-bunny-image-cleaner' ); ?>
						<span class="cbio-version">
							v<?php echo defined( 'CBIC_VERSION' ) ? esc_html( CBIC_VERSION ) : ''; ?>
						</span>
					</p>
				</div>
			</div>
		</div>
		<div class="cbio-ic-wrap">
			<nav class="cbio-ic-tabs" aria-label="<?php esc_attr_e( 'Image Cleaner tabs', 'coding-bunny-image-cleaner' ); ?>">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=coding-bunny-image-cleaner&tab=missing' ) ); ?>" class="cbio-ic-tab<?php echo ( 'missing' === $active_tab ) ? ' cbio-ic-tab-active' : ''; ?>">
					<span class="dashicons dashicons-editor-unlink"></span>
					<?php esc_html_e( 'Missing Images', 'coding-bunny-image-cleaner' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=coding-bunny-image-cleaner&tab=nofeatured' ) ); ?>"
					class="cbio-ic-tab<?php echo ( 'nofeatured' === $active_tab ) ? ' cbio-ic-tab-active' : ''; ?>">
					<span class="dashicons dashicons-cover-image"></span>
					<?php esc_html_e( 'Missing Featured Images', 'coding-bunny-image-cleaner' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=coding-bunny-image-cleaner&tab=unused' ) ); ?>" class="cbio-ic-tab<?php echo ( 'unused' === $active_tab ) ? ' cbio-ic-tab-active' : ''; ?>">
					<span class="dashicons dashicons-trash"></span>
					<?php esc_html_e( 'Unused Images', 'coding-bunny-image-cleaner' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=coding-bunny-image-cleaner&tab=duplicate' ) ); ?>" class="cbio-ic-tab<?php echo ( 'duplicate' === $active_tab ) ? ' cbio-ic-tab-active' : ''; ?>">
					<span class="dashicons dashicons-format-gallery"></span>
					<?php esc_html_e( 'Duplicate Images', 'coding-bunny-image-cleaner' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=coding-bunny-image-cleaner&tab=orphan' ) ); ?>" class="cbio-ic-tab<?php echo ( 'orphan' === $active_tab ) ? ' cbio-ic-tab-active' : ''; ?>">
					<span class="dashicons dashicons-forms"></span>
					<?php esc_html_e( 'Orphan Thumbnails', 'coding-bunny-image-cleaner' ); ?>
				</a>
			</nav>
			<div class="cbio-ic-content">
				<?php
				switch ( $active_tab ) {
					case 'unused':
					$this->render_unused_images_tab();
					break;
					case 'missing':
					$this->render_missing_images_tab();
					break;
					case 'duplicate':
					$this->render_duplicate_images_tab();
					break;
					case 'orphan':
					$this->render_orphan_thumbnails_tab();
					break;
					case 'nofeatured':
					default:
					if ( 'nofeatured' === $active_tab ) {
						$this->render_no_featured_images_tab();
					} else {
						$this->render_orphan_thumbnails_tab();
					}
					break;
				}
				?>
			</div>
		</div>
	</div>
	<?php
}

private function get_db_items( $issue_type, $per_page = 50, $paged = 1, &$total = 0 ) {
	$total = 0;
	if ( ! class_exists( 'CBIC_DB_Manager' ) ) {
		return array();
	}
	$manager = CBIC_DB_Manager::instance();
	$offset  = max( 0, ( (int) $paged - 1 ) * (int) $per_page );

	if ( method_exists( $manager, 'count_items' ) ) {
		$total = (int) $manager->count_items( $issue_type );
	} else {
		$total = null;
	}

	$items = array();
	if ( method_exists( $manager, 'get_items' ) ) {
		$items = $manager->get_items( $issue_type, (int) $per_page, (int) $offset );
	} else {
		$items = $manager->get_latest_items( $issue_type, (int) $per_page, (int) $offset );
	}

	if ( null === $total ) {
		$total = $offset + count( $items );
	}

	return $items;
}

private function get_db_run_finished_at( $issue_type ) {
	if ( ! class_exists( 'CBIC_DB_Manager' ) ) {
		return false;
	}
	$manager = CBIC_DB_Manager::instance();
	if ( method_exists( $manager, 'get_latest_run_finished_at' ) ) {
		return $manager->get_latest_run_finished_at( $issue_type );
	}
	return false;
}

private function render_pagination( $total, $per_page, $paged ) {
	$total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;
	if ( $total_pages <= 1 ) {
		return;
	}

	$base_url = remove_query_arg( array( 'paged' ) );

	echo '<div class="tablenav"><div class="tablenav-pages">';

	for ( $i = 1; $i <= $total_pages; $i++ ) {
		$url = add_query_arg( 'paged', $i, $base_url );

		$classes = array( 'page-numbers' );
		if ( $i === (int) $paged ) {
			$classes[] = 'current';
		}

		printf(
		'<a class="%s" href="%s">%s</a> ',
		esc_attr( implode( ' ', $classes ) ),
		esc_url( $url ),
		esc_html( $i )
	);
}

echo '</div></div>';
}

public function render_no_featured_images_tab() {
$post_types = $this->missing_featured_scanner->get_all_public_post_types();
$types_keys = array_keys( $post_types );

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$do_scan = isset( $_GET['cbic_refresh'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['cbic_refresh'] ) );

$missing_ids = array();
$per_page    = 50;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$paged = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;

$total = 0;

if ( class_exists( 'CBIC_DB_Manager' ) && ! $do_scan ) {
	$db_rows = $this->get_db_items( 'missing_featured', $per_page, $paged, $total );
	$missing_ids = array_map( static fn( $row ) => $row['data']['post_id'] ?? 0, $db_rows );
} elseif ( $do_scan && class_exists( 'CBIC_DB_Manager' ) ) {
	$missing_ids = $this->missing_featured_scanner->get_posts_without_featured_image(
	array(
		'post_types'  => $types_keys,
		'numberposts' => -1,
	)
);
$manager = CBIC_DB_Manager::instance();
$run_id  = $manager->start_run( 'missing_featured', array(), null );
$items   = array();
foreach ( $missing_ids as $pid ) {
	$items[] = array(
		'run_id'      => $run_id,
		'object_id'   => (int) $pid,
		'object_type' => 'post',
		'issue_type'  => 'missing_featured',
		'group_key'   => null,
		'data'        => array( 'post_id' => (int) $pid ),
	);
}
if ( ! empty( $items ) ) {
	$manager->bulk_insert_items( $items );
}
$manager->complete_run( $run_id, 'completed' );

$db_rows = $this->get_db_items( 'missing_featured', $per_page, $paged, $total );
$missing_ids = array_map( static fn( $row ) => $row['data']['post_id'] ?? 0, $db_rows );
} else {
	$cache_key   = 'cbic_no_featured_scan_v1';
	$cache_group = 'cbic_image_cleaner';

	$cached = wp_cache_get( $cache_key, $cache_group );
	if ( false === $cached ) {
		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			wp_cache_set( $cache_key, $cached, $cache_group, 0 );
		}
	}

	if ( $do_scan ) {
		$missing_ids = $this->missing_featured_scanner->get_posts_without_featured_image(
		array(
			'post_types'  => $types_keys,
			'numberposts' => -1,
		)
	);

	set_transient( $cache_key, $missing_ids, 0 );
	update_option( 'cbic_no_featured_last_scan', time() );
	update_option( 'cbic_missing_featured_total_count', count( $missing_ids ) );
	update_option( 'cbic_missing_featured_last_scan', time() );

	wp_cache_set( $cache_key, $missing_ids, $cache_group, 0 );
} elseif ( false !== $cached ) {
	$missing_ids = $cached;
}

$total = count( $missing_ids );
}

$last_scan_ts = $this->get_db_run_finished_at( 'missing_featured' );
if ( ! $last_scan_ts ) {
$last_scan_ts = get_option( 'cbic_no_featured_last_scan', false );
}

$rows = array();
if ( ! empty( $missing_ids ) ) {
foreach ( $missing_ids as $pid ) {
	$post = get_post( $pid );
	if ( ! $post ) {
		continue;
	}
	$type_label = isset( $post_types[ $post->post_type ] ) ? $post_types[ $post->post_type ] : $post->post_type;
	$rows[] = array(
		'pid'        => $pid,
		'post'       => $post,
		'type_label' => $type_label,
		'title'      => get_the_title( $pid ),
	);
}

usort(
$rows,
function( $a, $b ) {
	$cmp = strcasecmp( $a['type_label'], $b['type_label'] );
	if ( 0 === $cmp ) {
		return strcasecmp( $a['title'], $b['title'] );
	}
	return $cmp;
}
);
}

$total_files = $total;

?>
<div>
<p><?php esc_html_e( 'Find posts, pages and custom post types that do not have a featured image assigned.', 'coding-bunny-image-cleaner' ); ?></p>

<form method="get">
<input type="hidden" name="page" value="coding-bunny-image-cleaner" />
<input type="hidden" name="tab" value="nofeatured" />
<input type="hidden" name="cbic_refresh" value="1" />
<button type="submit" class="button button-primary">
	<span class="dashicons dashicons-search" aria-hidden="true"></span>
	<?php esc_html_e( 'Start Scan', 'coding-bunny-image-cleaner' ); ?>
</button>

<?php if ( $last_scan_ts ) : ?>
	<div class="cbio-info">
		<strong>
			<?php
			echo esc_html(
			sprintf(
			/* translators: %s: date and time of the last scan */
			__( 'Last scan: %s', 'coding-bunny-image-cleaner' ),
			gmdate( 'Y-m-d H:i', (int) $last_scan_ts ) . ' UTC'
				)
		);
		?>
	</strong>
</div>
<?php endif; ?>
</form>

<?php
$container_class = ( (int) $total_files === 0 ) ? 'cbio-approved' : 'cbio-important';

if ( ! $do_scan && empty( $rows ) && $total_files === 0 ) {
echo '<div class="' . esc_attr( $container_class ) . '">';
echo esc_html__( 'Click on “Start scan” to search for articles without a featured image.', 'coding-bunny-image-cleaner' );
echo '</div>';
} else {
if ( (int) $total_files === 0 ) {
	$message = esc_html__( 'Great job! No items without a featured image were found.', 'coding-bunny-image-cleaner' );
} else {
	$message = sprintf(
	/* translators: %1$d: number of items without a featured image */
	esc_html__( 'Found %1$d items without a featured image.', 'coding-bunny-image-cleaner' ),
	(int) $total_files
);
}
?>
<div class="<?php echo esc_attr( $container_class ); ?>">
<?php echo esc_html( $message ); ?>
</div>

<div class="cbio-cleaner-table">
<table class="cbio-orphan-table" style="width:auto;">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Title', 'coding-bunny-image-cleaner' ); ?></th>
			<th><?php esc_html_e( 'Type', 'coding-bunny-image-cleaner' ); ?></th>
			<th><?php esc_html_e( 'Status', 'coding-bunny-image-cleaner' ); ?></th>
			<th><?php esc_html_e( 'Author', 'coding-bunny-image-cleaner' ); ?></th>
			<th><?php esc_html_e( 'Modified', 'coding-bunny-image-cleaner' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php if ( ! empty( $rows ) ) : ?>
			<?php foreach ( $rows as $row ) : ?>
				<?php
				$post     = $row['post'];
				$pid      = (int) $row['pid'];
				$edit_url = get_edit_post_link( $pid );
				?>
				<tr>
					<td>
						<?php if ( $edit_url ) : ?>
							<a href="<?php echo esc_url( $edit_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $row['title'] ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $row['title'] ); ?>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $row['type_label'] ); ?></td>
					<td><?php echo esc_html( $post->post_status ); ?></td>
					<td><?php echo esc_html( get_the_author_meta( 'display_name', $post->post_author ) ); ?></td>
					<td><?php echo esc_html( get_the_modified_date( 'Y-m-d H:i', $pid ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		<?php else : ?>
			<tr>
				<td colspan="5"><?php esc_html_e( 'No content found without a featured image.', 'coding-bunny-image-cleaner' ); ?></td>
			</tr>
		<?php endif; ?>
	</tbody>
</table>
</div>
<?php $this->render_pagination( $total_files, $per_page, $paged ); ?>
<?php
}
?>
</div>
<?php
}

public function render_orphan_thumbnails_tab() {
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$force = isset( $_GET['cbic_refresh'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['cbic_refresh'] ) );

$orphans = array();
$error_message = '';
$show_scan_required_notice = false;

$per_page = 50;
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$paged    = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
$total    = 0;

if ( $force && class_exists( 'CBIC_DB_Manager' ) ) {
$result = $this->orphan_cleaner->run_orphan_scan( true );
if ( is_wp_error( $result ) ) {
$error_message = $result->get_error_message();
$orphans = array();
}
}

if ( class_exists( 'CBIC_DB_Manager' ) && ! is_wp_error( $error_message ) ) {
$db_rows = $this->get_db_items( 'orphan', $per_page, $paged, $total );
$orphans = array_map(
static function ( $row ) {
return is_array( $row['data'] ?? null ) ? $row['data'] : array();
},
$db_rows
);
} elseif ( ! $force ) {
$orphans = $this->orphan_cleaner->get_orphan_thumbnails();
if ( empty( $orphans ) ) {
$show_scan_required_notice = true;
}
$total = count( $orphans );
}

$total_files = $total;
$total_size  = 0;
$upload_dir  = wp_get_upload_dir();

foreach ( $orphans as $orphan ) {
$filepath = $upload_dir['basedir'] . '/' . ltrim( (string) ( $orphan['relative'] ?? '' ), '/\\' );
if ( is_file( $filepath ) ) {
$size = filesize( $filepath );
if ( $size > 0 ) {
$total_size += $size;
}
}
}
$readable_size = size_format( $total_size, 2 );

$deleted_count = 0;
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( isset( $_GET['deleted'] ) && $this->get_active_tab() === 'orphan' ) {
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$deleted_count = absint( wp_unslash( $_GET['deleted'] ) );
}

$last_scan_ts = $this->get_db_run_finished_at( 'orphan' );
if ( ! $last_scan_ts && method_exists( $this->orphan_cleaner, 'get_last_scan_timestamp' ) ) {
$last_scan_ts = $this->orphan_cleaner->get_last_scan_timestamp();
}
?>

<div>
<p><?php esc_html_e( 'Find and delete orphaned thumbnail files. You can select the thumbnails you want to delete to free up disk space.', 'coding-bunny-image-cleaner' ); ?></p>

<form method="get" style="margin-bottom:10px;">
<input type="hidden" name="page" value="coding-bunny-image-cleaner" />
<input type="hidden" name="tab" value="orphan" />
<input type="hidden" name="cbic_refresh" value="1" />
<button type="submit" class="button button-primary">
<span class="dashicons dashicons-search" aria-hidden="true"></span>
<?php esc_html_e( 'Start Scan', 'coding-bunny-image-cleaner' ); ?>
</button>

<?php if ( $last_scan_ts ) : ?>
<div class="cbio-info">
	<strong>
		<?php
		echo esc_html(
		sprintf(
		/* translators: %s: date and time of the last scan */
		__( 'Last scan: %s', 'coding-bunny-image-cleaner' ),
		gmdate( 'Y-m-d H:i', (int) $last_scan_ts ) . ' UTC'
			)
	);
	?>
</strong>
</div>
<?php endif; ?>
</form>

<?php if ( $error_message ) : ?>
<div class="notice notice-error"><p><?php echo esc_html( $error_message ); ?></p></div>
<?php endif; ?>

<?php
$container_class = ( (int) $total_files === 0 ) ? 'cbio-approved' : 'cbio-important';

if ( $show_scan_required_notice && $total_files === 0 ) {
echo '<div class="' . esc_attr( $container_class ) . '">';
echo esc_html__( 'Click “Start Scan” to search for orphan thumbnails.', 'coding-bunny-image-cleaner' );
echo '</div>';
} else {
if ( (int) $total_files === 0 ) {
$message = __( 'Great job! No orphan files found.', 'coding-bunny-image-cleaner' );
} else {
	$message = sprintf(
	/* translators: %1$d: number of orphan files, %2$s: amount of disk space that can be freed */
	__( 'Found %1$d orphan files. Potential space to free: %2$s.', 'coding-bunny-image-cleaner' ),
	(int) $total_files,
	$readable_size
);
}
?>
<div class="<?php echo esc_attr( $container_class ); ?>">
<?php echo esc_html( $message ); ?>
</div>
<?php
}
?>

<?php if ( $deleted_count > 0 ) : ?>
<div class="notice notice-success">
<p><?php echo esc_html( $deleted_count ); ?> <?php esc_html_e( 'files deleted.', 'coding-bunny-image-cleaner' ); ?></p>
</div>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
<?php wp_nonce_field( 'cbic_clean_orphan_images', 'cbic_clean_orphan_images_nonce' ); ?>
<input type="hidden" name="action" value="cbic_clean_orphan_images" />
<input type="hidden" name="tab" value="orphan" />
<div class="cbio-cleaner-table">
<table class="cbio-orphan-table" style="width:auto;">
	<colgroup>
		<col class="checkbox-col">
		<col class="file-col">
	</colgroup>
	<thead>
		<tr>
			<th></th>
			<th><?php esc_html_e( 'File path (uploads/)', 'coding-bunny-image-cleaner' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php if ( ! empty( $orphans ) ) : ?>
			<?php foreach ( $orphans as $orphan ) : ?>
				<tr>
					<td><input type="checkbox" name="orphan_files[]" value="<?php echo esc_attr( $orphan['relative'] ); ?>" checked /></td>
					<td><?php echo esc_html( $orphan['relative'] ); ?></td>
				</tr>
			<?php endforeach; ?>
		<?php else : ?>
			<tr><td colspan="2"><?php echo $show_scan_required_notice ? esc_html__( '-', 'coding-bunny-image-cleaner' ) : esc_html__( 'No orphan thumbnails found.', 'coding-bunny-image-cleaner' ); ?></td></tr>
		<?php endif; ?>
	</tbody>
</table>
</div>

<div class="cbio-warning">
<strong><?php esc_html_e( 'WARNING:', 'coding-bunny-image-cleaner' ); ?></strong>
<?php esc_html_e( 'Before deleting any files, make sure you have made a complete backup of your site and files. Deletion is irreversible.', 'coding-bunny-image-cleaner' ); ?>
</div>

<p>
<button type="submit" class="button button-delete" onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to delete these files? This action cannot be undone.', 'coding-bunny-image-cleaner' ) ); ?>');" aria-label="<?php echo esc_attr__( 'Delete Selected Thumbnails', 'coding-bunny-image-cleaner' ); ?>">
	<span class="dashicons dashicons-trash" aria-hidden="true"></span>
	<?php echo esc_html__( 'Delete Selected Thumbnails', 'coding-bunny-image-cleaner' ); ?>
</button>
</p>
</form>
<?php $this->render_pagination( $total_files, $per_page, $paged ); ?>
</div>
<?php
}

public function render_unused_images_tab() {
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$force = isset( $_GET['cbic_refresh'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['cbic_refresh'] ) );

$unused_images = array();
$show_scan_required_notice = false;
$error_message = '';

$per_page = 50;
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$paged    = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
$total    = 0;

if ( $force ) {
$result = $this->unused_scanner->run_unused_scan( array() );
if ( is_wp_error( $result ) ) {
$error_message = $result->get_error_message();
}
}

if ( class_exists( 'CBIC_DB_Manager' ) && ! $error_message ) {
$db_rows = $this->get_db_items( 'unused', $per_page, $paged, $total );
$unused_images = array_map(
static function ( $row ) {
return is_array( $row['data'] ?? null ) ? $row['data'] : array();
},
$db_rows
);
if ( empty( $unused_images ) && ! $force ) {
$show_scan_required_notice = true;
}
} elseif ( ! $force ) {
$unused_images = $this->unused_scanner->get_unused_images();
if ( empty( $unused_images ) ) {
$show_scan_required_notice = true;
}
$total = count( $unused_images );
}

$total_files = $total;
$total_size  = 0;
$upload_dir  = wp_get_upload_dir();

foreach ( $unused_images as $image ) {
if ( ! empty( $image['file'] ) ) {
$filepath = $upload_dir['basedir'] . '/' . ltrim( $image['file'], '/\\' );
if ( is_file( $filepath ) ) {
$sz = filesize( $filepath );
if ( $sz > 0 ) {
	$total_size += $sz;
}
}
}
}
$readable_size = size_format( $total_size, 2 );

$delete_product_images = get_option( 'cbic_delete_product_images', '0' );
$delete_post_images    = get_option( 'cbic_delete_post_images', '0' );
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$settings_updated = isset( $_GET['settings_updated'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['settings_updated'] ) );
$deleted_count    = 0;
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( isset( $_GET['deleted'] ) && 'unused' === $this->get_active_tab() ) {
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$deleted_count = absint( wp_unslash( $_GET['deleted'] ) );
}

$last_scan_ts = $this->get_db_run_finished_at( 'unused' );
if ( ! $last_scan_ts && method_exists( $this->unused_scanner, 'get_last_scan_timestamp' ) ) {
$last_scan_ts = $this->unused_scanner->get_last_scan_timestamp();
}
?>
<div>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
<?php wp_nonce_field( 'cbic_update_unused_settings', 'cbic_update_unused_settings_nonce' ); ?>
<input type="hidden" name="action" value="cbic_update_unused_settings" />

<table class="cbio-form-table">
<tr valign="top">
	<th scope="row"><label for="delete_product_images"><?php esc_html_e( 'WooCommerce Product Images', 'coding-bunny-image-cleaner' ); ?></label></th>
	<td>
		<label class="cbio-toggle-label">
			<input type="checkbox" class="cbio-toggle" id="delete_product_images" name="delete_product_images" value="1" <?php checked( '1' === $delete_product_images ); ?> />
			<span class="cbio-slider"></span>
			<?php esc_html_e( 'Automatically delete images associated with WooCommerce products when they are deleted.', 'coding-bunny-image-cleaner' ); ?>
		</label>
	</td>
</tr>
<tr valign="top">
	<th scope="row"><label for="delete_post_images"><?php esc_html_e( 'Post & Page Images', 'coding-bunny-image-cleaner' ); ?></label></th>
	<td>
		<label class="cbio-toggle-label">
			<input type="checkbox" class="cbio-toggle" id="delete_post_images" name="delete_post_images" value="1" <?php checked( '1' === $delete_post_images ); ?> />
			<span class="cbio-slider"></span>
			<?php esc_html_e( 'Automatically delete images associated with posts or pages when they are deleted.', 'coding-bunny-image-cleaner' ); ?>
		</label>
	</td>
</tr>
</table>

<div class="cbio-warning" style="margin-top: 10px;">
<strong><?php esc_html_e( 'WARNING:', 'coding-bunny-image-cleaner' ); ?></strong>
<?php esc_html_e( 'Before enabling these options, be sure to have a complete backup of your site and files. Deletion is irreversible.', 'coding-bunny-image-cleaner' ); ?>
</div>

<?php submit_button( esc_html__( 'Save Settings', 'coding-bunny-image-cleaner' ) ); ?>
</form>

<?php if ( $settings_updated ) : ?>
<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'coding-bunny-image-cleaner' ); ?></p></div>
<?php endif; ?>
<hr>
<p><?php esc_html_e( 'Find and delete images uploaded to WordPress that are no longer linked to any content, product, or taxonomy. You can select the unused images you want to delete to free up disk space.', 'coding-bunny-image-cleaner' ); ?></p>

<form method="get" style="margin-bottom:10px;">
<input type="hidden" name="page" value="coding-bunny-image-cleaner" />
<input type="hidden" name="tab" value="unused" />
<input type="hidden" name="cbic_refresh" value="1" />
<button type="submit" class="button button-primary">
<span class="dashicons dashicons-search" aria-hidden="true"></span>
<?php esc_html_e( 'Start Scan', 'coding-bunny-image-cleaner' ); ?>
</button>

<?php if ( $last_scan_ts ) : ?>
<div class="cbio-info">
	<strong>
		<?php
		echo esc_html(
		sprintf(
		/* translators: %s: date and time of the last scan */
		__( 'Last scan: %s', 'coding-bunny-image-cleaner' ),
		gmdate( 'Y-m-d H:i', (int) $last_scan_ts ) . ' UTC'
			)
	);
	?>
</strong>
</div>
<?php endif; ?>
</form>

<?php if ( $error_message ) : ?>
<div class="notice notice-error"><p><?php echo esc_html( $error_message ); ?></p></div>
<?php endif; ?>

<?php
$container_class = ( (int) $total_files === 0 ) ? 'cbio-approved' : 'cbio-important';

if ( $show_scan_required_notice && $total_files === 0 ) {
echo '<div class="' . esc_attr( $container_class ) . '">';
echo esc_html__( 'Click "Start Scan" to search for unused images', 'coding-bunny-image-cleaner' );
echo '</div>';
} else {
if ( (int) $total_files === 0 ) {
$message = __( 'Great job! No unused images found.', 'coding-bunny-image-cleaner' );
} else {
	$message = sprintf(
	/* translators: %1$d: number of unused images, %2$s: amount of disk space that can be freed */
	__( 'Found %1$d unused images. Potential space to free: %2$s.', 'coding-bunny-image-cleaner' ),
	(int) $total_files,
	$readable_size
);
}
?>
<div class="<?php echo esc_attr( $container_class ); ?>">
<?php echo esc_html( $message ); ?>
</div>
<?php
}
?>

<?php if ( $deleted_count > 0 ) : ?>
<div class="notice notice-success">
<p><?php echo esc_html( $deleted_count ); ?> <?php esc_html_e( 'images deleted.', 'coding-bunny-image-cleaner' ); ?></p>
</div>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
<?php wp_nonce_field( 'cbic_delete_unused_images', 'cbic_delete_unused_images_nonce' ); ?>
<input type="hidden" name="action" value="cbic_delete_unused_images" />
<input type="hidden" name="tab" value="unused" />
<div class="cbio-cleaner-table">
<table class="cbio-unused-table" style="width:auto;">
	<colgroup>
		<col class="checkbox-col">
		<col class="preview-col">
		<col class="file-col">
		<col class="size-col">
	</colgroup>
	<thead>
		<tr>
			<th></th>
			<th><?php esc_html_e( 'Image', 'coding-bunny-image-cleaner' ); ?></th>
			<th><?php esc_html_e( 'File path (upload/)', 'coding-bunny-image-cleaner' ); ?></th>
			<th><?php esc_html_e( 'Size', 'coding-bunny-image-cleaner' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php if ( ! empty( $unused_images ) ) : ?>
			<?php foreach ( $unused_images as $image ) : ?>
				<tr>
					<td><input type="checkbox" name="unused_images[]" value="<?php echo esc_attr( $image['ID'] ?? 0 ); ?>" /></td>
					<td><?php echo wp_kses_post( $image['preview'] ?? '' ); ?></td>
					<td class="file-col"><?php echo esc_html( $image['file'] ?? '' ); ?></td>
					<td><?php echo esc_html( size_format( $image['size'] ?? 0, 2 ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		<?php else : ?>
			<tr>
				<td colspan="4">
					<?php echo $show_scan_required_notice ? esc_html__( '-', 'coding-bunny-image-cleaner' ) : esc_html__( 'No unused images found.', 'coding-bunny-image-cleaner' ); ?>
				</td>
			</tr>
		<?php endif; ?>
	</tbody>
</table>
</div>
<?php $this->render_pagination( $total_files, $per_page, $paged ); ?>
<div class="cbio-warning">
<?php esc_html_e( 'WARNING: Before deleting any images, make sure you have made a complete backup of your site and files. Deletion is irreversible.', 'coding-bunny-image-cleaner' ); ?>
</div>
<p>
<button type="submit" class="button button-delete" onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to delete these images? This action cannot be undone.', 'coding-bunny-image-cleaner' ) ); ?>');">
	<span class="dashicons dashicons-trash" aria-hidden="true"></span>
	<?php echo esc_html__( 'Delete Selected Images', 'coding-bunny-image-cleaner' ); ?>
</button>
</p>
</form>
</div>
<?php
}

public function render_missing_images_tab() {
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$force = isset( $_GET['cbic_refresh'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['cbic_refresh'] ) );

$missing_images = array();
$show_scan_required_notice = false;
$error_message = '';

$per_page = 50;
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$paged    = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
$total    = 0;

if ( $force ) {
$this->missing_scanner->get_missing_images_cached( true );
if ( class_exists( 'CBIC_DB_Manager' ) ) {
$missing_images = $this->get_db_items( 'missing', $per_page, $paged, $total );
}
} elseif ( class_exists( 'CBIC_DB_Manager' ) ) {
$missing_images = $this->get_db_items( 'missing', $per_page, $paged, $total );
if ( empty( $missing_images ) ) {
$show_scan_required_notice = true;
}
} else {
$cached_key  = 'cbic_ic_missing_scan_cache_v1';
$cache_group = 'cbic_image_cleaner';

$cached = wp_cache_get( $cached_key, $cache_group );

if ( false === $cached ) {
	$cached = get_transient( $cached_key );
	if ( false !== $cached ) {
		wp_cache_set( $cached_key, $cached, $cache_group, 0 );
	}
}

if ( $cached ) {
	$missing_images = $cached;
	$total = count( $missing_images );
} else {
	$missing_images = array();
	$show_scan_required_notice = true;
}
}

if ( 0 === $total && class_exists( 'CBIC_DB_Manager' ) ) {
$total = CBIC_DB_Manager::instance()->count_items( 'missing' );
}

$last_scan_ts = $this->get_db_run_finished_at( 'missing' );
if ( ! $last_scan_ts ) {
$last_scan_ts = get_option( 'cbic_ic_missing_last_scan', false );
}

$total_files = $total;
?>

<div>
<p>
	<?php esc_html_e( 'Find missing images that are linked to content but not present in the uploads folder.', 'coding-bunny-image-cleaner' ); ?><br><br>
	<a href="<?php echo esc_url( add_query_arg( [ 'page' => 'coding-bunny-image-cleaner', 'tab' => 'missing', 'cbic_refresh' => 1 ], admin_url( 'admin.php' ) ) ); ?>" class="button button-primary"><span class="dashicons dashicons-search"></span>
		<?php esc_html_e( 'Start Scan', 'coding-bunny-image-cleaner' ); ?>
	</a>
</p>

<?php if ( $last_scan_ts ) : ?>
	<div class="cbio-info">
		<strong>
			<?php
			echo esc_html(
			sprintf(
			/* translators: %s: date and time of the last scan */
			__( 'Last scan: %s', 'coding-bunny-image-cleaner' ),
			gmdate( 'Y-m-d H:i', (int) $last_scan_ts ) . ' UTC'
				)
		);
		?>
	</strong>
</div>
<?php endif; ?>

<?php
$container_class = ( (int) $total_files === 0 ) ? 'cbio-approved' : 'cbio-important';

if ( $show_scan_required_notice && $total_files === 0 ) {
echo '<div class="' . esc_attr( $container_class ) . '">';
echo esc_html__( 'Click "Start Scan" to search for the missing images.', 'coding-bunny-image-cleaner' );
echo '</div>';
} else {
	if ( (int) $total_files === 0 ) {
		$format = __( 'Great job! No missing images found.', 'coding-bunny-image-cleaner' );
		echo '<div class="' . esc_attr( $container_class ) . '">';
		echo wp_kses_post( sprintf( $format, 0 ) );
		echo '</div>';
	} else {
		/* translators: %1$d: number of missing images */
		$format = __( 'Found %1$d missing images.', 'coding-bunny-image-cleaner' );
		echo '<div class="' . esc_attr( $container_class ) . '">';
		echo wp_kses_post( sprintf( $format, (int) $total_files ) );
		echo '</div>';
	}
}
?>
<div class="cbio-cleaner-table">
	<table class="cbio-orphan-table" style="width:auto;">
		<thead>
			<tr>
				<th><?php esc_html_e( 'File path (uploads/)', 'coding-bunny-image-cleaner' ); ?></th>
				<th><?php esc_html_e( 'Used by', 'coding-bunny-image-cleaner' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( ! empty( $missing_images ) ) : ?>
				<?php
				$u = wp_get_upload_dir();
				$b = $u['baseurl'];
				foreach ( $missing_images as $img ) :
					$data = is_array( $img ) && isset( $img['data'] ) ? $img['data'] : $img;
					$url  = (string) ( $data['url'] ?? '' );
					if ( ! $url ) {
						continue;
					}
					$rel  = ltrim( str_replace( $b, '', $url ), '/\\' );
					$link = '-';
					if ( ! empty( $data['post_id'] ) && ( $edit = get_edit_post_link( (int) $data['post_id'] ) ) ) {
						$title = get_the_title( $data['post_id'] );
						$link  = '<a href="' . esc_url( $edit ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $title ) . '</a>';
					} elseif ( ! empty( $data['source'] ) ) {
						$link = esc_html( $data['source'] );
					}
					?>
					<tr>
						<td style="word-break:break-all;"><?php echo esc_html( $rel ); ?></td>
						<td><?php echo wp_kses_post( $link ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php else : ?>
				<tr><td colspan="2"><?php echo $show_scan_required_notice ? esc_html__( '-', 'coding-bunny-image-cleaner' ) : esc_html__( 'No missing images found.', 'coding-bunny-image-cleaner' ); ?></td></tr>
			<?php endif; ?>
		</tbody>
	</table>
</div>
<?php $this->render_pagination( $total_files, $per_page, $paged ); ?>
</div>
<?php
}

public function render_duplicate_images_tab() {
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$force = isset( $_GET['cbic_refresh'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['cbic_refresh'] ) );

$duplicates = array();
$show_scan_required_notice = false;
$error_message = '';

$per_page = 50;
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$paged    = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
$total    = 0;

if ( $force ) {
$result = $this->duplicate_scanner->run_duplicate_scan( true );
if ( is_wp_error( $result ) ) {
	$error_message = $result->get_error_message();
}
}

if ( class_exists( 'CBIC_DB_Manager' ) && ! $error_message ) {
$db_rows = $this->get_db_items( 'duplicate', $per_page, $paged, $total );
foreach ( $db_rows as $row ) {
	$gk = $row['group_key'] ?: 'unknown';
	$duplicates[ $gk ][] = $row['data'];
}
if ( empty( $duplicates ) && ! $force ) {
	$show_scan_required_notice = true;
}
} else {
	$duplicates = $this->duplicate_scanner->get_duplicate_images();
	if ( is_wp_error( $duplicates ) ) {
		$error_message = $duplicates->get_error_message();
		$duplicates = array();
	}
	if ( empty( $duplicates ) ) {
		$show_scan_required_notice = true;
	}
	$total = count( $duplicates );
}

$total_groups = count( $duplicates );
$total_files  = 0;
$total_size   = 0;
$upload_dir   = wp_get_upload_dir();

foreach ( $duplicates as $group ) {
	$total_files += count( $group );
	foreach ( $group as $img ) {
		$filepath = isset( $img['path'] ) ? $img['path'] : '';
		if ( is_file( $filepath ) ) {
			$sz = filesize( $filepath );
			if ( $sz > 0 ) {
				$total_size += $sz;
			}
		}
	}
}
$readable_size = size_format( $total_size, 2 );

$deleted_count = 0;
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( isset( $_GET['deleted'] ) && 'duplicate' === $this->get_active_tab() ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$deleted_count = absint( wp_unslash( $_GET['deleted'] ) );
}

$last_scan_ts = $this->get_db_run_finished_at( 'duplicate' );
if ( ! $last_scan_ts ) {
	$last_scan_ts = $this->duplicate_scanner->get_last_scan_timestamp();
}
?>

<div>
	<p><?php esc_html_e( 'Find images in your Media Library that are exact duplicates (same file content). You can select the duplicates you want to delete to free up disk space.', 'coding-bunny-image-cleaner' ); ?></p>
	<form method="get" style="margin-bottom:10px;">
		<input type="hidden" name="page" value="coding-bunny-image-cleaner" />
		<input type="hidden" name="tab" value="duplicate" />
		<input type="hidden" name="cbic_refresh" value="1" />
		<button type="submit" class="button button-primary">
			<span class="dashicons dashicons-search" aria-hidden="true"></span>
			<?php esc_html_e( 'Start Scan', 'coding-bunny-image-cleaner' ); ?>
		</button>
		<?php if ( $last_scan_ts ) : ?>
			<div class="cbio-info">
				<strong>
					<?php
					echo esc_html(
					sprintf(
					/* translators: %s: date and time of the last scan */
					__( 'Last scan: %s', 'coding-bunny-image-cleaner' ),
					gmdate( 'Y-m-d H:i', (int) $last_scan_ts ) . ' UTC'
						)
				);
				?>
			</strong>
		</div>
	<?php endif; ?>
</form>

<?php if ( $error_message ) : ?>
	<div class="notice notice-error"><p><?php echo esc_html( $error_message ); ?></p></div>
<?php endif; ?>

<?php
$container_class = ( (int) $total_groups === 0 ) ? 'cbio-approved' : 'cbio-important';

if ( $show_scan_required_notice && $total_groups === 0 ) {
	echo '<div class="' . esc_attr( $container_class ) . '">';
	echo esc_html__( 'Click "Start scan" to search for the duplicate images.', 'coding-bunny-image-cleaner' );
	echo '</div>';
} else {
	if ( (int) $total_groups === 0 ) {
		$message = __( 'Great job! No duplicate images found.', 'coding-bunny-image-cleaner' );
	} else {
		$message = sprintf(
		/* translators: %1$d: number of groups of duplicate images, %2$s: amount of disk space that can be freed */
		__( 'Found %1$d groups of duplicate images. Potential space to free: %2$s.', 'coding-bunny-image-cleaner' ),
		$total_groups,
		$readable_size
	);
}
?>
<div class="<?php echo esc_attr( $container_class ); ?>">
	<?php echo esc_html( $message ); ?>
</div>
<?php
}
?>

<?php if ( $deleted_count > 0 ) : ?>
<div class="notice notice-success">
	<p><?php echo esc_html( $deleted_count ); ?> <?php esc_html_e( 'images deleted.', 'coding-bunny-image-cleaner' ); ?></p>
</div>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
<?php wp_nonce_field( 'cbic_delete_duplicate_images', 'cbic_delete_duplicate_images_nonce' ); ?>
<input type="hidden" name="action" value="cbic_delete_duplicate_images" />
<input type="hidden" name="tab" value="duplicate" />
<div class="cbio-cleaner-table">
	<table class="cbio-duplicate-table" style="width:auto;">
		<colgroup>
			<col class="checkbox-col">
			<col class="preview-col">
			<col class="file-col">
			<col class="used-in-col">
			<col class="size-col">
			<col class="hash-col">
		</colgroup>
		<thead>
			<tr>
				<th></th>
				<th><?php esc_html_e( 'Image', 'coding-bunny-image-cleaner' ); ?></th>
				<th><?php esc_html_e( 'File path (uploads/)', 'coding-bunny-image-cleaner' ); ?></th>
				<th><?php esc_html_e( 'Used In', 'coding-bunny-image-cleaner' ); ?></th>
				<th><?php esc_html_e( 'Size', 'coding-bunny-image-cleaner' ); ?></th>
				<th><?php esc_html_e( 'Hash', 'coding-bunny-image-cleaner' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( ! empty( $duplicates ) ) : ?>
				<?php foreach ( $duplicates as $hash => $group ) : ?>
					<?php foreach ( $group as $img ) : ?>
						<tr>
							<td><input type="checkbox" name="duplicate_images[]" value="<?php echo esc_attr( $img['ID'] ); ?>" /></td>
							<td class="preview-col">
								<?php if ( ! empty( $img['url'] ) ) : ?>
									<img src="<?php echo esc_url( $img['url'] ); ?>" alt="" style="width:60px;height:auto;max-height:60px;" />
								<?php endif; ?>
							</td>
							<td class="file-col"><?php echo esc_html( str_replace( $upload_dir['basedir'] . '/', '', ( isset( $img['path'] ) ? $img['path'] : '' ) ) ); ?></td>
							<td class="used-in-col">
								<?php
								if ( isset( $img['used_in'] ) ) {
									echo wp_kses_post( $this->duplicate_scanner->format_usage_display( $img['used_in'] ) );
								} else {
									echo '<span style="color:#999;">' . esc_html__( 'Not used', 'coding-bunny-image-cleaner' ) . '</span>';
								}
								?>
							</td>
							<td class="size-col"><?php echo esc_html( size_format( is_file( ( isset( $img['path'] ) ? $img['path'] : '' ) ) ? filesize( $img['path'] ) : 0, 2 ) ); ?></td>
							<td><code><?php echo esc_html( $hash ); ?></code></td>
						</tr>
					<?php endforeach; ?>
				<?php endforeach; ?>
			<?php else : ?>
				<tr><td colspan="6"><?php esc_html_e( '-', 'coding-bunny-image-cleaner' ); ?></td></tr>
			<?php endif; ?>
		</tbody>
	</table>
</div>
<?php $this->render_pagination( $total_files, $per_page, $paged ); ?>
<div class="cbio-warning">
	<?php esc_html_e( 'WARNING: Before deleting any images, make sure you have made a complete backup of your site and files. Deletion is irreversible.', 'coding-bunny-image-cleaner' ); ?>
</div>
<p>
	<button type="submit" class="button button-delete" onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to delete these images? This action cannot be undone.', 'coding-bunny-image-cleaner' ) ); ?>');" aria-label="<?php echo esc_attr__( 'Delete Selected Images', 'coding-bunny-image-cleaner' ); ?>">
		<span class="dashicons dashicons-trash" aria-hidden="true"></span>
		<?php echo esc_html__( 'Delete Selected Images', 'coding-bunny-image-cleaner' ); ?>
	</button>
</p>
</form>
</div>
<?php
}

public function invalidate_missing_cache() {
$this->init_scanners();
if ( $this->missing_scanner ) {
$this->missing_scanner->invalidate_cache();
}
}

public function maybe_invalidate_option_cache( $option, $old, $value ) {
$this->init_scanners();
if ( is_string( $value ) && preg_match( '/\.(jpg|jpeg|png|gif|webp|svg|avif)/i', $value ) ) {
$this->invalidate_missing_cache();
}
}

public function handle_clean_orphan_action() {
$this->init_scanners();
if ( ! current_user_can( 'manage_options' ) ) {
wp_die( esc_html__( 'Permission denied.', 'coding-bunny-image-cleaner' ) );
}
// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
$nonce = isset( $_POST['cbic_clean_orphan_images_nonce'] ) ? wp_unslash( $_POST['cbic_clean_orphan_images_nonce'] ) : '';
if ( ! wp_verify_nonce( $nonce, 'cbic_clean_orphan_images' ) ) {
wp_die( esc_html__( 'Permission denied.', 'coding-bunny-image-cleaner' ) );
}

$deleted = 0;
if ( ! empty( $_POST['orphan_files'] ) && is_array( $_POST['orphan_files'] ) ) {
$files   = array_map( 'sanitize_text_field', wp_unslash( $_POST['orphan_files'] ) );
$deleted = $this->orphan_cleaner->delete_orphan_thumbnail_files( $files );
}

if ( $deleted > 0 && method_exists( $this->orphan_cleaner, 'run_orphan_scan' ) ) {
$result = $this->orphan_cleaner->run_orphan_scan( true );
if ( is_wp_error( $result ) ) {
}
}

wp_safe_redirect(
add_query_arg(
[
'page'         => 'coding-bunny-image-cleaner',
'tab'          => 'orphan',
'deleted'      => $deleted,
'cbic_refresh' => 1,
],
admin_url( 'admin.php' )
)
);
exit;
}

public function handle_delete_unused_action() {
$this->init_scanners();
if ( ! current_user_can( 'manage_options' ) ) {
wp_die( esc_html__( 'Permission denied.', 'coding-bunny-image-cleaner' ) );
}
// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
$nonce = isset( $_POST['cbic_delete_unused_images_nonce'] ) ? wp_unslash( $_POST['cbic_delete_unused_images_nonce'] ) : '';
if ( ! wp_verify_nonce( $nonce, 'cbic_delete_unused_images' ) ) {
wp_die( esc_html__( 'Permission denied.', 'coding-bunny-image-cleaner' ) );
}

$deleted = 0;
if ( ! empty( $_POST['unused_images'] ) && is_array( $_POST['unused_images'] ) ) {
$ids     = array_map( 'intval', wp_unslash( $_POST['unused_images'] ) );
$deleted = $this->unused_scanner->delete_unused_attachments( $ids );
}

if ( $deleted > 0 ) {
$result = $this->unused_scanner->run_unused_scan( array() );
if ( is_wp_error( $result ) ) {
}
}

wp_safe_redirect(
add_query_arg(
[
'page'         => 'coding-bunny-image-cleaner',
'tab'          => 'unused',
'deleted'      => $deleted,
'cbic_refresh' => 1,
],
admin_url( 'admin.php' )
)
);
exit;
}

public function handle_unused_settings() {
if ( ! current_user_can( 'manage_options' ) ) {
wp_die( esc_html__( 'Permission denied.', 'coding-bunny-image-cleaner' ) );
}
// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
$nonce = isset( $_POST['cbic_update_unused_settings_nonce'] ) ? wp_unslash( $_POST['cbic_update_unused_settings_nonce'] ) : '';
if ( ! wp_verify_nonce( $nonce, 'cbic_update_unused_settings' ) ) {
wp_die( esc_html__( 'Permission denied.', 'coding-bunny-image-cleaner' ) );
}

$delete_product_images = ( isset( $_POST['delete_product_images'] ) ) ? '1' : '0';
$delete_post_images    = ( isset( $_POST['delete_post_images'] ) ) ? '1' : '0';

update_option( 'cbic_delete_product_images', $delete_product_images );
update_option( 'cbic_delete_post_images', $delete_post_images );

wp_safe_redirect(
add_query_arg(
[
'page'             => 'coding-bunny-image-cleaner',
'tab'              => 'unused',
'settings_updated' => 1,
],
admin_url( 'admin.php' )
)
);
exit;
}

public function handle_delete_duplicate_action() {
$this->init_scanners();
if ( ! current_user_can( 'manage_options' ) ) {
wp_die( esc_html__( 'Permission denied.', 'coding-bunny-image-cleaner' ) );
}
// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
$nonce = isset( $_POST['cbic_delete_duplicate_images_nonce'] ) ? wp_unslash( $_POST['cbic_delete_duplicate_images_nonce'] ) : '';
if ( ! wp_verify_nonce( $nonce, 'cbic_delete_duplicate_images' ) ) {
wp_die( esc_html__( 'Permission denied.', 'coding-bunny-image-cleaner' ) );
}

$deleted = 0;
if ( ! empty( $_POST['duplicate_images'] ) && is_array( $_POST['duplicate_images'] ) ) {
$ids = array_map( 'intval', wp_unslash( $_POST['duplicate_images'] ) );

$duplicates    = $this->duplicate_scanner->get_duplicate_images();
$ids_to_delete = $ids;

foreach ( $duplicates as $group ) {
$group_ids = array();
foreach ( $group as $img ) {
if ( isset( $img['ID'] ) ) {
$group_ids[] = intval( $img['ID'] );
}
}
if ( empty( $group_ids ) ) {
continue;
}
$intersection = array_intersect( $group_ids, $ids_to_delete );
if ( count( $intersection ) >= count( $group_ids ) ) {
$keep_id = reset( $group_ids );
if ( false !== $keep_id && in_array( $keep_id, $ids_to_delete, true ) ) {
$key = array_search( $keep_id, $ids_to_delete, true );
if ( false !== $key ) {
unset( $ids_to_delete[ $key ] );
}
} else {
foreach ( $intersection as $remove_candidate ) {
	$key = array_search( $remove_candidate, $ids_to_delete, true );
	if ( false !== $key ) {
		unset( $ids_to_delete[ $key ] );
		break;
	}
}
}
}
}

$ids_to_delete = array_values( $ids_to_delete );

if ( ! empty( $ids_to_delete ) ) {
$deleted = $this->duplicate_scanner->delete_duplicate_attachments( $ids_to_delete );
} else {
$deleted = 0;
}
}

if ( $deleted > 0 && method_exists( $this->duplicate_scanner, 'run_duplicate_scan' ) ) {
$result = $this->duplicate_scanner->run_duplicate_scan( true );
if ( is_wp_error( $result ) ) {
}
}

wp_safe_redirect(
add_query_arg(
[
'page'         => 'coding-bunny-image-cleaner',
'tab'          => 'duplicate',
'deleted'      => $deleted,
'cbic_refresh' => 1,
],
admin_url( 'admin.php' )
)
);
exit;
}

private function get_active_tab() {
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'missing';
$allowed_tabs = [ 'missing', 'unused', 'duplicate', 'orphan', 'nofeatured' ];
return in_array( $tab, $allowed_tabs, true ) ? $tab : 'missing';
}
}

new CBIC_Image_Cleaner_Page();