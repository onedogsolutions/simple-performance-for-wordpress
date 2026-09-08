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
 * connect-src origins missing for every provider the policy already uses.
 *
 * @param {Object} directives Directive map from the builder.
 * @return {Array<{bundle: Bundle, missing: string[]}>} One entry per gap.
 */
export const connectSrcGaps = ( directives ) => {
	const present = directives[ 'connect-src' ] || [];

	// A directive set to 'none' is a deliberate choice, not a gap to nag about.
	if ( present.includes( "'none'" ) ) {
		return [];
	}

	return THIRD_PARTY_BUNDLES.map( ( bundle ) => {
		if ( ! bundleInUse( directives, bundle ) ) {
			return null;
		}

		const missing = ( bundle.directives[ 'connect-src' ] || [] ).filter(
			( origin ) => ! present.includes( origin )
		);

		return missing.length > 0 ? { bundle, missing } : null;
	} ).filter( Boolean );
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
