/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useContext, useEffect, useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { Navigation } from '../../components/navigation-context-provider';

// Keep active theme data across screen navigations.
let CACHED_ACTIVE_THEME = null;

/**
 * Screen showing site configuration details.
 */
export function SiteConfigurationSummary() {
	const { canGoForward, setCanGoForward } = useContext( Navigation );
	const [ activeTheme, setActiveTheme ] = useState( null );
	const [ fetchingActiveTheme, setFetchingActiveTheme ] = useState( false );
	const [ fetchActiveThemeError, setFetchActiveThemeError ] = useState( null );

	// This component sets state inside async functions. Use this ref to prevent state updates after unmount.
	const hasUnmounted = useRef( false );

	/**
	 * Allow moving forward.
	 */
	useEffect( () => {
		if ( canGoForward === false ) {
			setCanGoForward( true );
		}
	}, [ canGoForward, setCanGoForward ] );

	useEffect( () => {
		console.log( 'hihihi' );
		async function fetchActiveTheme() {
			setFetchingActiveTheme( true );

			try {
				if ( ! CACHED_ACTIVE_THEME ) {
					const activeThemes = await apiFetch( { path: '/wp/v2/themes?status=active' } );
					console.log( activeThemes );
					CACHED_ACTIVE_THEME = activeThemes[ 0 ];
				}

				if ( true === hasUnmounted.current ) {
					return;
				}

				setActiveTheme( CACHED_ACTIVE_THEME );
			} catch ( e ) {
				if ( true === hasUnmounted.current ) {
					return;
				}
				setFetchActiveThemeError( e );
			}

			setFetchingActiveTheme( false );
		}

		console;
		if ( null === activeTheme && ! fetchActiveThemeError && ! fetchingActiveTheme ) {
			console.log( 'fetching' );
			fetchActiveTheme();
		}
	}, [ setFetchingActiveTheme, activeTheme, setActiveTheme, fetchingActiveTheme, setFetchActiveThemeError, fetchActiveThemeError ] );

	useEffect( () => () => {
		hasUnmounted.current = true;
	} );

	return (
		<div>
			{ __( 'Site Configuration Summary', 'amp' ) }
		</div>
	);
}
