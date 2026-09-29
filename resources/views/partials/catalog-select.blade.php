@push('styles')
    <link rel="stylesheet" href="{{ asset('vendor/select2/select2.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/municipality-select.css') }}">
@endpush
@push('scripts')
    <script src="{{ asset('vendor/jquery/jquery.min.js') }}" defer></script>
    <script src="{{ asset('vendor/select2/select2.min.js') }}" defer></script>
    <script src="{{ asset('vendor/select2/es.js') }}" defer></script>
    <script src="{{ asset('js/select2-accessibility.js') }}" defer></script>
    <script src="{{ asset('js/catalog-select.js') }}" defer></script>
@endpush
