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

function StatusBadge( { status, onRestore } ) {
	const style = STATUS_STYLES[ status ];

	if ( ! style ) {
		return null;
	}

	const needsRestore = 'missing' === status || 'altered' === status;

	return (
		<div className="flex items-center gap-x-3">
			<span
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
		</div>
	);
}

// Small pass/fail chip for one capability in the upgrade-compatibility
// report. Reuses the badge styling of StatusBadge so the two read alike.
function CheckPill( { ok, label } ) {
	return (
		<span
			className={ `inline-flex items-center gap-x-1 rounded-md px-1.5 py-0.5 text-xs font-medium ring-1 ring-inset ${
				ok
					? 'bg-green-50 text-green-700 ring-green-600/20'
					: 'bg-red-50 text-red-700 ring-red-600/10'
			}` }
		>
			<span aria-hidden="true">{ ok ? '✓' : '✗' }</span>
			{ label }
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
	upgradeCheck,
	onUpgradeCheck,
	onUpgradeCleanup,
	isCheckingUpgrade,
	isCleaningUpgrade,
} ) {
	const hardening = settings.hardening || {};
	const adminEmail = settings.admin_email || '';

	// Kept out of the JSX so the button label stays free of a nested ternary.
	const upgradeCheckLabel = () => {
		if ( isCheckingUpgrade ) {
			return __( 'Checking…', 'simple-performance-for-wordpress' );
		}

		if ( upgradeCheck ) {
			return __( 'Run check again', 'simple-performance-for-wordpress' );
		}

		return __( 'Run upgrade check', 'simple-performance-for-wordpress' );
	};

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
							onRestore={ () => onRestore( 'plugins' ) }
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
							onRestore={ () => onRestore( 'uploads' ) }
						/>
					) }
				</SettingsRow>

				<SettingsRow
					title={ __(
						'Upgrade compatibility',
						'simple-performance-for-wordpress'
					) }
					description={ __(
						'Replays the file operations WordPress performs during a plugin install or update: creating, moving and listing scratch content in wp-content/upgrade, wp-content/upgrade-temp-backup and wp-content/plugins. Run this when an update fails with "Could not move the old version to the upgrade-temp-backup directory" or "Filesystem error: A directory could not be read" — those come from the filesystem, not from the rules above, and this tells you which directory is at fault. All scratch content is removed again.',
						'simple-performance-for-wordpress'
					) }
				>
					<button
						type="button"
						onClick={ onUpgradeCheck }
						disabled={ isCheckingUpgrade }
						className="rounded-md bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
					>
						{ upgradeCheckLabel() }
					</button>
				</SettingsRow>

				{ upgradeCheck && (
					<SettingsRow
						title={ __(
							'Check results',
							'simple-performance-for-wordpress'
						) }
						description={ __(
							'Write, Move and Read must all pass for every directory. When one fails, compare its owner against the user PHP runs as — a mismatch is the usual cause.',
							'simple-performance-for-wordpress'
						) }
					>
						<div className="w-full space-y-3">
							<div
								className={ `rounded-md p-3 ring-1 ring-inset ${
									upgradeCheck.pass
										? 'bg-green-50 ring-green-600/20'
										: 'bg-red-50 ring-red-600/10'
								}` }
							>
								<p
									className={ `text-sm font-medium ${
										upgradeCheck.pass
											? 'text-green-800'
											: 'text-red-800'
									}` }
								>
									{ upgradeCheck.pass
										? __(
												'The upgrader can read, write and move files in every directory it needs.',
												'simple-performance-for-wordpress'
										  )
										: __(
												'At least one directory the upgrader needs is not usable. Plugin uploads and updates will keep failing until this is corrected on the server.',
												'simple-performance-for-wordpress'
										  ) }
								</p>
							</div>

							<ul className="space-y-2">
								{ upgradeCheck.directories.map( ( dir ) => (
									<li
										key={ dir.key }
										className="rounded-md p-2.5 ring-1 ring-inset ring-gray-200"
									>
										<div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
											<code className="text-xs font-mono text-gray-800">
												{ dir.label }
											</code>
											<div className="flex flex-wrap gap-1">
												<CheckPill
													ok={ dir.writable }
													label={ __(
														'Write',
														'simple-performance-for-wordpress'
													) }
												/>
												<CheckPill
													ok={ dir.movable }
													label={ __(
														'Move',
														'simple-performance-for-wordpress'
													) }
												/>
												<CheckPill
													ok={ dir.readable }
													label={ __(
														'Read',
														'simple-performance-for-wordpress'
													) }
												/>
											</div>
										</div>

										{ dir.error && (
											<p className="mt-1.5 text-xs text-red-700">
												{ dir.error }
											</p>
										) }

										<p className="mt-1 text-xs text-gray-500">
											{ sprintf(
												/* translators: %1$s: directory owner, %2$s: user PHP runs as */
												__(
													'Owner: %1$s · PHP runs as: %2$s',
													'simple-performance-for-wordpress'
												),
												dir.owner || '—',
												dir.php_user || '—'
											) }
										</p>

										{ dir.stale > 0 && (
											<p className="mt-1 text-xs text-amber-700">
												{ sprintf(
													/* translators: %d: number of leftover items */
													__(
														'%d leftover item(s) from an interrupted update.',
														'simple-performance-for-wordpress'
													),
													dir.stale
												) }
											</p>
										) }
									</li>
								) ) }
							</ul>

							<div
								className={ `rounded-md p-2.5 ring-1 ring-inset ${
									upgradeCheck.upgrader_move.ok
										? 'ring-gray-200'
										: 'bg-red-50 ring-red-600/10'
								}` }
							>
								<CheckPill
									ok={ upgradeCheck.upgrader_move.ok }
									label={ __(
										'plugins → upgrade-temp-backup move (what an update actually does)',
										'simple-performance-for-wordpress'
									) }
								/>
								{ upgradeCheck.upgrader_move.error && (
									<p className="mt-1.5 text-xs text-red-700">
										{ upgradeCheck.upgrader_move.error }
									</p>
								) }
							</div>

							{ upgradeCheck.stale_total > 0 && (
								<div className="rounded-md bg-amber-50 p-3 ring-1 ring-inset ring-amber-600/20">
									<p className="text-sm text-amber-800">
										{ sprintf(
											/* translators: %d: number of leftover items */
											__(
												'%d leftover item(s) are still sitting in the upgrader’s scratch directories. WordPress empties these when an update finishes, so anything left behind means a run was interrupted — and every later update then fails over the debris.',
												'simple-performance-for-wordpress'
											),
											upgradeCheck.stale_total
										) }
									</p>
									<button
										type="button"
										onClick={ onUpgradeCleanup }
										disabled={ isCleaningUpgrade }
										className="mt-2 rounded-md bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-amber-500 disabled:opacity-50 disabled:cursor-not-allowed"
									>
										{ isCleaningUpgrade
											? __(
													'Clearing…',
													'simple-performance-for-wordpress'
											  )
											: __(
													'Clear leftovers and re-check',
													'simple-performance-for-wordpress'
											  ) }
									</button>
								</div>
							) }

							<p className="text-xs text-gray-400">
								{ sprintf(
									/* translators: %1$s: date/time of the check, %2$s: WordPress filesystem method */
									__(
										'Checked %1$s · filesystem method: %2$s',
										'simple-performance-for-wordpress'
									),
									upgradeCheck.checked > 0
										? new Date(
												upgradeCheck.checked * 1000
										  ).toLocaleString()
										: '—',
									upgradeCheck.fs_method || '—'
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
							onRestore={ () => onRestore( 'root' ) }
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
							'Returns 403 for xmlrpc.php before PHP boots, turning a full WordPress bootstrap into a static denial. Protects against brute-force and system.multicall floods. MainWP is unaffected — it uses its own signed HTTP channel, not XML-RPC.',
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
