@if ($value === null || $value === '')<span class="report-unknown">No informado</span>@elseif (is_bool($value)){{ $value ? 'Sí' : 'No' }}@else{{ $value }}@endif
