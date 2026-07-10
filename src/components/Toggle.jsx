export default function Toggle( { checked, onChange, disabled = false } ) {
	return (
		<button
			type="button"
			role="switch"
			aria-checked={ checked }
			disabled={ disabled }
			className={ `relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 ${
				checked ? 'bg-indigo-600' : 'bg-gray-200'
			}` }
			onClick={ () => ! disabled && onChange( ! checked ) }
		>
			<span
				aria-hidden="true"
				className={ `pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${
					checked ? 'translate-x-5' : 'translate-x-0'
				}` }
			/>
		</button>
	);
}
