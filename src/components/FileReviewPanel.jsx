import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import SettingsRow from './SettingsRow';

// How each verification source reads in the "Last verified" line.
const SOURCE_LABELS = {
	manual: __( 'marked as verified', 'simple-performance-for-wordpress' ),
	package: __(
		'matched the update package',
		'simple-performance-for-wordpress'
	),
	wporg: __(
		'matched wordpress.org checksums',
		'simple-performance-for-wordpress'
	),
	initial: __( 'first scan', 'simple-performance-for-wordpress' ),
	mainwp: __(
		'marked as verified from the MainWP dashboard',
		'simple-performance-for-wordpress'
	),
	consensus: __(
		'matched the same version on other managed sites',
		'simple-performance-for-wordpress'
	),
};

const BUTTON =
	'shrink-0 rounded-md bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed';

// One collapsible list of paths, prefixed +/~/- by change type.
function PathList( { paths } ) {
	const rows = [
		...( paths.added || [] ).map( ( p ) => [ '+', p ] ),
		...( paths.modified || [] ).map( ( p ) => [ '~', p ] ),
		...( paths.removed || [] ).map( ( p ) => [ '-', p ] ),
	];

	return (
		<ul className="space-y-0.5">
			{ rows.map( ( [ mark, path ] ) => (
				<li
					key={ mark + path }
					className="text-xs font-mono text-gray-700 break-all"
				>
					{ mark } { path }
				</li>
			) ) }
		</ul>
	);
}

const countPaths = ( paths ) =>
	( paths.added || [] ).length +
	( paths.modified || [] ).length +
	( paths.removed || [] ).length;

/**
 * What the file monitor is waiting for a person to review: changes nothing
 * explains, and plugin updates nothing could verify. Mark as verified accepts
 * a scope into the baseline; the server re-checks that the files are still
 * exactly what this panel showed, using the signature from the last scan.
 *
 * @param {Object}   props
 * @param {Object}   props.pending      { unexplained, updates, signatures }.
 * @param {Object}   props.verified     { time, source, user }.
 * @param {Object}   props.autoVerified Slug => source verified by the last scan.
 * @param {Function} props.onVerify     ( scope, signature ) => void.
 * @param {boolean}  props.isVerifying  A verification request is in flight.
 */
export default function FileReviewPanel( {
	pending,
	verified,
	autoVerified,
	onVerify,
	isVerifying,
} ) {
	const [ open, setOpen ] = useState( {} );
	const [ confirmAll, setConfirmAll ] = useState( false );

	const unexplained = ( pending && pending.unexplained ) || {
		added: [],
		modified: [],
		removed: [],
	};
	const updates = ( pending && pending.updates ) || {};
	const signatures = ( pending && pending.signatures ) || {};
	const pluginSigs = signatures.plugins || {};
	const unexplainedCount = countPaths( unexplained );
	const updateSlugs = Object.keys( updates );
	const autoCount = Object.keys( autoVerified || {} ).length;
	const nothingPending = 0 === unexplainedCount && 0 === updateSlugs.length;

	const toggle = ( key ) =>
		setOpen( ( prev ) => ( { ...prev, [ key ]: ! prev[ key ] } ) );

	const verifiedLine =
		verified && verified.time > 0
			? sprintf(
					/* translators: 1: date/time, 2: how it was verified, 3: user name or empty */
					__(
						'Last verified: %1$s — %2$s%3$s',
						'simple-performance-for-wordpress'
					),
					new Date( verified.time * 1000 ).toLocaleString(),
					SOURCE_LABELS[ verified.source ] || verified.source,
					verified.user ? ' · ' + verified.user : ''
			  )
			: '';

	return (
		<SettingsRow
			title={ __( 'Review', 'simple-performance-for-wordpress' ) }
			description={ __(
				'Changes stay listed until something verifies them: the update package WordPress downloaded, wordpress.org checksums, or you. Scanning never accepts a change on its own.',
				'simple-performance-for-wordpress'
			) }
		>
			<div className="w-full space-y-4">
				{ verifiedLine && (
					<p className="text-sm text-gray-500">{ verifiedLine }</p>
				) }

				{ autoCount > 0 && (
					<p className="text-sm text-green-700">
						{ sprintf(
							/* translators: %d: number of plugins */
							__(
								'%d plugin update(s) verified automatically in the last scan.',
								'simple-performance-for-wordpress'
							),
							autoCount
						) }
					</p>
				) }

				{ nothingPending && (
					<p className="text-sm text-green-600">
						{ __(
							'Nothing waiting for review.',
							'simple-performance-for-wordpress'
						) }
					</p>
				) }

				{ unexplainedCount > 0 && (
					<div className="rounded-md bg-red-50 p-3 ring-1 ring-inset ring-red-600/20 space-y-2">
						<div className="flex items-center justify-between gap-x-3">
							<p className="text-sm font-medium text-red-800">
								{ sprintf(
									/* translators: %d: number of files */
									__(
										'%d unexplained change(s) — not from any plugin update.',
										'simple-performance-for-wordpress'
									),
									unexplainedCount
								) }
							</p>
							<button
								type="button"
								className={ BUTTON }
								onClick={ () => toggle( 'unexplained' ) }
							>
								{ open.unexplained
									? __(
											'Hide files',
											'simple-performance-for-wordpress'
									  )
									: __(
											'Show files',
											'simple-performance-for-wordpress'
									  ) }
							</button>
						</div>
						{ open.unexplained && (
							<PathList paths={ unexplained } />
						) }
					</div>
				) }

				{ updateSlugs.length > 0 && (
					<div className="space-y-2">
						<h4 className="text-xs font-semibold text-amber-700 uppercase tracking-wide">
							{ __(
								'Updates not verified',
								'simple-performance-for-wordpress'
							) }
						</h4>
						{ updateSlugs.map( ( slug ) => {
							const group = updates[ slug ];

							return (
								<div
									key={ slug }
									className="rounded-md bg-amber-50 p-3 ring-1 ring-inset ring-amber-600/20 space-y-2"
								>
									<div className="flex flex-wrap items-center justify-between gap-2">
										<p className="text-sm text-amber-900">
											<span className="font-mono">
												{ slug }
											</span>{ ' ' }
											{ ( group.from || '?' ) +
												' → ' +
												group.to }
										</p>
										<div className="flex gap-2">
											<button
												type="button"
												className={ BUTTON }
												onClick={ () =>
													toggle( 'u:' + slug )
												}
											>
												{ open[ 'u:' + slug ]
													? __(
															'Hide files',
															'simple-performance-for-wordpress'
													  )
													: __(
															'Show files',
															'simple-performance-for-wordpress'
													  ) }
											</button>
											<button
												type="button"
												className={ BUTTON }
												disabled={
													isVerifying ||
													! pluginSigs[ slug ]
												}
												onClick={ () =>
													onVerify(
														slug,
														pluginSigs[ slug ]
													)
												}
											>
												{ __(
													'Mark verified',
													'simple-performance-for-wordpress'
												) }
											</button>
										</div>
									</div>
									{ open[ 'u:' + slug ] && (
										<PathList paths={ group.paths } />
									) }
								</div>
							);
						} ) }
					</div>
				) }

				{ ! nothingPending && (
					<div className="flex flex-wrap items-center gap-2">
						{ ! confirmAll ? (
							<button
								type="button"
								className={ BUTTON }
								disabled={ isVerifying || ! signatures.all }
								onClick={ () => setConfirmAll( true ) }
							>
								{ __(
									'Mark all as verified…',
									'simple-performance-for-wordpress'
								) }
							</button>
						) : (
							<>
								<span className="text-sm text-gray-700">
									{ __(
										'Accept every listed change as known good?',
										'simple-performance-for-wordpress'
									) }
								</span>
								<button
									type="button"
									className="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
									disabled={ isVerifying }
									onClick={ () => {
										setConfirmAll( false );
										onVerify( 'all', signatures.all );
									} }
								>
									{ __(
										'Yes, mark all verified',
										'simple-performance-for-wordpress'
									) }
								</button>
								<button
									type="button"
									className={ BUTTON }
									onClick={ () => setConfirmAll( false ) }
								>
									{ __(
										'Cancel',
										'simple-performance-for-wordpress'
									) }
								</button>
							</>
						) }
						{ ! signatures.all && (
							<span className="text-xs text-gray-500">
								{ __(
									'Run a scan to review these changes before verifying.',
									'simple-performance-for-wordpress'
								) }
							</span>
						) }
					</div>
				) }
			</div>
		</SettingsRow>
	);
}
