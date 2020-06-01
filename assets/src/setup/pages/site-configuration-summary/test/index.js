
/**
 * External dependencies
 */
import { create } from 'react-test-renderer';

/**
 * Internal dependencies
 */
import { SiteConfigurationSummary } from '..';
import { Providers } from '../../..';

let container;

describe( 'SiteConfigurationSummary', () => {
	beforeEach( () => {
		container = document.createElement( 'div' );
		document.body.appendChild( container );
	} );

	afterEach( () => {
		document.body.removeChild( container );
		container = null;
	} );

	it( 'matches snapshot', () => {
		const wrapper = create(
			<Providers>
				<SiteConfigurationSummary />
			</Providers>,
		);
		expect( wrapper.toJSON() ).toMatchSnapshot();
	} );
} );
