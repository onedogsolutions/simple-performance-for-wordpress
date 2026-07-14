import { useState, useEffect } from '@wordpress/element';
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
