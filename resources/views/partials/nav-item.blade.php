<a href="{{ route($route) }}" @class(['nav-item', 'active' => request()->routeIs($route, ...($patterns ?? []))])>
    <x-icon :name="$icon" />
    <span>{{ $label }}</span>
</a>
