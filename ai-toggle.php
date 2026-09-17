<?php
/**
 * Plugin Name:       AI Toggle
 * Description:       Adds a switch to your menu that lets visitors hide the posts of one chosen category from the blog feed, archives and search results. The choice is remembered in a cookie and applied server-side, so pagination and post counts stay correct.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Your Name
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-toggle
 * Domain Path:       /languages
 *
 * @package AI_Toggle
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The whole plugin lives in one class so that nothing ends up in the global
 * namespace. Everything is static; there is no instance state to keep.
 */
final class AI_Toggle_Plugin {

	const VERSION       = '1.0.0';
	const OPT_VERSION   = 'ai_toggle_version';
	const OPT_CATEGORY  = 'ai_toggle_category';
	const OPT_LOCATIONS = 'ai_toggle_locations';
	const OPT_LABEL     = 'ai_toggle_label';
	const COOKIE        = 'ai_toggle';
	const FIELD_STATE   = 'ai_toggle_state';
	const FIELD_TARGET  = 'ai_toggle_target';
	const NONCE_ACTION  = 'ai_toggle_switch';
	const SETTINGS_PAGE = 'ai-toggle';
	const OPTION_GROUP  = 'ai_toggle_settings';
	const COOKIE_TTL    = YEAR_IN_SECONDS;

	/**
	 * Option names used by builds of this plugin that predate the directory
	 * release. Kept only so existing installs do not lose their settings.
	 *
	 * @var array<string,string> New option name => old option name.
	 */
	private static $legacy_options = array(
		self::OPT_CATEGORY  => 'pcpal_ai_toggle_category',
		self::OPT_LOCATIONS => 'pcpal_ai_toggle_locations',
		self::OPT_LABEL     => 'pcpal_ai_toggle_label',
	);

	/**
	 * Validated category ID, or 0. Null means "not determined yet".
	 *
	 * @var int|null
	 */
	private static $category = null;

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade' ) );
		add_action( 'init', array( __CLASS__, 'load_textdomain' ) );
		add_action( 'init', array( __CLASS__, 'handle_switch' ), 1 );
		// Late on purpose: themes commonly set category__not_in at priority 10
		// with a plain set(), which throws an earlier value away.
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_main_query' ), 9999 );
		add_action( 'send_headers', array( __CLASS__, 'send_vary_header' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_filter( 'wp_nav_menu_items', array( __CLASS__, 'add_to_menu' ), 10, 2 );

		add_shortcode( 'ai_toggle', array( __CLASS__, 'shortcode' ) );

		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Load the bundled translations.
	 *
	 * @return void
	 */
	public static function load_textdomain() {
		load_plugin_textdomain( 'ai-toggle', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	/* ---------------------------------------------------------------------
	 * Upgrade / install
	 * ------------------------------------------------------------------ */

	/**
	 * Run the data migration once per version.
	 *
	 * WordPress does not fire the activation hook when a plugin is updated, so
	 * the migration cannot live in activate() alone.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( self::VERSION === get_option( self::OPT_VERSION, '' ) ) {
			return;
		}

		self::migrate_options();

		update_option( self::OPT_VERSION, self::VERSION, true );
	}

	/**
	 * Copy settings stored under the old option names, then drop the old rows.
	 *
	 * @return void
	 */
	private static function migrate_options() {
		foreach ( self::$legacy_options as $new => $old ) {
			$legacy = get_option( $old, null );
			if ( null === $legacy ) {
				continue;
			}

			if ( false === get_option( $new, false ) ) {
				add_option( $new, $legacy );
			}

			delete_option( $old );
		}

		// Even older builds kept the category in a single option.
		if ( false === get_option( self::OPT_CATEGORY, false ) ) {
			$legacy = intval( get_option( 'pcpal_ai_category_id', 0 ) );
			if ( $legacy > 0 ) {
				add_option( self::OPT_CATEGORY, $legacy );
				delete_option( 'pcpal_ai_category_id' );
			}
		}
	}

	/**
	 * Set the defaults on activation.
	 *
	 * @return void
	 */
	public static function activate() {
		self::migrate_options();

		if ( false === get_option( self::OPT_CATEGORY, false ) ) {
			add_option( self::OPT_CATEGORY, 0 );
		}

		if ( false === get_option( self::OPT_LOCATIONS, false ) ) {
			add_option( self::OPT_LOCATIONS, array( 'primary' ) );
		}

		update_option( self::OPT_VERSION, self::VERSION, true );
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------ */

	/**
	 * The configured category, but only while it still exists.
	 *
	 * get_term() returns null or a WP_Error for a deleted term, which is
	 * caught here so a removed category cannot take the site down.
	 *
	 * @return int 0 when nothing valid is configured.
	 */
	public static function category_id() {
		if ( null !== self::$category ) {
			return self::$category;
		}

		$id = (int) get_option( self::OPT_CATEGORY, 0 );
		if ( $id > 0 ) {
			$term = get_term( $id, 'category' );
			if ( ! $term || is_wp_error( $term ) ) {
				$id = 0;
			}
		}

		self::$category = max( 0, $id );

		return self::$category;
	}

	/**
	 * Menu locations the switch may be appended to.
	 *
	 * @return string[]
	 */
	public static function locations() {
		$stored = get_option( self::OPT_LOCATIONS, array( 'primary' ) );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_values( array_filter( array_map( 'strval', $stored ) ) );
	}

	/**
	 * The visible label next to the switch.
	 *
	 * @return string
	 */
	public static function label() {
		$label = (string) get_option( self::OPT_LABEL, '' );

		return '' !== trim( $label ) ? $label : __( 'Hide AI content', 'ai-toggle' );
	}

	/* ---------------------------------------------------------------------
	 * Visitor state
	 * ------------------------------------------------------------------ */

	/**
	 * Whether this visitor wants the posts hidden.
	 *
	 * @return bool
	 */
	public static function is_hiding() {
		if ( ! isset( $_COOKIE[ self::COOKIE ] ) ) {
			return false;
		}

		return '1' === sanitize_key( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
	}

	/**
	 * Whether the plugin actually has anything to do.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return self::category_id() > 0;
	}

	/**
	 * Handle a press of the switch: set the cookie and redirect back.
	 *
	 * A POST with a nonce rather than a GET parameter or a fetch() call: a POST
	 * is not prefetched or cached, and because the request is redirected
	 * afterwards the server renders the page in the new state right away. There
	 * is no asynchronous call that could overwrite an earlier decision.
	 *
	 * @return void
	 */
	public static function handle_switch() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only used to detect the request; the nonce is verified below.
		if ( ! isset( $_POST[ self::FIELD_STATE ] ) ) {
			return;
		}
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		// An expired nonce (a page that sat open for a day): change nothing, but
		// still redirect. The visitor then gets a fresh page with a valid nonce
		// instead of a button that silently does nothing.
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::NONCE_ACTION ) ) {
			wp_safe_redirect( self::target_url(), 303 );
			exit;
		}

		$state = '1' === sanitize_key( wp_unslash( $_POST[ self::FIELD_STATE ] ) ) ? '1' : '0';

		setcookie(
			self::COOKIE,
			$state,
			array(
				'expires'  => time() + self::COOKIE_TTL,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			)
		);
		$_COOKIE[ self::COOKIE ] = $state;

		wp_safe_redirect( self::target_url(), 303 );
		exit;
	}

	/**
	 * Where to return to after flipping the switch.
	 *
	 * Site-local paths only; wp_validate_redirect() rejects the rest.
	 *
	 * @return string
	 */
	private static function target_url() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The value is only used as a site-local redirect target and is validated below.
		$raw = isset( $_POST[ self::FIELD_TARGET ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD_TARGET ] ) ) : '';

		$fallback = home_url( '/' );
		if ( '' === $raw || 0 !== strpos( $raw, '/' ) || 0 === strpos( $raw, '//' ) ) {
			return $fallback;
		}

		return wp_validate_redirect( home_url( $raw ), $fallback );
	}

	/**
	 * The current path plus query string, usable as a return value.
	 *
	 * @return string
	 */
	private static function current_path() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$uri = is_string( $uri ) ? sanitize_url( $uri ) : '/';

		return ( '' !== $uri && 0 === strpos( $uri, '/' ) && 0 !== strpos( $uri, '//' ) ) ? $uri : '/';
	}

	/* ---------------------------------------------------------------------
	 * The actual filtering
	 * ------------------------------------------------------------------ */

	/**
	 * Exclude the category from the main query of list views.
	 *
	 * This is the only place where posts disappear. Nothing is hidden with CSS:
	 * that left holes in the list and made pagination, max_num_pages and any
	 * load-more or infinite scroll disagree with what was actually shown.
	 *
	 * @param WP_Query $query The query that is about to run.
	 * @return void
	 */
	public static function filter_main_query( $query ) {
		if ( is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() ) {
			return;
		}
		if ( ! self::is_hiding() || ! self::is_active() ) {
			return;
		}
		if ( $query->is_singular() || $query->is_feed() ) {
			return;
		}
		if ( ! ( $query->is_home() || $query->is_archive() || $query->is_search() ) ) {
			return;
		}

		$category = self::category_id();

		// Do not empty the archive of the category itself: anyone who navigates
		// there is asking for it explicitly.
		if ( $query->is_category( $category ) ) {
			return;
		}

		// Add to whatever is already there instead of replacing it: themes
		// exclude categories here too and those must stay excluded.
		$excluded   = (array) $query->get( 'category__not_in' );
		$excluded   = array_filter( array_map( 'intval', $excluded ) );
		$excluded[] = $category;

		$query->set( 'category__not_in', array_values( array_unique( $excluded ) ) );
	}

	/**
	 * The output depends on a cookie, so every cache needs to know that.
	 *
	 * @return void
	 */
	public static function send_vary_header() {
		if ( is_admin() || is_singular() || ! self::is_active() ) {
			return;
		}

		header( 'Vary: Cookie', false );

		if ( self::is_hiding() && ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
	}

	/**
	 * Body class so a theme or child theme can hook into the state.
	 *
	 * @param string[] $classes Existing classes.
	 * @return string[]
	 */
	public static function body_class( $classes ) {
		if ( self::is_active() && self::is_hiding() ) {
			$classes[] = 'ai-toggle-hiding';
		}

		return $classes;
	}

	/* ---------------------------------------------------------------------
	 * Front end
	 * ------------------------------------------------------------------ */

	/**
	 * Enqueue the stylesheet, but only when the plugin actually does something.
	 *
	 * @return void
	 */
	public static function enqueue_assets() {
		if ( ! self::is_active() ) {
			return;
		}

		wp_enqueue_style(
			'ai-toggle',
			plugins_url( 'assets/ai-toggle.css', __FILE__ ),
			array(),
			self::VERSION
		);
	}

	/**
	 * Build the switch.
	 *
	 * A form with a submit button, no JavaScript. It therefore works without JS
	 * and can by definition not get out of step with what the server renders.
	 *
	 * @return string HTML, or an empty string when nothing is configured.
	 */
	public static function render() {
		if ( ! self::is_active() ) {
			return '';
		}

		$hiding = self::is_hiding();
		$next   = $hiding ? '0' : '1';
		$label  = self::label();
		$title  = $hiding
			? __( 'Show the AI content in the feed again', 'ai-toggle' )
			: __( 'Hide the AI content in the feed', 'ai-toggle' );

		ob_start();
		?>
		<span class="ai-toggle">
			<form class="ai-toggle__form" method="post" action="<?php echo esc_url( self::current_path() ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="<?php echo esc_attr( self::FIELD_STATE ); ?>" value="<?php echo esc_attr( $next ); ?>" />
				<input type="hidden" name="<?php echo esc_attr( self::FIELD_TARGET ); ?>" value="<?php echo esc_attr( self::current_path() ); ?>" />
				<button
					type="submit"
					class="ai-toggle__button"
					role="switch"
					aria-checked="<?php echo $hiding ? 'true' : 'false'; ?>"
					title="<?php echo esc_attr( $title ); ?>"
				>
					<span class="ai-toggle__track" aria-hidden="true"><span class="ai-toggle__thumb"></span></span>
					<span class="ai-toggle__text"><?php echo esc_html( $label ); ?></span>
				</button>
			</form>
		</span>
		<?php

		return trim( (string) ob_get_clean() );
	}

	/**
	 * Shortcode variant, for placement outside the menu.
	 *
	 * @return string
	 */
	public static function shortcode() {
		return self::render();
	}

	/**
	 * Append the switch to the menu items of the chosen location(s).
	 *
	 * Matched strictly on theme_location, so the switch does not end up in
	 * footer or widget menus that have no location.
	 *
	 * @param string   $items Menu HTML so far.
	 * @param stdClass $args  Arguments of wp_nav_menu().
	 * @return string
	 */
	public static function add_to_menu( $items, $args ) {
		if ( ! self::is_active() ) {
			return $items;
		}

		$location = isset( $args->theme_location ) ? (string) $args->theme_location : '';
		if ( '' === $location || ! in_array( $location, self::locations(), true ) ) {
			return $items;
		}

		$toggle = self::render();
		if ( '' === $toggle ) {
			return $items;
		}

		return $items . '<li class="menu-item ai-toggle-menu-item">' . $toggle . '</li>';
	}

	/* ---------------------------------------------------------------------
	 * Admin screen
	 * ------------------------------------------------------------------ */

	/**
	 * Settings page under Settings.
	 *
	 * @return void
	 */
	public static function admin_menu() {
		add_options_page(
			__( 'AI Toggle', 'ai-toggle' ),
			__( 'AI Toggle', 'ai-toggle' ),
			'manage_options',
			self::SETTINGS_PAGE,
			array( __CLASS__, 'settings_page' )
		);
	}

	/**
	 * Register the options, each with its own sanitizing callback.
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			self::OPT_CATEGORY,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( __CLASS__, 'sanitize_category' ),
				'default'           => 0,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			self::OPT_LOCATIONS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_locations' ),
				'default'           => array( 'primary' ),
			)
		);

		register_setting(
			self::OPTION_GROUP,
			self::OPT_LABEL,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
	}

	/**
	 * Accept a category ID only when the term exists.
	 *
	 * @param mixed $value Raw input.
	 * @return int
	 */
	public static function sanitize_category( $value ) {
		$id = intval( $value );
		if ( $id <= 0 ) {
			return 0;
		}

		$term = get_term( $id, 'category' );

		return ( $term && ! is_wp_error( $term ) ) ? $id : 0;
	}

	/**
	 * Accept only locations the active theme actually registers.
	 *
	 * @param mixed $value Raw input.
	 * @return string[]
	 */
	public static function sanitize_locations( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$registered = array_keys( get_registered_nav_menus() );

		$clean = array();
		foreach ( $value as $location ) {
			$location = sanitize_key( $location );
			if ( in_array( $location, $registered, true ) && ! in_array( $location, $clean, true ) ) {
				$clean[] = $location;
			}
		}

		return $clean;
	}

	/**
	 * The settings page itself.
	 *
	 * @return void
	 */
	public static function settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$selected   = (int) get_option( self::OPT_CATEGORY, 0 );
		$locations  = self::locations();
		$registered = get_registered_nav_menus();
		$categories = get_categories( array( 'hide_empty' => 0 ) );
		$missing    = ( $selected > 0 && 0 === self::category_id() );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'AI Toggle', 'ai-toggle' ); ?></h1>

			<?php if ( $missing ) : ?>
				<div class="notice notice-warning">
					<p><?php echo esc_html__( 'The category you selected earlier no longer exists. The switch stays hidden until you pick a category that does exist.', 'ai-toggle' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION_GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="ai-toggle-category"><?php echo esc_html__( 'Category to hide', 'ai-toggle' ); ?></label>
						</th>
						<td>
							<select name="<?php echo esc_attr( self::OPT_CATEGORY ); ?>" id="ai-toggle-category">
								<option value="0"><?php echo esc_html__( '— None (switch disabled) —', 'ai-toggle' ); ?></option>
								<?php foreach ( $categories as $category ) : ?>
									<option value="<?php echo esc_attr( $category->term_id ); ?>" <?php selected( $selected, $category->term_id ); ?>>
										<?php
										printf(
											/* translators: 1: category name, 2: number of posts in that category */
											'%1$s (%2$d)',
											esc_html( $category->name ),
											(int) $category->count
										);
										?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php echo esc_html__( 'Posts in this category disappear from the feed as soon as a visitor turns the switch on. Without a category the plugin does nothing.', 'ai-toggle' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Show the switch in', 'ai-toggle' ); ?></th>
						<td>
							<?php if ( empty( $registered ) ) : ?>
								<p><?php echo esc_html__( 'This theme does not register any menu locations.', 'ai-toggle' ); ?></p>
							<?php else : ?>
								<fieldset>
									<?php foreach ( $registered as $slug => $description ) : ?>
										<label style="display:block;margin-bottom:4px;">
											<input
												type="checkbox"
												name="<?php echo esc_attr( self::OPT_LOCATIONS ); ?>[]"
												value="<?php echo esc_attr( $slug ); ?>"
												<?php checked( in_array( $slug, $locations, true ) ); ?>
											/>
											<?php echo esc_html( $description ); ?>
											<code><?php echo esc_html( $slug ); ?></code>
										</label>
									<?php endforeach; ?>
								</fieldset>
							<?php endif; ?>
							<p class="description"><?php echo esc_html__( 'Check nothing to place the switch with the [ai_toggle] shortcode only.', 'ai-toggle' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="ai-toggle-label"><?php echo esc_html__( 'Text next to the switch', 'ai-toggle' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								class="regular-text"
								id="ai-toggle-label"
								name="<?php echo esc_attr( self::OPT_LABEL ); ?>"
								value="<?php echo esc_attr( (string) get_option( self::OPT_LABEL, '' ) ); ?>"
								placeholder="<?php echo esc_attr__( 'Hide AI content', 'ai-toggle' ); ?>"
							/>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php echo esc_html__( 'How it works', 'ai-toggle' ); ?></h2>
			<p>
				<?php echo esc_html__( 'The visitor\'s choice is stored in a cookie and applied server-side to the main query of the blog page, the archives and the search results. Pagination and the number of pages therefore always match what is on screen. Single posts and the archive of the category itself stay reachable; the switch filters the feed, it does not block anything.', 'ai-toggle' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Settings link on the plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=' . self::SETTINGS_PAGE ) ),
			esc_html__( 'Settings', 'ai-toggle' )
		);

		array_unshift( $links, $settings );

		return $links;
	}
}

register_activation_hook( __FILE__, array( 'AI_Toggle_Plugin', 'activate' ) );

AI_Toggle_Plugin::boot();
