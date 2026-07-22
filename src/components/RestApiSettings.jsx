import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
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

export default function RestApiSettings( { settings, onChange } ) {
	const restapi = settings.restapi || {};
	const [ namespaces, setNamespaces ] = useState( [] );

	// Local state to prevent React from stripping newlines as you type
	const [ localNamespacesText, setLocalNamespacesText ] = useState( '' );
	const [ localWhitelistText, setLocalWhitelistText ] = useState( '' );

	const disabledList = Array.isArray( restapi.disabled_namespaces )
		? restapi.disabled_namespaces
		: [];

	useEffect( () => {
		apiFetch( { path: '/' } )
			.then( ( data ) => setNamespaces( data.namespaces || [] ) )
			.catch( () => setNamespaces( [] ) );
	}, [] );

	// Keep local text in sync when the external settings change
	useEffect( () => {
		setLocalNamespacesText( listToText( restapi.disabled_namespaces ) );
	}, [ restapi.disabled_namespaces ] );

	useEffect( () => {
		setLocalWhitelistText( listToText( restapi.whitelist_routes ) );
	}, [ restapi.whitelist_routes ] );

	const toggleNamespace = ( ns, isDisabled ) => {
		const next = isDisabled
			? [ ...disabledList, ns ]
			: disabledList.filter( ( item ) => item !== ns );
		onChange( 'disabled_namespaces', next );
	};

	const handleNamespacesBlur = () => {
		onChange( 'disabled_namespaces', textToList( localNamespacesText ) );
	};

	return (
		<SettingsCard
			title={ __( 'REST API', 'simple-performance-for-wordpress' ) }
			description={ __(
				'Restrict or disable REST API routes to reduce your attack surface and prevent user enumeration.',
				'simple-performance-for-wordpress'
			) }
		>
			<SettingsRow
				title={ __(
					'Require authentication',
					'simple-performance-for-wordpress'
				) }
				description={ __(
					'Blocks all REST API requests from logged-out visitors, except whitelisted routes below.',
					'simple-performance-for-wordpress'
				) }
			>
				<Toggle
					checked={ !! restapi.require_auth }
					onChange={ ( v ) => onChange( 'require_auth', v ) }
				/>
			</SettingsRow>

			<div className="py-6">
				<h3 className="text-sm font-semibold text-gray-900">
					{ __(
						'Disable namespaces',
						'simple-performance-for-wordpress'
					) }
				</h3>
				<p className="mt-1 text-sm text-gray-500">
					{ __(
						'Unregisters matching routes entirely (404, not 403) for logged-out visitors, to prevent user enumeration and scanning. Logged-in users who can edit content are never restricted.',
						'simple-performance-for-wordpress'
					) }
				</p>

				<div className="mt-4 space-y-4">
					{ namespaces.length > 0 && (
						<div className="flex flex-wrap gap-3">
							{ namespaces.map( ( ns ) => {
								const inputId = `spfw-ns-${ ns.replace(
									/[^a-zA-Z0-9]/g,
									'-'
								) }`;
								return (
									<div
										key={ ns }
										className="flex grow basis-72 items-center justify-between gap-x-3 bg-gray-50 px-3 py-2.5 rounded-md border border-gray-200 shadow-sm"
									>
										<label
											htmlFor={ inputId }
											className="text-sm font-medium text-gray-700 cursor-pointer select-none"
										>
											{ ns }
										</label>
										<Toggle
											checked={ disabledList.includes(
												ns
											) }
											onChange={ ( checked ) =>
												toggleNamespace( ns, checked )
											}
										/>
									</div>
								);
							} ) }
						</div>
					) }
					<div>
						<label
							htmlFor="spfw-disabled-namespaces"
							className="block text-xs font-medium text-gray-600 mb-1"
						>
							{ __(
								'Advanced: one route prefix per line (e.g. wp/v2/users)',
								'simple-performance-for-wordpress'
							) }
						</label>
						<textarea
							id="spfw-disabled-namespaces"
							rows={ 3 }
							value={ localNamespacesText }
							onChange={ ( e ) =>
								setLocalNamespacesText( e.target.value )
							}
							onBlur={ handleNamespacesBlur }
							className={ textareaClass }
						/>
					</div>
				</div>
			</div>

			<SettingsRow
				title={ __(
					'Whitelist routes',
					'simple-performance-for-wordpress'
				) }
				description={ __(
					'Route prefixes that always bypass both the auth gate and namespace disabling (e.g. Contact Form 7, WooCommerce). This plugin’s own settings API (spfw/v1) is always exempt automatically.',
					'simple-performance-for-wordpress'
				) }
			>
				<textarea
					rows={ 3 }
					placeholder={ 'contact-form-7/v1\nwc/v3\nwc/store' }
					value={ localWhitelistText }
					onChange={ ( e ) =>
						setLocalWhitelistText( e.target.value )
					}
					onBlur={ () =>
						onChange(
							'whitelist_routes',
							textToList( localWhitelistText )
						)
					}
					className={ textareaClass }
				/>
			</SettingsRow>
		</SettingsCard>
	);
}
