@if (request()->attributes->has('session_continuity'))
    <section class="notice session-notice" role="alert" aria-live="assertive" tabindex="-1" data-session-notice hidden>
        <p data-session-message></p>
        <button type="button" class="button secondary" data-session-retry>Comprobar sesión</button>
        <a class="button" href="{{ route('login') }}" target="_blank" rel="noopener" data-session-login data-session-exempt hidden>Iniciar sesión en otra pestaña</a>
    </section>
@endif
