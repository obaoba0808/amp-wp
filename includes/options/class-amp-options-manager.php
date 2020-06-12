<?php
/**
 * Class AMP_Options_Manager.
 *
 * @package AMP
 */

use AmpProject\AmpWP\Option;
use AmpProject\AmpWP\PluginRegistry;

/**
 * Class AMP_Options_Manager
 */
class AMP_Options_Manager {

	/**
	 * Option name.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'amp-options';

	/**
	 * Default option values.
	 *
	 * @var array
	 */
	protected static $defaults = [
		Option::THEME_SUPPORT           => AMP_Theme_Support::READER_MODE_SLUG,
		Option::SUPPORTED_POST_TYPES    => [ 'post' ],
		Option::ANALYTICS               => [],
		Option::ALL_TEMPLATES_SUPPORTED => true,
		Option::SUPPORTED_TEMPLATES     => [ 'is_singular' ],
		Option::VERSION                 => AMP__VERSION,
		Option::READER_THEME            => AMP_Reader_Themes::DEFAULT_READER_THEME,
		Option::SUPPRESSED_PLUGINS      => [],
	];

	/**
	 * Sets up hooks.
	 */
	public static function init() {
		add_action( 'admin_notices', [ __CLASS__, 'render_welcome_notice' ] );
		add_action( 'admin_notices', [ __CLASS__, 'render_php_css_parser_conflict_notice' ] );
		add_action( 'admin_notices', [ __CLASS__, 'insecure_connection_notice' ] );
	}

	/**
	 * Register settings.
	 */
	public static function register_settings() {
		register_setting(
			self::OPTION_NAME,
			self::OPTION_NAME,
			[
				'type'              => 'array',
				'sanitize_callback' => [ __CLASS__, 'validate_options' ],
			]
		);

		add_action( 'update_option_' . self::OPTION_NAME, [ __CLASS__, 'maybe_flush_rewrite_rules' ], 10, 2 );
	}

	/**
	 * Flush rewrite rules if the supported_post_types have changed.
	 *
	 * @since 0.6.2
	 *
	 * @param array $old_options Old options.
	 * @param array $new_options New options.
	 */
	public static function maybe_flush_rewrite_rules( $old_options, $new_options ) {
		$old_post_types = isset( $old_options[ Option::SUPPORTED_POST_TYPES ] ) ? $old_options[ Option::SUPPORTED_POST_TYPES ] : [];
		$new_post_types = isset( $new_options[ Option::SUPPORTED_POST_TYPES ] ) ? $new_options[ Option::SUPPORTED_POST_TYPES ] : [];
		sort( $old_post_types );
		sort( $new_post_types );
		if ( $old_post_types !== $new_post_types ) {
			// Flush rewrite rules.
			add_rewrite_endpoint( amp_get_slug(), EP_PERMALINK );
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Get plugin options.
	 *
	 * @return array Options.
	 */
	public static function get_options() {
		$options = get_option( self::OPTION_NAME, [] );
		if ( empty( $options ) ) {
			$options = []; // Ensure empty string becomes array.
		}

		$defaults = self::$defaults;

		if ( current_theme_supports( 'amp' ) ) {
			$defaults[ Option::THEME_SUPPORT ] = amp_is_canonical() ? AMP_Theme_Support::STANDARD_MODE_SLUG : AMP_Theme_Support::TRANSITIONAL_MODE_SLUG;
		}

		$options = array_merge( $defaults, $options );

		// Migrate theme support slugs.
		if ( 'native' === $options[ Option::THEME_SUPPORT ] ) {
			$options[ Option::THEME_SUPPORT ] = AMP_Theme_Support::STANDARD_MODE_SLUG;
		} elseif ( 'paired' === $options[ Option::THEME_SUPPORT ] ) {
			$options[ Option::THEME_SUPPORT ] = AMP_Theme_Support::TRANSITIONAL_MODE_SLUG;
		} elseif ( 'disabled' === $options[ Option::THEME_SUPPORT ] ) {
			/*
			 * Prior to 1.2, the theme support slug for Reader mode was 'disabled'. This would be saved in options for
			 * themes that had 'amp' theme support defined. Also prior to 1.2, the user could not switch between modes
			 * when the theme had 'amp' theme support. The result is that a site running 1.1 could be AMP-first and then
			 * upon upgrading to 1.2, be switched to Reader mode. So when migrating the old 'disabled' slug to the new
			 * value, we need to make sure we use the default theme support slug as it has been determined above. If the
			 * site has non-paired 'amp' theme support and the theme support slug is 'disabled' then it should here be
			 * set to 'standard' as opposed to 'reader', and the same goes for paired 'amp' theme support, as it should
			 * become 'transitional'. Otherwise, if the theme lacks 'amp' theme support, then this will become the
			 * default 'reader' mode.
			 */
			$options[ Option::THEME_SUPPORT ] = $defaults[ Option::THEME_SUPPORT ];
		}

		unset(
			/**
			 * Remove 'auto_accept_sanitization' option.
			 *
			 * @since 1.4.0
			 */
			$options[ Option::AUTO_ACCEPT_SANITIZATION ],
			/**
			 * Remove Story related options.
			 *
			 * Option::ENABLE_AMP_STORIES was added in 1.2-beta and later migrated into the `experiences` option.
			 *
			 * @since 1.5.0
			 */
			$options[ Option::STORY_TEMPLATES_VERSION ],
			$options[ Option::STORY_EXPORT_BASE_URL ],
			$options[ Option::STORY_SETTINGS ],
			$options[ Option::ENABLE_AMP_STORIES ],
			/**
			 * Remove 'experiences' option.
			 *
			 * @since 1.5.0
			 */
			$options[ Option::EXPERIENCES ],
			/**
			 * Remove 'enable_response_caching' option.
			 *
			 * @since 1.5.0
			 */
			$options[ Option::ENABLE_RESPONSE_CACHING ]
		);

		return $options;
	}

	/**
	 * Get plugin option.
	 *
	 * @param string $option  Plugin option name.
	 * @param bool   $default Default value.
	 *
	 * @return mixed Option value.
	 */
	public static function get_option( $option, $default = false ) {
		$amp_options = self::get_options();

		if ( ! isset( $amp_options[ $option ] ) ) {
			return $default;
		}

		return $amp_options[ $option ];
	}

	/**
	 * Validate options.
	 *
	 * @param array $new_options Plugin options.
	 * @return array Options.
	 */
	public static function validate_options( $new_options ) {
		$options = self::get_options();

		if ( ! current_user_can( 'manage_options' ) ) {
			return $options;
		}

		// Theme support.
		$recognized_theme_supports = [
			AMP_Theme_Support::READER_MODE_SLUG,
			AMP_Theme_Support::TRANSITIONAL_MODE_SLUG,
			AMP_Theme_Support::STANDARD_MODE_SLUG,
		];
		if ( isset( $new_options[ Option::THEME_SUPPORT ] ) && in_array( $new_options[ Option::THEME_SUPPORT ], $recognized_theme_supports, true ) ) {
			$options[ Option::THEME_SUPPORT ] = $new_options[ Option::THEME_SUPPORT ];

			// If this option was changed, display a notice with the new template mode.
			if ( self::get_option( Option::THEME_SUPPORT ) !== $new_options[ Option::THEME_SUPPORT ] ) {
				add_action( 'update_option_' . self::OPTION_NAME, [ __CLASS__, 'handle_updated_theme_support_option' ] );
			}
		}

		// Validate post type support.
		if ( isset( $new_options[ Option::SUPPORTED_POST_TYPES ] ) ) {
			$options[ Option::SUPPORTED_POST_TYPES ] = [];

			foreach ( $new_options[ Option::SUPPORTED_POST_TYPES ] as $post_type ) {
				if ( ! post_type_exists( $post_type ) ) {
					self::add_settings_error( self::OPTION_NAME, 'unknown_post_type', __( 'Unrecognized post type.', 'amp' ) );
				} else {
					$options[ Option::SUPPORTED_POST_TYPES ][] = $post_type;
				}
			}
		}

		$theme_support_args = AMP_Theme_Support::get_theme_support_args();

		$is_template_support_required = ( isset( $theme_support_args['templates_supported'] ) && 'all' === $theme_support_args['templates_supported'] );
		if ( ! $is_template_support_required && ! isset( $theme_support_args['available_callback'] ) ) {
			$options[ Option::ALL_TEMPLATES_SUPPORTED ] = ! empty( $new_options[ Option::ALL_TEMPLATES_SUPPORTED ] );

			// Validate supported templates.
			$options[ Option::SUPPORTED_TEMPLATES ] = [];
			if ( isset( $new_options[ Option::SUPPORTED_TEMPLATES ] ) ) {
				$options[ Option::SUPPORTED_TEMPLATES ] = array_intersect(
					$new_options[ Option::SUPPORTED_TEMPLATES ],
					array_keys( AMP_Theme_Support::get_supportable_templates() )
				);
			}
		}

		// Validate analytics.
		if ( isset( $new_options[ Option::ANALYTICS ] ) && $new_options[ Option::ANALYTICS ] !== $options[ Option::ANALYTICS ] ) {
			foreach ( $new_options[ Option::ANALYTICS ] as $id => $data ) {

				// Check save/delete pre-conditions and proceed if correct.
				if ( empty( $data['type'] ) || empty( $data['config'] ) ) {
					self::add_settings_error( self::OPTION_NAME, 'missing_analytics_vendor_or_config', __( 'Missing vendor type or config.', 'amp' ) );
					continue;
				}

				// Validate JSON configuration.
				$is_valid_json = AMP_HTML_Utils::is_valid_json( $data['config'] );
				if ( ! $is_valid_json ) {
					self::add_settings_error( self::OPTION_NAME, 'invalid_analytics_config_json', __( 'Invalid analytics config JSON.', 'amp' ) );
					continue;
				}

				$entry_vendor_type = preg_replace( '/[^a-zA-Z0-9_\-]/', '', $data['type'] );
				$entry_config      = trim( $data['config'] );

				if ( ! empty( $data['id'] ) && '__new__' !== $data['id'] ) {
					$entry_id = sanitize_key( $data['id'] );
				} else {

					// Generate a hash string to uniquely identify this entry.
					$entry_id = substr( md5( $entry_vendor_type . $entry_config ), 0, 12 );

					// Avoid duplicates.
					if ( isset( $options[ Option::ANALYTICS ][ $entry_id ] ) ) {
						self::add_settings_error( self::OPTION_NAME, 'duplicate_analytics_entry', __( 'Duplicate analytics entry found.', 'amp' ) );
						continue;
					}
				}

				if ( isset( $data['delete'] ) ) {
					unset( $options[ Option::ANALYTICS ][ $entry_id ] );
				} else {
					$options[ Option::ANALYTICS ][ $entry_id ] = [
						'type'   => $entry_vendor_type,
						'config' => $entry_config,
					];
				}
			}
		}

		if ( isset( $new_options[ Option::READER_THEME ] ) ) {
			$reader_theme_slugs = wp_list_pluck( ( new AMP_Reader_Themes() )->get_themes(), 'slug' );
			if ( in_array( $new_options[ Option::READER_THEME ], $reader_theme_slugs, true ) ) {
				$options[ Option::READER_THEME ] = $new_options[ Option::READER_THEME ];
			}
		}

		if ( array_key_exists( Option::DISABLE_CSS_TRANSIENT_CACHING, $new_options ) && true === $new_options[ Option::DISABLE_CSS_TRANSIENT_CACHING ] ) {
			$options[ Option::DISABLE_CSS_TRANSIENT_CACHING ] = true;
		} else {
			unset( $options[ Option::DISABLE_CSS_TRANSIENT_CACHING ] );
		}

		if ( isset( $new_options[ Option::SUPPRESSED_PLUGINS ] ) ) {
			$options[ Option::SUPPRESSED_PLUGINS ] = self::validate_suppressed_plugins( $new_options[ Option::SUPPRESSED_PLUGINS ], $options[ Option::SUPPRESSED_PLUGINS ] );
		}

		// Store the current version with the options so we know the format.
		$options[ Option::VERSION ] = AMP__VERSION;

		return $options;
	}

	/**
	 * Validate suppressed plugins.
	 *
	 * @param string[] $posted_suppressed_plugins Posted suppressed plugins, mapping of plugin slug to '0' or '1'.
	 * @param array    $old_option                Old option.
	 * @return array New option value.
	 */
	private static function validate_suppressed_plugins( $posted_suppressed_plugins, $old_option ) {
		$option = $old_option;

		$plugins           = PluginRegistry::get_plugins( true );
		$errors_by_source  = AMP_Validated_URL_Post_Type::get_recent_validation_errors_by_source();
		$urls_by_frequency = [];
		$changes           = 0;
		foreach ( $posted_suppressed_plugins as $plugin_slug => $suppressed ) {
			if ( ! isset( $plugins[ $plugin_slug ] ) ) {
				unset( $option[ $plugin_slug ] );
				continue;
			}

			$suppressed = rest_sanitize_boolean( $suppressed );
			if ( isset( $option[ $plugin_slug ] ) && ! $suppressed ) {

				// Gather the URLs on which the error occurred, keeping track of the frequency so that we can use the URL with the most errors to re-validate.
				if ( ! empty( $option[ $plugin_slug ][ Option::SUPPRESSED_PLUGINS_ERRORING_URLS ] ) ) {
					foreach ( $option[ $plugin_slug ][ Option::SUPPRESSED_PLUGINS_ERRORING_URLS ] as $url ) {
						if ( ! isset( $urls_by_frequency[ $url ] ) ) {
							$urls_by_frequency[ $url ] = 0;
						}
						$urls_by_frequency[ $url ]++;
					}
				}

				// Remove the plugin from being suppressed.
				unset( $option[ $plugin_slug ] );

				$changes++;
			} elseif ( ! isset( $option[ $plugin_slug ] ) && $suppressed && array_key_exists( $plugin_slug, $plugins ) ) {

				// Capture the URLs that the error occurred on so we can check them again when the plugin is re-activated.
				$urls = [];
				if ( isset( $errors_by_source['plugin'][ $plugin_slug ] ) ) {
					foreach ( $errors_by_source['plugin'][ $plugin_slug ] as $validation_error ) {
						$urls = array_merge(
							$urls,
							array_map(
								[ AMP_Validated_URL_Post_Type::class, 'get_url_from_post' ],
								$validation_error['post_ids']
							)
						);
					}
				}

				$option[ $plugin_slug ] = [
					// Note that we store the version that was suppressed so that we can alert the user when to check again.
					Option::SUPPRESSED_PLUGINS_LAST_VERSION => $plugins[ $plugin_slug ]['Version'],
					Option::SUPPRESSED_PLUGINS_TIMESTAMP => time(),
					Option::SUPPRESSED_PLUGINS_ERRORING_URLS => array_unique( array_filter( $urls ) ),
				];
				$changes++;
			}
		}

		// When the suppressed plugins changed, re-validate so validation errors can be re-computed with the plugins newly-suppressed or un-suppressed.
		if ( $changes > 0 ) {
			add_action(
				'update_option_' . self::OPTION_NAME,
				static function () use ( $urls_by_frequency ) {
					$url = null;
					if ( count( $urls_by_frequency ) > 0 ) {
						arsort( $urls_by_frequency );
						$url = key( $urls_by_frequency );
					} else {
						$validated_url_posts = get_posts(
							[
								'post_type'      => AMP_Validated_URL_Post_Type::POST_TYPE_SLUG,
								'posts_per_page' => 1,
							]
						);
						if ( count( $validated_url_posts ) > 0 ) {
							$url = AMP_Validated_URL_Post_Type::get_url_from_post( $validated_url_posts[0] );
						}
					}
					if ( $url ) {
						AMP_Validation_Manager::validate_url_and_store( $url );
					}
				}
			);
		}

		return $option;
	}

	/**
	 * Check for errors with updating the supported post types.
	 *
	 * @since 0.6
	 * @see add_settings_error()
	 */
	public static function check_supported_post_type_update_errors() {
		// If all templates are supported then skip check since all post types are also supported. This option only applies with standard/transitional theme support.
		if ( self::get_option( Option::ALL_TEMPLATES_SUPPORTED, false ) && AMP_Theme_Support::READER_MODE_SLUG !== self::get_option( Option::THEME_SUPPORT ) ) {
			return;
		}

		$supported_types = self::get_option( Option::SUPPORTED_POST_TYPES, [] );
		foreach ( AMP_Post_Type_Support::get_eligible_post_types() as $name ) {
			$post_type = get_post_type_object( $name );
			if ( empty( $post_type ) ) {
				continue;
			}

			$post_type_supported = post_type_supports( $post_type->name, AMP_Post_Type_Support::SLUG );
			$is_support_elected  = in_array( $post_type->name, $supported_types, true );

			$error = null;
			$code  = null;
			if ( $is_support_elected && ! $post_type_supported ) {
				/* translators: %s: Post type name. */
				$error = __( '"%s" could not be activated because support is removed by a plugin or theme', 'amp' );
				$code  = sprintf( '%s_activation_error', $post_type->name );
			} elseif ( ! $is_support_elected && $post_type_supported ) {
				/* translators: %s: Post type name. */
				$error = __( '"%s" could not be deactivated because support is added by a plugin or theme', 'amp' );
				$code  = sprintf( '%s_deactivation_error', $post_type->name );
			}

			if ( isset( $error, $code ) ) {
				self::add_settings_error(
					self::OPTION_NAME,
					$code,
					esc_html(
						sprintf(
							$error,
							isset( $post_type->label ) ? $post_type->label : $post_type->name
						)
					)
				);
			}
		}
	}

	/**
	 * Update plugin option.
	 *
	 * @param string $option Plugin option name.
	 * @param mixed  $value  Plugin option value.
	 *
	 * @return bool Whether update succeeded.
	 */
	public static function update_option( $option, $value ) {
		$amp_options = self::get_options();

		$amp_options[ $option ] = $value;
		return update_option( self::OPTION_NAME, $amp_options, false );
	}

	/**
	 * Update plugin options.
	 *
	 * @param array $options Plugin option name.
	 * @return bool Whether update succeeded.
	 */
	public static function update_options( $options ) {
		$amp_options = array_merge(
			self::get_options(),
			$options
		);

		return update_option( self::OPTION_NAME, $amp_options, false );
	}

	/**
	 * Handle analytics submission.
	 */
	public static function handle_analytics_submit() {

		// Request must come from user with right capabilities.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you do not have the necessary permissions to perform this action', 'amp' ) );
		}

		// Ensure request is coming from analytics option form.
		check_admin_referer( 'analytics-options', 'analytics-options' );

		if ( isset( $_POST[ self::OPTION_NAME ][ Option::ANALYTICS ] ) ) {
			self::update_option( Option::ANALYTICS, wp_unslash( $_POST[ self::OPTION_NAME ][ Option::ANALYTICS ] ) );

			$errors = get_settings_errors( self::OPTION_NAME );
			if ( empty( $errors ) ) {
				self::add_settings_error( self::OPTION_NAME, 'settings_updated', __( 'The analytics entry was successfully saved!', 'amp' ), 'updated' );
				$errors = get_settings_errors( self::OPTION_NAME );
			}
			set_transient( 'settings_errors', $errors );
		}

		/*
		 * Redirect to keep the user in the analytics options page.
		 * Wrap in is_admin() to enable phpunit tests to exercise this code.
		 */
		wp_safe_redirect( admin_url( 'admin.php?page=amp-analytics-options&settings-updated=1' ) );
		exit;
	}

	/**
	 * Update analytics options.
	 *
	 * @codeCoverageIgnore
	 * @deprecated
	 * @param array $data Unsanitized unslashed data.
	 * @return bool Whether options were updated.
	 */
	public static function update_analytics_options( $data ) {
		_deprecated_function( __METHOD__, '0.6', __CLASS__ . '::update_option' );
		return self::update_option( Option::ANALYTICS, wp_unslash( $data ) );
	}

	/**
	 * Renders the welcome notice on the 'AMP Settings' page.
	 *
	 * Uses the user meta values for the dismissed WP pointers.
	 * So once the user dismisses this notice, it will never appear again.
	 */
	public static function render_welcome_notice() {
		if ( 'toplevel_page_' . self::OPTION_NAME !== get_current_screen()->id ) {
			return;
		}

		$notice_id = 'amp-welcome-notice-1';
		$dismissed = get_user_meta( get_current_user_id(), 'dismissed_wp_pointers', true );
		if ( in_array( $notice_id, explode( ',', (string) $dismissed ), true ) ) {
			return;
		}

		?>
		<div class="amp-welcome-notice notice notice-info is-dismissible" id="<?php echo esc_attr( $notice_id ); ?>">
			<div class="notice-dismiss"></div>
			<div class="amp-welcome-icon-holder">
				<img width="200" height="200" class="amp-welcome-icon" src="<?php echo esc_url( amp_get_asset_url( 'images/amp-welcome-icon.svg' ) ); ?>" alt="<?php esc_attr_e( 'Illustration of WordPress running AMP plugin.', 'amp' ); ?>" />
			</div>
			<h2><?php esc_html_e( 'Welcome to AMP for WordPress', 'amp' ); ?></h2>
			<h3><?php esc_html_e( 'Bring the speed and features of the open source AMP project to your site, complete with the tools to support content authoring and website development.', 'amp' ); ?></h3>
			<h3><?php esc_html_e( 'From granular controls that help you create AMP content, to Core Gutenberg support, to a sanitizer that only shows visitors error-free pages, to a full error workflow for developers, this release enables rich, performant experiences for your WordPress site.', 'amp' ); ?></h3>
			<a href="https://amp-wp.org/getting-started/" target="_blank" class="button button-primary"><?php esc_html_e( 'Learn More', 'amp' ); ?></a>
		</div>

		<script>
		jQuery( function( $ ) {
			// On dismissing the notice, make a POST request to store this notice with the dismissed WP pointers so it doesn't display again.
			$( <?php echo wp_json_encode( "#$notice_id" ); ?> ).on( 'click', '.notice-dismiss', function() {
				$.post( ajaxurl, {
					pointer: <?php echo wp_json_encode( $notice_id ); ?>,
					action: 'dismiss-wp-pointer'
				} );
			} );
		} );
		</script>
		<style type="text/css">
			.amp-welcome-notice {
				padding: 38px;
				min-height: 200px;
			}
			.amp-welcome-notice + .notice {
				clear: both;
			}
			.amp-welcome-icon-holder {
				width: 200px;
				height: 200px;
				float: left;
				margin: 0 38px 38px 0;
			}
			.amp-welcome-icon {
				width: 100%;
				height: 100%;
				display: block;
			}
			.amp-welcome-notice h1 {
				font-weight: bold;
			}
			.amp-welcome-notice h3 {
				font-size: 16px;
				font-weight: 500;
			}

		</style>
		<?php
	}

	/**
	 * Render PHP-CSS-Parser conflict notice.
	 *
	 * @return void
	 */
	public static function render_php_css_parser_conflict_notice() {
		if ( 'toplevel_page_' . self::OPTION_NAME !== get_current_screen()->id ) {
			return;
		}

		if ( AMP_Style_Sanitizer::has_required_php_css_parser() ) {
			return;
		}

		try {
			$reflection = new ReflectionClass( 'Sabberworm\CSS\CSSList\CSSList' );
			$source_dir = str_replace(
				trailingslashit( WP_CONTENT_DIR ),
				'',
				preg_replace( '#/vendor/sabberworm/.+#', '', $reflection->getFileName() )
			);

			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				wp_kses(
					sprintf(
						/* translators: %s: path to the conflicting library */
						__( 'A conflicting version of PHP-CSS-Parser appears to be installed by another plugin or theme (located in %s). Because of this, CSS processing will be limited, and tree shaking will not be available.', 'amp' ),
						'<code>' . esc_html( $source_dir ) . '</code>'
					),
					[ 'code' => [] ]
				)
			);
		} catch ( ReflectionException $e ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'PHP-CSS-Parser is not available so CSS processing will not be available.', 'amp' )
			);
		}
	}

	/**
	 * Outputs an admin notice if the site is not served over HTTPS.
	 *
	 * @since 1.3
	 *
	 * @return void
	 */
	public static function insecure_connection_notice() {
		// is_ssl() only tells us whether the admin backend uses HTTPS here, so we add a few more sanity checks.
		$uses_ssl = (
			is_ssl()
			&&
			( strpos( get_bloginfo( 'wpurl' ), 'https' ) === 0 )
			&&
			( strpos( get_bloginfo( 'url' ), 'https' ) === 0 )
		);

		if ( ! $uses_ssl && 'toplevel_page_' . self::OPTION_NAME === get_current_screen()->id ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				wp_kses(
					sprintf(
						/* translators: %s: "Why should I use HTTPS" support URL */
						__( 'Your site is not being fully served over a secure connection (using HTTPS).<br>As some AMP functionality requires a secure connection, you might experience degraded performance or broken components.<br><a href="%s">More details</a>', 'amp' ),
						esc_url( __( 'https://wordpress.org/support/article/why-should-i-use-https/', 'amp' ) )
					),
					[
						'br' => [],
						'a'  => [ 'href' => true ],
					]
				)
			);
		}
	}

	/**
	 * Adds a message for an update of the theme support setting.
	 */
	public static function handle_updated_theme_support_option() {
		$template_mode = self::get_option( Option::THEME_SUPPORT );

		// Make sure post type support has been added for sake of amp_admin_get_preview_permalink().
		foreach ( AMP_Post_Type_Support::get_eligible_post_types() as $post_type ) {
			remove_post_type_support( $post_type, AMP_Post_Type_Support::SLUG );
		}
		AMP_Post_Type_Support::add_post_type_support();

		// Ensure theme support flags are set properly according to the new mode so that proper AMP URL can be generated.
		$has_theme_support = ( AMP_Theme_Support::STANDARD_MODE_SLUG === $template_mode || AMP_Theme_Support::TRANSITIONAL_MODE_SLUG === $template_mode );
		if ( $has_theme_support ) {
			$theme_support = current_theme_supports( AMP_Theme_Support::SLUG );
			if ( ! is_array( $theme_support ) ) {
				$theme_support = [];
			}
			$theme_support['paired'] = AMP_Theme_Support::TRANSITIONAL_MODE_SLUG === $template_mode;
			add_theme_support( AMP_Theme_Support::SLUG, $theme_support );
		} else {
			remove_theme_support( AMP_Theme_Support::SLUG ); // So that the amp_get_permalink() will work for reader mode URL.
		}

		$url = amp_admin_get_preview_permalink();

		$notice_type     = 'updated';
		$review_messages = [];
		if ( $url && $has_theme_support ) {
			$validation = AMP_Validation_Manager::validate_url_and_store( $url );

			if ( is_wp_error( $validation ) ) {
				$review_messages[] = esc_html__( 'However, there was an error when checking the AMP validity for your site.', 'amp' );
				$review_messages[] = AMP_Validation_Manager::get_validate_url_error_message( $validation->get_error_code(), $validation->get_error_message() );
				$notice_type       = 'error';
			} elseif ( is_array( $validation ) ) {
				$new_errors      = 0;
				$rejected_errors = 0;

				$errors = wp_list_pluck( $validation['results'], 'error' );
				foreach ( $errors as $error ) {
					$sanitization    = AMP_Validation_Error_Taxonomy::get_validation_error_sanitization( $error );
					$is_new_rejected = AMP_Validation_Error_Taxonomy::VALIDATION_ERROR_NEW_REJECTED_STATUS === $sanitization['status'];
					if ( $is_new_rejected || AMP_Validation_Error_Taxonomy::VALIDATION_ERROR_NEW_ACCEPTED_STATUS === $sanitization['status'] ) {
						$new_errors++;
					}
					if ( $is_new_rejected || AMP_Validation_Error_Taxonomy::VALIDATION_ERROR_ACK_REJECTED_STATUS === $sanitization['status'] ) {
						$rejected_errors++;
					}
				}

				$invalid_url_post_id    = $validation['post_id'];
				$invalid_url_screen_url = ! is_wp_error( $invalid_url_post_id ) ? get_edit_post_link( $invalid_url_post_id, 'raw' ) : null;

				if ( $rejected_errors > 0 ) {
					$notice_type = 'error';

					$message = esc_html(
						sprintf(
							/* translators: %s is count of rejected errors */
							_n(
								'However, AMP is not yet available due to %s validation error (for one URL at least).',
								'However, AMP is not yet available due to %s validation errors (for one URL at least).',
								number_format_i18n( $rejected_errors ),
								'amp'
							),
							$rejected_errors,
							esc_url( $invalid_url_screen_url )
						)
					);

					if ( $invalid_url_screen_url ) {
						$message .= ' ' . sprintf(
							/* translators: %s is URL to review issues */
							_n(
								'<a href="%s">Review Issue</a>.',
								'<a href="%s">Review Issues</a>.',
								$rejected_errors,
								'amp'
							),
							esc_url( $invalid_url_screen_url )
						);
					}

					$review_messages[] = $message;
				} else {
					$message = sprintf(
						/* translators: %s is an AMP URL */
						__( 'View an <a href="%s">AMP version of your site</a>.', 'amp' ),
						esc_url( $url )
					);

					if ( $new_errors > 0 && $invalid_url_screen_url ) {
						$message .= ' ' . sprintf(
							/* translators: 1: URL to review issues. 2: count of new errors. */
							_n(
								'Please also <a href="%1$s">review %2$s issue</a> which may need to be fixed (for one URL at least).',
								'Please also <a href="%1$s">review %2$s issues</a> which may need to be fixed (for one URL at least).',
								$new_errors,
								'amp'
							),
							esc_url( $invalid_url_screen_url ),
							number_format_i18n( $new_errors )
						);
					}

					$review_messages[] = $message;
				}
			}
		}

		switch ( $template_mode ) {
			case AMP_Theme_Support::STANDARD_MODE_SLUG:
				$message = esc_html__( 'Standard mode activated!', 'amp' );
				if ( $review_messages ) {
					$message .= ' ' . implode( ' ', $review_messages );
				}
				break;
			case AMP_Theme_Support::TRANSITIONAL_MODE_SLUG:
				$message = esc_html__( 'Transitional mode activated!', 'amp' );
				if ( $review_messages ) {
					$message .= ' ' . implode( ' ', $review_messages );
				}
				break;
			case AMP_Theme_Support::READER_MODE_SLUG:
				$message = sprintf(
					/* translators: %s is an AMP URL */
					__( 'Reader mode activated! View the <a href="%s">AMP version of a recent post</a>. It is recommended that you upgrade to Standard or Transitional mode.', 'amp' ),
					esc_url( $url )
				);
				break;
		}

		if ( isset( $message ) ) {
			self::add_settings_error( self::OPTION_NAME, 'template_mode_updated', wp_kses_post( $message ), $notice_type );
		}
	}

	/**
	 * Register a settings error to be displayed to the user.
	 *
	 * @see add_settings_error()
	 *
	 * @param string $setting Slug title of the setting to which this error applies.
	 * @param string $code    Slug-name to identify the error. Used as part of 'id' attribute in HTML output.
	 * @param string $message The formatted message text to display to the user (will be shown inside styled
	 *                        `<div>` and `<p>` tags).
	 * @param string $type    Optional. Message type, controls HTML class. Possible values include 'error',
	 *                        'success', 'warning', 'info'. Default 'error'.
	 */
	private static function add_settings_error( $setting, $code, $message, $type = 'error' ) {
		require_once ABSPATH . 'wp-admin/includes/template.php';
		add_settings_error( $setting, $code, $message, $type );
	}
}
