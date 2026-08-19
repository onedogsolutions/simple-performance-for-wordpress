import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import SettingsCard from './SettingsCard';
import SettingsRow from './SettingsRow';
import Toggle from './Toggle';

// Well-known plugin paths that commonly execute PHP from wp-content/plugins/.
// Offered as a convenience "Pre-fill" action, mirroring the CSP builder's
// "Pre-fill common third-party origins" pattern.
const COMMON_PLUGINS = [
	{
		label: 'ShortPixel Image Optimizer',
		path: 'plugins/shortpixel-ai/shortpixel-ai.php',
	},
	{
		label: 'ShortPixel Adaptive Images',
		path: 'plugins/shortpixel-adaptive-images/shortpixel-ai.php',
	},
	{
		label: 'Imagify',
		path: 'plugins/imagify/inc/front/process.php',
	},
	{
		label: 'EWWW Image Optimizer',
		path: 'plugins/ewww-image-optimizer/ewww-image-optimizer.php',
	},
	{
		label: 'UpdraftPlus',
		path: 'plugins/updraftplus/includes/backup-created.php',
	},
];

// Removable chip showing a whitelisted path (same visual style as the CSP
// builder's source chips).
function WhitelistChip( { path, onRemove } ) {
	return (
		<span className="inline-flex items-center gap-x-1 rounded-md bg-indigo-50 px-2 py-1 text-xs font-mono text-indigo-700 ring-1 ring-inset ring-indigo-600/20">
			{ path }
			<button
				type="button"
				onClick={ onRemove }
				className="ml-0.5 text-indigo-400 hover:text-indigo-600"
				aria-label={ sprintf(
					/* translators: %s: the file path being removed */
					__( 'Remove %s', 'simple-performance-for-wordpress' ),
					path
				) }
			>
				&times;
			</button>
		</span>
	);
}

export default function PhpWhitelistCard( {
	hardening,
	onChange,
	fileScanResults,
	onScanFiles,
	isScanning,
	adminEmail,
} ) {
	const whitelist = hardening.php_whitelist || [];
	const [ inputPath, setInputPath ] = useState( '' );
	const [ showPrefillConfirm, setShowPrefillConfirm ] = useState( false );
	// The results list can be very long (a first scan reports every tracked
	// file), so it stays collapsed behind a "Show file list" button.
	const [ showFileList, setShowFileList ] = useState( false );

	const trimmedInput = inputPath.trim().replace( /\\/g, '/' ).replace( /^\/+/, '' );
	const isDuplicate = whitelist.includes( trimmedInput );
	const canAdd = trimmedInput.length > 0 && ! isDuplicate;

	const addPath = () => {
		if ( ! canAdd ) {
			return;
		}

		onChange( 'php_whitelist', [ ...whitelist, trimmedInput ] );
		setInputPath( '' );
	};

	const removePath = ( path ) => {
		onChange(
			'php_whitelist',
			whitelist.filter( ( p ) => p !== path )
		);
	};

	const handleKeyDown = ( e ) => {
		if ( 'Enter' === e.key ) {
			e.preventDefault();
			addPath();
		}
	};

	const prefillCommon = () => {
		const existing = new Set( whitelist );
		const toAdd = COMMON_PLUGINS.map( ( p ) => p.path ).filter(
			( p ) => ! existing.has( p )
		);

		if ( toAdd.length > 0 ) {
			onChange( 'php_whitelist', [ ...whitelist, ...toAdd ] );
		}

		setShowPrefillConfirm( false );
	};

	const monitorEnabled = !! hardening.file_monitor_enabled;
	const monitorEmail = hardening.file_monitor_email || '';
	const lastScan = hardening.file_monitor_last_scan || 0;
	const snapshotCount = hardening.file_monitor_snapshot_count || 0;

	const scanResult = fileScanResults || null;
	const hasChanges =
		scanResult &&
		( scanResult.added.length > 0 ||
			scanResult.modified.length > 0 ||
			scanResult.removed.length > 0 );

	// Collapse the list again whenever a fresh scan result arrives.
	useEffect( () => {
		setShowFileList( false );
	}, [ fileScanResults ] );

	const isOnWhitelist = ( path ) => whitelist.includes( path );

	return (
		<SettingsCard
			title={ __(
				'PHP Execution Whitelist & File Monitor',
				'simple-performance-for-wordpress'
			) }
			description={ __(
				'Allow specific PHP files to execute even when directory hardening is on, and monitor wp-content for unexpected file changes.',
				'simple-performance-for-wordpress'
			) }
		>
			<SettingsRow
				title={ __(
					'PHP execution whitelist',
					'simple-performance-for-wordpress'
				) }
				description={ __(
					'WP-content-relative paths allowed to execute PHP when directory hardening is active. Use forward slashes (e.g. plugins/shortpixel-ai/shortpixel-ai.php). Whitelisted files pass through the .htaccess deny rule via RewriteRule.',
					'simple-performance-for-wordpress'
				) }
			>
				<div className="w-full space-y-3">
					{ whitelist.length > 0 && (
						<div className="flex flex-wrap gap-2">
							{ whitelist.map( ( path ) => (
								<WhitelistChip
									key={ path }
									path={ path }
									onRemove={ () => removePath( path ) }
								/>
							) ) }
						</div>
					) }

					<div className="flex items-center gap-x-2">
						<input
							type="text"
							value={ inputPath }
							onChange={ ( e ) => setInputPath( e.target.value ) }
							onKeyDown={ handleKeyDown }
							placeholder={ __(
								'plugins/plugin-slug/file.php',
								'simple-performance-for-wordpress'
							) }
							className="block flex-1 rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 text-xs font-mono"
						/>
						<button
							type="button"
							onClick={ addPath }
							disabled={ ! canAdd }
							className="rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50 disabled:cursor-not-allowed"
						>
							{ __( 'Add', 'simple-performance-for-wordpress' ) }
						</button>
					</div>

					{ trimmedInput && isDuplicate && (
						<p className="text-xs text-amber-600">
							{ __(
								'This path is already in the whitelist.',
								'simple-performance-for-wordpress'
							) }
						</p>
					) }

					<div className="flex items-center gap-x-3">
						{ showPrefillConfirm ? (
							<span className="flex items-center gap-x-2 text-sm">
								<span className="text-gray-700">
									{ __(
										'Add common plugin paths (ShortPixel, Imagify, EWWW, UpdraftPlus)?',
										'simple-performance-for-wordpress'
									) }
								</span>
								<button
									type="button"
									onClick={ prefillCommon }
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
										setShowPrefillConfirm( false )
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
								onClick={ () => setShowPrefillConfirm( true ) }
								className="text-sm font-medium text-indigo-600 hover:text-indigo-500"
							>
								{ __(
									'Pre-fill common plugin paths',
									'simple-performance-for-wordpress'
								) }
							</button>
						) }
					</div>
				</div>
			</SettingsRow>

			<SettingsRow
				title={ __(
					'File integrity monitoring',
					'simple-performance-for-wordpress'
				) }
				description={ __(
					'Periodically scans wp-content/plugins and wp-content/uploads for new, modified, or removed PHP files and sends an email alert when changes are detected.',
					'simple-performance-for-wordpress'
				) }
			>
				<Toggle
					checked={ monitorEnabled }
					onChange={ ( v ) => onChange( 'file_monitor_enabled', v ) }
				/>
			</SettingsRow>

			{ monitorEnabled && (
				<>
					<SettingsRow
						title={ __(
							'Alert email address',
							'simple-performance-for-wordpress'
						) }
						description={ sprintf(
							/* translators: %s: the WordPress admin email fallback */
							__(
								'Email address for file-change alerts. Leave empty to use the site admin email (%s).',
								'simple-performance-for-wordpress'
							),
							adminEmail || '…'
						) }
					>
						<input
							type="email"
							value={ monitorEmail }
							onChange={ ( e ) =>
								onChange( 'file_monitor_email', e.target.value )
							}
							placeholder={
								adminEmail ||
								__(
									'admin@example.com',
									'simple-performance-for-wordpress'
								)
							}
							className="block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 text-sm"
						/>
					</SettingsRow>

					<SettingsRow
						title={ __(
							'Scan status',
							'simple-performance-for-wordpress'
						) }
						description={ __(
							'Last scan time and number of tracked files.',
							'simple-performance-for-wordpress'
						) }
					>
						<div className="space-y-2">
							<p className="text-sm text-gray-700">
								{ lastScan > 0
									? sprintf(
											/* translators: %s: human-readable date/time */
											__(
												'Last scan: %s',
												'simple-performance-for-wordpress'
											),
											new Date(
												lastScan * 1000
											).toLocaleString()
									  )
									: __(
											'No scans yet.',
											'simple-performance-for-wordpress'
									  ) }
							</p>
							{ snapshotCount > 0 && (
								<p className="text-sm text-gray-500">
									{ sprintf(
										/* translators: %d: number of tracked files */
										__(
											'Tracking %d file(s).',
											'simple-performance-for-wordpress'
										),
										snapshotCount
									) }
								</p>
							) }
							<button
								type="button"
								onClick={ onScanFiles }
								disabled={ isScanning }
								className="rounded-md bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
							>
								{ isScanning
									? __(
											'Scanning…',
											'simple-performance-for-wordpress'
									  )
									: __(
											'Scan now',
											'simple-performance-for-wordpress'
									  ) }
							</button>
						</div>
					</SettingsRow>

					{ hasChanges && (
						<SettingsRow
							title={ __(
								'Scan results',
								'simple-performance-for-wordpress'
							) }
							description={ __(
								'Changes detected since the previous scan. Entries marked "not on whitelist" are not in the PHP execution whitelist above.',
								'simple-performance-for-wordpress'
							) }
						>
							<div className="w-full space-y-3">
								<div className="flex items-center justify-between gap-x-3">
									<p className="text-sm text-gray-700">
										{ sprintf(
											/* translators: %1$d: new files, %2$d: modified files, %3$d: removed files */
											__(
												'%1$d new, %2$d modified, %3$d removed file(s) since the last scan.',
												'simple-performance-for-wordpress'
											),
											scanResult.added.length,
											scanResult.modified.length,
											scanResult.removed.length
										) }
									</p>
									<button
										type="button"
										onClick={ () =>
											setShowFileList(
												( prev ) => ! prev
											)
										}
										className="shrink-0 rounded-md bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
									>
										{ showFileList
											? __(
													'Hide file list',
													'simple-performance-for-wordpress'
											  )
											: __(
													'Show file list',
													'simple-performance-for-wordpress'
											  ) }
									</button>
								</div>

								{ showFileList &&
									scanResult.added.length > 0 && (
									<div>
										<h4 className="text-xs font-semibold text-green-700 uppercase tracking-wide mb-1">
											{ __(
												'New files',
												'simple-performance-for-wordpress'
											) }
										</h4>
										<ul className="space-y-0.5">
											{ scanResult.added.map(
												( path ) => (
													<li
														key={ path }
														className={ `text-xs font-mono ${
															isOnWhitelist(
																path
															)
																? 'text-gray-600'
																: 'text-amber-700 bg-amber-50 px-2 py-0.5 rounded'
														}` }
													>
														+ { path }
														{ ! isOnWhitelist(
															path
														) &&
															' ' +
																__(
																	'[not on whitelist]',
																	'simple-performance-for-wordpress'
																) }
													</li>
												)
											) }
										</ul>
									</div>
								) }

								{ showFileList &&
									scanResult.modified.length > 0 && (
									<div>
										<h4 className="text-xs font-semibold text-amber-700 uppercase tracking-wide mb-1">
											{ __(
												'Modified files',
												'simple-performance-for-wordpress'
											) }
										</h4>
										<ul className="space-y-0.5">
											{ scanResult.modified.map(
												( path ) => (
													<li
														key={ path }
														className={ `text-xs font-mono ${
															isOnWhitelist(
																path
															)
																? 'text-gray-600'
																: 'text-amber-700 bg-amber-50 px-2 py-0.5 rounded'
														}` }
													>
														~ { path }
														{ ! isOnWhitelist(
															path
														) &&
															' ' +
																__(
																	'[not on whitelist]',
																	'simple-performance-for-wordpress'
																) }
													</li>
												)
											) }
										</ul>
									</div>
								) }

								{ showFileList &&
									scanResult.removed.length > 0 && (
									<div>
										<h4 className="text-xs font-semibold text-red-700 uppercase tracking-wide mb-1">
											{ __(
												'Removed files',
												'simple-performance-for-wordpress'
											) }
										</h4>
										<ul className="space-y-0.5">
											{ scanResult.removed.map(
												( path ) => (
													<li
														key={ path }
														className="text-xs font-mono text-gray-600"
													>
														- { path }
													</li>
												)
											) }
										</ul>
									</div>
								) }
							</div>
						</SettingsRow>
					) }

					{ scanResult && ! hasChanges && (
						<SettingsRow
							title={ __(
								'Scan results',
								'simple-performance-for-wordpress'
							) }
							description=""
						>
							<p className="text-sm text-green-600">
								{ __(
									'No changes detected since the last scan.',
									'simple-performance-for-wordpress'
								) }
							</p>
						</SettingsRow>
					) }
				</>
			) }
		</SettingsCard>
	);
}
