@if (in_array($mode ?? 'map', ['map', 'list'], true))
    @include('partials.catalog-select')
@endif
@push('styles')
    @if (($mode ?? 'map') !== 'list')<link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}">@endif
    <link rel="stylesheet" href="{{ asset('css/maps.css') }}">
@endpush
@push('scripts')
    @if (($mode ?? 'map') !== 'list')<script src="{{ asset('vendor/leaflet/leaflet.js') }}" defer></script>@endif
    @if (($mode ?? 'map') === 'map')
        <script src="{{ asset('vendor/supercluster/supercluster.min.js') }}" defer></script>
        <script src="{{ asset('vendor/leaflet-heat/leaflet-heat.js') }}" defer></script>
    @endif
    <script src="{{ asset('js/public/'.($mode ?? 'map').'.js') }}" type="module"></script>
@endpush
