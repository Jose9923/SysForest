<nav class="risk-tabs" aria-label="Gestión del riesgo">
    <a class="button {{ request()->routeIs('risk.index') ? '' : 'secondary' }}" href="{{ route('risk.index') }}" @if (request()->routeIs('risk.index')) aria-current="page" @endif>Árboles y riesgo</a>
    <a class="button {{ request()->routeIs('risk.methodology') ? '' : 'secondary' }}" href="{{ route('risk.methodology') }}" @if (request()->routeIs('risk.methodology')) aria-current="page" @endif>Metodología institucional</a>
</nav>
