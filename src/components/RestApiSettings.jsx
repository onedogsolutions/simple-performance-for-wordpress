import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
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

	useEffect( () => {
		apiFetch( { path: '/' } )
			.then( ( data ) => setNamespaces( data.namespaces || [] ) )
			.catch( () => setNamespaces( [] ) );
	}, [] );

	const disabledList = Array.isArray( restapi.disabled_namespaces )
		? restapi.disabled_namespaces
		: [];

	const toggleNamespace = ( ns, isDisabled ) => {
		const next = isDisabled
			? [ ...disabledList, ns ]
			: disabledList.filter( ( item ) => item !== ns );
		onChange( 'disabled_namespaces', next );
	};

	return (
		<div className="divide-y divide-gray-200">
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

			<SettingsRow
				title={ __(
					'Disable namespaces',
					'simple-performance-for-wordpress'
				) }
				description={ __(
					'Unregisters matching routes entirely (404, not 403) to prevent user enumeration and scanning.',
					'simple-performance-for-wordpress'
				) }
			>
				<div className="w-full space-y-3">
					{ namespaces.length > 0 && (
						<div className="flex flex-wrap gap-x-4 gap-y-2">
							{ namespaces.map( ( ns ) => {
								const inputId = `spfw-ns-${ ns.replace(
									/[^a-zA-Z0-9]/g,
									'-'
								) }`;
								return (
									<label
										key={ ns }
										htmlFor={ inputId }
										className="flex items-center gap-x-2 text-sm text-gray-600"
									>
										<input
											id={ inputId }
											type="checkbox"
											checked={ disabledList.includes(
												ns
											) }
											onChange={ ( e ) =>
												toggleNamespace(
													ns,
													e.target.checked
												)
											}
											className="rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
										/>
										{ ns }
									</label>
								);
							} ) }
						</div>
					) }
					<div>
						<label
							htmlFor="spfw-disabled-namespaces"
							className="block text-xs text-gray-600 mb-1"
						>
							{ __(
								'Advanced: one route prefix per line (e.g. wp/v2/users)',
								'simple-performance-for-wordpress'
							) }
						</label>
						<textarea
							id="spfw-disabled-namespaces"
							rows={ 3 }
							value={ listToText( disabledList ) }
							onChange={ ( e ) =>
								onChange(
									'disabled_namespaces',
									textToList( e.target.value )
								)
							}
							className={ textareaClass }
						/>
					</div>
				</div>
			</SettingsRow>

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
					value={ listToText( restapi.whitelist_routes ) }
					onChange={ ( e ) =>
						onChange(
							'whitelist_routes',
							textToList( e.target.value )
						)
					}
					className={ textareaClass }
				/>
			</SettingsRow>
		</div>
	);
}
