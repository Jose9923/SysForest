@forelse ($groups as $conditions)
    @php($category = $conditions->first()->category)
    <h3 class="inspection-found-type">{{ $category->name }}</h3><ul class="inspection-found-list">
        @foreach ($conditions as $condition)<li>{{ $condition->name }}@if (!$condition->active || !$category->active)<small>Referencia histórica inactiva</small>@endif</li>@endforeach
    </ul>
@empty
    <p class="muted">Sin condiciones registradas en esta evaluación.</p>
@endforelse
