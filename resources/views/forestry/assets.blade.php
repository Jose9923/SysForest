@include('partials.catalog-select')
@push('styles')
    @if ($withMap ?? false)<link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}">@endif
    <link rel="stylesheet" href="{{ asset('css/forestry.css') }}">
@endpush
@push('scripts')
    @if ($withMap ?? false)<script src="{{ asset('vendor/leaflet/leaflet.js') }}" defer></script>@endif
    <script src="{{ asset('js/forestry.js') }}" defer></script>
@endpush
