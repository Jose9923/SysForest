@if ($sessionContinuity = request()->attributes->get('session_continuity'))
    <script type="application/json" id="session-continuity-config">{!! \Illuminate\Support\Js::encode($sessionContinuity) !!}</script>
    <script src="{{ asset('js/session-continuity.js') }}"></script>
@endif
