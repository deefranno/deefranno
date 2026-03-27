<?php
/**
 * Plugin Name: Bellocq Elementor Template
 * Plugin URI:  https://github.com/deefranno/deefranno
 * Description: Luxury editorial homepage template for Elementor — Cormorant Garamond + Montserrat, cream/black palette inspired by bellocqtea.com.
 * Version:     1.0.0
 * Author:      deefranno
 * License:     GPL-2.0-or-later
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Elementor tested up to: 3.25
 * Text Domain: bellocq-template
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BELLOCQ_VERSION',       '1.0.0' );
define( 'BELLOCQ_PATH',          plugin_dir_path( __FILE__ ) );
define( 'BELLOCQ_URL',           plugin_dir_url( __FILE__ ) );
define( 'BELLOCQ_TEMPLATE_FILE', BELLOCQ_PATH . 'template.json' );

/* -----------------------------------------------------------------------
 * Activation: auto-import template into Elementor library
 * --------------------------------------------------------------------- */

register_activation_hook( __FILE__, 'bellocq_activate' );

function bellocq_activate(): void {
	if ( ! file_exists( BELLOCQ_TEMPLATE_FILE ) ) {
		return;
	}

	// Avoid duplicate imports across re-activations.
	$already = get_posts( [
		'post_type'      => 'elementor_library',
		'meta_key'       => '_bellocq_imported',
		'meta_value'     => '1',
		'posts_per_page' => 1,
		'fields'         => 'ids',
	] );
	if ( ! empty( $already ) ) {
		return;
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$raw = file_get_contents( BELLOCQ_TEMPLATE_FILE );
	if ( false === $raw ) {
		return;
	}

	$tpl = json_decode( $raw, true );
	if ( null === $tpl || JSON_ERROR_NONE !== json_last_error() ) {
		return;
	}

	bellocq_save_template( $tpl );
}

/**
 * Persist a decoded template array as an Elementor library post.
 *
 * @param array<string,mixed> $tpl
 * @return int|\WP_Error New post ID or WP_Error.
 */
function bellocq_save_template( array $tpl ): int|\WP_Error {
	$post_id = wp_insert_post( [
		'post_title'  => $tpl['title'] ?? 'Bellocq Luxury Homepage',
		'post_type'   => 'elementor_library',
		'post_status' => 'publish',
	] );

	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	update_post_meta( $post_id, '_elementor_data',          wp_slash( wp_json_encode( $tpl['content']       ?? [] ) ) );
	update_post_meta( $post_id, '_elementor_template_type', 'page' );
	update_post_meta( $post_id, '_elementor_edit_mode',     'builder' );
	update_post_meta( $post_id, '_bellocq_imported',        '1' );

	if ( ! empty( $tpl['page_settings'] ) ) {
		update_post_meta( $post_id, '_elementor_page_settings', $tpl['page_settings'] );
	}

	// Bust Elementor's file cache so the new template is visible immediately.
	if ( class_exists( '\\Elementor\\Plugin' ) ) {
		\Elementor\Plugin::$instance->files_manager->clear_cache();
	}

	return $post_id;
}

/* -----------------------------------------------------------------------
 * Main Plugin Class
 * --------------------------------------------------------------------- */

final class Bellocq_Template {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'plugins_loaded', [ $this, 'init' ] );
	}

	public function init(): void {
		if ( ! did_action( 'elementor/loaded' ) ) {
			add_action( 'admin_notices', [ $this, 'notice_missing_elementor' ] );
			return;
		}

		add_action( 'wp_enqueue_scripts',                  [ $this, 'enqueue_frontend' ] );
		add_action( 'elementor/editor/after_enqueue_styles', [ $this, 'enqueue_frontend' ] );
		add_action( 'admin_menu',                          [ $this, 'admin_menu' ] );
		add_action( 'wp_ajax_bellocq_import',              [ $this, 'ajax_import' ] );
	}

	/* ---- Notices ---- */

	public function notice_missing_elementor(): void {
		$link = '<strong><a href="https://wordpress.org/plugins/elementor/" target="_blank">Elementor</a></strong>';
		/* translators: %s: Elementor plugin link */
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			wp_kses_post( sprintf( __( 'Bellocq Template requires %s to be installed and active.', 'bellocq-template' ), $link ) )
		);
	}

	/* ---- Assets ---- */

	public function enqueue_frontend(): void {
		wp_enqueue_style(
			'bellocq-fonts',
			'https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Montserrat:wght@200;300;400;500&display=swap',
			[],
			null
		);
		wp_enqueue_style(
			'bellocq-styles',
			BELLOCQ_URL . 'assets/style.css',
			[ 'bellocq-fonts' ],
			BELLOCQ_VERSION
		);

		// Tiny script: adds `.scrolled` to sticky header on scroll for box-shadow effect.
		wp_register_script( 'bellocq-scroll', false, [], BELLOCQ_VERSION, [ 'strategy' => 'defer' ] );
		wp_enqueue_script( 'bellocq-scroll' );
		wp_add_inline_script(
			'bellocq-scroll',
			'(function(){
				var h = document.querySelector(".bellocq-sticky-header");
				if (!h) return;
				window.addEventListener("scroll", function(){
					h.classList.toggle("scrolled", window.scrollY > 40);
				}, { passive: true });
			})();'
		);
	}

	/* ---- Admin menu ---- */

	public function admin_menu(): void {
		add_submenu_page(
			'elementor',
			__( 'Bellocq Template', 'bellocq-template' ),
			__( 'Bellocq Template', 'bellocq-template' ),
			'manage_options',
			'bellocq-elementor-template',
			[ $this, 'render_admin_page' ]
		);
	}

	public function render_admin_page(): void {
		$imported = (bool) count( get_posts( [
			'post_type'      => 'elementor_library',
			'meta_key'       => '_bellocq_imported',
			'meta_value'     => '1',
			'posts_per_page' => 1,
			'fields'         => 'ids',
		] ) );
		?>
		<div class="wrap" style="max-width:820px;">
			<h1 style="font-family:Georgia,serif;font-weight:normal;font-size:26px;margin-bottom:4px;">
				<?php esc_html_e( 'Bellocq Elementor Template', 'bellocq-template' ); ?>
			</h1>
			<p style="color:#666;margin-bottom:28px;">
				<?php esc_html_e( 'Luxury editorial homepage — Cormorant Garamond · Montserrat · Cream/Black palette.', 'bellocq-template' ); ?>
			</p>

			<?php if ( $imported ) : ?>
				<div class="notice notice-success inline" style="margin:0 0 24px;">
					<p>
						<?php esc_html_e( 'Template is in your library.', 'bellocq-template' ); ?>
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=elementor_library' ) ); ?>">
							<?php esc_html_e( 'View Saved Templates &rarr;', 'bellocq-template' ); ?>
						</a>
					</p>
				</div>
			<?php else : ?>
				<p><?php esc_html_e( 'Template not yet imported (Elementor may not have been active at plugin activation time).', 'bellocq-template' ); ?></p>
				<button id="bq-import-btn" class="button button-primary button-large">
					<?php esc_html_e( 'Import Template Now', 'bellocq-template' ); ?>
				</button>
				<span id="bq-import-msg" style="margin-left:12px;display:none;"></span>
			<?php endif; ?>

			<hr style="margin:32px 0;">

			<h2 style="font-size:15px;"><?php esc_html_e( 'How to Use', 'bellocq-template' ); ?></h2>
			<ol style="line-height:2.2;color:#444;font-size:13px;">
				<li><?php esc_html_e( 'Create or open a page in WordPress.', 'bellocq-template' ); ?></li>
				<li><?php esc_html_e( 'Click \'Edit with Elementor\'.', 'bellocq-template' ); ?></li>
				<li><?php esc_html_e( 'Click the folder icon (Templates) in the left panel.', 'bellocq-template' ); ?></li>
				<li><?php esc_html_e( 'Go to My Templates → find \'Bellocq Luxury Homepage\' → Insert.', 'bellocq-template' ); ?></li>
				<li><?php esc_html_e( 'Replace placeholder images and customize text/colors.', 'bellocq-template' ); ?></li>
			</ol>

			<h2 style="font-size:15px;"><?php esc_html_e( 'Manual JSON Import', 'bellocq-template' ); ?></h2>
			<p style="color:#444;font-size:13px;">
				<a href="<?php echo esc_url( BELLOCQ_URL . 'template.json' ); ?>" download class="button button-secondary">
					<?php esc_html_e( 'Download template.json', 'bellocq-template' ); ?>
				</a>
				&nbsp;
				<?php esc_html_e( 'Then: Elementor &rarr; Templates &rarr; Import Templates.', 'bellocq-template' ); ?>
			</p>
		</div>

		<script>
		(function($){
			$('#bq-import-btn').on('click', function(){
				var $b = $(this), $m = $('#bq-import-msg');
				$b.prop('disabled', true).text('<?php esc_html_e( 'Importing…', 'bellocq-template' ); ?>');
				$m.show().css('color','').text('');
				$.post(ajaxurl, { action: 'bellocq_import', nonce: '<?php echo esc_js( wp_create_nonce( 'bellocq_import' ) ); ?>' },
					function(r){
						if (r.success) {
							$m.css('color','green').text(r.data.message);
							$b.text('<?php esc_html_e( 'Done!', 'bellocq-template' ); ?>');
						} else {
							$m.css('color','red').text(r.data.message || '<?php esc_html_e( 'Error — check error log.', 'bellocq-template' ); ?>');
							$b.prop('disabled', false).text('<?php esc_html_e( 'Import Template Now', 'bellocq-template' ); ?>');
						}
					}
				);
			});
		})(jQuery);
		</script>
		<?php
	}

	/* ---- AJAX import ---- */

	public function ajax_import(): void {
		check_ajax_referer( 'bellocq_import', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'bellocq-template' ) ] );
		}

		if ( ! file_exists( BELLOCQ_TEMPLATE_FILE ) ) {
			wp_send_json_error( [ 'message' => __( 'template.json not found in plugin folder.', 'bellocq-template' ) ] );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw = file_get_contents( BELLOCQ_TEMPLATE_FILE );
		$tpl = json_decode( $raw ?: '', true );

		if ( ! is_array( $tpl ) || JSON_ERROR_NONE !== json_last_error() ) {
			wp_send_json_error( [ 'message' => __( 'Invalid JSON in template.json.', 'bellocq-template' ) ] );
		}

		$result = bellocq_save_template( $tpl );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		wp_send_json_success( [
			'message'     => __( 'Template imported! Go to Elementor → Templates → Saved Templates.', 'bellocq-template' ),
			'template_id' => $result,
		] );
	}
}

Bellocq_Template::instance();
