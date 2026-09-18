@if ($withForestryAssets ?? false)
    @include('forestry.assets')
@endif
@once
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/evidences.css') }}">
    @endpush
    @push('scripts')
        <script src="{{ asset('js/evidences.js') }}" defer></script>
    @endpush
@endonce
