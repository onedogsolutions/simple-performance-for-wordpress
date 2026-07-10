export default function SettingsCard( { title, description, children } ) {
	return (
		<div className="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-xl">
			<div className="px-4 py-6 sm:p-8">
				<h2 className="text-base font-semibold leading-7 text-gray-900">
					{ title }
				</h2>
				{ description && (
					<p className="mt-1 text-sm leading-6 text-gray-600">
						{ description }
					</p>
				) }
				<div className="mt-6 divide-y divide-gray-100 border-t border-gray-100">
					{ children }
				</div>
			</div>
		</div>
	);
}
