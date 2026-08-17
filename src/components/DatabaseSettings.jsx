import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import SettingsCard from './SettingsCard';

const TARGETS = [
	{
		key: 'post_revisions',
		label: __( 'Revisions', 'simple-performance-for-wordpress' ),
	},
	{
		key: 'post_auto_drafts',
		label: __( 'Auto Drafts', 'simple-performance-for-wordpress' ),
	},
	{
		key: 'trashed_posts',
		label: __( 'Trashed Posts', 'simple-performance-for-wordpress' ),
	},
	{
		key: 'spam_comments',
		label: __( 'Spam Comments', 'simple-performance-for-wordpress' ),
	},
	{
		key: 'trashed_comments',
		label: __( 'Trashed Comments', 'simple-performance-for-wordpress' ),
	},
	{
		key: 'expired_transients',
		label: __( 'Expired Transients', 'simple-performance-for-wordpress' ),
	},
	{
		key: 'all_transients',
		label: __( 'All Transients', 'simple-performance-for-wordpress' ),
	},
	{
		key: 'tables',
		label: __(
			'Table Optimization',
			'simple-performance-for-wordpress'
		),
	},
];

export default function DatabaseSettings( { settings, onChange } ) {
	const db = settings.database || {};
	const [ scanCounts, setScanCounts ] = useState( null );
	const [ scanning, setScanning ] = useState( false );
	const [ optimizing, setOptimizing ] = useState( false );
	const [ optimizeResult, setOptimizeResult ] = useState( null );

	const selectedTargets = TARGETS.filter( ( t ) => db[ t.key ] ).map(
		( t ) => t.key
	);

	const handleScan = () => {
		setScanning( true );
		setScanCounts( null );
		setOptimizeResult( null );

		apiFetch( { path: '/spfw/v1/settings/database-scan' } )
			.then( ( data ) => {
				setScanCounts( data.counts || {} );
				setScanning( false );
			} )
			.catch( () => {
				setScanning( false );
			} );
	};

	const handleOptimize = () => {
		if ( selectedTargets.length === 0 ) {
			return;
		}

		setOptimizing( true );
		setOptimizeResult( null );

		apiFetch( {
			path: '/spfw/v1/settings/database-optimize',
			method: 'POST',
			data: { targets: selectedTargets },
		} )
			.then( ( data ) => {
				setOptimizeResult( data );
				setOptimizing( false );
				// Re-scan to refresh counts.
				handleScan();
			} )
			.catch( () => {
				setOptimizing( false );
			} );
	};

	const resultTotal =
		optimizeResult && optimizeResult.results
			? Object.values( optimizeResult.results ).reduce(
					( sum, v ) => sum + v,
					0
			  )
			: 0;

	return (
		<div className="space-y-8">
			{ /* ── Database Cleanup ── */ }
			<SettingsCard
				title={ __(
					'Database Cleanup',
					'simple-performance-for-wordpress'
				) }
				description={ __(
					'Scan and clean post revisions, drafts, trashed content, spam, transients, and fragmented tables. Select the items you want to clean, then click Optimize.',
					'simple-performance-for-wordpress'
				) }
			>
				<div className="space-y-3 pb-4">
					{ TARGETS.map( ( target ) => (
						<label
							key={ target.key }
							className="flex items-center gap-x-3 cursor-pointer"
						>
							<input
								type="checkbox"
								checked={ !! db[ target.key ] }
								onChange={ () =>
									onChange( target.key, ! db[ target.key ] )
								}
								className="rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
							/>
							<span className="text-sm text-gray-900 flex-1">
								{ target.label }
							</span>
							{ scanCounts !== null &&
								scanCounts[ target.key ] !== undefined && (
									<span
										className={ `text-xs font-medium px-2 py-0.5 rounded-full ${
											scanCounts[ target.key ] > 0
												? 'bg-amber-100 text-amber-800'
												: 'bg-green-100 text-green-800'
										}` }
									>
										{ scanCounts[ target.key ] }
										{ ' ' }
										{ __(
											'found',
											'simple-performance-for-wordpress'
										) }
									</span>
								) }
						</label>
					) ) }
				</div>

				<div className="flex items-center gap-x-3 pt-4">
					<button
						type="button"
						onClick={ handleScan }
						disabled={ scanning }
						className="rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-50"
					>
						{ scanning
							? __(
									'Scanning…',
									'simple-performance-for-wordpress'
							  )
							: __(
									'Scan Database',
									'simple-performance-for-wordpress'
							  ) }
					</button>

					<button
						type="button"
						onClick={ handleOptimize }
						disabled={
							optimizing || selectedTargets.length === 0
						}
						className="rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500 disabled:opacity-50"
					>
						{ optimizing
							? __(
									'Optimizing…',
									'simple-performance-for-wordpress'
							  )
							: __(
									'Optimize',
									'simple-performance-for-wordpress'
							  ) }
					</button>

					{ selectedTargets.length === 0 && (
						<span className="text-xs text-gray-500">
							{ __(
								'Select at least one target above.',
								'simple-performance-for-wordpress'
							) }
						</span>
					) }
				</div>

				{ optimizeResult && resultTotal >= 0 && (
					<div className="mt-4 rounded-md bg-green-50 p-4 ring-1 ring-inset ring-green-600/20">
						<p className="text-sm font-medium text-green-800">
							{ resultTotal > 0
								? `${ resultTotal } ${ __(
										'item(s) removed.',
										'simple-performance-for-wordpress'
								  ) }`
								: __(
										'No items found to clean.',
										'simple-performance-for-wordpress'
								  ) }
						</p>
						{ resultTotal > 0 &&
							optimizeResult.results && (
								<ul className="mt-2 text-xs text-green-700 space-y-0.5">
									{ Object.entries(
										optimizeResult.results
									).map( ( [ key, count ] ) => {
										if ( count <= 0 ) {
											return null;
										}
										const label =
											optimizeResult.labels?.[ key ] ||
											key;
										return (
											<li key={ key }>
												<strong>{ label }:</strong>{ ' ' }
												{ count }
											</li>
										);
									} ) }
								</ul>
							) }
					</div>
				) }
			</SettingsCard>

			{ /* ── Scheduled Cleanup ── */ }
			<SettingsCard
				title={ __(
					'Scheduled Cleanup',
					'simple-performance-for-wordpress'
				) }
				description={ __(
					'Automatically run the enabled cleanup targets on a recurring schedule. Uses WP-Cron.',
					'simple-performance-for-wordpress'
				) }
			>
				<div className="flex items-center gap-x-4 pb-4 pt-2">
					<label
						htmlFor="spfw-db-schedule"
						className="text-sm font-medium text-gray-700"
					>
						{ __(
							'Schedule',
							'simple-performance-for-wordpress'
						) }
					</label>
					<select
						id="spfw-db-schedule"
						value={ db.optimize_schedule || 'off' }
						onChange={ ( e ) =>
							onChange( 'optimize_schedule', e.target.value )
						}
						className="rounded-md border-0 py-2 px-3 text-sm text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600"
					>
						<option value="off">
							{ __( 'Off', 'simple-performance-for-wordpress' ) }
						</option>
						<option value="daily">
							{ __(
								'Daily',
								'simple-performance-for-wordpress'
							) }
						</option>
						<option value="weekly">
							{ __(
								'Weekly',
								'simple-performance-for-wordpress'
							) }
						</option>
						<option value="monthly">
							{ __(
								'Monthly',
								'simple-performance-for-wordpress'
							) }
						</option>
					</select>
				</div>
				<p className="text-xs text-gray-500 pb-2">
					{ __(
						'All targets enabled above will be cleaned automatically on the selected schedule.',
						'simple-performance-for-wordpress'
					) }
				</p>
			</SettingsCard>
		</div>
	);
}
