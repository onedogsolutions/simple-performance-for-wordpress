import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import SettingsCard from './SettingsCard';
import SettingsRow from './SettingsRow';
import Toggle from './Toggle';
import CspPolicyCard from './CspPolicyCard';
import PhpWhitelistCard from './PhpWhitelistCard';

const STATUS_STYLES = {
	ok: {
		label: __( 'Active', 'simple-performance-for-wordpress' ),
		badge: 'bg-green-50 text-green-700 ring-green-600/20',
		dot: 'bg-green-600',
	},
	missing: {
		label: __( 'File missing', 'simple-performance-for-wordpress' ),
		badge: 'bg-red-50 text-red-700 ring-red-600/10',
		dot: 'bg-red-600',
	},
	altered: {
		label: __( 'File modified', 'simple-performance-for-wordpress' ),
		badge: 'bg-amber-50 text-amber-700 ring-amber-600/20',
		dot: 'bg-amber-600',
	},
};

// Enforcement verdicts layer onto the 'ok' integrity state. A file can be
// present and byte-for-byte intact (status 'ok') yet completely inert on a
// vhost that ignores .htaccess, so 'ok' is split into three honest runtime
// verdicts read from the cached probe. 'missing'/'altered' keep STATUS_STYLES:
// enforcement is meaningless until the file is restored.
const ENFORCEMENT_STYLES = {
	enforced: {
		label: __( 'Enforced', 'simple-performance-for-wordpress' ),
		short: __( 'Enforced', 'simple-performance-for-wordpress' ),
		glyph: '✓',
		badge: 'bg-green-50 text-green-700 ring-green-600/20',
		dot: 'bg-green-600',
	},
	not_enforced: {
		label: __(
			'Present — not enforced by server',
			'simple-performance-for-wordpress'
		),
		short: __( 'Not enforced', 'simple-performance-for-wordpress' ),
		glyph: '✗',
		badge: 'bg-amber-50 text-amber-700 ring-amber-600/20',
		dot: 'bg-amber-600',
		hint: __(
			'The .htaccess file is present and intact, but this web server is not applying its rules. On LiteSpeed, ensure "Auto Load from .htaccess" is enabled (WebAdmin → Virtual Host → Rewrite), then reload the server. Without that, even the RewriteRule directives cannot take effect.',
			'simple-performance-for-wordpress'
		),
	},
	unknown: {
		label: __(
			'Present (enforcement unverified)',
			'simple-performance-for-wordpress'
		),
		short: __( 'Unverified', 'simple-performance-for-wordpress' ),
		glyph: '?',
		badge: 'bg-gray-50 text-gray-600 ring-gray-500/20',
		dot: 'bg-gray-400',
		hint: __(
			'Enforcement has not been confirmed yet. Run "Verify enforcement" to probe whether the server is actually applying these rules.',
			'simple-performance-for-wordpress'
		),
	},
};

// Tone for the results-panel headline, keyed by the server-wide
// htaccess_honored verdict (yes | no | unknown).
const HONORED_TONES = {
	yes: { box: 'bg-green-50 ring-green-600/20', text: 'text-green-800' },
	no: { box: 'bg-amber-50 ring-amber-600/20', text: 'text-amber-800' },
	unknown: { box: 'bg-gray-50 ring-gray-500/20', text: 'text-gray-700' },
};

function StatusBadge( {
	status,
	enforcement,
	onRestore,
	onVerify,
	isVerifying,
} ) {
	// 'ok' only proves the file is present and matches the hash we stored; it
	// says nothing about whether the server applies the rules. When the file is
	// intact, report the runtime-enforcement verdict instead of a bare "Active";
	// otherwise fall back to the integrity styling.
	const isIntact = 'ok' === status;
	const resolved = isIntact ? enforcement || 'unknown' : status;
	const style = isIntact
		? ENFORCEMENT_STYLES[ resolved ]
		: STATUS_STYLES[ status ];

	if ( ! style ) {
		return null;
	}

	const needsRestore = 'missing' === status || 'altered' === status;
	const showVerify = isIntact && 'enforced' !== resolved && !! onVerify;
	const verifyButtonLabel = isVerifying
		? __( 'Verifying…', 'simple-performance-for-wordpress' )
		: __( 'Verify enforcement', 'simple-performance-for-wordpress' );

	return (
		<div className="flex items-center gap-x-3">
			<span
				title={ style.hint }
				className={ `inline-flex items-center gap-x-1.5 rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset ${ style.badge }` }
			>
				<svg
					className={ `h-1.5 w-1.5 ${ style.dot } rounded-full` }
					viewBox="0 0 6 6"
					aria-hidden="true"
				>
					<circle cx="3" cy="3" r="3" />
				</svg>
				{ style.label }
			</span>
			{ needsRestore && (
				<button
					type="button"
					onClick={ onRestore }
					className="text-sm font-medium text-indigo-600 hover:text-indigo-500"
				>
					{ __( 'Restore', 'simple-performance-for-wordpress' ) }
				</button>
			) }
			{ showVerify && (
				<button
					type="button"
					onClick={ onVerify }
					disabled={ isVerifying }
					className="text-sm font-medium text-indigo-600 hover:text-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
				>
					{ verifyButtonLabel }
				</button>
			) }
		</div>
	);
}

// Three-state enforcement chip for one canary row in the results panel. Reuses
// ENFORCEMENT_STYLES so a row and its card badge never disagree.
function EnforcementPill( { state } ) {
	const style = ENFORCEMENT_STYLES[ state ] || ENFORCEMENT_STYLES.unknown;

	return (
		<span
			className={ `inline-flex items-center gap-x-1 rounded-md px-1.5 py-0.5 text-xs font-medium ring-1 ring-inset ${ style.badge }` }
		>
			<span aria-hidden="true">{ style.glyph }</span>
			{ style.short }
		</span>
	);
}

// Per-feature row with optional allowlist editor and presets.
// Displayed inside the Site Hardening card when security_headers is on.
function PermissionsPolicyRow( { hardening, onChange } ) {
	// Track which feature rows have the allowlist editor open.
	const [ expanded, setExpanded ] = useState( {} );

	const FEATURES = [
		'geolocation',
		'microphone',
		'camera',
		'payment',
		'usb',
		'interest-cohort',
	];

	const policy = hardening.permissions_policy || {};

	const setPolicy = ( next ) => onChange( 'permissions_policy', next );

	const isBlocked = ( feature ) =>
		Object.prototype.hasOwnProperty.call( policy, feature ) &&
		false !== policy[ feature ];

	const allowlistText = ( feature ) =>
		( policy[ feature ] || [] ).join( ' ' );

	// Apply the "Allow embedded maps" preset for geolocation:
	// geolocation=('self' https://www.google.com)
	const applyMapsPreset = () => {
		setPolicy( {
			...policy,
			geolocation: [ "'self'", 'https://www.google.com' ],
		} );
	};

	return (
		<SettingsRow
			title={ __(
				'Permissions-Policy features',
				'simple-performance-for-wordpress'
			) }
			description={ __(
				'Browser features to restrict via the Permissions-Policy header. Checked features are blocked (empty allowlist). Expand a row to allow specific origins instead of blocking entirely.',
				'simple-performance-for-wordpress'
			) }
		>
			<div className="space-y-3 w-full">
				{ FEATURES.map( ( feature ) => {
					const blocked = isBlocked( feature );
					const isExpanded = !! expanded[ feature ];
					const hasAllowlist =
						blocked &&
						Array.isArray( policy[ feature ] ) &&
						policy[ feature ].length > 0;

					return (
						<div key={ feature }>
							<div className="flex items-center gap-x-2">
								<input
									type="checkbox"
									checked={ blocked }
									onChange={ ( e ) => {
										const next = { ...policy };
										if ( e.target.checked ) {
											next[ feature ] = [];
										} else {
											next[ feature ] = false;
											setExpanded( ( prev ) => ( { ...prev, [ feature ]: false } ) );
										}
										setPolicy( next );
									} }
									className="rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
								/>
								<span className="text-sm text-gray-700">
									{ __( 'Block', 'simple-performance-for-wordpress' ) }{ ' ' }
									<code className="text-xs bg-gray-100 px-1 py-0.5 rounded">
										{ feature }
									</code>
								</span>
								{ blocked && (
									<button
										type="button"
										onClick={ () =>
											setExpanded( ( prev ) => ( {
												...prev,
												[ feature ]: ! prev[ feature ],
											} ) )
										}
										className="text-xs font-medium text-indigo-600 hover:text-indigo-500"
									>
										{ isExpanded
											? __( 'Hide allowlist', 'simple-performance-for-wordpress' )
											: hasAllowlist
												? sprintf(
													/* translators: %d: number of allowed origins */
													__( 'Allowed: %d origin(s)', 'simple-performance-for-wordpress' ),
													policy[ feature ].length
												  )
												: __( 'Add allowlist', 'simple-performance-for-wordpress' ) }
									</button>
								) }
							</div>

							{ blocked && isExpanded && (
								<div className="mt-2 ml-6 space-y-2">
									{ 'geolocation' === feature && (
										<>
											<p className="text-xs text-gray-500">
												{ __(
													'This is a Permissions-Policy restriction, not a CSP error. Google Maps embeds request geolocation from the browser — blocking it stops the "My Location" button from working inside embedded maps.',
													'simple-performance-for-wordpress'
												) }
											</p>
											<button
												type="button"
												onClick={ applyMapsPreset }
												className="rounded-md bg-white px-3 py-1 text-xs font-semibold text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
											>
												{ __( 'Allow embedded maps', 'simple-performance-for-wordpress' ) }
											</button>
											<p className="text-xs text-gray-400">
												{ __(
													"Sets geolocation=('self' https://www.google.com) — allows the current page and Google Maps iframes to request the visitor's location.",
													'simple-performance-for-wordpress'
												) }
											</p>
										</>
									) }
									<label
										className="block text-xs text-gray-600"
									>
										{ __( 'Allowed origins (space-separated, or leave empty to block all):', 'simple-performance-for-wordpress' ) }
										<input
											type="text"
											value={ allowlistText( feature ) }
											placeholder="'self' https://example.com"
											onChange={ ( e ) => {
												const tokens = e.target.value
													.split( /\s+/ )
													.map( ( t ) => t.trim() )
													.filter( Boolean );
												setPolicy( { ...policy, [ feature ]: tokens } );
											} }
											className="mt-1 block w-full rounded-md border-0 py-1 px-2 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 text-xs font-mono"
										/>
									</label>
								</div>
							) }
						</div>
					);
				} ) }
			</div>
		</SettingsRow>
	);
}

export default function HardeningSettings( {
	settings,
	onChange,
	hardeningStatus,
	uploadsStatus,
	rootStatus,
	onRestore,
	cspReports,
	cspReportStats,
	onRefreshCspReports,
	onClearCspReports,
	onDismissCspReport,
	onSetCspCollection,
	fileScanResults,
	onScanFiles,
	isScanning,
	hardeningEnforcement,
	uploadsEnforcement,
	rootEnforcement,
	htaccessHonored,
	enforcementTargets,
	enforcementTime,
	onVerifyHtaccess,
	isVerifyingHtaccess,
} ) {
	const hardening = settings.hardening || {};
	const adminEmail = settings.admin_email || '';

	// Kept out of the JSX so the Verify button label stays free of a nested
	// ternary in the JSX.
	const verifyLabel = () => {
		if ( isVerifyingHtaccess ) {
			return __( 'Verifying…', 'simple-performance-for-wordpress' );
		}

		if ( enforcementTargets && enforcementTargets.length ) {
			return __(
				'Verify enforcement again',
				'simple-performance-for-wordpress'
			);
		}

		return __( 'Verify enforcement', 'simple-performance-for-wordpress' );
	};

	// Headline for the results panel, keyed by the server-wide verdict. Kept in
	// a helper (not inline) to avoid a nested ternary in the JSX.
	const honoredHeadline = () => {
		if ( 'yes' === htaccessHonored ) {
			return __(
				'The web server is applying your .htaccess hardening rules.',
				'simple-performance-for-wordpress'
			);
		}

		if ( 'no' === htaccessHonored ) {
			return __(
				'These files are present and intact, but the web server is not applying their rules — direct requests still get through. The fix is on the server, described above.',
				'simple-performance-for-wordpress'
			);
		}

		return __(
			'Enforcement could not be confirmed. Each canary below was inconclusive — a redirect, a missing canary file, or a proxy/CDN in front of the origin.',
			'simple-performance-for-wordpress'
		);
	};

	const honoredTone =
		HONORED_TONES[ htaccessHonored ] || HONORED_TONES.unknown;

	return (
		<div className="space-y-6">
			<SettingsCard
				title={ __(
					'Directory Hardening',
					'simple-performance-for-wordpress'
				) }
				description={ __(
					'Server-level restrictions that reduce each directory’s attack surface. These rules govern HTTP requests only — they never block plugin or theme installs and updates, which happen entirely in PHP. On OpenLiteSpeed these .htaccess rules are honored only when "Allow Override" is enabled for the vhost (LiteSpeed WebAdmin → Rewrite → Auto Load from .htaccess); when override is off they have no effect but cause no harm.',
					'simple-performance-for-wordpress'
				) }
			>
				{ 'no' === htaccessHonored && (
					<div className="py-6">
						<div className="rounded-md bg-amber-50 p-3 ring-1 ring-inset ring-amber-600/20">
							<p className="text-sm font-medium text-amber-800">
								{ __(
									'This web server is not applying your .htaccess rules.',
									'simple-performance-for-wordpress'
								) }
							</p>
							<p className="mt-1 text-xs text-amber-700">
								{ __(
									'The hardening files are present and intact, but every deny rule below is inert, so direct requests to plugins/*.php, readme.html, license.txt and xmlrpc.php still get through. The plugin now uses OpenLiteSpeed-compatible RewriteRule directives; on LiteSpeed enable "Auto Load from .htaccess" (WebAdmin → Virtual Host → Rewrite), reload the web server, and run "Verify enforcement" again. Moving the rules into the vhost/context config is still an option if .htaccess loading must stay off.',
									'simple-performance-for-wordpress'
								) }
							</p>
						</div>
					</div>
				) }

				<SettingsRow
					title={ __(
						'Block direct PHP execution in wp-content/plugins',
						'simple-performance-for-wordpress'
					) }
					description={ __(
						'Drops an .htaccess file into the plugins directory that denies direct requests to .php files. A small number of legacy plugins serve front-facing PHP from /plugins/ — disable this if something breaks.',
						'simple-performance-for-wordpress'
					) }
				>
					<Toggle
						checked={ !! hardening.plugins_htaccess }
						onChange={ ( v ) => onChange( 'plugins_htaccess', v ) }
					/>

					{ !! hardening.plugins_htaccess && (
						<StatusBadge
							status={ hardeningStatus }
							enforcement={ hardeningEnforcement }
							onRestore={ () => onRestore( 'plugins' ) }
							onVerify={ onVerifyHtaccess }
							isVerifying={ isVerifyingHtaccess }
						/>
					) }
				</SettingsRow>

				<SettingsRow
					title={ __(
						'Block direct PHP execution in uploads',
						'simple-performance-for-wordpress'
					) }
					description={ __(
						'Drops an .htaccess file into wp-content/uploads that denies direct requests to .php files. The uploads directory is the most common place a malicious script is planted through a vulnerable upload form; images and other media are unaffected.',
						'simple-performance-for-wordpress'
					) }
				>
					<Toggle
						checked={ !! hardening.uploads_htaccess }
						onChange={ ( v ) => onChange( 'uploads_htaccess', v ) }
					/>

					{ !! hardening.uploads_htaccess && (
						<StatusBadge
							status={ uploadsStatus }
							enforcement={ uploadsEnforcement }
							onRestore={ () => onRestore( 'uploads' ) }
							onVerify={ onVerifyHtaccess }
							isVerifying={ isVerifyingHtaccess }
						/>
					) }
				</SettingsRow>

				<SettingsRow
					title={ __(
						'Runtime enforcement',
						'simple-performance-for-wordpress'
					) }
					description={ __(
						'A .htaccess file can be present and intact yet inert: on a vhost that does not honor .htaccess (for example OpenLiteSpeed with "Auto Load from .htaccess" off) the deny rules never run. This probes the live URLs those rules should block — plugins/index.php, readme.html, xmlrpc.php — over a loopback request and reports the code the server actually returned. It runs only when you click it, never on page load.',
						'simple-performance-for-wordpress'
					) }
				>
					<button
						type="button"
						onClick={ onVerifyHtaccess }
						disabled={ isVerifyingHtaccess }
						className="rounded-md bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
					>
						{ verifyLabel() }
					</button>
				</SettingsRow>

				{ enforcementTargets && enforcementTargets.length > 0 && (
					<SettingsRow
						title={ __(
							'Enforcement results',
							'simple-performance-for-wordpress'
						) }
						description={ __(
							'Each canary should return its expected code. A 403 means the rule is enforced; a 200 (or 405 for xmlrpc.php) means the request got through and the rule is inert.',
							'simple-performance-for-wordpress'
						) }
					>
						<div className="w-full space-y-3">
							<div
								className={ `rounded-md p-3 ring-1 ring-inset ${ honoredTone.box }` }
							>
								<p
									className={ `text-sm font-medium ${ honoredTone.text }` }
								>
									{ honoredHeadline() }
								</p>
							</div>

							<ul className="space-y-2">
								{ enforcementTargets.map( ( row ) => (
									<li
										key={ row.target }
										className="rounded-md p-2.5 ring-1 ring-inset ring-gray-200"
									>
										<div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
											<code className="text-xs font-mono text-gray-800">
												{ row.label }
											</code>
											<EnforcementPill
												state={ row.state }
											/>
										</div>
										<p className="mt-1 text-xs text-gray-500">
											{ sprintf(
												/* translators: %1$s: observed HTTP status code, %2$s: expected code, %3$s: probed URL */
												__(
													'Observed HTTP %1$s (expected %2$s) · %3$s',
													'simple-performance-for-wordpress'
												),
												row.observed_code || '—',
												row.expected || '—',
												row.url || '—'
											) }
										</p>
									</li>
								) ) }
							</ul>

							<p className="text-xs text-gray-400">
								{ sprintf(
									/* translators: %s: date/time of the enforcement check */
									__(
										'Checked %s',
										'simple-performance-for-wordpress'
									),
									enforcementTime > 0
										? new Date(
												enforcementTime * 1000
										  ).toLocaleString()
										: '—'
								) }
							</p>
						</div>
					</SettingsRow>
				) }

			</SettingsCard>

			<PhpWhitelistCard
				hardening={ hardening }
				onChange={ onChange }
				fileScanResults={ fileScanResults }
				onScanFiles={ onScanFiles }
				isScanning={ isScanning }
				adminEmail={ adminEmail }
			/>

			<SettingsCard
				title={ __(
					'Root .htaccess Rules',
					'simple-performance-for-wordpress'
				) }
				description={ __(
					'Server-level rules written to the site root .htaccess via WordPress markers (your permalink rules are preserved). On OpenLiteSpeed these require “Allow Override” enabled for the vhost. A safety check automatically removes the rules if they cause a 500 error.',
					'simple-performance-for-wordpress'
				) }
			>
				<SettingsRow
					title={ __(
						'Protect sensitive files',
						'simple-performance-for-wordpress'
					) }
					description={ __(
						'Blocks direct access to readme.html, license.txt, wp-config-sample.php, debug.log, .env, *.sql, *.bak, and *.old files. These commonly leak version info, credentials, and database dumps.',
						'simple-performance-for-wordpress'
					) }
				>
					<Toggle
						checked={ !! hardening.protect_sensitive_files }
						onChange={ ( v ) =>
							onChange( 'protect_sensitive_files', v )
						}
					/>
				</SettingsRow>

				{ ( !! hardening.protect_sensitive_files ||
					!! hardening.block_xmlrpc_file ) && (
					<SettingsRow
						title={ __(
							'Status',
							'simple-performance-for-wordpress'
						) }
						description={ __(
							'Integrity of the marker block in your root .htaccess.',
							'simple-performance-for-wordpress'
						) }
					>
						<StatusBadge
							status={ rootStatus }
							enforcement={ rootEnforcement }
							onRestore={ () => onRestore( 'root' ) }
							onVerify={ onVerifyHtaccess }
							isVerifying={ isVerifyingHtaccess }
						/>
					</SettingsRow>
				) }
			</SettingsCard>

			<SettingsCard
				title={ __(
					'Site Hardening',
					'simple-performance-for-wordpress'
				) }
				description={ __(
					'Application-level protections that apply regardless of the web server configuration.',
					'simple-performance-for-wordpress'
				) }
			>
				<SettingsRow
					title={ __(
						'Disable the theme & plugin file editor',
						'simple-performance-for-wordpress'
					) }
					description={ __(
						'Removes the built-in code editor under Appearance and Plugins (sets DISALLOW_FILE_EDIT), so an attacker who gains admin access cannot edit PHP directly from the dashboard.',
						'simple-performance-for-wordpress'
					) }
				>
					<Toggle
						checked={ !! hardening.disable_file_editing }
						onChange={ ( v ) =>
							onChange( 'disable_file_editing', v )
						}
					/>
				</SettingsRow>

				<SettingsRow
					title={ __(
						'Block author enumeration',
						'simple-performance-for-wordpress'
					) }
					description={ __(
						'Redirects ?author=N and /author/slug/ probes from logged-out visitors to the home page, preventing usernames from being harvested for brute-force attacks. Also removes the users sitemap (wp-sitemap-users-1.xml) to close the same leak via sitemaps. Complements disabling the REST users endpoint.',
						'simple-performance-for-wordpress'
					) }
				>
					<Toggle
						checked={ !! hardening.block_author_enum }
						onChange={ ( v ) => onChange( 'block_author_enum', v ) }
					/>
				</SettingsRow>

				<SettingsRow
					title={ __(
						'Disable Application Passwords',
						'simple-performance-for-wordpress'
					) }
					description={ __(
						'Removes the Application Passwords authentication method. These bypass two-factor authentication plugins. Warning: this will break any existing integration that authenticates with an application password. MainWP is unaffected — it uses its own signed channel.',
						'simple-performance-for-wordpress'
					) }
				>
					<Toggle
						checked={ !! hardening.disable_app_passwords }
						onChange={ ( v ) =>
							onChange( 'disable_app_passwords', v )
						}
					/>
				</SettingsRow>

				<SettingsRow
					title={ __(
						'Disable XML-RPC',
						'simple-performance-for-wordpress'
					) }
					description={ __(
						'Disables the XML-RPC endpoint and pingback headers/methods.',
						'simple-performance-for-wordpress'
					) }
				>
					<Toggle
						checked={ !! hardening.disable_xmlrpc }
						onChange={ ( v ) => {
							onChange( 'disable_xmlrpc', v );
							if ( ! v ) {
								onChange( 'block_xmlrpc_file', false );
							}
						} }
					/>
				</SettingsRow>

				{ !! hardening.disable_xmlrpc && (
					<SettingsRow
						title={ __(
							'Block xmlrpc.php at server level',
							'simple-performance-for-wordpress'
						) }
						description={ __(
							'Layers a server-level 403 for xmlrpc.php on top of the PHP disable above — it does not replace it. When the vhost honors .htaccess the request is denied before PHP boots, turning a full WordPress bootstrap into a static denial (a performance win against brute-force and system.multicall floods); when .htaccess is inert the PHP disable still blocks XML-RPC, so you are never left unprotected. MainWP is unaffected — it uses its own signed HTTP channel, not XML-RPC.',
							'simple-performance-for-wordpress'
						) }
					>
						<Toggle
							checked={ !! hardening.block_xmlrpc_file }
							onChange={ ( v ) =>
								onChange( 'block_xmlrpc_file', v )
							}
						/>
					</SettingsRow>
				) }

				<SettingsRow
					title={ __(
						'Generic login error messages',
						'simple-performance-for-wordpress'
					) }
					description={ __(
						'Replaces login and password-reset error messages with a single generic string, so attackers cannot determine whether a username exists. Real users see a less specific message.',
						'simple-performance-for-wordpress'
					) }
				>
					<Toggle
						checked={ !! hardening.generic_login_errors }
						onChange={ ( v ) =>
							onChange( 'generic_login_errors', v )
						}
					/>
				</SettingsRow>

				<SettingsRow
					title={ __(
						'Send security headers',
						'simple-performance-for-wordpress'
					) }
					description={ __(
						'Adds X-Content-Type-Options: nosniff, X-Frame-Options: SAMEORIGIN, a Referrer-Policy, COOP, CORP, and a configurable Permissions-Policy to front-end responses. Conservative defaults with no side effects. Content-Security-Policy is configured separately below.',
						'simple-performance-for-wordpress'
					) }
				>
					<Toggle
						checked={ !! hardening.security_headers }
						onChange={ ( v ) => onChange( 'security_headers', v ) }
					/>
				</SettingsRow>

				{ !! hardening.security_headers && (
					<PermissionsPolicyRow
						hardening={ hardening }
						onChange={ onChange }
					/>
				) }
			</SettingsCard>

			<CspPolicyCard
				hardening={ hardening }
				settings={ settings }
				onChange={ onChange }
				cspReports={ cspReports }
				cspReportStats={ cspReportStats }
				onRefreshCspReports={ onRefreshCspReports }
				onClearCspReports={ onClearCspReports }
				onDismissCspReport={ onDismissCspReport }
				onSetCspCollection={ onSetCspCollection }
			/>

			<SettingsCard
				title={ __(
					'HTTP Strict Transport Security',
					'simple-performance-for-wordpress'
				) }
				description={ __(
					'Tells browsers to only ever connect to your site over HTTPS for a set duration, protecting against protocol-downgrade attacks and cookie hijacking on insecure networks. Only sent when the request is actually HTTPS (including behind a reverse proxy). Once a browser has seen this header, it will refuse plain HTTP connections until max-age expires — make sure HTTPS works reliably before enabling.',
					'simple-performance-for-wordpress'
				) }
			>
				<SettingsRow
					title={ __(
						'Send Strict-Transport-Security header',
						'simple-performance-for-wordpress'
					) }
					description={ __(
						'Only sent on HTTPS responses. Do not enable if your site is not fully served over HTTPS.',
						'simple-performance-for-wordpress'
					) }
				>
					<Toggle
						checked={ !! hardening.hsts_enabled }
						onChange={ ( v ) => onChange( 'hsts_enabled', v ) }
					/>
				</SettingsRow>

				{ !! hardening.hsts_enabled && (
					<>
						<SettingsRow
							title={ __(
								'Max age',
								'simple-performance-for-wordpress'
							) }
							description={ __(
								'How long browsers should remember to force HTTPS for this site.',
								'simple-performance-for-wordpress'
							) }
						>
							<select
								value={ hardening.hsts_max_age || 31536000 }
								onChange={ ( e ) =>
									onChange(
										'hsts_max_age',
										parseInt( e.target.value, 10 )
									)
								}
								className="rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 text-sm"
							>
								<option value={ 86400 }>
									{ __(
										'1 day',
										'simple-performance-for-wordpress'
									) }
								</option>
								<option value={ 604800 }>
									{ __(
										'1 week',
										'simple-performance-for-wordpress'
									) }
								</option>
								<option value={ 2592000 }>
									{ __(
										'1 month',
										'simple-performance-for-wordpress'
									) }
								</option>
								<option value={ 15768000 }>
									{ __(
										'6 months',
										'simple-performance-for-wordpress'
									) }
								</option>
								<option value={ 31536000 }>
									{ __(
										'1 year (recommended)',
										'simple-performance-for-wordpress'
									) }
								</option>
								<option value={ 63072000 }>
									{ __(
										'2 years',
										'simple-performance-for-wordpress'
									) }
								</option>
							</select>
						</SettingsRow>

						<SettingsRow
							title={ __(
								'Include subdomains',
								'simple-performance-for-wordpress'
							) }
							description={ __(
								'Applies the policy to every subdomain too. Only enable once you have confirmed every subdomain is served over HTTPS — otherwise those subdomains will become unreachable until max-age expires.',
								'simple-performance-for-wordpress'
							) }
						>
							<Toggle
								checked={ !! hardening.hsts_include_subdomains }
								onChange={ ( v ) =>
									onChange( 'hsts_include_subdomains', v )
								}
							/>
						</SettingsRow>

						<SettingsRow
							title={ __(
								'Preload',
								'simple-performance-for-wordpress'
							) }
							description={ __(
								'Opts into browser HSTS preload lists (requires includeSubDomains, max-age of at least 1 year, and submission to hstspreload.org). This is very difficult to reverse — only enable if you are certain every subdomain, now and in the future, will be HTTPS-only.',
								'simple-performance-for-wordpress'
							) }
						>
							<Toggle
								checked={ !! hardening.hsts_preload }
								onChange={ ( v ) =>
									onChange( 'hsts_preload', v )
								}
							/>
						</SettingsRow>
					</>
				) }
			</SettingsCard>
		</div>
	);
}
