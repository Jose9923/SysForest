@if (!($stylesOnly ?? false))
    @include('forestry.assets')
@endif
@push('styles')
    <link rel="stylesheet" href="{{ asset('css/inspections.css') }}">
@endpush
@if (!($stylesOnly ?? false))
    @push('scripts')
        <script src="{{ asset('js/inspections.js') }}" defer></script>
    @endpush
@endif
