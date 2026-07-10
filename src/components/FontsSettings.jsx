import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import SettingsCard from './SettingsCard';
import SettingsRow from './SettingsRow';
import Toggle from './Toggle';

export default function FontsSettings( { settings, onChange, onScan } ) {
	const fonts = settings.fonts || {};
	const discovered = fonts.discovered || {};
	const families = Array.isArray( discovered.families )
		? discovered.families
		: [];
	const [ isScanning, setIsScanning ] = useState( false );

	const handleScan = () => {
		setIsScanning( true );
		onScan().finally( () => setIsScanning( false ) );
	};

	const lastScanLabel = fonts.last_scan
		? new Date( fonts.last_scan * 1000 ).toLocaleString()
		: __( 'Never', 'simple-performance-for-wordpress' );

	return (
		<SettingsCard
			title={ __( 'Google Fonts', 'simple-performance-for-wordpress' ) }
			description={ __(
				'Discover and self-host Google Fonts to remove third-party requests to Google.',
				'simple-performance-for-wordpress'
			) }
		>
			<SettingsRow
				title={ __(
					'Self-host Google Fonts',
					'simple-performance-for-wordpress'
				) }
				description={ __(
					'Serves discovered Google Fonts from your own site instead of fonts.googleapis.com/fonts.gstatic.com. Run a scan first — this has no effect until fonts have been discovered.',
					'simple-performance-for-wordpress'
				) }
			>
				<Toggle
					checked={ !! fonts.localize_google }
					onChange={ ( v ) => onChange( 'localize_google', v ) }
				/>
			</SettingsRow>

			<SettingsRow
				title={ __(
					'Font discovery',
					'simple-performance-for-wordpress'
				) }
				description={ __(
					'Scans your homepage for Google Fonts references and downloads the .woff2 files locally. Fetches your homepage and Google’s stylesheet.',
					'simple-performance-for-wordpress'
				) }
			>
				<div className="w-full flex flex-col items-start sm:items-end gap-y-2">
					<button
						type="button"
						onClick={ handleScan }
						disabled={ isScanning }
						className="inline-flex items-center gap-x-2 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-50"
					>
						{ isScanning
							? __(
									'Scanning…',
									'simple-performance-for-wordpress'
							  )
							: __(
									'Scan fonts now',
									'simple-performance-for-wordpress'
							  ) }
					</button>

					<p className="text-xs text-gray-500">
						{ __(
							'Last scan:',
							'simple-performance-for-wordpress'
						) }{ ' ' }
						{ lastScanLabel }
					</p>

					{ families.length > 0 && (
						<ul className="text-sm text-gray-700 list-disc list-inside">
							{ families.map( ( family ) => (
								<li key={ family }>{ family }</li>
							) ) }
						</ul>
					) }
				</div>
			</SettingsRow>
		</SettingsCard>
	);
}
