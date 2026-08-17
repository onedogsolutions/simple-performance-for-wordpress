import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import SettingsCard from './SettingsCard';

export default function OptionCleanerSettings() {
	const [ optionGroups, setOptionGroups ] = useState( [] );
	const [ capGroups, setCapGroups ] = useState( [] );
	const [ selectedOptions, setSelectedOptions ] = useState( {} );
	const [ selectedCaps, setSelectedCaps ] = useState( {} );
	const [ scanning, setScanning ] = useState( null );
	const [ deleting, setDeleting ] = useState( null );
	const [ result, setResult ] = useState( null );
	const [ manualPrefix, setManualPrefix ] = useState( '' );
	const [ totalOptions, setTotalOptions ] = useState( 0 );

	const showToast = ( message ) => {
		setResult( message );
		setTimeout( () => setResult( null ), 5000 );
	};

	const handleScanOptions = ( prefix = '' ) => {
		setScanning( 'options' );
		setResult( null );

		const query = prefix ? `?prefix=${ encodeURIComponent( prefix ) }` : '';

		apiFetch( { path: `/simple-performance/v1/option-cleaner/scan${ query }` } )
			.then( ( data ) => {
				setOptionGroups( data.groups || [] );
				setTotalOptions( data.total_options || 0 );
				setSelectedOptions( {} );
				setScanning( null );
				showToast(
					( data.groups || [] ).length > 0
						? `${ ( data.groups || [] ).length } orphaned group(s) found.`
						: 'No orphaned options detected.'
				);
			} )
			.catch( ( err ) => {
				setScanning( null );
				showToast( err.message || 'Scan failed.' );
			} );
	};

	const handleDeleteOptions = () => {
		const prefixes = Object.keys( selectedOptions ).filter(
			( k ) => selectedOptions[ k ]
		);

		if ( prefixes.length === 0 ) {
			return;
		}

		setDeleting( 'options' );

		apiFetch( {
			path: '/simple-performance/v1/option-cleaner/delete',
			method: 'POST',
			data: { prefixes },
		} )
			.then( ( data ) => {
				showToast( data.message || `${ data.deleted } row(s) deleted.` );
				setSelectedOptions( {} );
				// Re-scan to refresh.
				handleScanOptions();
			} )
			.catch( ( err ) => {
				setDeleting( null );
				showToast( err.message || 'Delete failed.' );
			} );
	};

	const handleScanCaps = () => {
		setScanning( 'caps' );
		setResult( null );

		apiFetch( {
			path: '/simple-performance/v1/option-cleaner/capabilities',
		} )
			.then( ( data ) => {
				setCapGroups( data.groups || [] );
				setSelectedCaps( {} );
				setScanning( null );
				showToast(
					( data.groups || [] ).length > 0
						? `${ ( data.groups || [] ).length } ghost capability group(s) found.`
						: 'No ghost capabilities detected.'
				);
			} )
			.catch( ( err ) => {
				setScanning( null );
				showToast( err.message || 'Scan failed.' );
			} );
	};

	const handleDeleteCaps = () => {
		const prefixes = Object.keys( selectedCaps ).filter(
			( k ) => selectedCaps[ k ]
		);

		if ( prefixes.length === 0 ) {
			return;
		}

		setDeleting( 'caps' );

		apiFetch( {
			path: '/simple-performance/v1/option-cleaner/capabilities/delete',
			method: 'POST',
			data: { prefixes },
		} )
			.then( ( data ) => {
				showToast( data.message || `${ data.removed } cap(s) removed.` );
				setSelectedCaps( {} );
				handleScanCaps();
			} )
			.catch( ( err ) => {
				setDeleting( null );
				showToast( err.message || 'Delete failed.' );
			} );
	};

	const toggleOption = ( prefix ) => {
		setSelectedOptions( ( prev ) => ( {
			...prev,
			[ prefix ]: ! prev[ prefix ],
		} ) );
	};

	const toggleCap = ( prefix ) => {
		setSelectedCaps( ( prev ) => ( {
			...prev,
			[ prefix ]: ! prev[ prefix ],
		} ) );
	};

	const selectAllOptions = () => {
		const all = {};
		optionGroups.forEach( ( g ) => {
			all[ g.prefix ] = true;
		} );
		setSelectedOptions( all );
	};

	const selectAllCaps = () => {
		const all = {};
		capGroups.forEach( ( g ) => {
			all[ g.prefix ] = true;
		} );
		setSelectedCaps( all );
	};

	const selectedOptionCount = Object.values( selectedOptions ).filter( Boolean ).length;
	const selectedCapCount = Object.values( selectedCaps ).filter( Boolean ).length;

	return (
		<div className="space-y-8">
			{ result && (
				<div className="rounded-md bg-blue-50 p-4 ring-1 ring-inset ring-blue-600/20">
					<p className="text-sm text-blue-800">{ result }</p>
				</div>
			) }

			{ /* ── Orphaned Options ── */ }
			<SettingsCard
				title={ __(
					'Orphaned Options',
					'simple-performance-for-wordpress'
				) }
				description={ __(
					'Scan wp_options for leftover entries from uninstalled plugins. Review the results and delete what you no longer need.',
					'simple-performance-for-wordpress'
				) }
			>
				<div className="flex flex-wrap items-end gap-x-4 gap-y-3">
					<button
						type="button"
						onClick={ () => handleScanOptions() }
						disabled={ scanning === 'options' }
						className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
					>
						{ scanning === 'options'
							? __( 'Scanning…', 'simple-performance-for-wordpress' )
							: __( 'Auto-Scan', 'simple-performance-for-wordpress' ) }
					</button>

					<div className="flex items-end gap-x-2">
						<div>
							<label
								htmlFor="spfw-manual-prefix"
								className="block text-xs font-medium text-gray-600 mb-1"
							>
								{ __(
									'Manual prefix',
									'simple-performance-for-wordpress'
								) }
							</label>
							<input
								id="spfw-manual-prefix"
								type="text"
								value={ manualPrefix }
								onChange={ ( e ) =>
									setManualPrefix( e.target.value )
								}
								placeholder="e.g. wc_"
								className="rounded-md border-0 py-2 px-3 text-sm text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 w-40"
							/>
						</div>
						<button
							type="button"
							onClick={ () =>
								handleScanOptions( manualPrefix )
							}
							disabled={
								scanning === 'options' || ! manualPrefix
							}
							className="rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-50"
						>
							{ __(
								'Search',
								'simple-performance-for-wordpress'
							) }
						</button>
					</div>
				</div>

				{ totalOptions > 0 && (
					<p className="mt-3 text-xs text-gray-500">
						{ `${ totalOptions } total options scanned.` }
					</p>
				) }

				{ optionGroups.length > 0 && (
					<div className="mt-4">
						<div className="flex items-center justify-between mb-2">
							<p className="text-sm font-medium text-gray-700">
								{ `${ optionGroups.length } group(s) found` }
							</p>
							<div className="flex gap-x-2">
								<button
									type="button"
									onClick={ selectAllOptions }
									className="text-xs text-indigo-600 hover:text-indigo-500"
								>
									{ __(
										'Select all',
										'simple-performance-for-wordpress'
									) }
								</button>
								<button
									type="button"
									onClick={ handleDeleteOptions }
									disabled={
										deleting === 'options' ||
										selectedOptionCount === 0
									}
									className="rounded-md bg-red-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-red-500 disabled:opacity-50"
								>
									{ deleting === 'options'
										? __(
												'Deleting…',
												'simple-performance-for-wordpress'
										  )
										: `${ __( 'Delete selected', 'simple-performance-for-wordpress' ) } (${ selectedOptionCount })` }
								</button>
							</div>
						</div>

						<div className="rounded-lg border border-gray-200 divide-y divide-gray-200 max-h-80 overflow-y-auto">
							{ optionGroups.map( ( group ) => (
								<label
									key={ group.prefix }
									className="flex items-start gap-x-3 px-4 py-3 cursor-pointer hover:bg-gray-50"
								>
									<input
										type="checkbox"
										checked={
											!! selectedOptions[ group.prefix ]
										}
										onChange={ () =>
											toggleOption( group.prefix )
										}
										className="mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
									/>
									<div className="min-w-0 flex-1">
										<p className="text-sm font-medium text-gray-900">
											{ group.prefix }
										</p>
										<p className="text-xs text-gray-500">
											{ `${ group.count } option(s) · ${( group.size / 1024 ).toFixed( 1 ) } KB` }
										</p>
										{ group.samples &&
											group.samples.length > 0 && (
												<p className="mt-1 text-xs text-gray-400 truncate">
													{ group.samples.join(
														', '
													) }
												</p>
											) }
									</div>
								</label>
							) ) }
						</div>
					</div>
				) }
			</SettingsCard>

			{ /* ── Ghost Capabilities ── */ }
			<SettingsCard
				title={ __(
					'Ghost Capabilities',
					'simple-performance-for-wordpress'
				) }
				description={ __(
					'Scan WordPress roles for orphaned capabilities left behind by uninstalled plugins. Review and strip them from every affected role.',
					'simple-performance-for-wordpress'
				) }
			>
				<button
					type="button"
					onClick={ handleScanCaps }
					disabled={ scanning === 'caps' }
					className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
				>
					{ scanning === 'caps'
						? __( 'Scanning…', 'simple-performance-for-wordpress' )
						: __(
								'Scan Capabilities',
								'simple-performance-for-wordpress'
						  ) }
				</button>

				{ capGroups.length > 0 && (
					<div className="mt-4">
						<div className="flex items-center justify-between mb-2">
							<p className="text-sm font-medium text-gray-700">
								{ `${ capGroups.length } group(s) found` }
							</p>
							<div className="flex gap-x-2">
								<button
									type="button"
									onClick={ selectAllCaps }
									className="text-xs text-indigo-600 hover:text-indigo-500"
								>
									{ __(
										'Select all',
										'simple-performance-for-wordpress'
									) }
								</button>
								<button
									type="button"
									onClick={ handleDeleteCaps }
									disabled={
										deleting === 'caps' ||
										selectedCapCount === 0
									}
									className="rounded-md bg-red-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-red-500 disabled:opacity-50"
								>
									{ deleting === 'caps'
										? __(
												'Removing…',
												'simple-performance-for-wordpress'
										  )
										: `${ __( 'Remove selected', 'simple-performance-for-wordpress' ) } (${ selectedCapCount })` }
								</button>
							</div>
						</div>

						<div className="rounded-lg border border-gray-200 divide-y divide-gray-200 max-h-80 overflow-y-auto">
							{ capGroups.map( ( group ) => (
								<label
									key={ group.prefix }
									className="flex items-start gap-x-3 px-4 py-3 cursor-pointer hover:bg-gray-50"
								>
									<input
										type="checkbox"
										checked={
											!! selectedCaps[ group.prefix ]
										}
										onChange={ () =>
											toggleCap( group.prefix )
										}
										className="mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
									/>
									<div className="min-w-0 flex-1">
										<p className="text-sm font-medium text-gray-900">
											{ group.prefix }
										</p>
										<p className="text-xs text-gray-500">
											{ `${ group.count } cap(s) across ${( group.roles || [] ).join( ', ' ) }` }
										</p>
										{ group.samples &&
											group.samples.length > 0 && (
												<p className="mt-1 text-xs text-gray-400 truncate">
													{ group.samples.join(
														', '
													) }
												</p>
											) }
									</div>
								</label>
							) ) }
						</div>
					</div>
				) }
			</SettingsCard>
		</div>
	);
}
