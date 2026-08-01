import { useState, useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import SettingsTabs from './SettingsTabs';
import CoreSettings from './CoreSettings';
import RestApiSettings from './RestApiSettings';
import HardeningSettings from './HardeningSettings';
import FontsSettings from './FontsSettings';
import WooCommerceSettings from './WooCommerceSettings';

const BASE_TABS = [
	{ id: 'core', label: __( 'Core', 'simple-performance-for-wordpress' ) },
	{
		id: 'restapi',
		label: __( 'REST API', 'simple-performance-for-wordpress' ),
	},
	{
		id: 'hardening',
		label: __( 'Hardening', 'simple-performance-for-wordpress' ),
	},
	{ id: 'fonts', label: __( 'Fonts', 'simple-performance-for-wordpress' ) },
];

export default function App() {
	const initialData = window.spfwAdminData || { settings: {} };
	const wooActive = !! initialData.woocommerceActive;

	const tabs = wooActive
		? [
				...BASE_TABS,
				{
					id: 'woocommerce',
					label: __(
						'WooCommerce',
						'simple-performance-for-wordpress'
					),
				},
		  ]
		: BASE_TABS;

	const [ settings, setSettings ] = useState( initialData.settings );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ toast, setToast ] = useState( { message: '', type: null } );
	const [ activeTab, setActiveTab ] = useState( 'core' );
	const [ presets, setPresets ] = useState( [] );
	const [ showPresetConfirm, setShowPresetConfirm ] = useState( null );
	const fileInputRef = useRef( null );

	useEffect( () => {
		if ( initialData.nonce ) {
			apiFetch.use( apiFetch.createNonceMiddleware( initialData.nonce ) );
		}

		apiFetch( { path: '/spfw/v1/settings' } )
			.then( ( data ) => setSettings( data ) )
			.catch( ( err ) => {
				// eslint-disable-next-line no-console
				console.error( 'Failed to load settings', err );
			} );

		apiFetch( { path: '/spfw/v1/settings/presets' } )
			.then( ( data ) => setPresets( data.presets || {} ) )
			.catch( () => {} );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const showToast = ( message, type ) => {
		setToast( { message, type } );
		setTimeout( () => setToast( { message: '', type: null } ), 4000 );
	};

	const handleChange = ( group, key, value ) => {
		setSettings( ( prev ) => ( {
			...prev,
			[ group ]: {
				...( prev[ group ] || {} ),
				[ key ]: value,
			},
		} ) );
	};

	const handleRestoreHtaccess = ( target = 'plugins' ) => {
		apiFetch( {
			path: '/spfw/v1/settings/restore-htaccess',
			method: 'POST',
			data: { target },
		} )
			.then( ( data ) => {
				setSettings( data );
				showToast(
					__(
						'Hardening file restored.',
						'simple-performance-for-wordpress'
					),
					'success'
				);
			} )
			.catch( ( err ) => {
				showToast(
					err.message ||
						__(
							'Failed to restore the hardening file.',
							'simple-performance-for-wordpress'
						),
					'error'
				);
			} );
	};

	const handleRefreshCspReports = () => {
		return apiFetch( { path: '/spfw/v1/csp-report' } )
			.then( ( data ) =>
				setSettings( ( prev ) => ( {
					...prev,
					csp_reports: data.csp_reports || [],
				} ) )
			)
			.catch( () => {} );
	};

	const handleClearCspReports = () => {
		return apiFetch( {
			path: '/spfw/v1/csp-report',
			method: 'DELETE',
		} )
			.then( ( data ) =>
				setSettings( ( prev ) => ( {
					...prev,
					csp_reports: data.csp_reports || [],
				} ) )
			)
			.catch( () => {} );
	};

	const handleScanFonts = () => {
		// Persist current settings first so manually declared weights and extra
		// scan URLs the user just typed are in effect for this scan (the scan
		// reads persisted settings, not the in-memory form state).
		return apiFetch( {
			path: '/spfw/v1/settings',
			method: 'POST',
			data: settings,
		} )
			.then( () =>
				apiFetch( {
					path: '/spfw/v1/settings/scan-fonts',
					method: 'POST',
				} )
			)
			.then( ( data ) => {
				setSettings( data );
				const found =
					data.scan_result &&
					Array.isArray( data.scan_result.families ) &&
					data.scan_result.families.length > 0;
				showToast(
					( data.scan_result && data.scan_result.message ) ||
						__(
							'Font scan complete.',
							'simple-performance-for-wordpress'
						),
					found ? 'success' : 'info'
				);
			} )
			.catch( ( err ) => {
				showToast(
					err.message ||
						__(
							'Font scan failed.',
							'simple-performance-for-wordpress'
						),
					'error'
				);
			} );
	};

	const handleSave = ( e ) => {
		e.preventDefault();
		setIsSaving( true );

		apiFetch( {
			path: '/spfw/v1/settings',
			method: 'POST',
			data: settings,
		} )
			.then( ( data ) => {
				setSettings( data );
				setIsSaving( false );
				showToast(
					__( 'Settings saved.', 'simple-performance-for-wordpress' ),
					'success'
				);
			} )
			.catch( ( err ) => {
				setIsSaving( false );
				showToast(
					err.message ||
						__(
							'Failed to save settings. Please try again.',
							'simple-performance-for-wordpress'
						),
					'error'
				);
			} );
	};

	const handleExport = () => {
		apiFetch( { path: '/spfw/v1/settings/export' } )
			.then( ( data ) => {
				const blob = new Blob(
					[ JSON.stringify( data, null, 2 ) ],
					{ type: 'application/json' }
				);
				const url = URL.createObjectURL( blob );
				const a = document.createElement( 'a' );
				a.href = url;
				a.download = 'spfw-settings-export.json';
				a.click();
				URL.revokeObjectURL( url );
				showToast(
					__(
						'Settings exported.',
						'simple-performance-for-wordpress'
					),
					'success'
				);
			} )
			.catch( ( err ) => {
				showToast(
					err.message ||
						__(
							'Export failed.',
							'simple-performance-for-wordpress'
						),
					'error'
				);
			} );
	};

	const handleImportFile = ( e ) => {
		const file = e.target.files && e.target.files[ 0 ];

		if ( ! file ) {
			return;
		}

		const reader = new FileReader();

		reader.onload = () => {
			try {
				const payload = JSON.parse( reader.result );

				apiFetch( {
					path: '/spfw/v1/settings/import',
					method: 'POST',
					data: payload,
				} )
					.then( ( data ) => {
						setSettings( data );
						showToast(
							__(
								'Settings imported.',
								'simple-performance-for-wordpress'
							),
							'success'
						);
					} )
					.catch( ( err ) => {
						showToast(
							err.message ||
								__(
									'Import failed.',
									'simple-performance-for-wordpress'
								),
							'error'
						);
					} );
			} catch ( parseErr ) {
				showToast(
					__(
						'Invalid JSON file.',
						'simple-performance-for-wordpress'
					),
					'error'
				);
			}
		};

		reader.readAsText( file );
		e.target.value = '';
	};

	const handleApplyPreset = ( name ) => {
		setShowPresetConfirm( null );

		apiFetch( {
			path: '/spfw/v1/settings/presets',
			method: 'POST',
			data: { preset: name },
		} )
			.then( ( data ) => {
				setSettings( data );
				showToast(
					__(
						'Preset applied.',
						'simple-performance-for-wordpress'
					),
					'success'
				);
			} )
			.catch( ( err ) => {
				showToast(
					err.message ||
						__(
							'Failed to apply preset.',
							'simple-performance-for-wordpress'
						),
					'error'
				);
			} );
	};

	return (
		<div className="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
			{ toast.message && (
				<div className="fixed bottom-5 right-5 z-50 max-w-sm rounded-lg p-4 shadow-lg border animate-slideIn transition-all duration-300 bg-white border-gray-150">
					<div className="flex items-center gap-x-3">
						{ toast.type === 'success' && (
							<span className="text-green-500 text-lg">✓</span>
						) }
						{ toast.type === 'error' && (
							<span className="text-red-500 text-lg">✗</span>
						) }
						{ toast.type === 'info' && (
							<span className="text-indigo-500 text-lg">ℹ</span>
						) }
						<p className="text-sm font-medium text-gray-900">
							{ toast.message }
						</p>
					</div>
				</div>
			) }

			<div className="border-b border-gray-200 pb-5 mb-8">
				<div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
					<div>
						<h1 className="text-2xl font-bold leading-7 text-gray-900 sm:truncate sm:text-3xl tracking-tight">
							{ __(
								'Simple Performance for WordPress',
								'simple-performance-for-wordpress'
							) }
						</h1>
						<p className="mt-1 text-sm text-gray-500">
							{ __(
								'Lightweight performance, REST API, and hardening controls for OpenLiteSpeed + LiteSpeed Cache.',
								'simple-performance-for-wordpress'
							) }
						</p>
					</div>

					<div className="flex flex-wrap items-center gap-x-2 gap-y-2">
						{ Object.keys( presets ).length > 0 && (
							<select
								value=""
								onChange={ ( e ) => {
									if ( e.target.value ) {
										setShowPresetConfirm(
											e.target.value
										);
									}
								} }
								className="rounded-md border-0 py-1.5 px-3 text-sm text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600"
							>
								<option value="">
									{ __(
										'Apply preset…',
										'simple-performance-for-wordpress'
									) }
								</option>
								{ Object.entries( presets ).map(
									( [ key, preset ] ) => (
										<option
											key={ key }
											value={ key }
										>
											{ preset.label }
										</option>
									)
								) }
							</select>
						) }

						<button
							type="button"
							onClick={ handleExport }
							className="rounded-md bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
						>
							{ __(
								'Export',
								'simple-performance-for-wordpress'
							) }
						</button>

						<button
							type="button"
							onClick={ () =>
								fileInputRef.current &&
								fileInputRef.current.click()
							}
							className="rounded-md bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
						>
							{ __(
								'Import',
								'simple-performance-for-wordpress'
							) }
						</button>

						<input
							ref={ fileInputRef }
							type="file"
							accept=".json"
							onChange={ handleImportFile }
							className="hidden"
						/>
					</div>
				</div>

				{ showPresetConfirm &&
					presets[ showPresetConfirm ] && (
						<div className="mt-4 rounded-md bg-amber-50 p-4 ring-1 ring-inset ring-amber-600/20">
							<p className="text-sm font-medium text-amber-800">
								{ __(
									'Apply preset',
									'simple-performance-for-wordpress'
								) }{ ' ' }
								“{ presets[ showPresetConfirm ].label }”?
							</p>
							<p className="mt-1 text-sm text-amber-700">
								{ presets[ showPresetConfirm ].description }
							</p>
							<p className="mt-1 text-xs text-amber-600">
								{ __(
									'This overwrites your current settings. Save first if you want to keep them.',
									'simple-performance-for-wordpress'
								) }
							</p>
							<div className="mt-3 flex gap-x-3">
								<button
									type="button"
									onClick={ () =>
										handleApplyPreset(
											showPresetConfirm
										)
									}
									className="rounded-md bg-amber-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-amber-500"
								>
									{ __(
										'Apply',
										'simple-performance-for-wordpress'
									) }
								</button>
								<button
									type="button"
									onClick={ () =>
										setShowPresetConfirm( null )
									}
									className="rounded-md bg-white px-3 py-1.5 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
								>
									{ __(
										'Cancel',
										'simple-performance-for-wordpress'
									) }
								</button>
							</div>
						</div>
					) }
			</div>

			<form onSubmit={ handleSave } className="space-y-8">
				<SettingsTabs
					tabs={ tabs }
					active={ activeTab }
					onChange={ setActiveTab }
				>
					{ {
						core: (
							<CoreSettings
								settings={ settings }
								onChange={ ( key, value ) =>
									handleChange( 'core', key, value )
								}
							/>
						),
						restapi: (
							<RestApiSettings
								settings={ settings }
								onChange={ ( key, value ) =>
									handleChange( 'restapi', key, value )
								}
							/>
						),
						hardening: (
							<HardeningSettings
								settings={ settings }
								onChange={ ( key, value ) =>
									handleChange( 'hardening', key, value )
								}
								hardeningStatus={ settings.hardening_status }
								uploadsStatus={
									settings.uploads_hardening_status
								}
								rootStatus={
									settings.root_hardening_status
								}
								onRestore={ handleRestoreHtaccess }
								cspReports={ settings.csp_reports }
								onRefreshCspReports={ handleRefreshCspReports }
								onClearCspReports={ handleClearCspReports }
							/>
						),
						fonts: (
							<FontsSettings
								settings={ settings }
								onChange={ ( key, value ) =>
									handleChange( 'fonts', key, value )
								}
								onScan={ handleScanFonts }
							/>
						),
						...( wooActive && {
							woocommerce: (
								<WooCommerceSettings
									settings={ settings }
									onChange={ ( key, value ) =>
										handleChange(
											'woocommerce',
											key,
											value
										)
									}
								/>
							),
						} ),
					} }
				</SettingsTabs>

				<div className="flex justify-end gap-x-3 border-t border-gray-900/10 pt-6">
					<button
						type="submit"
						disabled={ isSaving }
						className="inline-flex items-center gap-x-2 rounded-md bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 transition disabled:opacity-50"
					>
						{ isSaving
							? __(
									'Saving…',
									'simple-performance-for-wordpress'
							  )
							: __(
									'Save Settings',
									'simple-performance-for-wordpress'
							  ) }
					</button>
				</div>
			</form>
		</div>
	);
}
