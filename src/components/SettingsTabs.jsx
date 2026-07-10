import { useRef } from '@wordpress/element';

export default function SettingsTabs( { tabs, active, onChange, children } ) {
	const tabRefs = useRef( {} );

	const activateTab = ( id ) => {
		onChange( id );
		const el = tabRefs.current[ id ];
		if ( el ) {
			el.focus();
		}
	};

	const handleKeyDown = ( e, index ) => {
		let nextIndex = null;

		if ( e.key === 'ArrowRight' ) {
			nextIndex = ( index + 1 ) % tabs.length;
		} else if ( e.key === 'ArrowLeft' ) {
			nextIndex = ( index - 1 + tabs.length ) % tabs.length;
		} else if ( e.key === 'Home' ) {
			nextIndex = 0;
		} else if ( e.key === 'End' ) {
			nextIndex = tabs.length - 1;
		}

		if ( nextIndex !== null ) {
			e.preventDefault();
			activateTab( tabs[ nextIndex ].id );
		}
	};

	return (
		<div>
			<div
				role="tablist"
				aria-label="Settings sections"
				className="flex gap-x-6 overflow-x-auto whitespace-nowrap border-b border-gray-200"
			>
				{ tabs.map( ( tab, index ) => {
					const isActive = tab.id === active;
					return (
						<button
							key={ tab.id }
							ref={ ( el ) => {
								tabRefs.current[ tab.id ] = el;
							} }
							type="button"
							role="tab"
							id={ `spfw-tab-${ tab.id }` }
							aria-selected={ isActive }
							aria-controls={ `spfw-tabpanel-${ tab.id }` }
							tabIndex={ isActive ? 0 : -1 }
							onClick={ () => activateTab( tab.id ) }
							onKeyDown={ ( e ) => handleKeyDown( e, index ) }
							className={ `border-b-2 px-1 py-4 text-sm font-medium transition ${
								isActive
									? 'border-indigo-600 text-indigo-600'
									: 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
							}` }
						>
							{ tab.label }
						</button>
					);
				} ) }
			</div>

			<div className="mt-8">
				{ tabs.map( ( tab ) => (
					<div
						key={ tab.id }
						id={ `spfw-tabpanel-${ tab.id }` }
						role="tabpanel"
						aria-labelledby={ `spfw-tab-${ tab.id }` }
						hidden={ tab.id !== active }
						className="space-y-8"
					>
						{ children[ tab.id ] }
					</div>
				) ) }
			</div>
		</div>
	);
}
