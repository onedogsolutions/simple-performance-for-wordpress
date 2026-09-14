import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import {
	bundleGaps,
	policyRisks,
	addOrigins,
	THIRD_PARTY_BUNDLES,
} from '../lib/csp-bundles';

import SettingsCard from './SettingsCard';
import SettingsRow from './SettingsRow';
import Toggle from './Toggle';

const textareaClass =
	'block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 text-sm font-mono';

// The directive rows the builder renders, each with its preset source chips.
// Order here is also the serialization order of the generated policy string.
const CSP_DIRECTIVES = [
	{
		name: 'default-src',
		label: __( 'Default (fallback)', 'simple-performance-for-wordpress' ),
		tokens: [ "'self'", "'none'" ],
	},
	{
		name: 'script-src',
		label: __( 'Scripts', 'simple-performance-for-wordpress' ),
		tokens: [
			"'self'",
			"'unsafe-inline'",
			"'unsafe-eval'",
			'https:',
			'data:',
			'blob:',
			"'none'",
		],
	},
	{
		name: 'style-src',
		label: __( 'Styles', 'simple-performance-for-wordpress' ),
		tokens: [
			"'self'",
			"'unsafe-inline'",
			'https:',
			'data:',
			'blob:',
			"'none'",
		],
	},
	{
		name: 'img-src',
		label: __( 'Images', 'simple-performance-for-wordpress' ),
		tokens: [ "'self'", 'data:', 'https:', 'blob:', "'none'" ],
	},
	{
		name: 'font-src',
		label: __( 'Fonts', 'simple-performance-for-wordpress' ),
		tokens: [ "'self'", 'data:', 'https:', "'none'" ],
	},
	{
		name: 'connect-src',
		label: __(
			'Connections (XHR / fetch / WebSocket)',
			'simple-performance-for-wordpress'
		),
		tokens: [ "'self'", 'https:', 'wss:', "'none'" ],
	},
	{
		name: 'media-src',
		label: __( 'Audio / video', 'simple-performance-for-wordpress' ),
		tokens: [ "'self'", 'https:', 'data:', "'none'" ],
	},
	{
		name: 'worker-src',
		label: __(
			'Workers (Web / Service / Shared Workers)',
			'simple-performance-for-wordpress'
		),
		tokens: [ "'self'", 'blob:', 'https:', "'none'" ],
	},
	{
		name: 'object-src',
		label: __(
			'Plugins (<object> / <embed>)',
			'simple-performance-for-wordpress'
		),
		tokens: [ "'none'", "'self'" ],
	},
	{
		name: 'frame-src',
		label: __(
			'Frames (embedded content)',
			'simple-performance-for-wordpress'
		),
		tokens: [ "'self'", 'https:', "'none'" ],
	},
	{
		name: 'frame-ancestors',
		label: __(
			'Who may embed this site',
			'simple-performance-for-wordpress'
		),
		tokens: [ "'self'", "'none'", 'https:' ],
	},
	{
		name: 'base-uri',
		label: __( 'Base URI', 'simple-performance-for-wordpress' ),
		tokens: [ "'self'", "'none'" ],
	},
	{
		name: 'form-action',
		label: __( 'Form submissions', 'simple-performance-for-wordpress' ),
		tokens: [ "'self'", "'none'" ],
	},
];

const NONE = "'none'";

// Trusted third-party origins that are safe to pre-fill for common tracker
// and map scripts. Keyed by directive; each entry lists origins to ADD
// (not replace). The admin still gets a confirmation modal before anything
// is written to the policy.
const TRUSTED_TRACKER_ORIGINS = {
	'script-src': [
		'https://www.googletagmanager.com',
		'https://www.google-analytics.com',
		'https://maps.googleapis.com',
		'https://www.clarity.ms',
		'https://connect.facebook.net',
		'https://snap.licdn.com',
	],
	'connect-src': [
		'https://www.google-analytics.com',
		'https://analytics.google.com',
		'https://stats.g.doubleclick.net',
		'https://maps.googleapis.com',
		'https://places.googleapis.com',
		'https://l.clarity.ms',
		'https://h.clarity.ms',
		'https://b.clarity.ms',
		'https://e.clarity.ms',
		'https://j.clarity.ms',
		'https://f.clarity.ms',
		'https://mgln.ai',
		'https://fid.agkn.com',
		'https://ad.doubleclick.net',
		'https://www.googleadservices.com',
		'https://www.facebook.com',
	],
	'img-src': [
		'https://www.google-analytics.com',
		'https://stats.g.doubleclick.net',
		'https://maps.googleapis.com',
		'https://maps.gstatic.com',
		'https://www.facebook.com',
	],
	'frame-src': [
		'https://www.google.com',
		'https://www.googletagmanager.com',
		'https://www.facebook.com',
	],
	'font-src': [
		'https://fonts.gstatic.com',
	],
};

// Serialize the structured directive map exactly as the PHP does, for the
// live preview: skip empty directives, collapse a 'none' to just 'none'.
function buildPolicyString( directives ) {
	const out = [];

	CSP_DIRECTIVES.forEach( ( { name } ) => {
		let tokens = ( directives[ name ] || [] )
			.map( ( t ) => t.trim() )
			.filter( Boolean );

		if ( ! tokens.length ) {
			return;
		}

		if ( tokens.includes( NONE ) ) {
			tokens = [ NONE ];
		}

		out.push( `${ name } ${ tokens.join( ' ' ) }` );
	} );

	return out.length ? `${ out.join( '; ' ) };` : '';
}

// A preset chip toggle button.
function Chip( { active, children, onClick } ) {
	return (
		<button
			type="button"
			onClick={ onClick }
			className={ `rounded-md px-2 py-1 text-xs font-mono ring-1 ring-inset transition ${
				active
					? 'bg-indigo-600 text-white ring-indigo-600'
					: 'bg-white text-gray-600 ring-gray-300 hover:ring-gray-400'
			}` }
		>
			{ children }
		</button>
	);
}

// Human-readable "time left" for the collection window.
function formatRemaining( seconds ) {
	if ( seconds <= 0 ) {
		return '';
	}

	const hours = Math.floor( seconds / 3600 );
	const minutes = Math.floor( ( seconds % 3600 ) / 60 );

	if ( hours > 0 ) {
		return sprintf(
			/* translators: 1: hours, 2: minutes */
			__( '%1$dh %2$dm left', 'simple-performance-for-wordpress' ),
			hours,
			minutes
		);
	}

	return sprintf(
		/* translators: %d: minutes */
		__( '%dm left', 'simple-performance-for-wordpress' ),
		Math.max( 1, minutes )
	);
}

export default function CspPolicyCard( {
	hardening,
	settings,
	onChange,
	cspReports = [],
	cspReportStats = {},
	onRefreshCspReports,
	onClearCspReports,
	onDismissCspReport,
	onSetCspCollection,
} ) {
	const enabled = !! hardening.csp_enabled;
	const reportOnly = !! hardening.csp_report_only;

	// What the SERVER is currently sending, which is not the same thing as what
	// the toggles above show: the toggles are live form state, the header below
	// reflects the last save. Conflating the two is what makes an enforcing
	// policy look like a report-only one.
	const emittedHeaderName =
		settings.csp_emitted_header || 'Content-Security-Policy';
	const enforcingNow = ! /-Report-Only$/i.test( emittedHeaderName );
	const savedReportOnly = ! enforcingNow;

	const isCustom = 'custom' === hardening.csp_mode;
	const directives = hardening.csp_directives || {};
	const collecting = !! cspReportStats.collecting;

	// Providers the policy already uses somewhere, with origins missing from
	// the other directives their SDK needs. Nothing reports these until a
	// visitor reaches the step that requests them — the payment form, the
	// login modal's captcha — so enforcing on a clean violation log is not
	// evidence that those flows survive it.
	const gaps = bundleGaps( directives );

	// Structural holes that no report can surface, because the resource they
	// block is only requested on interaction. Both of these shipped as the
	// default policy and broke reCAPTCHA on a live site.
	const risks = policyRisks( directives );

	// Which page types the current window has actually served a reporting
	// policy to. Reports alone cannot answer this — a page that loads cleanly
	// produces no report, so silence from a page nobody visited is
	// indistinguishable from silence from a page that passed.
	const coverage = cspReportStats.coverage || {};
	const coverageTypes = Object.keys( coverage );
	const coverageTotal = coverageTypes.length;
	const coverageCovered = coverageTypes.filter(
		( type ) => coverage[ type ]
	).length;

	// Directives browsers ignore in a Report-Only policy, so the header under
	// test never exercised them however long the window ran. Mirrors
	// SPFW_Module_Hardening::REPORT_ONLY_IGNORED.
	const enforcedOnly = [ 'frame-ancestors', 'sandbox' ].filter(
		( name ) => ( directives[ name ] || [] ).length > 0
	);

	// Human labels for the coverage checklist.
	const PAGE_TYPE_LABELS = {
		home: __( 'Home', 'simple-performance-for-wordpress' ),
		page: __( 'A page', 'simple-performance-for-wordpress' ),
		post: __( 'A post', 'simple-performance-for-wordpress' ),
		archive: __( 'An archive', 'simple-performance-for-wordpress' ),
		search: __( 'Search results', 'simple-performance-for-wordpress' ),
		404: __( 'A 404', 'simple-performance-for-wordpress' ),
		shop: __( 'Shop', 'simple-performance-for-wordpress' ),
		product: __( 'A product', 'simple-performance-for-wordpress' ),
		cart: __( 'Cart', 'simple-performance-for-wordpress' ),
		checkout: __( 'Checkout', 'simple-performance-for-wordpress' ),
		account: __( 'My account', 'simple-performance-for-wordpress' ),
	};

	// Mirrors SPFW_Settings::CSP_MAX_TOKENS, read from the server so the two
	// cannot drift. Exceeding it used to truncate silently on save.
	const maxTokens = settings.csp_max_tokens || 30;

	// Raw text of each "additional hosts" field, kept locally so a trailing
	// space (needed to type the next host) is not stripped on every keystroke
	// by re-deriving the value from the parsed tokens. Cleared per-directive
	// when a discrete action (Allow, 'none', reset) changes hosts out-of-band.
	const [ hostText, setHostText ] = useState( {} );
	const [ scanning, setScanning ] = useState( false );
	const [ collectHours, setCollectHours ] = useState( 24 );

	// Origins the admin has just allowed, hidden immediately rather than
	// waiting for the next poll. The server drops them too — this is only so
	// the row disappears the moment the button is pressed.
	const [ dismissed, setDismissed ] = useState( [] );

	// Which row is awaiting its "Allow" confirmation. Violation reports are
	// submitted by unauthenticated browsers, so the origins listed here are
	// attacker-influencable — allowing one writes it into the live policy, and
	// that is not something a single stray click should do.
	const [ confirming, setConfirming ] = useState( null );

	// Trusted-tracker pre-fill confirmation modal.
	const [ showTrustedConfirm, setShowTrustedConfirm ] = useState( false );

	// Payment-provider pre-fill confirmation modal.
	const [ showPaymentConfirm, setShowPaymentConfirm ] = useState( false );

	// Bulk-allow confirmation state: null | 'all' | directive-name.
	const [ bulkConfirm, setBulkConfirm ] = useState( null );

	// Whether the admin is being asked to confirm leaving Report-Only. Turning
	// that toggle off is the moment the policy starts breaking things for real
	// visitors, and it was a single unguarded click — on evidence the admin had
	// no way to judge, because an empty violation log looks identical whether
	// the policy is clean or nothing ever tested it.
	const [ enforceConfirm, setEnforceConfirm ] = useState( false );

	// Test-endpoint state: null | 'testing' | 'ok' | 'error'.
	const [ testState, setTestState ] = useState( null );
	const testTimeoutRef = useRef( null );

	const clearHostText = ( name ) =>
		setHostText( ( prev ) => {
			const next = { ...prev };
			delete next[ name ];
			return next;
		} );

	// Poll the violation log only while a collection window is open — outside
	// it nothing can arrive, so polling would just be background noise.
	useEffect( () => {
		if ( ! enabled || ! collecting || ! onRefreshCspReports ) {
			return undefined;
		}

		const id = setInterval( onRefreshCspReports, 20000 );
		return () => clearInterval( id );
	}, [ enabled, collecting, onRefreshCspReports ] );

	const setDirectiveTokens = ( name, tokens ) => {
		onChange( 'csp_directives', { ...directives, [ name ]: tokens } );
	};

	const togglePresetToken = ( name, token ) => {
		const current = directives[ name ] || [];

		if ( NONE === token ) {
			// Selecting 'none' clears all sources (including custom hosts), so
			// drop the local host text too or it would show stale entries.
			clearHostText( name );
			setDirectiveTokens(
				name,
				current.includes( NONE ) ? [] : [ NONE ]
			);
			return;
		}

		const withoutNone = current.filter( ( t ) => t !== NONE );
		setDirectiveTokens(
			name,
			withoutNone.includes( token )
				? withoutNone.filter( ( t ) => t !== token )
				: [ ...withoutNone, token ]
		);
	};

	// Custom (non-preset) hosts for a directive, edited as a space-separated
	// text field beneath the chips.
	const setCustomHosts = ( directive, value ) => {
		setHostText( ( prev ) => ( { ...prev, [ directive.name ]: value } ) );

		const hosts = value
			.split( /\s+/ )
			.map( ( s ) => s.trim() )
			.filter( Boolean );
		const presetSelected = ( directives[ directive.name ] || [] ).filter(
			( t ) => directive.tokens.includes( t ) && t !== NONE
		);
		setDirectiveTokens( directive.name, [ ...presetSelected, ...hosts ] );
	};

	// Displayed value for a directive's hosts field: the live raw text if the
	// user is editing it, otherwise derived from the stored tokens.
	const hostFieldValue = ( directive ) =>
		undefined !== hostText[ directive.name ]
			? hostText[ directive.name ]
			: ( directives[ directive.name ] || [] )
					.filter( ( t ) => ! directive.tokens.includes( t ) )
					.join( ' ' );

	// "Allow" a reported source. Browsers report keyword/scheme blocks as a
	// bare word ('inline', 'eval', 'data', 'blob'), which map to real CSP
	// tokens; anything else is a host origin added verbatim.
	const KEYWORD_TOKENS = {
		inline: "'unsafe-inline'",
		eval: "'unsafe-eval'",
		data: 'data:',
		blob: 'blob:',
		filesystem: 'filesystem:',
		mediastream: 'mediastream:',
	};

	const tokenFor = ( origin ) => KEYWORD_TOKENS[ origin ] || origin;

	const allowSource = ( name, origin ) => {
		const token = tokenFor( origin );

		const current = ( directives[ name ] || [] ).filter(
			( t ) => t !== NONE
		);
		if ( ! current.includes( token ) ) {
			setDirectiveTokens( name, [ ...current, token ] );
		}
		// Let the hosts field recompute so a newly-allowed origin shows up.
		clearHostText( name );

		// The violation has been actioned: take it out of the outstanding list
		// straight away, and off the server so a later poll cannot resurrect it.
		setDismissed( ( prev ) =>
			prev.includes( `${ name }|${ origin }` )
				? prev
				: [ ...prev, `${ name }|${ origin }` ]
		);

		if ( onDismissCspReport ) {
			onDismissCspReport( name, origin );
		}
	};

	const loadRecommended = () => {
		setHostText( {} );
		onChange(
			'csp_directives',
			JSON.parse(
				JSON.stringify( settings.csp_default_directives || {} )
			)
		);
	};

	// Add all trusted third-party origins across every directive.
	const applyTrustedTrackers = () => {
		setShowTrustedConfirm( false );
		const next = { ...directives };
		Object.entries( TRUSTED_TRACKER_ORIGINS ).forEach( ( [ directive, origins ] ) => {
			const current = ( next[ directive ] || [] ).filter(
				( t ) => t !== NONE
			);
			const merged = [ ...new Set( [ ...current, ...origins ] ) ];
			next[ directive ] = merged;
		} );
		setHostText( {} );
		onChange( 'csp_directives', next );
	};

	// Close one provider's gap across every directive it is missing from.
	const fixGap = ( missing ) => {
		Object.keys( missing ).forEach( clearHostText );
		onChange(
			'csp_directives',
			Object.keys( missing ).reduce(
				( acc, directive ) =>
					addOrigins( acc, directive, missing[ directive ] ),
				directives
			)
		);
	};

	// Add the sources a structural risk suggests to the directive it names.
	const fixRisk = ( risk ) => {
		clearHostText( risk.directive );
		onChange(
			'csp_directives',
			addOrigins( directives, risk.directive, risk.suggest )
		);
	};

	const fixAllGaps = () => {
		gaps.forEach( ( gap ) =>
			Object.keys( gap.missing ).forEach( clearHostText )
		);
		onChange(
			'csp_directives',
			gaps.reduce(
				( acc, gap ) =>
					Object.keys( gap.missing ).reduce(
						( inner, directive ) =>
							addOrigins(
								inner,
								directive,
								gap.missing[ directive ]
							),
						acc
					),
				directives
			)
		);
	};

	// Add every origin a payment provider needs, across all its directives.
	const applyPaymentOrigins = () => {
		setShowPaymentConfirm( false );

		const next = THIRD_PARTY_BUNDLES.reduce( ( acc, bundle ) => {
			return Object.keys( bundle.directives ).reduce(
				( inner, directive ) =>
					addOrigins(
						inner,
						directive,
						bundle.directives[ directive ]
					),
				acc
			);
		}, directives );

		setHostText( {} );
		onChange( 'csp_directives', next );
	};

	// Allow all visible violations (bulk).
	const allowAll = ( forDirective = null ) => {
		setBulkConfirm( null );
		const toAllow = forDirective
			? visibleReports.filter( ( r ) => r.directive === forDirective )
			: visibleReports;

		if ( ! toAllow.length ) {
			return;
		}

		const next = { ...directives };

		toAllow.forEach( ( r ) => {
			const token = tokenFor( r.blocked_origin );
			const current = ( next[ r.directive ] || [] ).filter(
				( t ) => t !== NONE
			);
			if ( ! current.includes( token ) ) {
				next[ r.directive ] = [ ...current, token ];
			}
			setDismissed( ( prev ) =>
				prev.includes( `${ r.directive }|${ r.blocked_origin }` )
					? prev
					: [ ...prev, `${ r.directive }|${ r.blocked_origin }` ]
			);
			if ( onDismissCspReport ) {
				onDismissCspReport( r.directive, r.blocked_origin );
			}
		} );

		setHostText( {} );
		onChange( 'csp_directives', next );
	};

	// POST a synthetic violation report to the configured report_uri to verify
	// the endpoint is reachable from the browser.
	const testReportEndpoint = () => {
		const reportUri = cspReportStats.report_uri;
		if ( ! reportUri ) {
			return;
		}
		setTestState( 'testing' );
		clearTimeout( testTimeoutRef.current );

		fetch( reportUri, {
			method: 'POST',
			headers: { 'Content-Type': 'application/csp-report' },
			body: JSON.stringify( {
				'csp-report': {
					'blocked-uri': 'https://test.example.com',
					'violated-directive': 'connect-src',
					'document-uri': window.location.href,
					'effective-directive': 'connect-src',
				},
			} ),
		} )
			.then( ( res ) => {
				setTestState( res.ok || 204 === res.status ? 'ok' : 'error' );
				testTimeoutRef.current = setTimeout(
					() => setTestState( null ),
					5000
				);
			} )
			.catch( () => {
				setTestState( 'error' );
				testTimeoutRef.current = setTimeout(
					() => setTestState( null ),
					5000
				);
			} );
	};

	const switchMode = ( toCustom ) => {
		if ( toCustom && ! hardening.csp_policy ) {
			// Seed the raw editor from the current builder policy so the admin
			// has a starting point rather than a blank box.
			onChange( 'csp_policy', buildPolicyString( directives ) );
		}
		onChange( 'csp_mode', toCustom ? 'custom' : 'builder' );
	};

	// Group reports by directive; anything whose directive is not a builder row
	// falls into the "other" bucket shown at the bottom. Locally-allowed
	// entries are filtered out everywhere they would otherwise still show.
	const knownNames = CSP_DIRECTIVES.map( ( d ) => d.name );
	const visibleReports = cspReports.filter(
		( r ) =>
			! dismissed.includes( `${ r.directive }|${ r.blocked_origin }` )
	);
	const reportsFor = ( name ) =>
		visibleReports.filter( ( r ) => r.directive === name );
	const otherReports = visibleReports.filter(
		( r ) => ! knownNames.includes( r.directive )
	);

	return (
		<SettingsCard
			title={ __(
				'Content-Security-Policy',
				'simple-performance-for-wordpress'
			) }
			description={ __(
				'A Content-Security-Policy restricts where scripts, styles, images, and other resources may load from — the strongest defense against cross-site scripting (XSS). It is powerful but can break your front end if a resource is not allowed, so build it up in Report-Only mode and only enforce once the violations below are clear.',
				'simple-performance-for-wordpress'
			) }
		>
			<SettingsRow
				title={ __(
					'Send Content-Security-Policy header',
					'simple-performance-for-wordpress'
				) }
				description={ __(
					'Emits the header on front-end responses (never in wp-admin). Test thoroughly before enforcing.',
					'simple-performance-for-wordpress'
				) }
			>
				<Toggle
					checked={ enabled }
					onChange={ ( v ) => onChange( 'csp_enabled', v ) }
				/>
			</SettingsRow>

			{ enabled && (
				<>
					<SettingsRow
						title={ __(
							'Report-Only mode',
							'simple-performance-for-wordpress'
						) }
						description={ __(
							'Sends Content-Security-Policy-Report-Only, which logs violations without blocking anything so you can see exactly what the policy would break. Keep this on until the list below is clear, then turn it off to enforce. Violations are collected here in both modes — while enforcing, a warning below means something is actually being blocked.',
							'simple-performance-for-wordpress'
						) }
					>
						<Toggle
							checked={ reportOnly }
							onChange={ ( v ) => {
								if ( v ) {
									setEnforceConfirm( false );
									onChange( 'csp_report_only', true );
									return;
								}

								setEnforceConfirm( true );
							} }
						/>
					</SettingsRow>

					{ enforceConfirm && reportOnly && (
						<div className="rounded-md bg-gray-50 p-4 ring-1 ring-inset ring-gray-300">
							<h4 className="text-sm font-semibold text-gray-900">
								{ __(
									'Enforce this policy for real visitors?',
									'simple-performance-for-wordpress'
								) }
							</h4>

							<p className="mt-1 text-sm text-gray-700">
								{ __(
									'From the next save, anything this policy does not allow is blocked instead of logged. Here is what the testing so far actually covered:',
									'simple-performance-for-wordpress'
								) }
							</p>

							<ul className="mt-3 space-y-1 text-sm text-gray-800">
								<li>
									{ sprintf(
										/* translators: %d: number of distinct violations recorded */
										__(
											'%d distinct violations recorded.',
											'simple-performance-for-wordpress'
										),
										cspReportStats.entries || 0
									) }
								</li>
								<li>
									{ coverageTotal > 0
										? sprintf(
												/* translators: 1: covered page types, 2: total page types */
												__(
													'%1$d of %2$d page types were served a reporting policy.',
													'simple-performance-for-wordpress'
												),
												coverageCovered,
												coverageTotal
										  )
										: __(
												'No collection window has run, so nothing has been tested.',
												'simple-performance-for-wordpress'
										  ) }
								</li>
								{ ! cspReportStats.admin_included && (
									<li className="text-amber-800">
										{ __(
											'Your own browsing was not included — only logged-out visitors were sent the policy.',
											'simple-performance-for-wordpress'
										) }
									</li>
								) }
								{ enforcedOnly.length > 0 && (
									<li>
										{ sprintf(
											/* translators: %s: space-separated CSP directive names */
											__(
												'Not tested, because browsers ignore them in Report-Only: %s. They start applying now.',
												'simple-performance-for-wordpress'
											),
											enforcedOnly.join( ' ' )
										) }
									</li>
								) }
							</ul>

							{ ( coverageTotal === 0 ||
								coverageCovered < coverageTotal ) && (
								<p className="mt-3 text-sm text-amber-800">
									{ __(
										'Pages that were never loaded during a window cannot have reported anything, so an empty list is not evidence about them. The captcha inside a login or password-reset modal, and the payment step of checkout, only request anything when someone interacts with them — visiting the page is not enough.',
										'simple-performance-for-wordpress'
									) }
								</p>
							) }

							<p className="mt-3 text-xs text-gray-600">
								{ __(
									'A short collection window opens automatically when you enforce, so anything that does break is recorded rather than silent.',
									'simple-performance-for-wordpress'
								) }
							</p>

							<div className="mt-4 flex items-center gap-x-4">
								<button
									type="button"
									onClick={ () => {
										setEnforceConfirm( false );
										onChange( 'csp_report_only', false );
									} }
									className="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-indigo-500"
								>
									{ __(
										'Enforce the policy',
										'simple-performance-for-wordpress'
									) }
								</button>
								<button
									type="button"
									onClick={ () => setEnforceConfirm( false ) }
									className="text-sm font-medium text-gray-600 hover:text-gray-500"
								>
									{ __(
										'Stay in Report-Only',
										'simple-performance-for-wordpress'
									) }
								</button>
							</div>
						</div>
					) }

					{ gaps.length > 0 && (
						<div
							className={ `rounded-md p-4 ring-1 ring-inset ${
								reportOnly
									? 'bg-amber-50 ring-amber-200'
									: 'bg-red-50 ring-red-200'
							}` }
						>
							<h4
								className={ `text-sm font-semibold ${
									reportOnly
										? 'text-amber-900'
										: 'text-red-900'
								}` }
							>
								{ reportOnly
									? __(
											'Not ready to enforce — third-party origins are missing',
											'simple-performance-for-wordpress'
									  )
									: __(
											'Enforcing with third-party origins missing',
											'simple-performance-for-wordpress'
									  ) }
							</h4>
							<p
								className={ `mt-1 text-sm ${
									reportOnly
										? 'text-amber-800'
										: 'text-red-800'
								}` }
							>
								{ __(
									'This policy already allows these providers somewhere, so the site uses them — but other directives they need are missing origins. Nothing reports this until a visitor reaches the step that requests them: a payment form, or the captcha inside a login or password-reset modal. An empty violation list above is not evidence that those flows survive enforcing.',
									'simple-performance-for-wordpress'
								) }
							</p>

							<ul className="mt-3 space-y-2">
								{ gaps.map( ( gap ) => (
									<li
										key={ gap.bundle.id }
										className="flex flex-wrap items-start justify-between gap-2"
									>
										<span className="text-sm text-gray-800">
											<span className="font-semibold">
												{ gap.bundle.label }
											</span>
											{ Object.keys( gap.missing ).map(
												( directive ) => (
													<span
														key={ directive }
														className="block"
													>
														<span className="font-mono text-xs text-gray-600">
															{ directive }
														</span>
														{ ' ' }
														<span className="font-mono text-xs">
															{ gap.missing[
																directive
															].join( ' ' ) }
														</span>
													</span>
												)
											) }
										</span>
										<button
											type="button"
											onClick={ () =>
												fixGap( gap.missing )
											}
											className="shrink-0 text-sm font-medium text-indigo-600 hover:text-indigo-500"
										>
											{ __(
												'Add these origins',
												'simple-performance-for-wordpress'
											) }
										</button>
									</li>
								) ) }
							</ul>

							{ gaps.length > 1 && (
								<button
									type="button"
									onClick={ fixAllGaps }
									className="mt-3 text-sm font-medium text-indigo-600 hover:text-indigo-500"
								>
									{ __(
										'Add every missing origin',
										'simple-performance-for-wordpress'
									) }
								</button>
							) }

							<p className="mt-3 text-xs text-gray-600">
								{ __(
									'These lists come from each provider’s published CSP guidance and are a starting point, not a guarantee — integrations differ and providers add hosts. Confirm with a real test purchase, and a real password reset, before enforcing.',
									'simple-performance-for-wordpress'
								) }
							</p>
						</div>
					) }

					{ risks.length > 0 && (
						<div className="rounded-md bg-amber-50 p-4 ring-1 ring-inset ring-amber-200">
							<h4 className="text-sm font-semibold text-amber-900">
								{ __(
									'This policy blocks third-party content in a way nothing will report',
									'simple-performance-for-wordpress'
								) }
							</h4>

							<ul className="mt-3 space-y-3">
								{ risks.map( ( risk ) => (
									<li
										key={ risk.id }
										className="flex flex-wrap items-start justify-between gap-2"
									>
										<span className="max-w-xl text-sm text-amber-900">
											{ 'missing-frame-src' === risk.id
												? __(
														'No frame-src is set, so embedded content falls back to default-src. Every third-party iframe on the site — a reCAPTCHA challenge, an embedded video, a map, a payment form — is refused, and each one fails as a blank space rather than an error anyone connects to CSP.',
														'simple-performance-for-wordpress'
												  )
												: __(
														'connect-src allows nothing but this site, so every third-party fetch is refused: the reCAPTCHA token call, analytics beacons, a payment SDK’s API. None of them is requested until a visitor interacts with the page, so Report-Only browsing will not surface any of it.',
														'simple-performance-for-wordpress'
												  ) }
										</span>
										<button
											type="button"
											onClick={ () => fixRisk( risk ) }
											className="shrink-0 text-sm font-medium text-indigo-600 hover:text-indigo-500"
										>
											{ sprintf(
												/* translators: 1: CSP directive name, 2: source tokens to add */
												__(
													'Add %2$s to %1$s',
													'simple-performance-for-wordpress'
												),
												risk.directive,
												risk.suggest.join( ' ' )
											) }
										</button>
									</li>
								) ) }
							</ul>

							<p className="mt-3 text-xs text-amber-800">
								{ __(
									'This is the failure that prompted the check: a site enforcing these defaults left logged-out visitors unable to reset a password, because the captcha could neither load its frame nor fetch its token.',
									'simple-performance-for-wordpress'
								) }
							</p>
						</div>
					) }

					<SettingsRow
						title={ __(
							'Do not apply to logged-in users',
							'simple-performance-for-wordpress'
						) }
						description={ __(
							'Skips the header for logged-in users. Recommended: the block editor, customizer, and admin bar rely on inline scripts a strict policy would block. Violations are therefore only reported by logged-out visitors. Front-end pages viewed while logged in are also marked uncacheable while this is on, so a copy without the header can never be cached and then served to logged-out visitors.',
							'simple-performance-for-wordpress'
						) }
					>
						<Toggle
							checked={ !! hardening.csp_exclude_logged_in }
							onChange={ ( v ) =>
								onChange( 'csp_exclude_logged_in', v )
							}
						/>
					</SettingsRow>

					<SettingsRow
						title={ __(
							'Advanced: edit raw policy',
							'simple-performance-for-wordpress'
						) }
						description={ __(
							'Turn off the builder and edit the full policy string by hand — use this to add directives or sources the builder does not cover.',
							'simple-performance-for-wordpress'
						) }
					>
						<Toggle
							checked={ isCustom }
							onChange={ ( v ) => switchMode( v ) }
						/>
					</SettingsRow>

					<SettingsRow
						title={ __(
							'Tighten script-src (Advanced)',
							'simple-performance-for-wordpress'
						) }
						description={ __(
							"Replaces 'unsafe-inline' in script-src with sha256 hashes of your site's inline scripts. Real XSS protection, but it requires re-scanning after every plugin or theme change, and any inline script that varies per request (timestamps, personalization, cart contents) hashes differently every time and will always violate. Use Report-Only mode until the violation log is clean.",
							'simple-performance-for-wordpress'
						) }
					>
						<div className="w-full space-y-3">
							<Toggle
								checked={
									!! hardening.csp_tighten_script_src
								}
								onChange={ ( v ) =>
									onChange( 'csp_tighten_script_src', v )
								}
							/>

							{ !! hardening.csp_tighten_script_src && (
								<div className="space-y-3 rounded-md bg-gray-50 p-4">
									<div className="flex items-center justify-between">
										<span className="text-sm text-gray-700">
											{ hardening.csp_script_hashes &&
											hardening.csp_script_hashes.length > 0
												? sprintf(
														/* translators: %d: number of hashes */
														__(
															'%d script hashes collected',
															'simple-performance-for-wordpress'
														),
														hardening.csp_script_hashes.length
												  )
												: __(
														'No hashes collected yet',
														'simple-performance-for-wordpress'
												  ) }
										</span>
										<button
											type="button"
											onClick={ () => {
												setScanning( true );
												apiFetch( {
													path: '/spfw/v1/settings/scan-script-hashes',
													method: 'POST',
												} )
													.then(
														( data ) => {
															onChange(
																'csp_script_hashes',
																data.hashes
															);
															onChange(
																'csp_hash_last_scan',
																data.last_scan
															);
															setScanning(
																false
															);
														}
													)
													.catch( () =>
														setScanning(
															false
														)
													);
											} }
											disabled={ scanning }
											className="rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-500 disabled:opacity-50"
										>
											{ scanning
												? __(
														'Scanning…',
														'simple-performance-for-wordpress'
												  )
												: __(
														'Scan for inline scripts',
														'simple-performance-for-wordpress'
												  ) }
										</button>
									</div>

									<p className="text-xs text-gray-500">
										{ __(
											'Scans your homepage, most recent post and page, and — on a WooCommerce site — the shop, cart, checkout, account and a product page. Re-scan after any plugin or theme change.',
											'simple-performance-for-wordpress'
										) }
									</p>

									<div className="border-t border-gray-200 pt-3">
										<div className="flex items-start justify-between gap-x-4">
											<div>
												<span className="text-sm font-medium text-gray-900">
													{ __(
														"Also add 'strict-dynamic'",
														'simple-performance-for-wordpress'
													) }
												</span>
												<p className="mt-1 text-xs text-gray-600">
													{ __(
														"Off by default, and a much bigger change than hashing. Browsers that support 'strict-dynamic' ignore https: and every host in script-src — only the hashed scripts, and whatever those scripts load themselves, may run. Any <script src> written straight into your HTML by a theme or a third party (reCAPTCHA, a hand-placed tag manager snippet) is refused. Turn this on only after testing with it in Report-Only mode.",
														'simple-performance-for-wordpress'
													) }
												</p>
											</div>
											<Toggle
												checked={
													!! hardening.csp_strict_dynamic
												}
												onChange={ ( v ) =>
													onChange(
														'csp_strict_dynamic',
														v
													)
												}
											/>
										</div>

										{ !! hardening.csp_strict_dynamic && (
											<p className="mt-2 text-xs text-amber-600">
												{ __(
													'Your script-src host allowlist is now ignored by supporting browsers. Everything in it must be loaded by a hashed script to keep working.',
													'simple-performance-for-wordpress'
												) }
											</p>
										) }
									</div>
								</div>
							) }
						</div>
					</SettingsRow>

					{ ! isCustom && (
						<div className="space-y-5 pt-2">
							<div className="flex items-center justify-between flex-wrap gap-2">
								<h4 className="text-sm font-semibold text-gray-900">
									{ __(
										'Policy builder',
										'simple-performance-for-wordpress'
									) }
								</h4>
								<div className="flex items-center gap-x-3">
									{ showTrustedConfirm ? (
										<span className="flex items-center gap-x-2 text-sm">
											<span className="text-gray-700">
												{ __(
													'Add Google Maps, Analytics, Clarity & Facebook origins?',
													'simple-performance-for-wordpress'
												) }
											</span>
											<button
												type="button"
												onClick={ applyTrustedTrackers }
												className="font-medium text-indigo-600 hover:text-indigo-500"
											>
												{ __( 'Confirm', 'simple-performance-for-wordpress' ) }
											</button>
											<button
												type="button"
												onClick={ () => setShowTrustedConfirm( false ) }
												className="font-medium text-gray-500 hover:text-gray-700"
											>
												{ __( 'Cancel', 'simple-performance-for-wordpress' ) }
											</button>
										</span>
									) : (
										<button
											type="button"
											onClick={ () => setShowTrustedConfirm( true ) }
											className="text-sm font-medium text-indigo-600 hover:text-indigo-500"
										>
											{ __(
												'Pre-fill common third-party origins',
												'simple-performance-for-wordpress'
											) }
										</button>
									) }
									{ showPaymentConfirm ? (
										<span className="flex items-center gap-x-2 text-sm">
											<span className="text-gray-700">
												{ __(
													'Add Stripe & PayPal origins?',
													'simple-performance-for-wordpress'
												) }
											</span>
											<button
												type="button"
												onClick={ applyPaymentOrigins }
												className="font-medium text-indigo-600 hover:text-indigo-500"
											>
												{ __(
													'Confirm',
													'simple-performance-for-wordpress'
												) }
											</button>
											<button
												type="button"
												onClick={ () =>
													setShowPaymentConfirm(
														false
													)
												}
												className="font-medium text-gray-500 hover:text-gray-700"
											>
												{ __(
													'Cancel',
													'simple-performance-for-wordpress'
												) }
											</button>
										</span>
									) : (
										<button
											type="button"
											onClick={ () =>
												setShowPaymentConfirm( true )
											}
											className="text-sm font-medium text-indigo-600 hover:text-indigo-500"
										>
											{ __(
												'Pre-fill payment provider origins',
												'simple-performance-for-wordpress'
											) }
										</button>
									) }
									<button
										type="button"
										onClick={ loadRecommended }
										className="text-sm font-medium text-gray-500 hover:text-gray-700"
									>
										{ __(
											'Reset to recommended',
											'simple-performance-for-wordpress'
										) }
									</button>
								</div>
							</div>

							{ CSP_DIRECTIVES.map( ( directive ) => {
								const selected =
									directives[ directive.name ] || [];
								const reports = reportsFor( directive.name );

								return (
									<div
										key={ directive.name }
										className="border-b border-gray-100 pb-4 last:border-0"
									>
										<div className="flex items-baseline justify-between gap-x-3">
											<span className="text-sm font-medium text-gray-900">
												{ directive.label }
											</span>
											<code className="text-xs text-gray-400">
												{ directive.name }
											</code>
										</div>

										<div className="mt-2 flex flex-wrap gap-2">
											{ directive.tokens.map(
												( token ) => (
													<Chip
														key={ token }
														active={ selected.includes(
															token
														) }
														onClick={ () =>
															togglePresetToken(
																directive.name,
																token
															)
														}
													>
														{ token }
													</Chip>
												)
											) }
										</div>

										<input
											type="text"
											value={ hostFieldValue(
												directive
											) }
											onChange={ ( e ) =>
												setCustomHosts(
													directive,
													e.target.value
												)
											}
											placeholder={ __(
												'Additional hosts, space-separated (e.g. https://www.googletagmanager.com)',
												'simple-performance-for-wordpress'
											) }
											className="mt-2 block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 text-xs font-mono"
										/>

										{ ( directives[ directive.name ] || [] )
											.length >= maxTokens && (
											<p className="mt-1 text-xs font-medium text-red-700">
												{ sprintf(
													/* translators: %d: maximum number of sources per directive. */
													__(
														'At the %d-source limit for this directive — anything added beyond it is dropped when you save. Consolidate hosts with a wildcard (https://*.example.com) to make room.',
														'simple-performance-for-wordpress'
													),
													maxTokens
												) }
											</p>
										) }

										{ reports.length > 0 && (
											<div className="mt-2 rounded-md bg-amber-50 p-3 ring-1 ring-inset ring-amber-600/20">
												<p className="text-xs font-semibold text-amber-800">
													{ __(
														'Blocked by this directive:',
														'simple-performance-for-wordpress'
													) }
												</p>
												<ul className="mt-1 space-y-1">
													{ reports.map( ( r ) => {
														const rowKey = `${ directive.name }|${ r.blocked_origin }`;
														const isConfirming =
															confirming ===
															rowKey;

														return (
															<li
																key={
																	r.blocked_origin
																}
																className="flex items-center justify-between gap-x-3 text-xs text-amber-900"
															>
																<span className="min-w-0 truncate">
																	<span className="font-mono">
																		{
																			r.blocked_origin
																		}
																	</span>{ ' ' }
																	<span className="text-amber-600">
																		(
																		{
																			r.count
																		}
																		)
																	</span>
																	{ r.document_uri && (
																		<span
																			className="block truncate font-normal text-amber-700"
																			title={
																				r.document_uri
																			}
																		>
																			{ sprintf(
																				/* translators: %s: URL of the page the violation was reported from */
																				__(
																					'on %s',
																					'simple-performance-for-wordpress'
																				),
																				r.document_uri
																			) }
																		</span>
																	) }
																</span>
																{ isConfirming ? (
																	<span className="flex shrink-0 items-center gap-x-2">
																		<span className="text-amber-700">
																			{ sprintf(
																				/* translators: %s: the CSP source token that will be added */
																				__(
																					'Add %s?',
																					'simple-performance-for-wordpress'
																				),
																				tokenFor(
																					r.blocked_origin
																				)
																			) }
																		</span>
																		<button
																			type="button"
																			onClick={ () => {
																				setConfirming(
																					null
																				);
																				allowSource(
																					directive.name,
																					r.blocked_origin
																				);
																			} }
																			className="font-medium text-indigo-600 hover:text-indigo-500"
																		>
																			{ __(
																				'Confirm',
																				'simple-performance-for-wordpress'
																			) }
																		</button>
																		<button
																			type="button"
																			onClick={ () =>
																				setConfirming(
																					null
																				)
																			}
																			className="font-medium text-gray-500 hover:text-gray-700"
																		>
																			{ __(
																				'Cancel',
																				'simple-performance-for-wordpress'
																			) }
																		</button>
																	</span>
																) : (
																	<button
																		type="button"
																		onClick={ () =>
																			setConfirming(
																				rowKey
																			)
																		}
																		className="shrink-0 font-medium text-indigo-600 hover:text-indigo-500"
																	>
																		{ __(
																			'Allow',
																			'simple-performance-for-wordpress'
																		) }
																	</button>
																) }
															</li>
														);
													} ) }
												</ul>
											</div>
										) }
									</div>
								);
							} ) }

							<div>
								<p className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">
									{ __(
										'Generated policy',
										'simple-performance-for-wordpress'
									) }
								</p>
								<pre className="whitespace-pre-wrap break-words rounded-md bg-gray-50 p-3 text-xs font-mono text-gray-700 ring-1 ring-inset ring-gray-200">
									{ buildPolicyString( directives ) ||
										__(
											'(empty — no directives set)',
											'simple-performance-for-wordpress'
										) }
								</pre>
							</div>
						</div>
					) }

					{ isCustom && (
						<div className="pt-2">
							<div className="flex items-center justify-between">
								<label
									htmlFor="spfw-csp-policy"
									className="block text-sm font-medium text-gray-900"
								>
									{ __(
										'Policy',
										'simple-performance-for-wordpress'
									) }
								</label>
								<button
									type="button"
									onClick={ () =>
										onChange(
											'csp_policy',
											settings.csp_default || ''
										)
									}
									className="text-sm font-medium text-indigo-600 hover:text-indigo-500"
								>
									{ __(
										'Load recommended policy',
										'simple-performance-for-wordpress'
									) }
								</button>
							</div>
							<p className="mt-1 mb-2 text-sm text-gray-500">
								{ __(
									'The full policy directive string. Leave blank to use the recommended WordPress-friendly default shown as the placeholder.',
									'simple-performance-for-wordpress'
								) }
							</p>
							<textarea
								id="spfw-csp-policy"
								rows={ 4 }
								value={ hardening.csp_policy || '' }
								placeholder={ settings.csp_default || '' }
								onChange={ ( e ) =>
									onChange( 'csp_policy', e.target.value )
								}
								className={ textareaClass }
							/>
						</div>
					) }

					{ settings.csp_emitted_policy && (
						<div className="mt-4 border-t border-gray-100 pt-4">
							<p className="mb-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs font-semibold uppercase tracking-wide text-gray-500">
								{ __(
									'Actual emitted header',
									'simple-performance-for-wordpress'
								) }
								{ enforcingNow ? (
									<span className="rounded bg-red-100 px-1.5 py-0.5 text-red-700 ring-1 ring-inset ring-red-200">
										{ __(
											'Enforcing — blocking now',
											'simple-performance-for-wordpress'
										) }
									</span>
								) : (
									<span className="rounded bg-green-100 px-1.5 py-0.5 text-green-700 ring-1 ring-inset ring-green-200">
										{ __(
											'Report-only — nothing blocked',
											'simple-performance-for-wordpress'
										) }
									</span>
								) }
							</p>
							<pre
								className={ `whitespace-pre-wrap break-words rounded-md p-3 text-xs font-mono ring-1 ring-inset ${
									enforcingNow
										? 'bg-red-50 text-red-900 ring-red-200'
										: 'bg-indigo-50 text-indigo-900 ring-indigo-200'
								}` }
							>
								<span className="font-semibold">
									{ emittedHeaderName }:
								</span>{ ' ' }
								{ settings.csp_emitted_policy }
							</pre>
							<p className="mt-1 text-xs text-gray-500">
								{ savedReportOnly !== reportOnly
									? __(
											'This is what the site is sending right now, from the last saved settings — you have an unsaved change to Report-Only mode above. Save to apply it.',
											'simple-performance-for-wordpress'
									  )
									: __(
											'This is what the site is sending right now, taken from the saved settings.',
											'simple-performance-for-wordpress'
									  ) }
								{ settings.csp_excludes_logged_in &&
									' ' +
										__(
											'Not sent to you while logged in — "Do not apply to logged-in users" is on, so check it in a private window.',
											'simple-performance-for-wordpress'
										) }
							</p>
						</div>
					) }

					<div className="mt-4 border-t border-gray-100 pt-4">
						<div className="flex items-center justify-between">
							<h4 className="text-sm font-semibold text-gray-900">
								{ __(
									'Violation reports',
									'simple-performance-for-wordpress'
								) }
							</h4>
							<div className="flex items-center gap-x-4">
								{ onRefreshCspReports && (
									<button
										type="button"
										onClick={ onRefreshCspReports }
										className="text-sm font-medium text-indigo-600 hover:text-indigo-500"
									>
										{ __(
											'Refresh',
											'simple-performance-for-wordpress'
										) }
									</button>
								) }
								{ onClearCspReports &&
									visibleReports.length > 0 && (
										<button
											type="button"
											onClick={ onClearCspReports }
											className="text-sm font-medium text-gray-500 hover:text-gray-700"
										>
											{ __(
												'Clear log',
												'simple-performance-for-wordpress'
											) }
										</button>
									) }
							</div>
						</div>

						<div className="mt-2 rounded-md bg-gray-50 p-4">
							<p className="text-sm text-gray-600">
								{ __(
									'Collecting violations asks every visitor’s browser to POST a report to this site, which cannot be cached and costs a full page load each time. Pages carrying a reporting policy are also kept out of the page cache, because the header is set by PHP and a cache hit never runs PHP — without that, a window only sees whatever the cache happened to regenerate. So collection runs in a time-boxed window: open one, browse the site yourself (or let real traffic do it), then work through the list below. The window closes itself.',
									'simple-performance-for-wordpress'
								) }
							</p>

							<div className="mt-3 flex flex-wrap items-center gap-3">
								{ collecting ? (
									<>
										<span className="inline-flex items-center rounded-md bg-green-100 px-2 py-1 text-xs font-medium text-green-800">
											{ __(
												'Collecting',
												'simple-performance-for-wordpress'
											) }
											{ cspReportStats.collect_until >
												cspReportStats.now &&
												` — ${ formatRemaining(
													cspReportStats.collect_until -
														cspReportStats.now
												) }` }
										</span>
										{ onSetCspCollection && (
											<button
												type="button"
												onClick={ () =>
													onSetCspCollection( 'stop' )
												}
												className="rounded-md bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
											>
												{ __(
													'Stop collecting',
													'simple-performance-for-wordpress'
												) }
											</button>
										) }
									</>
								) : (
									<>
										<span className="inline-flex items-center rounded-md bg-gray-200 px-2 py-1 text-xs font-medium text-gray-700">
											{ __(
												'Not collecting',
												'simple-performance-for-wordpress'
											) }
										</span>
										<select
											value={ collectHours }
											onChange={ ( e ) =>
												setCollectHours(
													parseInt(
														e.target.value,
														10
													)
												)
											}
											className="rounded-md border-0 py-1 pl-2 pr-8 text-xs text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600"
										>
											<option value={ 1 }>
												{ __(
													'for 1 hour',
													'simple-performance-for-wordpress'
												) }
											</option>
											<option value={ 24 }>
												{ __(
													'for 24 hours',
													'simple-performance-for-wordpress'
												) }
											</option>
											<option value={ 72 }>
												{ __(
													'for 3 days',
													'simple-performance-for-wordpress'
												) }
											</option>
										</select>
										{ onSetCspCollection && (
											<button
												type="button"
												onClick={ () =>
													onSetCspCollection(
														'start',
														collectHours
													)
												}
												className="rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-500"
											>
												{ __(
													'Start collecting',
													'simple-performance-for-wordpress'
												) }
											</button>
										) }
									</>
								) }

								<label
									htmlFor="spfw-csp-sample"
									className="flex items-center gap-x-2 text-xs text-gray-600"
								>
									{ __(
										'Sample',
										'simple-performance-for-wordpress'
									) }
									<select
										id="spfw-csp-sample"
										value={
											hardening.csp_collect_sample || 100
										}
										onChange={ ( e ) =>
											onChange(
												'csp_collect_sample',
												parseInt( e.target.value, 10 )
											)
										}
										className="rounded-md border-0 py-1 pl-2 pr-8 text-xs text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600"
									>
										<option value={ 100 }>
											{ __(
												'100% of page views',
												'simple-performance-for-wordpress'
											) }
										</option>
										<option value={ 25 }>
											{ __(
												'25% of page views',
												'simple-performance-for-wordpress'
											) }
										</option>
										<option value={ 10 }>
											{ __(
												'10% of page views',
												'simple-performance-for-wordpress'
											) }
										</option>
										<option value={ 1 }>
											{ __(
												'1% of page views',
												'simple-performance-for-wordpress'
											) }
										</option>
									</select>
								</label>
							</div>

							<p className="mt-2 text-xs text-gray-500">
								{ __(
									'On a busy site, lower the sample rate — a representative sample finds the same broken resources at a fraction of the requests. Remember to Save after changing it.',
									'simple-performance-for-wordpress'
								) }
							</p>

							{ collecting && (
								<p className="mt-2 text-xs text-gray-500">
									{ sprintf(
										/* translators: 1: number of distinct violations, 2: total reports recorded */
										__(
											'%1$d distinct violations, %2$d reports recorded.',
											'simple-performance-for-wordpress'
										),
										cspReportStats.entries || 0,
										cspReportStats.recorded || 0
									) }
									{ cspReportStats.full &&
										` ${ __(
											'The log is full — clear it to make room for new violations.',
											'simple-performance-for-wordpress'
										) }` }
								</p>
							) }
						
							{ collecting && cspReportStats.dropped > 0 && (
								<p className="mt-2 text-xs text-amber-700">
									{ sprintf(
										/* translators: %d: count of dropped reports */
										__(
											'%d reports were dropped by the rate limiter this session. Raise the rate limit below or clear the log to make room.',
											'simple-performance-for-wordpress'
										),
										cspReportStats.dropped
									) }
								</p>
							) }

							{ collecting && (
								<p
									className={ `mt-2 text-xs ${
										cspReportStats.admin_included
											? 'text-green-700'
											: 'text-amber-700'
									}` }
								>
									{ cspReportStats.admin_included
										? __(
												'You are inside the test: while this window is open you are sent the policy too, so your own browsing reports violations. Open the login and password-reset forms, and walk through checkout — those load scripts that nothing else on the site requests.',
												'simple-performance-for-wordpress'
										  )
										: __(
												'You are not inside the test. The policy is being withheld from logged-in users, so nothing you do in this browser can report a violation — only logged-out visitors can. Turn on “Include my own browsing” below, or test in a private window.',
												'simple-performance-for-wordpress'
										  ) }
								</p>
							) }

							{ collecting && coverageTotal > 0 && (
								<div className="mt-3 rounded-md bg-white p-3 ring-1 ring-inset ring-gray-200">
									<p className="text-xs font-semibold text-gray-600">
										{ sprintf(
											/* translators: 1: covered page types, 2: total page types */
											__(
												'Pages covered this window: %1$d of %2$d',
												'simple-performance-for-wordpress'
											),
											coverageCovered,
											coverageTotal
										) }
									</p>

									<ul className="mt-2 flex flex-wrap gap-2">
										{ coverageTypes.map( ( type ) => (
											<li
												key={ type }
												className={ `rounded px-2 py-1 text-xs ${
													coverage[ type ]
														? 'bg-green-100 text-green-800'
														: 'bg-gray-100 text-gray-500'
												}` }
											>
												{ coverage[ type ]
													? '✓ '
													: '· ' }
												{ PAGE_TYPE_LABELS[ type ] ||
													type }
											</li>
										) ) }
									</ul>

									<p className="mt-2 text-xs text-gray-500">
										{ __(
											'A page nobody loaded cannot have reported anything, so an empty list below says nothing about the grey entries. Coverage cannot see interactions either: opening a captcha-protected form or reaching the payment step requests scripts that merely viewing the page does not.',
											'simple-performance-for-wordpress'
										) }
									</p>
								</div>
							) }
						
							{ cspReportStats.report_uri && (
								<div className="mt-3 rounded-md bg-gray-50 p-3 ring-1 ring-inset ring-gray-200 space-y-2">
									<div className="flex items-center justify-between flex-wrap gap-2">
										<p className="text-xs font-semibold text-gray-600">
											{ __( 'Report endpoint', 'simple-performance-for-wordpress' ) }
										</p>
										<div className="flex items-center gap-x-2">
											{ 'ok' === testState && (
												<span className="text-xs text-green-700">
													{ __( 'Reachable', 'simple-performance-for-wordpress' ) }
												</span>
											) }
											{ 'error' === testState && (
												<span className="text-xs text-red-700">
													{ __( 'Failed — check CORS / CDN config', 'simple-performance-for-wordpress' ) }
												</span>
											) }
											<button
												type="button"
												onClick={ testReportEndpoint }
												disabled={ 'testing' === testState }
												className="rounded-md bg-white px-3 py-1 text-xs font-semibold text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-50"
											>
												{ 'testing' === testState
													? __( 'Testing…', 'simple-performance-for-wordpress' )
													: __( 'Test endpoint', 'simple-performance-for-wordpress' ) }
											</button>
										</div>
									</div>
									<code className="block text-xs font-mono text-gray-700 break-all">
										{ cspReportStats.report_uri }
									</code>
									<div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-500">
										{ null != cspReportStats.last_report_age && (
											<span>
												{ cspReportStats.last_report_age < 0
													? __( 'No reports received yet', 'simple-performance-for-wordpress' )
													: sprintf(
														/* translators: %d: seconds since last report */
														__( 'Last report: %ds ago', 'simple-performance-for-wordpress' ),
														cspReportStats.last_report_age
													) }
											</span>
										) }
										{ null != cspReportStats.sampling && (
											<span>
												{ sprintf(
													/* translators: %d: sampling percentage */
													__( 'Sampling: %d%%', 'simple-performance-for-wordpress' ),
													cspReportStats.sampling
												) }
											</span>
										) }
									</div>
								</div>
							) }
						
							<div className="mt-3">
								<label
									htmlFor="spfw-csp-rate-limit"
									className="flex items-center gap-x-2 text-xs text-gray-600"
								>
									{ __( 'Max new violations per minute', 'simple-performance-for-wordpress' ) }
									<select
										id="spfw-csp-rate-limit"
										value={ hardening.csp_rate_limit || 10 }
										onChange={ ( e ) =>
											onChange(
												'csp_rate_limit',
												parseInt( e.target.value, 10 )
											)
										}
										className="rounded-md border-0 py-1 pl-2 pr-8 text-xs text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600"
									>
										<option value={ 5 }>{ __( '5 / min', 'simple-performance-for-wordpress' ) }</option>
										<option value={ 10 }>{ __( '10 / min', 'simple-performance-for-wordpress' ) }</option>
										<option value={ 20 }>{ __( '20 / min', 'simple-performance-for-wordpress' ) }</option>
										<option value={ 30 }>{ __( '30 / min', 'simple-performance-for-wordpress' ) }</option>
										<option value={ 60 }>{ __( '60 / min', 'simple-performance-for-wordpress' ) }</option>
									</select>
								</label>
								<p className="mt-1 text-xs text-gray-400">
									{ __( 'Raise this on tracker-heavy sites if reports are being dropped. Save after changing.', 'simple-performance-for-wordpress' ) }
								</p>
							</div>

							<div className="mt-3 space-y-3 border-t border-gray-200 pt-3">
								<div>
									<label
										htmlFor="spfw-csp-collect-admin"
										className="flex items-start gap-x-3 text-xs font-medium text-gray-700"
									>
										<input
											id="spfw-csp-collect-admin"
											type="checkbox"
											checked={
												hardening.csp_collect_admin !==
												false
											}
											onChange={ ( e ) =>
												onChange(
													'csp_collect_admin',
													e.target.checked
												)
											}
											className="mt-0.5 h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
										/>
										{ __(
											'Include my own browsing while a window is open',
											'simple-performance-for-wordpress'
										) }
									</label>
									<p className="mt-1 pl-7 text-xs text-gray-400">
										{ __(
											'Sends the policy to administrators for the duration of the window, overriding “Do not apply to logged-in users”. Without it the one person deliberately testing the site is the only visitor the test cannot see, and the window closes on an empty log that reads as proof the policy is safe. Save after changing.',
											'simple-performance-for-wordpress'
										) }
									</p>
								</div>

								<div>
									<label
										htmlFor="spfw-csp-bypass-cache"
										className="flex items-start gap-x-3 text-xs font-medium text-gray-700"
									>
										<input
											id="spfw-csp-bypass-cache"
											type="checkbox"
											checked={
												hardening.csp_collect_nocache !==
												false
											}
											onChange={ ( e ) =>
												onChange(
													'csp_collect_nocache',
													e.target.checked
												)
											}
											className="mt-0.5 h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
										/>
										{ __(
											'Bypass the page cache while collecting',
											'simple-performance-for-wordpress'
										) }
									</label>
									<p className="mt-1 pl-7 text-xs text-gray-400">
										{ __(
											'Recommended. The reporting header is set by PHP, which does not run for a cache hit, so leaving this off limits a window to whatever the cache regenerated while it was open. It costs performance for the length of the window — which is why the window is short. Save after changing.',
											'simple-performance-for-wordpress'
										) }
									</p>
								</div>
							</div>
						</div>

						{ ! collecting && enabled && (
							<p className="mt-3 rounded-md bg-yellow-50 px-3 py-2 text-xs text-yellow-800 ring-1 ring-inset ring-yellow-300">
								{ __(
									'CSP is enabled but no collection window is open. Start collecting violation reports above to populate this list.',
									'simple-performance-for-wordpress'
								) }
							</p>
						) }
						
						{ visibleReports.length > 0 && (
							<p className="mt-2 text-xs text-gray-500">
								{ __(
									"Violation reports come from visitors\u2019 browsers and are not verified \u2014 anyone can post to the report endpoint while a window is open. Only allow origins you recognise as part of your own site.",
									'simple-performance-for-wordpress'
								) }
							</p>
						) }
						
						{ visibleReports.length > 0 && (
							<div className="mt-2 flex flex-wrap items-center gap-x-4">
								{ bulkConfirm === 'all' ? (
									<span className="flex items-center gap-x-2 text-sm">
										<span className="text-gray-700">
											{ __( 'Allow all reported origins into the policy?', 'simple-performance-for-wordpress' ) }
										</span>
										<button
											type="button"
											onClick={ () => allowAll() }
											className="font-medium text-indigo-600 hover:text-indigo-500"
										>
											{ __( 'Confirm', 'simple-performance-for-wordpress' ) }
										</button>
										<button
											type="button"
											onClick={ () => setBulkConfirm( null ) }
											className="font-medium text-gray-500 hover:text-gray-700"
										>
											{ __( 'Cancel', 'simple-performance-for-wordpress' ) }
										</button>
									</span>
								) : (
									<button
										type="button"
										onClick={ () => setBulkConfirm( 'all' ) }
										className="text-sm font-medium text-indigo-600 hover:text-indigo-500"
									>
										{ __( 'Allow all reported origins', 'simple-performance-for-wordpress' ) }
									</button>
								) }
							</div>
						) }
						
						{ ! reportOnly && visibleReports.length > 0 && (
							<p className="mt-1 text-sm text-amber-700">
								{ __(
									'Enforcing: each entry below is a resource currently being blocked on your live site.',
									'simple-performance-for-wordpress'
								) }
							</p>
						) }
						
						{ 0 === visibleReports.length && collecting && (
							<p className="mt-2 text-sm text-gray-500">
								{ __(
									'No violations collected yet. Browse your site as a logged-out visitor to generate reports, then Refresh.',
									'simple-performance-for-wordpress'
								) }
							</p>
						) }
						
						{ 0 === visibleReports.length &&
							collecting &&
							! reportOnly && (
								<p className="mt-2 text-xs text-gray-400">
									{ __(
										'Behind a CDN (QUIC.cloud, Cloudflare)? Ensure it forwards X-Forwarded-Proto and X-Forwarded-Host headers to origin, and that the REST API path /wp-json/spfw/v1/csp-report is not cached or blocked at the edge. Note: ERR_BLOCKED_BY_ORB or ERR_BLOCKED_BY_RESPONSE errors in the browser console are not CSP violations \u2014 they indicate a CDN serving cached assets with the wrong Content-Type, and will not appear in this log.',
										'simple-performance-for-wordpress'
									) }
								</p>
							) }

						{ otherReports.length > 0 && (
							<div className="mt-2">
								<p className="text-xs font-semibold text-gray-600">
									{ __(
										'Other violations (directives not in the builder):',
										'simple-performance-for-wordpress'
									) }
								</p>
								<ul className="mt-1 space-y-1">
									{ otherReports.map( ( r ) => (
										<li
											key={ `${ r.directive }|${ r.blocked_origin }` }
											className="text-xs text-gray-700 font-mono"
										>
											{ r.directive } →{ ' ' }
											{ r.blocked_origin }{ ' ' }
											<span className="text-gray-400">
												({ r.count })
											</span>
										</li>
									) ) }
								</ul>
							</div>
						) }
					</div>
				</>
			) }
		</SettingsCard>
	);
}
