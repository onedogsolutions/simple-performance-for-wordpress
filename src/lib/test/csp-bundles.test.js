/**
 * The connect-src gap check.
 *
 * The failure this guards against: a policy that allows a payment provider's
 * frame and script origins — because those violations were reported early and
 * allowed — while the connect-src origins its SDK needs are still missing.
 * Nothing reports that gap until someone reaches the payment step, so
 * enforcing looks safe and breaks checkout.
 */

import {
	THIRD_PARTY_BUNDLES,
	bundleInUse,
	connectSrcGaps,
	addOrigins,
} from '../csp-bundles';

const stripe = THIRD_PARTY_BUNDLES.find( ( b ) => b.id === 'stripe' );
const paypal = THIRD_PARTY_BUNDLES.find( ( b ) => b.id === 'paypal' );

describe( 'bundleInUse', () => {
	it( 'is false for a policy with no provider origins', () => {
		expect( bundleInUse( { 'connect-src': [ "'self'" ] }, stripe ) ).toBe(
			false
		);
	} );

	it( 'is true when the provider appears in any directive', () => {
		expect(
			bundleInUse( { 'frame-src': [ 'https://js.stripe.com' ] }, stripe )
		).toBe( true );
	} );
} );

describe( 'connectSrcGaps', () => {
	it( 'reports nothing when no provider is in use', () => {
		expect(
			connectSrcGaps( {
				'connect-src': [ "'self'" ],
				'frame-src': [ 'https://www.youtube.com' ],
			} )
		).toEqual( [] );
	} );

	it( 'catches the real-world shape: payment frames allowed, connect-src not', () => {
		// Exactly the policy that prompted this: frame-src carries the payment
		// origins because those violations were reported and allowed, while
		// connect-src is a tracker allowlist with no payment origins at all.
		const gaps = connectSrcGaps( {
			'frame-src': [
				'https://js.stripe.com',
				'https://c.paypal.com',
				'https://www.paypal.com',
			],
			'connect-src': [
				"'self'",
				'https://www.google-analytics.com',
				'https://www.paypal.com',
			],
		} );

		expect( gaps.map( ( g ) => g.bundle.id ).sort() ).toEqual( [
			'paypal',
			'stripe',
		] );
		expect(
			gaps.find( ( g ) => g.bundle.id === 'stripe' ).missing
		).toContain( 'https://api.stripe.com' );
		// www.paypal.com being present is not the same as *.paypal.com.
		expect(
			gaps.find( ( g ) => g.bundle.id === 'paypal' ).missing
		).toContain( 'https://*.paypal.com' );
	} );

	it( 'reports nothing once the connect-src origins are present', () => {
		const directives = {
			'frame-src': [ 'https://js.stripe.com' ],
			'connect-src': [ "'self'", ...stripe.directives[ 'connect-src' ] ],
		};

		expect( connectSrcGaps( directives ) ).toEqual( [] );
	} );

	it( 'respects a deliberate connect-src none', () => {
		expect(
			connectSrcGaps( {
				'frame-src': [ 'https://js.stripe.com' ],
				'connect-src': [ "'none'" ],
			} )
		).toEqual( [] );
	} );
} );

describe( 'addOrigins', () => {
	it( 'closes the gap it just reported', () => {
		let directives = {
			'frame-src': [ 'https://js.stripe.com' ],
			'connect-src': [ "'self'" ],
		};

		connectSrcGaps( directives ).forEach( ( { missing } ) => {
			directives = addOrigins( directives, 'connect-src', missing );
		} );

		expect( connectSrcGaps( directives ) ).toEqual( [] );
	} );

	it( 'drops none, de-duplicates, and does not mutate the input', () => {
		const before = { 'connect-src': [ "'none'" ] };
		const after = addOrigins( before, 'connect-src', [
			'https://api.stripe.com',
			'https://api.stripe.com',
		] );

		expect( after[ 'connect-src' ] ).toEqual( [
			'https://api.stripe.com',
		] );
		expect( before[ 'connect-src' ] ).toEqual( [ "'none'" ] );
	} );

	it( 'preserves the other directives untouched', () => {
		const after = addOrigins(
			{ 'frame-src': [ 'https://js.stripe.com' ], 'connect-src': [] },
			'connect-src',
			[ 'https://api.stripe.com' ]
		);

		expect( after[ 'frame-src' ] ).toEqual( [ 'https://js.stripe.com' ] );
	} );
} );

describe( 'bundle definitions', () => {
	it( 'every bundle declares connect-src origins — that is the point', () => {
		THIRD_PARTY_BUNDLES.forEach( ( bundle ) => {
			expect( bundle.directives[ 'connect-src' ].length ).toBeGreaterThan(
				0
			);
		} );
	} );

	it( 'stays within the per-directive token cap when fully applied', () => {
		// SPFW_Settings::CSP_MAX_TOKENS. Silently exceeding it was the reason
		// added origins used to vanish on save.
		const total = THIRD_PARTY_BUNDLES.reduce(
			( n, b ) => n + b.directives[ 'connect-src' ].length,
			1 // 'self'
		);

		expect( total ).toBeLessThanOrEqual( 30 );
	} );

	it( 'paypal follows the vendor wildcard guidance', () => {
		expect( paypal.directives[ 'connect-src' ] ).toContain(
			'https://*.paypal.com'
		);
	} );
} );
