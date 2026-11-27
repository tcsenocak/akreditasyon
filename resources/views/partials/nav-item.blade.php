<a href="{{ route($route) }}" @class(['nav-item', 'active' => request()->routeIs($route, ...($patterns ?? []))])>
    <x-icon :name="$icon" />
    <span>{{ $label }}</span>
    @if (! empty($badge))<span class="nav-badge">{{ $badge }}</span>@endif
</a>
