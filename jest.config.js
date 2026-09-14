/**
 * Jest configuration for the admin app's unit tests.
 *
 * Extends the @wordpress/scripts default and adds one mapping:
 * `@wordpress/element` is provided by WordPress at runtime as a webpack
 * external (`wp.element`) rather than installed as a package, so Jest cannot
 * resolve it. It is a thin re-export of React, which IS installed, so pointing
 * the two at each other lets the components be rendered in tests.
 *
 * That mapping is what makes the render smoke test possible, and that test
 * exists because a `const` referenced above its own declaration shipped once:
 * webpack compiles it happily and only throws at render, so a green build is
 * not evidence the admin screen loads.
 */
const defaults = require( '@wordpress/scripts/config/jest-unit.config' );

module.exports = {
	...defaults,
	moduleNameMapper: {
		...( defaults.moduleNameMapper || {} ),
		'^@wordpress/element$': 'react',
	},
};
