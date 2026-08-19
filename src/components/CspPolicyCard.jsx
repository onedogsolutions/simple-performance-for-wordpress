import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
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
	const isCustom = 'custom' === hardening.csp_mode;
	const directives = hardening.csp_directives || {};
	const collecting = !! cspReportStats.collecting;

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

	// Bulk-allow confirmation state: null | 'all' | directive-name.
	const [ bulkConfirm, setBulkConfirm ] = useState( null );

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
							onChange={ ( v ) =>
								onChange( 'csp_report_only', v )
							}
						/>
					</SettingsRow>

					<SettingsRow
						title={ __(
							'Do not apply to logged-in users',
							'simple-performance-for-wordpress'
						) }
						description={ __(
							'Skips the header for logged-in users. Recommended: the block editor, customizer, and admin bar rely on inline scripts a strict policy would block. Note: violations are therefore only reported by logged-out visitors.',
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
							'Replaces \'unsafe-inline\' in script-src with sha256 hashes of your site\'s inline scripts, plus \'strict-dynamic\'. This provides real XSS protection but requires re-scanning after every plugin/theme change. Any inline script that varies per request (timestamps, personalization) will always violate. Use Report-Only mode until the violation log is clean.',
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
											'Scans your homepage, most recent post, and most recent page for inline scripts. Re-scan after any plugin or theme change.',
											'simple-performance-for-wordpress'
										) }
									</p>

									<p className="text-xs text-amber-600">
										{ __(
											'Warning: \'strict-dynamic\' causes browsers to ignore https: and host allowlists in script-src. Trust propagates from hashed scripts only.',
											'simple-performance-for-wordpress'
										) }
									</p>
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
																<span className="font-mono truncate">
																	{
																		r.blocked_origin
																	}{ ' ' }
																	<span className="text-amber-600">
																		(
																		{
																			r.count
																		}
																		)
																	</span>
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
								{ settings.csp_emitted_policy && settings.csp_emitted_policy !== buildPolicyString( directives ) && (
									<>
										<p className="mt-2 mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">
											{ __(
												'Actual emitted header (including report-uri)',
												'simple-performance-for-wordpress'
											) }
										</p>
										<pre className="whitespace-pre-wrap break-words rounded-md bg-indigo-50 p-3 text-xs font-mono text-indigo-900 ring-1 ring-inset ring-indigo-200">
											{ settings.csp_emitted_policy }
										</pre>
									</>
								) }
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
									'Collecting violations asks every visitor’s browser to POST a report to this site, which cannot be cached and costs a full page load each time. So collection runs in a time-boxed window: open one, browse the site (or let real traffic do it), then work through the list below. The window closes itself.',
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
