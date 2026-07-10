import { __ } from '@wordpress/i18n';
import SettingsCard from './SettingsCard';
import SettingsRow from './SettingsRow';
import Toggle from './Toggle';

export default function WooCommerceSettings( { settings, onChange } ) {
	const woo = settings.woocommerce || {};

	const toggleRow = ( key, title, description ) => (
		<SettingsRow title={ title } description={ description }>
			<Toggle
				checked={ !! woo[ key ] }
				onChange={ ( v ) => onChange( key, v ) }
			/>
		</SettingsRow>
	);

	return (
		<>
			<SettingsCard
				title={ __( 'Storefront', 'simple-performance-for-wordpress' ) }
				description={ __(
					'Trim WooCommerce assets on the front end so non-store pages stay lean.',
					'simple-performance-for-wordpress'
				) }
			>
				{ toggleRow(
					'disable_cart_fragments',
					__(
						'Disable cart fragments',
						'simple-performance-for-wordpress'
					),
					__(
						'Stops the AJAX cart-fragments request (?wc-ajax=get_refreshed_fragments) everywhere except the cart and checkout. The single biggest WooCommerce speed win, and it lets full-page caching work.',
						'simple-performance-for-wordpress'
					)
				) }
				{ toggleRow(
					'disable_scripts_styles',
					__(
						'Disable scripts & styles on non-store pages',
						'simple-performance-for-wordpress'
					),
					__(
						'Only loads WooCommerce CSS/JS on shop, product, cart, checkout, and account pages — great for sites using page builders elsewhere.',
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
						'Dequeues the WooCommerce password-strength-meter script on account and checkout forms.',
						'simple-performance-for-wordpress'
					)
				) }
			</SettingsCard>

			<SettingsCard
				title={ __( 'Admin', 'simple-performance-for-wordpress' ) }
				description={ __(
					'Remove WooCommerce dashboard and admin bloat you don’t use.',
					'simple-performance-for-wordpress'
				) }
			>
				{ toggleRow(
					'disable_status_widget',
					__(
						'Disable status dashboard widget',
						'simple-performance-for-wordpress'
					),
					__(
						'Removes the WooCommerce Status and Recent Reviews meta boxes from the admin dashboard.',
						'simple-performance-for-wordpress'
					)
				) }
				{ toggleRow(
					'disable_widgets',
					__(
						'Disable legacy widgets',
						'simple-performance-for-wordpress'
					),
					__(
						'Unregisters the legacy WooCommerce widgets (cart, filters, product lists, etc.).',
						'simple-performance-for-wordpress'
					)
				) }
				{ toggleRow(
					'disable_marketing_hub',
					__(
						'Disable Marketing hub',
						'simple-performance-for-wordpress'
					),
					__(
						'Removes the WooCommerce → Marketing menu and its admin feature.',
						'simple-performance-for-wordpress'
					)
				) }
			</SettingsCard>
		</>
	);
}
