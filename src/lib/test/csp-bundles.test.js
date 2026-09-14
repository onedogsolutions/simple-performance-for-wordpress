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
	bundleGaps,
	connectSrcGaps,
	policyRisks,
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

describe( 'bundleGaps', () => {
	// The connect-src-only check could not see this one at all: a policy that
	// allows the captcha's script but neither its frame nor its token call
	// fails just as completely, and just as invisibly, as a payment SDK with a
	// missing API host. Both only break once a visitor opens the form.
	it( 'reports a missing frame-src, not just connect-src', () => {
		const gaps = bundleGaps( {
			'script-src': [ "'self'", 'https://www.google.com' ],
			'connect-src': [ "'self'" ],
			'frame-src': [ "'self'" ],
		} );

		const recaptcha = gaps.find( ( g ) => g.bundle.id === 'recaptcha' );

		expect( recaptcha.missing[ 'frame-src' ] ).toEqual( [
			'https://www.google.com',
		] );
		expect( recaptcha.missing[ 'connect-src' ] ).toEqual( [
			'https://www.google.com',
		] );
		expect( recaptcha.missing[ 'script-src' ] ).toEqual( [
			'https://www.gstatic.com',
		] );
	} );

	it( 'treats a scheme source as covering every origin under it', () => {
		// The widened default policy carries `https:` on these directives, so
		// nothing should be reported as missing — the origins really are
		// allowed, and nagging about them would train admins to ignore this.
		expect(
			bundleGaps( {
				'script-src': [ "'self'", 'https:' ],
				'connect-src': [ "'self'", 'https:' ],
				'frame-src': [ "'self'", 'https:' ],
				'img-src': [ "'self'", 'https:' ],
			} )
		).toEqual( [] );
	} );

	it( 'treats a wildcard host as covering its subdomains', () => {
		const gaps = bundleGaps( {
			'frame-src': [ 'https://js.stripe.com' ],
			'connect-src': [ "'self'", 'https://*.stripe.com' ],
		} );

		const gap = gaps.find( ( g ) => g.bundle.id === 'stripe' );

		// api.stripe.com falls under the *.stripe.com the policy already
		// carries, so it is not a gap. m.stripe.network is a different domain
		// that no wildcard here covers — Stripe's fraud-detection origin is
		// exactly the entry a reader would assume the wildcard handles, and it
		// does not.
		expect( gap.missing[ 'connect-src' ] ).toEqual( [
			'https://m.stripe.network',
		] );
	} );

	it( 'closes every directive gap it reported', () => {
		let directives = {
			'script-src': [ 'https://www.google.com' ],
			'connect-src': [ "'self'" ],
			'frame-src': [ "'self'" ],
		};

		bundleGaps( directives ).forEach( ( { missing } ) => {
			Object.keys( missing ).forEach( ( directive ) => {
				directives = addOrigins(
					directives,
					directive,
					missing[ directive ]
				);
			} );
		} );

		expect( bundleGaps( directives ) ).toEqual( [] );
	} );
} );

describe( 'reCAPTCHA bundle', () => {
	const recaptcha = THIRD_PARTY_BUNDLES.find( ( b ) => b.id === 'recaptcha' );

	// The widget needs all three, and the field failure that prompted this had
	// two of them missing: the challenge iframe and the token call.
	it( 'covers the script, the frame, and the token call', () => {
		expect( recaptcha.directives[ 'script-src' ] ).toEqual(
			expect.arrayContaining( [
				'https://www.google.com',
				'https://www.gstatic.com',
			] )
		);
		expect( recaptcha.directives[ 'frame-src' ] ).toContain(
			'https://www.google.com'
		);
		expect( recaptcha.directives[ 'connect-src' ] ).toContain(
			'https://www.google.com'
		);
	} );
} );

describe( 'policyRisks', () => {
	// Exactly the policy that shipped as the default, and exactly the two
	// reasons a logged-out visitor could not reset their password.
	const brokenDefault = {
		'default-src': [ "'self'" ],
		'script-src': [ "'self'", "'unsafe-inline'", 'https:' ],
		'connect-src': [ "'self'" ],
	};

	it( 'flags a missing frame-src, which no report will ever surface', () => {
		const ids = policyRisks( brokenDefault ).map( ( r ) => r.id );

		expect( ids ).toContain( 'missing-frame-src' );
	} );

	it( 'flags a connect-src that allows nothing but this site', () => {
		const ids = policyRisks( brokenDefault ).map( ( r ) => r.id );

		expect( ids ).toContain( 'narrow-connect-src' );
	} );

	it( 'is quiet about the current default policy', () => {
		expect(
			policyRisks( {
				'default-src': [ "'self'" ],
				'connect-src': [ "'self'", 'https:' ],
				'frame-src': [ "'self'", 'https:' ],
			} )
		).toEqual( [] );
	} );

	it( 'respects a deliberate none', () => {
		// An admin who set these to 'none' meant it; that is a decision, not a
		// hole to nag about.
		expect(
			policyRisks( {
				'default-src': [ "'none'" ],
				'connect-src': [ "'none'" ],
			} )
		).toEqual( [] );
	} );

	it( 'suggests sources that actually clear the risk it reported', () => {
		let directives = { ...brokenDefault };

		policyRisks( directives ).forEach( ( risk ) => {
			directives = addOrigins( directives, risk.directive, risk.suggest );
		} );

		expect( policyRisks( directives ) ).toEqual( [] );
	} );
} );
