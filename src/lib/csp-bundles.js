/**
 * Third-party origin bundles, and the connect-src gap check.
 *
 * The violation collector reports one directive at a time, and the "Allow"
 * action writes the origin into exactly that directive. That is correct but
 * incomplete for providers whose SDK loads a frame first and only calls its
 * API later: allowing `frame-src https://js.stripe.com` from an early report
 * looks like the job is done, while the `connect-src` the SDK needs is still
 * missing and will not be reported until someone reaches that step of
 * checkout — which, in Report-Only mode on a site nobody is test-buying from,
 * may be never. Enforcement then breaks payment for real customers.
 *
 * So the providers whose connect-src requirements are known are declared here,
 * and the builder checks for the gap directly instead of waiting for a report.
 *
 * These lists are a STARTING POINT taken from each provider's published CSP
 * guidance, not a guarantee: providers add hosts, and integrations differ
 * (PayPal Fastlane pulls in Braintree domains, Stripe address autocomplete
 * pulls in Google Maps). The violation log remains authoritative.
 *
 * Kept free of any WordPress or React import so it stays pure and testable.
 */

/**
 * @typedef {Object} Bundle
 * @property {string}                   id         Stable identifier.
 * @property {string}                   label      Provider name for the UI.
 * @property {Object<string, string[]>} directives Origins keyed by directive.
 */

/** @type {Bundle[]} */
export const THIRD_PARTY_BUNDLES = [
	{
		id: 'stripe',
		label: 'Stripe',
		directives: {
			'script-src': [
				'https://js.stripe.com',
				'https://*.js.stripe.com',
			],
			'frame-src': [
				'https://js.stripe.com',
				'https://*.js.stripe.com',
				'https://hooks.stripe.com',
			],
			// api.stripe.com is the documented one. The wildcard covers the
			// telemetry and error hosts (q.stripe.com, errors.stripe.com) that
			// show up in practice, and m.stripe.network is Stripe's separate
			// fraud-detection origin, which the wildcard cannot cover.
			'connect-src': [
				'https://api.stripe.com',
				'https://*.stripe.com',
				'https://m.stripe.network',
			],
		},
	},
	{
		id: 'paypal',
		label: 'PayPal',
		directives: {
			'script-src': [
				'https://www.paypal.com',
				'https://www.paypalobjects.com',
				'https://c.paypal.com',
			],
			'frame-src': [ 'https://www.paypal.com', 'https://c.paypal.com' ],
			// PayPal's own guidance is the wildcard rather than a host list.
			'connect-src': [
				'https://*.paypal.com',
				'https://*.paypalobjects.com',
			],
			'img-src': [
				'https://www.paypalobjects.com',
				'https://t.paypal.com',
			],
		},
	},
	{
		id: 'recaptcha',
		label: 'Google reCAPTCHA',
		// The bundle this list exists because of. A default policy with
		// `connect-src 'self'` and no `frame-src` blocks the widget's iframe
		// and its token call, and the only symptom the visitor gets is a form
		// that refuses to submit — on a password-reset or checkout form, with
		// no way around it. Nothing reports it either, because the widget only
		// loads once someone opens the form.
		directives: {
			'script-src': [
				'https://www.google.com',
				'https://www.gstatic.com',
			],
			'frame-src': [ 'https://www.google.com' ],
			'connect-src': [ 'https://www.google.com' ],
		},
	},
];

/**
 * Whether a policy already carries any origin belonging to a bundle — the
 * signal that the site uses that provider and its other directives matter.
 *
 * @param {Object} directives Directive map from the builder.
 * @param {Bundle} bundle     Bundle to test.
 * @return {boolean} True when at least one of the bundle's origins is present.
 */
export const bundleInUse = ( directives, bundle ) =>
	Object.keys( bundle.directives ).some( ( directive ) =>
		bundle.directives[ directive ].some( ( origin ) =>
			( directives[ directive ] || [] ).includes( origin )
		)
	);

/**
 * Whether a directive's token list already permits an origin without naming
 * it — `https:` covers every https origin, and so does a wildcard host that
 * the origin falls under.
 *
 * @param {string[]} tokens Tokens present on the directive.
 * @param {string}   origin Origin to test.
 * @return {boolean} True when the origin needs no separate entry.
 */
const covered = ( tokens, origin ) => {
	if ( tokens.includes( origin ) ) {
		return true;
	}

	const scheme = origin.split( ':' )[ 0 ];

	if ( tokens.includes( `${ scheme }:` ) ) {
		return true;
	}

	const host = origin.replace( /^[a-z][a-z0-9+.-]*:\/\//i, '' );

	return tokens.some( ( token ) => {
		if ( ! token.includes( '*.' ) ) {
			return false;
		}

		const suffix = token
			.replace( /^[a-z][a-z0-9+.-]*:\/\//i, '' )
			.replace( /^\*\./, '' );

		return host === suffix || host.endsWith( `.${ suffix }` );
	} );
};

/**
 * Every directive a provider needs and the policy is missing, for each
 * provider the policy already uses.
 *
 * Originally this checked `connect-src` alone, because the first gap anyone
 * hit was a payment SDK's API host. The same shape applies to every directive
 * a bundle declares: a reCAPTCHA policy that allows the script but not the
 * frame fails just as completely, and just as invisibly, as one that allows
 * the frame but not the API call.
 *
 * @param {Object} directives Directive map from the builder.
 * @return {Array<{bundle: Bundle, missing: Object<string, string[]>, count: number}>}
 *         One entry per provider with at least one gap.
 */
export const bundleGaps = ( directives ) =>
	THIRD_PARTY_BUNDLES.map( ( bundle ) => {
		if ( ! bundleInUse( directives, bundle ) ) {
			return null;
		}

		const missing = {};
		let count = 0;

		Object.keys( bundle.directives ).forEach( ( directive ) => {
			const present = directives[ directive ] || [];

			// A directive set to 'none' is a deliberate choice, not a gap to
			// nag about.
			if ( present.includes( "'none'" ) ) {
				return;
			}

			const gap = bundle.directives[ directive ].filter(
				( origin ) => ! covered( present, origin )
			);

			if ( gap.length > 0 ) {
				missing[ directive ] = gap;
				count += gap.length;
			}
		} );

		return count > 0 ? { bundle, missing, count } : null;
	} ).filter( Boolean );

/**
 * connect-src-only view of bundleGaps(), kept as its own export because the
 * connect-src gap is the one that costs money and the UI calls it out
 * separately.
 *
 * @param {Object} directives Directive map from the builder.
 * @return {Array<{bundle: Bundle, missing: string[]}>} One entry per gap.
 */
export const connectSrcGaps = ( directives ) =>
	bundleGaps( directives )
		.filter( ( gap ) => gap.missing[ 'connect-src' ] )
		.map( ( gap ) => ( {
			bundle: gap.bundle,
			missing: gap.missing[ 'connect-src' ],
		} ) );

/**
 * Structural risks in a policy that no violation report will ever surface,
 * because the resources they block are only requested when a visitor does
 * something specific.
 *
 * Returns identifiers rather than sentences so the copy stays translatable in
 * the component that renders it.
 *
 * @param {Object} directives Directive map from the builder.
 * @return {Array<{id: string, directive: string, suggest: string[]}>} Risks found.
 */
export const policyRisks = ( directives ) => {
	const risks = [];
	const tokensOf = ( name ) => ( directives[ name ] || [] ).filter( Boolean );

	const defaultSrc = tokensOf( 'default-src' );
	const frameSrc = tokensOf( 'frame-src' );
	const connectSrc = tokensOf( 'connect-src' );

	// No frame-src means embedded content falls back to default-src. With the
	// usual `default-src 'self'` that silently blocks every third-party iframe
	// on the site — reCAPTCHA, YouTube, Stripe, a map — and each one fails as
	// a blank space rather than an error anyone would connect to CSP.
	if (
		frameSrc.length === 0 &&
		defaultSrc.length > 0 &&
		! defaultSrc.includes( 'https:' ) &&
		! defaultSrc.includes( "'none'" )
	) {
		risks.push( {
			id: 'missing-frame-src',
			directive: 'frame-src',
			suggest: [ "'self'", 'https:' ],
		} );
	}

	// connect-src limited to 'self' blocks every third-party fetch/XHR: the
	// reCAPTCHA token call, analytics beacons, a payment SDK's API. None of
	// them is requested until a visitor interacts, so report-only browsing
	// never surfaces them.
	if (
		connectSrc.length > 0 &&
		! connectSrc.includes( "'none'" ) &&
		! connectSrc.includes( 'https:' ) &&
		connectSrc.every( ( token ) => token.startsWith( "'" ) )
	) {
		risks.push( {
			id: 'narrow-connect-src',
			directive: 'connect-src',
			suggest: [ 'https:' ],
		} );
	}

	return risks;
};

/**
 * Add origins to one directive, dropping 'none' and de-duplicating.
 *
 * @param {Object}   directives Directive map from the builder.
 * @param {string}   directive  Directive to extend.
 * @param {string[]} origins    Origins to add.
 * @return {Object} New directive map; the input is not mutated.
 */
export const addOrigins = ( directives, directive, origins ) => {
	const current = ( directives[ directive ] || [] ).filter(
		( token ) => token !== "'none'"
	);

	return {
		...directives,
		[ directive ]: [ ...new Set( [ ...current, ...origins ] ) ],
	};
};
