import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import SettingsTabs from './SettingsTabs';
import CoreSettings from './CoreSettings';
import RestApiSettings from './RestApiSettings';
import HardeningSettings from './HardeningSettings';
import FontsSettings from './FontsSettings';

const TABS = [
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

	const handleRestoreHtaccess = () => {
		apiFetch( {
			path: '/spfw/v1/settings/restore-htaccess',
			method: 'POST',
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

	const handleScanFonts = () => {
		return apiFetch( {
			path: '/spfw/v1/settings/scan-fonts',
			method: 'POST',
		} )
			.then( ( data ) => {
				setSettings( data );
				showToast(
					__(
						'Font scan complete.',
						'simple-performance-for-wordpress'
					),
					'success'
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
					tabs={ TABS }
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
								onRestore={ handleRestoreHtaccess }
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
