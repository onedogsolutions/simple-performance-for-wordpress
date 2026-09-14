/**
 * Reconciling the settings form with server payloads.
 *
 * The admin screen is one form with a single Save, but several endpoints
 * (scans, probes, opening the CSP collection window) persist something of
 * their own and return the whole settings object. Applying one of those
 * wholesale discards whatever the admin has changed but not yet saved — the
 * bug this module exists to prevent.
 *
 * Kept free of any WordPress or React import so it stays pure and testable.
 */

/**
 * Setting groups that are actually persisted. get_settings() also returns
 * computed, read-only fields (violation logs, scan results, probe state) that
 * change on their own schedule — comparing those would report the form as
 * permanently unsaved.
 *
 * @type {string[]}
 */
export const PERSISTED_GROUPS = [
	'core',
	'restapi',
	'hardening',
	'fonts',
	'woocommerce',
	'database',
];

/**
 * Stable representation of the persisted groups, for dirty-state comparison.
 *
 * @param {Object} state Settings object.
 * @return {string} Fingerprint.
 */
export const persistedFingerprint = ( state ) =>
	JSON.stringify(
		PERSISTED_GROUPS.reduce( ( acc, group ) => {
			if ( state && state[ group ] ) {
				acc[ group ] = state[ group ];
			}
			return acc;
		}, {} )
	);

/**
 * Keys the admin has changed but not yet saved.
 *
 * Compared per key rather than per group, so a payload that touches one key in
 * a group (a scan result, a collection deadline) does not have to discard an
 * unsaved edit to a different key in that same group.
 *
 * @param {Object} current Live form state.
 * @param {Object} saved   Last state the server confirmed.
 * @return {Object} { group: { key: value } } of pending edits.
 */
export const pendingEdits = ( current, saved ) => {
	const out = {};

	PERSISTED_GROUPS.forEach( ( group ) => {
		const live = current && current[ group ];

		if ( ! live ) {
			return;
		}

		const base = ( saved && saved[ group ] ) || {};

		Object.keys( live ).forEach( ( key ) => {
			if (
				JSON.stringify( live[ key ] ) !== JSON.stringify( base[ key ] )
			) {
				out[ group ] = out[ group ] || {};
				out[ group ][ key ] = live[ key ];
			}
		} );
	} );

	return out;
};

/**
 * Layer pending edits back over a fresh server payload.
 *
 * @param {Object} base  Server payload.
 * @param {Object} edits Pending edits from pendingEdits().
 * @return {Object} New settings object; `base` is not mutated.
 */
export const applyEdits = ( base, edits ) => {
	const next = { ...base };

	Object.keys( edits ).forEach( ( group ) => {
		next[ group ] = { ...( base[ group ] || {} ), ...edits[ group ] };
	} );

	return next;
};
