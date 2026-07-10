export default function SettingsRow( { title, description, children } ) {
	return (
		<div className="py-6 flex flex-col gap-y-4 sm:flex-row sm:items-center sm:justify-between sm:gap-x-8">
			<div className="flex-1">
				<h3 className="text-sm font-semibold text-gray-900">
					{ title }
				</h3>
				{ description && (
					<p className="mt-1 text-sm text-gray-500">
						{ description }
					</p>
				) }
			</div>

			<div className="flex flex-col items-start sm:items-end gap-y-3 min-w-[240px] w-full sm:w-auto">
				{ children }
			</div>
		</div>
	);
}
