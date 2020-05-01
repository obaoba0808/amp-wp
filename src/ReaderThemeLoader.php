<?php
/**
 * Class ReaderThemeLoader.
 *
 * @package AmpProject\AmpWP
 */

namespace AmpProject\AmpWP;

use AMP_Options_Manager;
use AMP_Theme_Support;
use WP_Theme;

/**
 * Switches to the designated Reader theme when template mode enabled and when requesting an AMP page.
 *
 * @package AmpProject\AmpWP
 */
final class ReaderThemeLoader implements Service {

	/**
	 * Register the service with the system.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'setup_theme', [ $this, 'override_theme' ] );
	}

	/**
	 * Is reader mode.
	 *
	 * @return bool Whether reader mode.
	 */
	public static function is_reader_mode() {
		return AMP_Theme_Support::READER_MODE_SLUG === AMP_Options_Manager::get_option( Option::THEME_SUPPORT );
	}

	/**
	 * Get reader theme.
	 *
	 * If the Reader template mode is enabled
	 *
	 * @return WP_Theme|null Theme if selected and no errors.
	 */
	public static function get_reader_theme() {
		$reader_theme_slug = AMP_Options_Manager::get_option( Option::READER_THEME );
		if ( ! $reader_theme_slug ) {
			return null;
		}

		$reader_theme = wp_get_theme( $reader_theme_slug );
		if ( $reader_theme->errors() ) {
			return null;
		}

		return $reader_theme;
	}

	/**
	 * Has AMP query var.
	 *
	 * @return bool Has AMP query var.
	 */
	public static function has_amp_query_var() {
		return isset( $_GET[ amp_get_slug() ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Switch theme if in Reader mode, a Reader theme was selected, and the AMP query var is present.
	 *
	 * Note that AMP_Theme_Support will redirect to the non-AMP version if AMP is not available for the query.
	 */
	public function override_theme() {
		if ( ! self::is_reader_mode() ) {
			return;
		}

		$theme = self::get_reader_theme();
		if ( ! $theme ) {
			return;
		}

		if ( ! self::has_amp_query_var() ) {
			return;
		}

		add_filter(
			'template',
			static function () use ( $theme ) {
				return $theme->get_template();
			}
		);
		add_filter(
			'stylesheet',
			static function () use ( $theme ) {
				return $theme->get_stylesheet();
			}
		);
	}
}
