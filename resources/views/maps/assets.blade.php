@include('partials.catalog-select')
@push('styles')
    <link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}">
    <link rel="stylesheet" href="{{ asset('css/maps.css') }}">
@endpush
@push('scripts')
    <script src="{{ asset('vendor/leaflet/leaflet.js') }}" defer></script>
    <script src="{{ asset('vendor/supercluster/supercluster.min.js') }}" defer></script>
    <script src="{{ asset('vendor/leaflet-heat/leaflet-heat.js') }}" defer></script>
    <script src="{{ asset('js/map/map.js') }}" type="module"></script>
@endpush
