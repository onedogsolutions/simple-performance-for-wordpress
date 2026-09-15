/**
 * Render smoke tests for the settings components.
 *
 * These assert almost nothing about output. They exist because webpack
 * compiles a temporal-dead-zone reference (`const gaps = f( directives )`
 * placed above `const directives = ...`) without complaint, and it throws only
 * when the component actually renders — so a successful build is not evidence
 * that the admin screen loads. Rendering each component once catches that
 * whole class of mistake.
 */

import { createRoot } from 'react-dom/client';
import { act } from 'react';

import CspPolicyCard from '../CspPolicyCard';
import WooCommerceSettings from '../WooCommerceSettings';
import FontsSettings from '../FontsSettings';
import HardeningSettings from '../HardeningSettings';

const noop = () => {};

// React 18 requires this flag before act() will drive updates synchronously.
global.IS_REACT_ACT_ENVIRONMENT = true;

// Mount into a detached container and unmount again. Any error thrown while
// the component body executes — a dead-zone reference included — surfaces here.
const renderOnce = ( element ) => {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	const root = createRoot( container );

	act( () => root.render( element ) );
	act( () => root.unmount() );

	container.remove();
};

const baseProps = {
	hardening: {
		csp_enabled: true,
		csp_report_only: true,
		csp_exclude_logged_in: true,
		csp_mode: 'builder',
		csp_directives: {
			'default-src': [ "'self'" ],
			'connect-src': [ "'self'" ],
		},
	},
	settings: {
		csp_emitted_header: 'Content-Security-Policy-Report-Only',
		csp_emitted_policy: "default-src 'self';",
		csp_excludes_logged_in: true,
		csp_max_tokens: 30,
		csp_default_directives: {},
	},
	onChange: noop,
};

describe( 'CspPolicyCard', () => {
	it( 'renders with a minimal policy', () => {
		expect( () =>
			renderOnce( <CspPolicyCard { ...baseProps } /> )
		).not.toThrow();
	} );

	it( 'renders with a payment provider present but connect-src missing', () => {
		// The state that drives the gap warning — the branch most likely to be
		// edited, and whose derived values caused the crash this file exists
		// to prevent.
		const props = {
			...baseProps,
			hardening: {
				...baseProps.hardening,
				csp_directives: {
					...baseProps.hardening.csp_directives,
					'frame-src': [ 'https://js.stripe.com' ],
				},
			},
		};

		expect( () =>
			renderOnce( <CspPolicyCard { ...props } /> )
		).not.toThrow();
	} );

	it( 'renders while enforcing', () => {
		const props = {
			...baseProps,
			hardening: { ...baseProps.hardening, csp_report_only: false },
			settings: {
				...baseProps.settings,
				csp_emitted_header: 'Content-Security-Policy',
			},
		};

		expect( () =>
			renderOnce( <CspPolicyCard { ...props } /> )
		).not.toThrow();
	} );

	it( 'renders in custom (raw policy) mode', () => {
		const props = {
			...baseProps,
			hardening: { ...baseProps.hardening, csp_mode: 'custom' },
		};

		expect( () =>
			renderOnce( <CspPolicyCard { ...props } /> )
		).not.toThrow();
	} );

	it( 'renders with CSP disabled', () => {
		const props = {
			...baseProps,
			hardening: { ...baseProps.hardening, csp_enabled: false },
		};

		expect( () =>
			renderOnce( <CspPolicyCard { ...props } /> )
		).not.toThrow();
	} );
} );

describe( 'FontsSettings', () => {
	const fontsProps = ( overrides = {} ) => ( {
		settings: {
			fonts: {
				localize_google: true,
				discovered: {
					families: [ 'Open Sans:400', 'Open Sans:700' ],
					files: [ 'aaa.woff2', 'bbb.woff2' ],
				},
				last_scan: 1700000000,
				manual_families: [],
				extra_scan_urls: [],
				last_scan_report: {},
			},
			...overrides,
		},
		onChange: noop,
		onScan: noop,
	} );

	it( 'renders with fonts discovered', () => {
		expect( () =>
			renderOnce( <FontsSettings { ...fontsProps() } /> )
		).not.toThrow();
	} );

	it( 'renders the zero state', () => {
		expect( () =>
			renderOnce(
				<FontsSettings
					settings={ { fonts: { localize_google: false } } }
					onChange={ noop }
					onScan={ noop }
				/>
			)
		).not.toThrow();
	} );

	// The cross-origin warning is the branch of the new diagnostics UI that
	// only appears on a misconfigured site, so it is the one least likely to
	// be exercised by hand before shipping.
	it( 'renders the cross-origin warning when uploads are on another host', () => {
		expect( () =>
			renderOnce(
				<FontsSettings
					{ ...fontsProps( {
						fonts_runtime: {
							base: 'https://cdn.example/wp-content/uploads/ods-fonts',
							base_url:
								'https://cdn.example/wp-content/uploads/ods-fonts',
							site_host: 'site.example',
							uploads_host: 'cdn.example',
							same_origin: false,
							css_file_exists: true,
							cors_file_exists: true,
							rendered_for:
								'https://cdn.example/wp-content/uploads/ods-fonts',
						},
					} ) }
				/>
			)
		).not.toThrow();
	} );

	// A failed scan persists its diagnostics; this is the shape the Scan
	// details panel falls back to after a page reload.
	it( 'renders a persisted scan report', () => {
		expect( () =>
			renderOnce(
				<FontsSettings
					{ ...fontsProps() }
					settings={ {
						fonts: {
							localize_google: true,
							discovered: {},
							manual_families: [],
							extra_scan_urls: [],
							last_scan_report: {
								message: 'No Google Fonts detected.',
								time: 1700000000,
								diagnostics: {
									captured: 0,
									from_html: 0,
									from_linked: 0,
									inline_faces: 0,
									faces: 0,
									downloads_ok: 0,
									downloads_ko: 0,
									manual_declared: [],
									manual: [],
									targets: [
										{
											url: 'https://site.example/',
											ok: true,
											bytes: 2048,
										},
									],
									css_urls: [],
								},
							},
						},
					} }
				/>
			)
		).not.toThrow();
	} );
} );

describe( 'WooCommerceSettings', () => {
	it( 'renders', () => {
		expect( () =>
			renderOnce(
				<WooCommerceSettings
					settings={ { woocommerce: {} } }
					onChange={ noop }
				/>
			)
		).not.toThrow();
	} );
} );

// The Hardening tab now renders whatever the server reports about itself, and
// an install can legitimately report almost nothing — an old cached settings
// payload from before the server block existed, a server that does not
// advertise itself, a stack with no snippet to show. Each of those reaches the
// component as an undefined prop, and a card that throws on one takes the whole
// admin screen with it.
describe( 'HardeningSettings', () => {
	const hardeningProps = {
		settings: { hardening: {} },
		onChange: noop,
		onRestore: noop,
		onVerifyHtaccess: noop,
		onRemoveFile: noop,
	};

	it( 'renders before any server report has arrived', () => {
		expect( () =>
			renderOnce( <HardeningSettings { ...hardeningProps } /> )
		).not.toThrow();
	} );

	it( 'renders an nginx install with a snippet and both clocks stale', () => {
		expect( () =>
			renderOnce(
				<HardeningSettings
					{ ...hardeningProps }
					settings={ {
						hardening: {
							uploads_htaccess: true,
							protect_sensitive_files: true,
						},
					} }
					uploadsStatus="unsupported"
					rootStatus="advisory"
					server={ {
						kind: 'nginx',
						software: 'nginx/1.24.0',
						sapi: 'fpm-fcgi',
						supports_htaccess: false,
						supports_user_ini: true,
						user_ini_filename: '.user.ini',
						user_ini_cache_ttl: 300,
						config_refresh: 'reload',
					} }
					strategies={ {
						plugins: { strategy: 'snippet', status: 'advisory' },
						uploads: { strategy: 'user_ini', status: 'ok' },
						root: { strategy: 'snippet', status: 'advisory' },
					} }
					snippet={ {
						format: 'nginx',
						body: 'location ~* /\\.user\\.ini$ {\n    deny all;\n}\n',
					} }
					configStaleness={ {
						stale: true,
						reason: 'awaiting_ttl',
						remedy: 'wait',
						applies_at: 1700000300,
						refresh: 'ttl',
					} }
					cacheStaleness={ {
						stale: true,
						changed: 1700000000,
						purged: 0,
						purge_available: false,
						remedy: 'purge_manual',
					} }
					removableFiles={ [
						{ file: 'readme.html', exists: true },
						{ file: 'license.txt', exists: false },
					] }
				/>
			)
		).not.toThrow();
	} );

	it( 'renders an OpenLiteSpeed install, which gets no snippet at all', () => {
		expect( () =>
			renderOnce(
				<HardeningSettings
					{ ...hardeningProps }
					settings={ {
						hardening: { plugins_htaccess: true },
					} }
					hardeningStatus="ok"
					hardeningEnforcement="enforced"
					server={ {
						kind: 'openlitespeed',
						software: 'LiteSpeed',
						sapi: 'litespeed',
						supports_htaccess: true,
						supports_user_ini: true,
						user_ini_filename: '.user.ini',
						user_ini_cache_ttl: 300,
						config_refresh: 'restart',
					} }
					strategies={ {
						plugins: { strategy: 'htaccess', status: 'ok' },
						uploads: { strategy: 'htaccess', status: 'disabled' },
						root: { strategy: 'htaccess', status: 'disabled' },
					} }
					snippet={ { format: '', body: '' } }
					configStaleness={ { stale: false } }
					cacheStaleness={ { stale: false } }
					removableFiles={ [] }
				/>
			)
		).not.toThrow();
	} );
} );
