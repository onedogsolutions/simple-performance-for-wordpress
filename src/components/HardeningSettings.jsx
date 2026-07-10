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

export default function HardeningSettings( {
	settings,
	onChange,
	hardeningStatus,
	onRestore,
} ) {
	const hardening = settings.hardening || {};
	const status = STATUS_STYLES[ hardeningStatus ];
	const needsRestore =
		'missing' === hardeningStatus || 'altered' === hardeningStatus;

	return (
		<SettingsCard
			title={ __(
				'Directory Hardening',
				'simple-performance-for-wordpress'
			) }
			description={ __(
				'Server-level restrictions that reduce the plugin directory’s attack surface.',
				'simple-performance-for-wordpress'
			) }
		>
			<SettingsRow
				title={ __(
					'Block direct PHP execution in wp-content/plugins',
					'simple-performance-for-wordpress'
				) }
				description={
					<>
						{ __(
							'Drops an .htaccess file into the plugins directory that denies direct requests to .php files.',
							'simple-performance-for-wordpress'
						) }
						<br />
						<strong>
							{ __(
								'OpenLiteSpeed note:',
								'simple-performance-for-wordpress'
							) }
						</strong>{ ' ' }
						{ __(
							'.htaccess is honored only when "Allow Override" is enabled for this vhost (LiteSpeed WebAdmin → Rewrite → Auto Load from .htaccess). If override is off, this file has no effect but causes no harm.',
							'simple-performance-for-wordpress'
						) }
						<br />
						{ __(
							'A small number of legacy plugins serve front-facing PHP from /plugins/ — disable this if something breaks.',
							'simple-performance-for-wordpress'
						) }
					</>
				}
			>
				<Toggle
					checked={ !! hardening.plugins_htaccess }
					onChange={ ( v ) => onChange( 'plugins_htaccess', v ) }
				/>

				{ !! hardening.plugins_htaccess && status && (
					<div className="flex items-center gap-x-3">
						<span
							className={ `inline-flex items-center gap-x-1.5 rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset ${ status.badge }` }
						>
							<svg
								className={ `h-1.5 w-1.5 ${ status.dot } rounded-full` }
								viewBox="0 0 6 6"
								aria-hidden="true"
							>
								<circle cx="3" cy="3" r="3" />
							</svg>
							{ status.label }
						</span>
						{ needsRestore && (
							<button
								type="button"
								onClick={ onRestore }
								className="text-sm font-medium text-indigo-600 hover:text-indigo-500"
							>
								{ __(
									'Restore',
									'simple-performance-for-wordpress'
								) }
							</button>
						) }
					</div>
				) }
			</SettingsRow>
		</SettingsCard>
	);
}
