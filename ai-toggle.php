<?php
/**
 * Plugin Name:       AI Toggle
 * Plugin URI:        https://jmvdpal.nl/
 * Description:       Zet een schakelaar in de menubalk waarmee bezoekers de posts uit een gekozen categorie (de LLM-geschreven posts) uit de feed kunnen verbergen. De keuze wordt in een cookie onthouden en serverzijdig toegepast, zodat paginering en telling blijven kloppen.
 * Version:           1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            J.M. van der Pal (PCPal)
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-toggle
 *
 * @package AI_Toggle
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hele plugin in één class zodat er geen losse functies in de globale ruimte
 * belanden. Alles statisch; er is geen instantie-state nodig.
 */
final class PCPal_AI_Toggle {

	const VERSION       = '1.0';
	const OPT_CATEGORY  = 'pcpal_ai_toggle_category';
	const OPT_LOCATIONS = 'pcpal_ai_toggle_locations';
	const OPT_LABEL     = 'pcpal_ai_toggle_label';
	const COOKIE        = 'pcpal_ai_toggle';
	const FIELD_STATE   = 'pcpal_ai_toggle_state';
	const FIELD_TARGET  = 'pcpal_ai_toggle_target';
	const NONCE_ACTION  = 'pcpal_ai_toggle_switch';
	const COOKIE_TTL    = YEAR_IN_SECONDS;

	/** @var int|null Gevalideerde categorie-ID, of 0. Null = nog niet bepaald. */
	private static $category = null;

	/** Haakjes registreren. */
	public static function boot() {
		add_action( 'init', array( __CLASS__, 'handle_switch' ), 1 );
		// Late, want Education Zone Pro zet category__not_in op prioriteit 10
		// met een simpele set() en gooit een eerdere waarde dus weg.
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_main_query' ), 9999 );
		add_action( 'send_headers', array( __CLASS__, 'send_vary_header' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_filter( 'wp_nav_menu_items', array( __CLASS__, 'add_to_menu' ), 10, 2 );

		add_shortcode( 'ai_toggle', array( __CLASS__, 'shortcode' ) );
		// Alias voor de oude conceptversie, zodat bestaande plaatsingen blijven werken.
		add_shortcode( 'pcpal_ai_toggle', array( __CLASS__, 'shortcode' ) );

		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'action_links' ) );
	}

	/* ---------------------------------------------------------------------
	 * Instellingen
	 * ------------------------------------------------------------------ */

	/**
	 * De ingestelde categorie, maar alleen als die nog echt bestaat.
	 *
	 * get_category( $id )->slug uit het concept was fataal zodra de categorie
	 * verwijderd werd; get_term() geeft null of WP_Error die we hier afvangen.
	 *
	 * @return int 0 als er niets (geldigs) is ingesteld.
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
	 * Menulocaties waar de toggle in geprikt mag worden.
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

	/** @return string Zichtbaar label naast de schakelaar. */
	public static function label() {
		$label = (string) get_option( self::OPT_LABEL, '' );

		return '' !== trim( $label ) ? $label : __( 'Verberg AI-content', 'ai-toggle' );
	}

	/* ---------------------------------------------------------------------
	 * Bezoekersstatus
	 * ------------------------------------------------------------------ */

	/** @return bool True als deze bezoeker de AI-posts verborgen wil hebben. */
	public static function is_hiding() {
		return isset( $_COOKIE[ self::COOKIE ] ) && '1' === $_COOKIE[ self::COOKIE ];
	}

	/** @return bool True als de plugin daadwerkelijk iets te doen heeft. */
	public static function is_active() {
		return self::category_id() > 0;
	}

	/**
	 * Verwerkt het indrukken van de schakelaar: cookie zetten en terugsturen.
	 *
	 * Bewust een POST met nonce in plaats van een GET-parameter of een
	 * fetch()-aanroep: een POST wordt niet geprefetcht of gecachet, en omdat we
	 * daarna redirecten rendert de server de pagina meteen in de nieuwe stand.
	 * Daarmee bestaat de race uit de conceptversie niet meer - er is geen
	 * asynchrone aanroep die een eerdere beslissing kan overschrijven.
	 */
	public static function handle_switch() {
		if ( ! isset( $_POST[ self::FIELD_STATE ] ) ) {
			return;
		}
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		// Verlopen nonce (pagina die een etmaal open heeft gestaan): niets
		// wijzigen, maar wel terugsturen. De bezoeker krijgt dan een verse
		// pagina met een geldige nonce in plaats van een knop die niets doet.
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ), self::NONCE_ACTION ) ) {
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
	 * Waar we na het schakelen naartoe terugkeren.
	 *
	 * Alleen paden op deze site; wp_validate_redirect() vangt de rest af.
	 *
	 * @return string
	 */
	private static function target_url() {
		$raw = isset( $_POST[ self::FIELD_TARGET ] ) ? wp_unslash( $_POST[ self::FIELD_TARGET ] ) : '';
		$raw = is_string( $raw ) ? $raw : '';

		$fallback = home_url( '/' );
		if ( '' === $raw || 0 !== strpos( $raw, '/' ) || 0 === strpos( $raw, '//' ) ) {
			return $fallback;
		}

		return wp_validate_redirect( home_url( $raw ), $fallback );
	}

	/** Het huidige pad + querystring, geschikt als terugkeerwaarde. */
	private static function current_path() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$uri = is_string( $uri ) ? $uri : '/';
		$uri = esc_url_raw( $uri );

		return ( '' !== $uri && 0 === strpos( $uri, '/' ) && 0 !== strpos( $uri, '//' ) ) ? $uri : '/';
	}

	/* ---------------------------------------------------------------------
	 * De eigenlijke filtering
	 * ------------------------------------------------------------------ */

	/**
	 * Sluit de categorie uit in de hoofdquery van lijstweergaves.
	 *
	 * Dit is de enige plek waar posts verdwijnen. Er wordt niets met CSS
	 * verborgen: dat liet gaten vallen in de lijst en liet paginering,
	 * max_num_pages en de infinite scroll van het thema niet meer kloppen.
	 * Omdat de infinite scroll van Education Zone Pro /page/N/ gewoon met
	 * cookies ophaalt, geldt dit filter ook voor bijgeladen pagina's.
	 *
	 * @param WP_Query $query De query die op het punt staat te draaien.
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

		// Het archief van de categorie zelf niet leegmaken: wie daar bewust
		// naartoe navigeert, vraagt er expliciet om.
		if ( $query->is_category( $category ) ) {
			return;
		}

		// Toevoegen aan wat er al staat, niet vervangen: het thema sluit hier
		// zelf ook categorieën uit en die moeten uitgesloten blijven.
		$excluded   = (array) $query->get( 'category__not_in' );
		$excluded   = array_filter( array_map( 'intval', $excluded ) );
		$excluded[] = $category;

		$query->set( 'category__not_in', array_values( array_unique( $excluded ) ) );
	}

	/**
	 * De uitvoer hangt van een cookie af, dus dat moet elke cache weten.
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
	 * Body-class zodat een thema of child-thema erop kan inhaken.
	 *
	 * @param string[] $classes Bestaande classes.
	 * @return string[]
	 */
	public static function body_class( $classes ) {
		if ( self::is_active() && self::is_hiding() ) {
			$classes[] = 'ai-toggle-hiding';
		}

		return $classes;
	}

	/* ---------------------------------------------------------------------
	 * Weergave
	 * ------------------------------------------------------------------ */

	/**
	 * Stylesheet meegeven, maar alleen als de plugin echt iets doet.
	 *
	 * Inline en niet als los bestand: Education Zone Pro haalt via
	 * style_loader_src de ?ver= van alle stylesheets af, en Cloudflare cachet
	 * statische bestanden. Een gewijzigde assets/ai-toggle.css zou daardoor
	 * dagenlang niet bij bezoekers aankomen. Het gaat om nog geen twee
	 * kilobyte, dus inline kost niets en is altijd actueel.
	 */
	public static function enqueue_assets() {
		if ( ! self::is_active() ) {
			return;
		}

		wp_register_style( 'ai-toggle', false, array(), self::VERSION );
		wp_enqueue_style( 'ai-toggle' );
		wp_add_inline_style( 'ai-toggle', self::css() );
	}

	/**
	 * De stylesheet als tekst. assets/ai-toggle.css blijft de bron.
	 *
	 * @return string
	 */
	private static function css() {
		static $css = null;

		if ( null === $css ) {
			$file = plugin_dir_path( __FILE__ ) . 'assets/ai-toggle.css';
			$css  = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}

		return $css;
	}

	/**
	 * Bouwt de schakelaar.
	 *
	 * Een formulier met een submit-knop, geen JavaScript. Werkt dus ook zonder
	 * JS en kan per definitie niet uit de pas lopen met wat de server rendert.
	 *
	 * @return string HTML, of een lege string als er niets is ingesteld.
	 */
	public static function render() {
		if ( ! self::is_active() ) {
			return '';
		}

		$hiding = self::is_hiding();
		$next   = $hiding ? '0' : '1';
		$label  = self::label();

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
					title="<?php echo esc_attr( $hiding ? __( 'Toon de AI-content weer in de feed', 'ai-toggle' ) : __( 'Verberg de AI-content in de feed', 'ai-toggle' ) ); ?>"
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
	 * Shortcode-variant, voor plaatsing buiten het menu.
	 *
	 * @return string
	 */
	public static function shortcode() {
		return self::render();
	}

	/**
	 * Hangt de schakelaar achter de menu-items van de gekozen locatie(s).
	 *
	 * Strikt op theme_location matchen; het concept plakte de toggle ook in
	 * elk menu zonder locatie, dus ook in footer- en widgetmenu's.
	 *
	 * @param string   $items Menu-HTML tot nu toe.
	 * @param stdClass $args  Argumenten van wp_nav_menu().
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
	 * Beheerscherm
	 * ------------------------------------------------------------------ */

	/** Instellingenpagina onder Instellingen. */
	public static function admin_menu() {
		add_options_page(
			__( 'AI Toggle', 'ai-toggle' ),
			__( 'AI Toggle', 'ai-toggle' ),
			'manage_options',
			'ai-toggle',
			array( __CLASS__, 'settings_page' )
		);
	}

	/** Opties registreren, met sanitizing per optie. */
	public static function register_settings() {
		register_setting(
			'ai_toggle_settings',
			self::OPT_CATEGORY,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( __CLASS__, 'sanitize_category' ),
				'default'           => 0,
			)
		);

		register_setting(
			'ai_toggle_settings',
			self::OPT_LOCATIONS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_locations' ),
				'default'           => array( 'primary' ),
			)
		);

		register_setting(
			'ai_toggle_settings',
			self::OPT_LABEL,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
	}

	/**
	 * @param mixed $value Ruwe invoer.
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
	 * Alleen locaties die het actieve thema ook echt registreert.
	 *
	 * @param mixed $value Ruwe invoer.
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

	/** De instellingenpagina zelf. */
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
			<h1><?php esc_html_e( 'AI Toggle', 'ai-toggle' ); ?></h1>

			<?php if ( $missing ) : ?>
				<div class="notice notice-warning">
					<p><?php esc_html_e( 'De eerder gekozen categorie bestaat niet meer. De schakelaar wordt niet getoond totdat je een bestaande categorie kiest.', 'ai-toggle' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'ai_toggle_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="ai-toggle-category"><?php esc_html_e( 'Te verbergen categorie', 'ai-toggle' ); ?></label>
						</th>
						<td>
							<select name="<?php echo esc_attr( self::OPT_CATEGORY ); ?>" id="ai-toggle-category">
								<option value="0"><?php esc_html_e( '— Geen (schakelaar uitgeschakeld) —', 'ai-toggle' ); ?></option>
								<?php foreach ( $categories as $category ) : ?>
									<option value="<?php echo esc_attr( $category->term_id ); ?>" <?php selected( $selected, $category->term_id ); ?>>
										<?php
										printf(
											'%1$s (%2$d)',
											esc_html( $category->name ),
											(int) $category->count
										);
										?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Posts uit deze categorie verdwijnen uit de feed zodra een bezoeker de schakelaar aanzet. Zonder categorie doet de plugin niets.', 'ai-toggle' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Toon de schakelaar in', 'ai-toggle' ); ?></th>
						<td>
							<?php if ( empty( $registered ) ) : ?>
								<p><?php esc_html_e( 'Dit thema registreert geen menulocaties.', 'ai-toggle' ); ?></p>
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
							<p class="description"><?php esc_html_e( 'Vink niets aan om de schakelaar alleen via de shortcode [ai_toggle] te plaatsen.', 'ai-toggle' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="ai-toggle-label"><?php esc_html_e( 'Tekst naast de schakelaar', 'ai-toggle' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								class="regular-text"
								id="ai-toggle-label"
								name="<?php echo esc_attr( self::OPT_LABEL ); ?>"
								value="<?php echo esc_attr( (string) get_option( self::OPT_LABEL, '' ) ); ?>"
								placeholder="<?php echo esc_attr__( 'Verberg AI-content', 'ai-toggle' ); ?>"
							/>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Hoe het werkt', 'ai-toggle' ); ?></h2>
			<p>
				<?php esc_html_e( 'De keuze van de bezoeker staat in een cookie en wordt serverzijdig toegepast op de hoofdquery van de blogpagina, archieven en zoekresultaten. Daardoor kloppen de paginering en het aantal pagina\'s altijd met wat er te zien is. Losse berichten en het archief van de categorie zelf blijven bereikbaar; de schakelaar filtert de feed, hij blokkeert niets.', 'ai-toggle' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Settings-link op de pluginpagina.
	 *
	 * @param string[] $links Bestaande links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=ai-toggle' ) ),
			esc_html__( 'Instellingen', 'ai-toggle' )
		);

		array_unshift( $links, $settings );

		return $links;
	}

	/**
	 * Bij activering: neem de instelling van de oude conceptversie over.
	 */
	public static function activate() {
		if ( false === get_option( self::OPT_CATEGORY, false ) ) {
			$legacy = intval( get_option( 'pcpal_ai_category_id', 0 ) );
			add_option( self::OPT_CATEGORY, $legacy > 0 ? $legacy : 0 );
		}

		if ( false === get_option( self::OPT_LOCATIONS, false ) ) {
			add_option( self::OPT_LOCATIONS, array( 'primary' ) );
		}
	}
}

register_activation_hook( __FILE__, array( 'PCPal_AI_Toggle', 'activate' ) );

PCPal_AI_Toggle::boot();
