@can('manage-acceptance', \App\Models\AcceptanceUser::class)
    <p>Shared ability</p>
@endcan

@canany(['view', 'update'], \App\Models\AcceptanceUser::class)
    <p>Policy ability list</p>
@elsecannot('delete', \App\Models\AcceptanceUser::class)
    <p>Delete denied</p>
@endcanany
