import { __ } from '@wordpress/i18n';
import SettingsCard from './SettingsCard';
import SettingsRow from './SettingsRow';
import Toggle from './Toggle';

const selectClass =
	'block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 text-sm sm:w-56';

const HEARTBEAT_CONTROL = [
	{
		value: 'default',
		label: __(
			'Default (WordPress behavior)',
			'simple-performance-for-wordpress'
		),
	},
	{
		value: 'disable',
		label: __( 'Disable everywhere', 'simple-performance-for-wordpress' ),
	},
	{
		value: 'allow_posts',
		label: __(
			'Only allow when editing posts/pages',
			'simple-performance-for-wordpress'
		),
	},
];

const HEARTBEAT_FREQUENCY = [
	{
		value: 0,
		label: __( 'Default', 'simple-performance-for-wordpress' ),
	},
	{
		value: 15,
		label: __( '15 seconds', 'simple-performance-for-wordpress' ),
	},
	{
		value: 30,
		label: __( '30 seconds', 'simple-performance-for-wordpress' ),
	},
	{
		value: 60,
		label: __( '60 seconds', 'simple-performance-for-wordpress' ),
	},
	{
		value: 120,
		label: __( '120 seconds', 'simple-performance-for-wordpress' ),
	},
];

const REVISION_OPTIONS = [
	{
		value: 'default',
		label: __( 'Default', 'simple-performance-for-wordpress' ),
	},
	{
		value: 'disable',
		label: __( 'Disable', 'simple-performance-for-wordpress' ),
	},
	...Array.from( { length: 30 }, ( _, i ) => ( {
		value: String( i + 1 ),
		label: String( i + 1 ),
	} ) ),
];

const AUTOSAVE_OPTIONS = [
	{
		value: 0,
		label: __( 'Default (1 minute)', 'simple-performance-for-wordpress' ),
	},
	{ value: 1, label: __( '1 minute', 'simple-performance-for-wordpress' ) },
	{ value: 2, label: __( '2 minutes', 'simple-performance-for-wordpress' ) },
	{ value: 3, label: __( '3 minutes', 'simple-performance-for-wordpress' ) },
	{ value: 4, label: __( '4 minutes', 'simple-performance-for-wordpress' ) },
	{ value: 5, label: __( '5 minutes', 'simple-performance-for-wordpress' ) },
];

export default function CoreSettings( { settings, onChange } ) {
	const core = settings.core || {};

	const toggleRow = ( key, title, description ) => (
		<SettingsRow title={ title } description={ description }>
			<Toggle
				checked={ !! core[ key ] }
				onChange={ ( v ) => onChange( key, v ) }
			/>
		</SettingsRow>
	);

	return (
		<>
			<SettingsCard
				title={ __(
					'Head Cleanup',
					'simple-performance-for-wordpress'
				) }
				description={ __(
					'Remove unused markup, scripts, and discovery links WordPress adds to your site header.',
					'simple-performance-for-wordpress'
				) }
			>
				{ toggleRow(
					'disable_emojis',
					__( 'Disable emojis', 'simple-performance-for-wordpress' ),
					__(
						'Removes emoji detection scripts, styles, and the s.w.org DNS-prefetch hint from the frontend and admin.',
						'simple-performance-for-wordpress'
					)
				) }
				{ toggleRow(
					'disable_embeds',
					__( 'Disable embeds', 'simple-performance-for-wordpress' ),
					__(
						'Removes oEmbed discovery links and the wp-embed script.',
						'simple-performance-for-wordpress'
					)
				) }
				{ toggleRow(
					'disable_dashicons',
					__(
						'Disable Dashicons for visitors',
						'simple-performance-for-wordpress'
					),
					__(
						'Stops loading the Dashicons stylesheet for logged-out visitors.',
						'simple-performance-for-wordpress'
					)
				) }
				{ toggleRow(
					'remove_rsd',
					__( 'Remove RSD link', 'simple-performance-for-wordpress' ),
					__(
						'Removes the Really Simple Discovery link from the site header.',
						'simple-performance-for-wordpress'
					)
				) }
				{ toggleRow(
					'remove_wlwmanifest',
					__(
						'Remove Windows Live Writer manifest',
						'simple-performance-for-wordpress'
					),
					__(
						'Removes the wlwmanifest link from the site header.',
						'simple-performance-for-wordpress'
					)
				) }
				{ toggleRow(
					'remove_shortlink',
					__(
						'Remove shortlink',
						'simple-performance-for-wordpress'
					),
					__(
						'Removes the shortlink tag from the header and HTTP headers.',
						'simple-performance-for-wordpress'
					)
				) }
				{ toggleRow(
					'hide_wp_version',
					__(
						'Hide WordPress version',
						'simple-performance-for-wordpress'
					),
					__(
						'Removes the generator meta tag and strips the WordPress version from core asset URLs.',
						'simple-performance-for-wordpress'
					)
				) }
				{ toggleRow(
					'remove_rest_api_links',
					__(
						'Remove REST API header links',
						'simple-performance-for-wordpress'
					),
					__(
						'Removes the REST API link tag and HTTP header. Does not disable the API — use the REST API tab for that.',
						'simple-performance-for-wordpress'
					)
				) }
			</SettingsCard>

			<SettingsCard
				title={ __(
					'Feeds & Pingbacks',
					'simple-performance-for-wordpress'
				) }
				description={ __(
					'Control RSS feeds, feed discovery links, XML-RPC, and self-pingbacks.',
					'simple-performance-for-wordpress'
				) }
			>
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
				{ toggleRow(
					'remove_feed_links',
					__(
						'Remove feed links',
						'simple-performance-for-wordpress'
					),
					__(
						'Removes feed discovery links from the header without blocking the feed URLs themselves.',
						'simple-performance-for-wordpress'
					)
				) }
				{ toggleRow(
					'disable_self_pingbacks',
					__(
						'Disable self pingbacks',
						'simple-performance-for-wordpress'
					),
					__(
						'Stops the site from sending pingbacks to itself when you link to your own posts.',
						'simple-performance-for-wordpress'
					)
				) }
				{ toggleRow(
					'disable_xmlrpc',
					__( 'Disable XML-RPC', 'simple-performance-for-wordpress' ),
					__(
						'Disables the XML-RPC endpoint and pingback headers/methods.',
						'simple-performance-for-wordpress'
					)
				) }
			</SettingsCard>

			<SettingsCard
				title={ __(
					'Scripts & Assets',
					'simple-performance-for-wordpress'
				) }
				description={ __(
					'Trim script dependencies, third-party embeds, and the WordPress Heartbeat API.',
					'simple-performance-for-wordpress'
				) }
			>
				{ toggleRow(
					'disable_jquery_migrate',
					__(
						'Disable jQuery Migrate',
						'simple-performance-for-wordpress'
					),
					__(
						'Removes jquery-migrate from jQuery’s dependencies on the frontend.',
						'simple-performance-for-wordpress'
					)
				) }
				{ toggleRow(
					'remove_query_strings',
					__(
						'Remove query strings from static assets',
						'simple-performance-for-wordpress'
					),
					__(
						'Strips ?ver= from enqueued scripts and styles. Low value under LiteSpeed Cache, which already caches by full URL — kept for completeness.',
						'simple-performance-for-wordpress'
					)
				) }
				{ toggleRow(
					'disable_google_maps',
					__(
						'Disable Google Maps',
						'simple-performance-for-wordpress'
					),
					__(
						'Strips Google Maps scripts and embedded map iframes from the frontend HTML. May not catch maps rendered entirely by JavaScript.',
						'simple-performance-for-wordpress'
					)
				) }
				{ toggleRow(
					'disable_password_meter',
					__(
						'Disable password strength meter',
						'simple-performance-for-wordpress'
					),
					__(
						'Dequeues the zxcvbn password-strength scripts on front-end forms (e.g. registration and account pages).',
						'simple-performance-for-wordpress'
					)
				) }

				<SettingsRow
					title={ __(
						'Heartbeat API',
						'simple-performance-for-wordpress'
					) }
					description={ __(
						'Controls the WordPress Heartbeat API, which drives autosave, post locking, and admin notifications.',
						'simple-performance-for-wordpress'
					) }
				>
					<select
						value={ core.heartbeat_control || 'default' }
						onChange={ ( e ) =>
							onChange( 'heartbeat_control', e.target.value )
						}
						className={ selectClass }
					>
						{ HEARTBEAT_CONTROL.map( ( mode ) => (
							<option key={ mode.value } value={ mode.value }>
								{ mode.label }
							</option>
						) ) }
					</select>

					{ core.heartbeat_control !== 'disable' && (
						<div className="w-full sm:w-56">
							<label
								htmlFor="spfw-heartbeat-frequency"
								className="block text-xs text-gray-600 mb-1"
							>
								{ __(
									'Frequency',
									'simple-performance-for-wordpress'
								) }
							</label>
							<select
								id="spfw-heartbeat-frequency"
								value={ core.heartbeat_frequency || 0 }
								onChange={ ( e ) =>
									onChange(
										'heartbeat_frequency',
										parseInt( e.target.value, 10 )
									)
								}
								className={ selectClass }
							>
								{ HEARTBEAT_FREQUENCY.map( ( freq ) => (
									<option
										key={ freq.value }
										value={ freq.value }
									>
										{ freq.label }
									</option>
								) ) }
							</select>
						</div>
					) }
				</SettingsRow>
			</SettingsCard>

			<SettingsCard
				title={ __( 'Comments', 'simple-performance-for-wordpress' ) }
				description={ __(
					'Reduce comment-related bloat and spam surface.',
					'simple-performance-for-wordpress'
				) }
			>
				{ toggleRow(
					'disable_comments',
					__(
						'Disable comments',
						'simple-performance-for-wordpress'
					),
					__(
						'Turns off comments site-wide: closes comment/ping status, hides existing comments, and removes the Comments admin menu, toolbar item, and dashboard widget.',
						'simple-performance-for-wordpress'
					)
				) }
				{ toggleRow(
					'remove_comment_urls',
					__(
						'Remove comment author URLs',
						'simple-performance-for-wordpress'
					),
					__(
						'Removes the website field from the comment form to cut spam incentive.',
						'simple-performance-for-wordpress'
					)
				) }
			</SettingsCard>

			<SettingsCard
				title={ __(
					'Database & Misc',
					'simple-performance-for-wordpress'
				) }
				description={ __(
					'Tune post revisions and autosave, and silence favicon 404s.',
					'simple-performance-for-wordpress'
				) }
			>
				<SettingsRow
					title={ __(
						'Limit post revisions',
						'simple-performance-for-wordpress'
					) }
					description={ __(
						'Caps how many revisions WordPress stores per post. Applies going forward; does not delete existing revisions.',
						'simple-performance-for-wordpress'
					) }
				>
					<select
						value={ core.post_revisions || 'default' }
						onChange={ ( e ) =>
							onChange( 'post_revisions', e.target.value )
						}
						className={ selectClass }
					>
						{ REVISION_OPTIONS.map( ( opt ) => (
							<option key={ opt.value } value={ opt.value }>
								{ opt.label }
							</option>
						) ) }
					</select>
				</SettingsRow>

				<SettingsRow
					title={ __(
						'Autosave interval',
						'simple-performance-for-wordpress'
					) }
					description={ __(
						'How often the editor autosaves a draft.',
						'simple-performance-for-wordpress'
					) }
				>
					<select
						value={ core.autosave_interval || 0 }
						onChange={ ( e ) =>
							onChange(
								'autosave_interval',
								parseInt( e.target.value, 10 )
							)
						}
						className={ selectClass }
					>
						{ AUTOSAVE_OPTIONS.map( ( opt ) => (
							<option key={ opt.value } value={ opt.value }>
								{ opt.label }
							</option>
						) ) }
					</select>
				</SettingsRow>

				{ toggleRow(
					'blank_favicon',
					__(
						'Add blank favicon',
						'simple-performance-for-wordpress'
					),
					__(
						'Outputs an empty favicon link to stop /favicon.ico 404s, unless a Site Icon is set.',
						'simple-performance-for-wordpress'
					)
				) }
			</SettingsCard>
		</>
	);
}
