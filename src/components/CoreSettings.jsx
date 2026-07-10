import { __ } from '@wordpress/i18n';
import SettingsRow from './SettingsRow';
import Toggle from './Toggle';

const HEARTBEAT_MODES = [
	{
		value: 'default',
		label: __(
			'Default (WordPress behavior)',
			'simple-performance-for-wordpress'
		),
	},
	{
		value: 'modify',
		label: __( 'Limit frequency', 'simple-performance-for-wordpress' ),
	},
	{
		value: 'disable',
		label: __( 'Disable entirely', 'simple-performance-for-wordpress' ),
	},
];

const selectClass =
	'block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 text-sm sm:w-56';

export default function CoreSettings( { settings, onChange } ) {
	const core = settings.core || {};

	return (
		<div className="divide-y divide-gray-200">
			<SettingsRow
				title={ __(
					'Disable emojis',
					'simple-performance-for-wordpress'
				) }
				description={ __(
					'Removes emoji detection scripts, styles, and the s.w.org DNS-prefetch hint from the frontend and admin.',
					'simple-performance-for-wordpress'
				) }
			>
				<Toggle
					checked={ !! core.disable_emojis }
					onChange={ ( v ) => onChange( 'disable_emojis', v ) }
				/>
			</SettingsRow>

			<SettingsRow
				title={ __(
					'Disable embeds',
					'simple-performance-for-wordpress'
				) }
				description={ __(
					'Removes oEmbed discovery links and the wp-embed script.',
					'simple-performance-for-wordpress'
				) }
			>
				<Toggle
					checked={ !! core.disable_embeds }
					onChange={ ( v ) => onChange( 'disable_embeds', v ) }
				/>
			</SettingsRow>

			<SettingsRow
				title={ __(
					'Disable Dashicons for visitors',
					'simple-performance-for-wordpress'
				) }
				description={ __(
					'Stops loading the Dashicons stylesheet for logged-out visitors.',
					'simple-performance-for-wordpress'
				) }
			>
				<Toggle
					checked={ !! core.disable_dashicons }
					onChange={ ( v ) => onChange( 'disable_dashicons', v ) }
				/>
			</SettingsRow>

			<SettingsRow
				title={ __(
					'Disable XML-RPC',
					'simple-performance-for-wordpress'
				) }
				description={ __(
					'Disables the XML-RPC endpoint and pingback headers/methods.',
					'simple-performance-for-wordpress'
				) }
			>
				<Toggle
					checked={ !! core.disable_xmlrpc }
					onChange={ ( v ) => onChange( 'disable_xmlrpc', v ) }
				/>
			</SettingsRow>

			<SettingsRow
				title={ __(
					'Remove RSD link',
					'simple-performance-for-wordpress'
				) }
				description={ __(
					'Removes the Really Simple Discovery link from the site header.',
					'simple-performance-for-wordpress'
				) }
			>
				<Toggle
					checked={ !! core.remove_rsd }
					onChange={ ( v ) => onChange( 'remove_rsd', v ) }
				/>
			</SettingsRow>

			<SettingsRow
				title={ __(
					'Remove Windows Live Writer manifest',
					'simple-performance-for-wordpress'
				) }
				description={ __(
					'Removes the wlwmanifest link from the site header.',
					'simple-performance-for-wordpress'
				) }
			>
				<Toggle
					checked={ !! core.remove_wlwmanifest }
					onChange={ ( v ) => onChange( 'remove_wlwmanifest', v ) }
				/>
			</SettingsRow>

			<SettingsRow
				title={ __(
					'Disable RSS feeds',
					'simple-performance-for-wordpress'
				) }
				description={ __(
					'Blocks all feed URLs (post, comment, category, etc.).',
					'simple-performance-for-wordpress'
				) }
			>
				<Toggle
					checked={ !! core.disable_feeds }
					onChange={ ( v ) => onChange( 'disable_feeds', v ) }
				/>
				{ !! core.disable_feeds && (
					<label
						htmlFor="spfw-feed-redirect-home"
						className="flex items-center gap-x-2 text-sm text-gray-600"
					>
						<input
							id="spfw-feed-redirect-home"
							type="checkbox"
							checked={ !! core.feed_redirect_home }
							onChange={ ( e ) =>
								onChange(
									'feed_redirect_home',
									e.target.checked
								)
							}
							className="rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
						/>
						{ __(
							'Redirect feed requests to the homepage (otherwise shows a 403)',
							'simple-performance-for-wordpress'
						) }
					</label>
				) }
			</SettingsRow>

			<SettingsRow
				title={ __(
					'Remove query strings from static assets',
					'simple-performance-for-wordpress'
				) }
				description={ __(
					'Strips ?ver= from enqueued scripts and styles. Low value under LiteSpeed Cache, which already caches by full URL and fingerprints assets — kept for completeness.',
					'simple-performance-for-wordpress'
				) }
			>
				<Toggle
					checked={ !! core.remove_query_strings }
					onChange={ ( v ) => onChange( 'remove_query_strings', v ) }
				/>
			</SettingsRow>

			<SettingsRow
				title={ __(
					'Heartbeat API',
					'simple-performance-for-wordpress'
				) }
				description={ __(
					'Controls the frequency of the WordPress Heartbeat API, which affects autosave, post locking, and admin notifications.',
					'simple-performance-for-wordpress'
				) }
			>
				<select
					value={ core.heartbeat_mode || 'modify' }
					onChange={ ( e ) =>
						onChange( 'heartbeat_mode', e.target.value )
					}
					className={ selectClass }
				>
					{ HEARTBEAT_MODES.map( ( mode ) => (
						<option key={ mode.value } value={ mode.value }>
							{ mode.label }
						</option>
					) ) }
				</select>

				{ core.heartbeat_mode === 'modify' && (
					<div className="w-full sm:w-56">
						<label
							htmlFor="spfw-heartbeat-interval"
							className="block text-xs text-gray-600 mb-1"
						>
							{ __(
								'Interval (seconds, 15-300)',
								'simple-performance-for-wordpress'
							) }
						</label>
						<input
							id="spfw-heartbeat-interval"
							type="number"
							min={ 15 }
							max={ 300 }
							value={ core.heartbeat_interval || 60 }
							onChange={ ( e ) => {
								const clamped = Math.max(
									15,
									Math.min(
										300,
										parseInt( e.target.value, 10 ) || 60
									)
								);
								onChange( 'heartbeat_interval', clamped );
							} }
							className={ selectClass }
						/>
					</div>
				) }

				{ core.heartbeat_mode === 'disable' && (
					<p className="text-xs text-amber-600">
						{ __(
							'Disabling Heartbeat entirely affects post-locking and autosave notices.',
							'simple-performance-for-wordpress'
						) }
					</p>
				) }
			</SettingsRow>

			<SettingsRow
				title={ __(
					'Disable jQuery Migrate',
					'simple-performance-for-wordpress'
				) }
				description={ __(
					'Removes jquery-migrate from jQuery’s dependencies on the frontend.',
					'simple-performance-for-wordpress'
				) }
			>
				<Toggle
					checked={ !! core.disable_jquery_migrate }
					onChange={ ( v ) =>
						onChange( 'disable_jquery_migrate', v )
					}
				/>
			</SettingsRow>
		</div>
	);
}
