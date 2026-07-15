import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import SettingsCard from './SettingsCard';
import SettingsRow from './SettingsRow';
import Toggle from './Toggle';

const textareaClass =
	'block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 text-sm font-mono';

function listToText( list ) {
	return Array.isArray( list ) ? list.join( '\n' ) : '';
}

function textToList( text ) {
	return text
		.split( /[\r\n]+/ )
		.map( ( line ) => line.trim() )
		.filter( Boolean );
}

export default function FontsSettings( { settings, onChange, onScan } ) {
	const fonts = settings.fonts || {};
	const discovered = fonts.discovered || {};
	const families = Array.isArray( discovered.families )
		? discovered.families
		: [];
	const fileCount = Array.isArray( discovered.files )
		? discovered.files.length
		: 0;
	const scanResult = settings.scan_result || null;
	const hasScanned = !! fonts.last_scan;
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
			{ fonts.needs_rescan && (
				<div className="mb-6 rounded-md border border-amber-300 bg-amber-50 px-4 py-3">
					<p className="text-sm font-medium text-amber-800">
						{ __(
							'Your localized fonts may render at the wrong weight.',
							'simple-performance-for-wordpress'
						) }
					</p>
					<p className="mt-1 text-sm text-amber-700">
						{ __(
							'These fonts were localized by an older version of this plugin that could drop font weights (e.g. a font family would render bold everywhere, even where a lighter weight was specified). Click "Scan fonts now" below to regenerate them correctly.',
							'simple-performance-for-wordpress'
						) }
					</p>
				</div>
			) }

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
					'Loads your homepage and detects the Google Fonts your theme and plugins actually enqueue, then downloads the .woff2 files locally. Fetches your homepage and Google’s stylesheets.',
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

					{ scanResult && scanResult.message && (
						<p className="text-xs text-gray-600">
							{ scanResult.message }
						</p>
					) }

					{ families.length > 0 ? (
						<div className="w-full sm:text-right">
							<p className="text-xs font-medium text-gray-700">
								{ sprintf(
									/* translators: 1: family count, 2: file count. */
									__(
										'%1$d families · %2$d files localized',
										'simple-performance-for-wordpress'
									),
									families.length,
									fileCount
								) }
							</p>
							<ul className="mt-1 text-sm text-gray-700 list-disc list-inside">
								{ families.map( ( family ) => (
									<li key={ family }>{ family }</li>
								) ) }
							</ul>
						</div>
					) : (
						hasScanned && (
							<p className="text-sm text-gray-500">
								{ __(
									'No Google Fonts detected yet.',
									'simple-performance-for-wordpress'
								) }
							</p>
						)
					) }
				</div>
			</SettingsRow>

			<div className="py-6">
				<h3 className="text-sm font-semibold text-gray-900">
					{ __(
						'Manual font weights',
						'simple-performance-for-wordpress'
					) }
				</h3>
				<p className="mt-1 text-sm text-gray-500">
					{ __(
						'If a CDN or optimizer (e.g. QUIC.cloud) hides some weights from automatic discovery, declare them here. One family per line as “Family:weights”, e.g. “Roboto Condensed:400,700”. Append “i” for italics (400i). A bare family name defaults to 400. Declared weights are downloaded straight from Google on the next scan, regardless of what the front end exposes.',
						'simple-performance-for-wordpress'
					) }
				</p>
				<textarea
					rows={ 3 }
					placeholder={
						'Roboto Condensed:400,700\nOpen Sans:400,600,700'
					}
					value={ listToText( fonts.manual_families ) }
					onChange={ ( e ) =>
						onChange(
							'manual_families',
							textToList( e.target.value )
						)
					}
					className={ `${ textareaClass } mt-4` }
				/>
			</div>

			<div className="py-6">
				<h3 className="text-sm font-semibold text-gray-900">
					{ __(
						'Extra pages to scan',
						'simple-performance-for-wordpress'
					) }
				</h3>
				<p className="mt-1 text-sm text-gray-500">
					{ __(
						'The scan already checks your homepage plus your most recent post and page. Add any other URLs whose fonts differ — a shop page, landing page, or custom template. One per line, as a path (/shop/) or full URL on this site.',
						'simple-performance-for-wordpress'
					) }
				</p>
				<textarea
					rows={ 3 }
					placeholder={ '/shop/\n/landing/' }
					value={ listToText( fonts.extra_scan_urls ) }
					onChange={ ( e ) =>
						onChange(
							'extra_scan_urls',
							textToList( e.target.value )
						)
					}
					className={ `${ textareaClass } mt-4` }
				/>
			</div>
		</SettingsCard>
	);
}
