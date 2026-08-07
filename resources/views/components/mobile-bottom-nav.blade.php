@php
    $navItems = [
        [
            'name' => 'Início',
            'route' => 'mobile.dashboard',
            'icon_outline' => '<path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />',
            'icon_solid' => '<path d="M11.47 3.841a.75.75 0 0 1 1.06 0l8.99 8.99a.75.75 0 1 1-1.06 1.06l-8.46-8.46-8.46 8.46a.75.75 0 0 1-1.06-1.06l8.99-8.99Z" /><path d="M12 5.432l8.159 8.159c.03.03.06.058.091.086v6.198c0 1.035-.84 1.875-1.875 1.875H15a.75.75 0 0 1-.75-.75v-4.5a.75.75 0 0 0-.75-.75h-3a.75.75 0 0 0-.75.75V21a.75.75 0 0 1-.75.75H5.625a1.875 1.875 0 0 1-1.875-1.875v-6.198a2.29 2.29 0 0 0 .091-.086L12 5.432Z" />'
        ],
        [
            'name' => 'Diário',
            'route' => 'mobile.daily', // Placeholder for now
            'icon_outline' => '<path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0 0 13.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 0 1-.75.75H9a.75.75 0 0 1-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 0 1-2.25 2.25H6.75A2.25 2.25 0 0 1 4.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 0 1 1.927-.184" />',
            'icon_solid' => '<path fill-rule="evenodd" d="M7.5 3.75A1.5 1.5 0 0 0 6 5.25v13.5a1.5 1.5 0 0 0 1.5 1.5h9a1.5 1.5 0 0 0 1.5-1.5V5.25a1.5 1.5 0 0 0-1.5-1.5h-9ZM10.5 2.25c-.414 0-.75.336-.75.75V4.5c0 .414.336.75.75.75h3c.414 0 .75-.336.75-.75V3c0-.414-.336-.75-.75-.75h-3Z" clip-rule="evenodd" />'
        ],
        [
            'name' => 'Análise',
            'route' => 'mobile.analysis', // Placeholder
            'icon_outline' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />',
            'icon_solid' => '<path d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />'
        ],
        [
            'name' => 'Perfil',
            'route' => 'mobile.profile', // Placeholder
            'icon_outline' => '<path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />',
            'icon_solid' => '<path fill-rule="evenodd" d="M7.5 6a4.5 4.5 0 1 1 9 0 4.5 4.5 0 0 1-9 0ZM3.751 20.105a8.25 8.25 0 0 1 16.498 0 .75.75 0 0 1-.437.695A18.683 18.683 0 0 1 12 22.5c-2.786 0-5.433-.608-7.812-1.7a.75.75 0 0 1-.437-.695Z" clip-rule="evenodd" />'
        ]
    ];
@endphp

<nav class="fixed bottom-0 w-full max-w-md mx-auto left-0 right-0 z-50 bg-black/80 backdrop-blur-xl border-t border-white/10 pb-[env(safe-area-inset-bottom)] pt-2 px-4 transition-colors">
    <div class="flex justify-between items-center h-14">
        @foreach ($navItems as $item)
            @php
                // Check if route exists to avoid errors on placeholder routes
                $url = Route::has($item['route']) ? route($item['route']) : '#';
                $isActive = request()->routeIs($item['route']);
            @endphp
            
            <a href="{{ $url }}" 
               @if(Route::has($item['route'])) wire:navigate @endif
               class="relative flex flex-col items-center justify-center w-full h-full active:scale-95 transition-transform duration-200 tap-highlight-transparent"
               aria-label="{{ $item['name'] }}">
                
                <div class="relative flex flex-col items-center justify-center">
                    @if ($isActive)
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-6 h-6 text-white mb-1 transition-colors duration-200">
                            {!! $item['icon_solid'] !!}
                        </svg>
                        <span class="text-[10px] font-medium text-white transition-colors duration-200">{{ $item['name'] }}</span>
                    @else
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 text-neutral-500 mb-1 transition-colors duration-200">
                            {!! $item['icon_outline'] !!}
                        </svg>
                        <span class="text-[10px] font-medium text-neutral-500 transition-colors duration-200">{{ $item['name'] }}</span>
                    @endif
                </div>
            </a>
        @endforeach
    </div>
</nav>
