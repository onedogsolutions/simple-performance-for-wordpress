import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
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

export default function CspPolicyCard( {
	hardening,
	settings,
	onChange,
	cspReports = [],
	onRefreshCspReports,
	onClearCspReports,
} ) {
	const enabled = !! hardening.csp_enabled;
	const reportOnly = !! hardening.csp_report_only;
	const isCustom = 'custom' === hardening.csp_mode;
	const directives = hardening.csp_directives || {};

	// Raw text of each "additional hosts" field, kept locally so a trailing
	// space (needed to type the next host) is not stripped on every keystroke
	// by re-deriving the value from the parsed tokens. Cleared per-directive
	// when a discrete action (Allow, 'none', reset) changes hosts out-of-band.
	const [ hostText, setHostText ] = useState( {} );

	const clearHostText = ( name ) =>
		setHostText( ( prev ) => {
			const next = { ...prev };
			delete next[ name ];
			return next;
		} );

	// While CSP is enabled (Report-Only or enforce), poll the violation log so
	// warnings surface without a manual refresh. Collection is closed only when
	// CSP itself is off.
	useEffect( () => {
		if ( ! enabled || ! onRefreshCspReports ) {
			return undefined;
		}

		const id = setInterval( onRefreshCspReports, 20000 );
		return () => clearInterval( id );
	}, [ enabled, onRefreshCspReports ] );

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

	const allowSource = ( name, origin ) => {
		const token = KEYWORD_TOKENS[ origin ] || origin;

		const current = ( directives[ name ] || [] ).filter(
			( t ) => t !== NONE
		);
		if ( ! current.includes( token ) ) {
			setDirectiveTokens( name, [ ...current, token ] );
		}
		// Let the hosts field recompute so a newly-allowed origin shows up.
		clearHostText( name );
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

	const switchMode = ( toCustom ) => {
		if ( toCustom && ! hardening.csp_policy ) {
			// Seed the raw editor from the current builder policy so the admin
			// has a starting point rather than a blank box.
			onChange( 'csp_policy', buildPolicyString( directives ) );
		}
		onChange( 'csp_mode', toCustom ? 'custom' : 'builder' );
	};

	// Group reports by directive; anything whose directive is not a builder row
	// falls into the "other" bucket shown at the bottom.
	const knownNames = CSP_DIRECTIVES.map( ( d ) => d.name );
	const reportsFor = ( name ) =>
		cspReports.filter( ( r ) => r.directive === name );
	const otherReports = cspReports.filter(
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

					{ ! isCustom && (
						<div className="space-y-5 pt-2">
							<div className="flex items-center justify-between">
								<h4 className="text-sm font-semibold text-gray-900">
									{ __(
										'Policy builder',
										'simple-performance-for-wordpress'
									) }
								</h4>
								<button
									type="button"
									onClick={ loadRecommended }
									className="text-sm font-medium text-indigo-600 hover:text-indigo-500"
								>
									{ __(
										'Reset to recommended',
										'simple-performance-for-wordpress'
									) }
								</button>
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
													{ reports.map( ( r ) => (
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
																	({ r.count }
																	)
																</span>
															</span>
															<button
																type="button"
																onClick={ () =>
																	allowSource(
																		directive.name,
																		r.blocked_origin
																	)
																}
																className="shrink-0 font-medium text-indigo-600 hover:text-indigo-500"
															>
																{ __(
																	'Allow',
																	'simple-performance-for-wordpress'
																) }
															</button>
														</li>
													) ) }
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
									cspReports.length > 0 && (
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

						{ ! reportOnly && cspReports.length > 0 && (
							<p className="mt-1 text-sm text-amber-700">
								{ __(
									'Enforcing: each entry below is a resource currently being blocked on your live site.',
									'simple-performance-for-wordpress'
								) }
							</p>
						) }

						{ 0 === cspReports.length && (
							<p className="mt-1 text-sm text-gray-500">
								{ __(
									'No violations collected yet. Browse your site as a logged-out visitor to generate reports, then Refresh.',
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
