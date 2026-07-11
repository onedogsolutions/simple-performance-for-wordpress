import { __ } from '@wordpress/i18n';
import SettingsCard from './SettingsCard';
import SettingsRow from './SettingsRow';
import Toggle from './Toggle';

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
						'Adds X-Content-Type-Options: nosniff, X-Frame-Options: SAMEORIGIN, a Referrer-Policy, and a restrictive Permissions-Policy to front-end responses. Conservative defaults that do not include HSTS or a Content-Security-Policy.',
						'simple-performance-for-wordpress'
					) }
				>
					<Toggle
						checked={ !! hardening.security_headers }
						onChange={ ( v ) => onChange( 'security_headers', v ) }
					/>
				</SettingsRow>
			</SettingsCard>
		</div>
	);
}
