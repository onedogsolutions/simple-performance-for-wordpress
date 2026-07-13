import { __ } from '@wordpress/i18n';
import SettingsCard from './SettingsCard';
import SettingsRow from './SettingsRow';
import Toggle from './Toggle';

const textareaClass =
	'block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 text-sm font-mono';

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

export default function HardeningSettings( {
	settings,
	onChange,
	hardeningStatus,
	uploadsStatus,
	onRestore,
} ) {
	const hardening = settings.hardening || {};

	return (
		<div className="space-y-6">
			<SettingsCard
				title={ __(
					'Directory Hardening',
					'simple-performance-for-wordpress'
				) }
				description={ __(
					'Server-level restrictions that reduce each directory’s attack surface. On OpenLiteSpeed these .htaccess rules are honored only when "Allow Override" is enabled for the vhost (LiteSpeed WebAdmin → Rewrite → Auto Load from .htaccess); when override is off they have no effect but cause no harm.',
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
						'Redirects ?author=N and /author/slug/ probes from logged-out visitors to the home page, preventing usernames from being harvested for brute-force attacks. Complements disabling the REST users endpoint.',
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
						'Send security headers',
						'simple-performance-for-wordpress'
					) }
					description={ __(
						'Adds X-Content-Type-Options: nosniff, X-Frame-Options: SAMEORIGIN, a Referrer-Policy, and a restrictive Permissions-Policy to front-end responses. Conservative defaults with no side effects. Content-Security-Policy is configured separately below.',
						'simple-performance-for-wordpress'
					) }
				>
					<Toggle
						checked={ !! hardening.security_headers }
						onChange={ ( v ) => onChange( 'security_headers', v ) }
					/>
				</SettingsRow>
			</SettingsCard>

			<SettingsCard
				title={ __(
					'Content-Security-Policy',
					'simple-performance-for-wordpress'
				) }
				description={ __(
					'A Content-Security-Policy restricts where scripts, styles, images, and other resources may load from — the strongest defense against cross-site scripting (XSS). It is powerful but can break your front end if a resource is not allowed, so start in Report-Only mode and only enforce once your browser console is free of CSP violations.',
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
						checked={ !! hardening.csp_enabled }
						onChange={ ( v ) => onChange( 'csp_enabled', v ) }
					/>
				</SettingsRow>

				{ !! hardening.csp_enabled && (
					<>
						<SettingsRow
							title={ __(
								'Report-Only mode',
								'simple-performance-for-wordpress'
							) }
							description={ __(
								'Sends Content-Security-Policy-Report-Only, which logs violations in the browser console without blocking anything. Keep this on until the console is clean, then turn it off to enforce the policy.',
								'simple-performance-for-wordpress'
							) }
						>
							<Toggle
								checked={ !! hardening.csp_report_only }
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
								'Skips the header for logged-in users. Recommended: the block editor, customizer, and admin bar rely on inline scripts a strict policy would block.',
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
					</>
				) }
			</SettingsCard>

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
