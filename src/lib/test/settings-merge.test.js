/**
 * Unsaved edits must survive a side-effect endpoint's settings payload.
 *
 * Scans, probes and the CSP collection window all return the full settings
 * object. Applying one wholesale used to discard whatever the admin had
 * changed but not yet saved — you could toggle Report-Only, press "Start
 * collecting", and silently lose the toggle.
 */

import { pendingEdits, applyEdits } from '../settings-merge';

const saved = {
	hardening: {
		csp_report_only: true,
		csp_enabled: true,
		csp_collect_until: 0,
	},
	core: { disable_emojis: false },
	csp_reports: [],
};

describe( 'pendingEdits', () => {
	it( 'reports nothing when the form matches the server', () => {
		expect( pendingEdits( saved, saved ) ).toEqual( {} );
	} );

	it( 'reports only the keys the admin actually changed', () => {
		const current = {
			...saved,
			hardening: { ...saved.hardening, csp_report_only: false },
		};

		expect( pendingEdits( current, saved ) ).toEqual( {
			hardening: { csp_report_only: false },
		} );
	} );

	it( 'ignores computed read-only fields outside the persisted groups', () => {
		const current = { ...saved, csp_reports: [ { directive: 'img-src' } ] };

		expect( pendingEdits( current, saved ) ).toEqual( {} );
	} );

	it( 'treats a newly added key as an edit', () => {
		const current = {
			...saved,
			core: { ...saved.core, disable_embeds: true },
		};

		expect( pendingEdits( current, saved ) ).toEqual( {
			core: { disable_embeds: true },
		} );
	} );

	it( 'compares arrays and objects by value, not identity', () => {
		const base = {
			hardening: { csp_directives: { 'img-src': [ "'self'" ] } },
		};
		const same = {
			hardening: { csp_directives: { 'img-src': [ "'self'" ] } },
		};
		const other = {
			hardening: { csp_directives: { 'img-src': [ 'https:' ] } },
		};

		expect( pendingEdits( same, base ) ).toEqual( {} );
		expect( pendingEdits( other, base ) ).toEqual( {
			hardening: { csp_directives: { 'img-src': [ 'https:' ] } },
		} );
	} );
} );

describe( 'applyEdits', () => {
	it( 'keeps the unsaved edit and the server-side change in the same group', () => {
		// The admin flipped Report-Only, then opened a collection window: the
		// server owns csp_collect_until, the admin still owns csp_report_only.
		const current = {
			...saved,
			hardening: { ...saved.hardening, csp_report_only: false },
		};
		const fromServer = {
			...saved,
			hardening: { ...saved.hardening, csp_collect_until: 1757000000 },
			csp_reports: [ { directive: 'img-src' } ],
		};

		const merged = applyEdits( fromServer, pendingEdits( current, saved ) );

		expect( merged.hardening.csp_report_only ).toBe( false );
		expect( merged.hardening.csp_collect_until ).toBe( 1757000000 );
		expect( merged.csp_reports ).toEqual( [ { directive: 'img-src' } ] );
	} );

	it( 'leaves the payload untouched when nothing is pending', () => {
		const fromServer = { ...saved, hardening: { ...saved.hardening } };

		expect( applyEdits( fromServer, {} ) ).toEqual( fromServer );
	} );

	it( 'does not mutate the server payload', () => {
		const fromServer = { hardening: { csp_report_only: true } };

		applyEdits( fromServer, { hardening: { csp_report_only: false } } );

		expect( fromServer.hardening.csp_report_only ).toBe( true );
	} );
} );
