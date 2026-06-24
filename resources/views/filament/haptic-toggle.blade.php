@php
    $hapticEnabled = auth()->user()?->haptic_enabled ?? true;
@endphp
<div x-data="{
    hapticEnabled: @json($hapticEnabled),
    init() {
        localStorage.setItem('mmcrespo_haptic_disabled', (!this.hapticEnabled).toString());
    },
    async toggle() {
        this.hapticEnabled = !this.hapticEnabled;
        localStorage.setItem('mmcrespo_haptic_disabled', (!this.hapticEnabled).toString());
        if (this.hapticEnabled && navigator.vibrate) {
            navigator.vibrate(15);
        }
        try {
            await fetch('/admin/update-haptic-preference', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({ enabled: this.hapticEnabled })
            });
        } catch (e) {
            // Silently fail if offline or network error
        }
    }
}"
@haptic-preference-updated.window="hapticEnabled = $event.detail.enabled"
class="relative flex items-center justify-center mr-2">
    <button
        x-on:click="toggle()"
        type="button"
        x-tooltip="hapticEnabled ? 'Desativar vibração (Haptic)' : 'Ativar vibração (Haptic)'"
        class="inline-flex items-center justify-center rounded-lg p-2 text-gray-500 hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-gray-300 transition-colors"
        :class="hapticEnabled ? 'text-primary-600 dark:text-primary-400' : 'text-gray-400 dark:text-gray-500'"
    >
        <!-- Phone vibrating SVG when enabled -->
        <svg x-show="hapticEnabled" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 1.5H8.25A2.25 2.25 0 006 3.75v16.5a2.25 2.25 0 002.25 2.25h7.5A2.25 2.25 0 0018 20.25V3.75a2.25 2.25 0 00-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-6 4.5h-.75A.75.75 0 006 6.75v10.5c0 .414.336.75.75.75H6.75M18 6.75v10.5a.75.75 0 01-.75.75H16.5m-12-7.5h.75m11.25 0h.75" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 9c-.621 0-1.125.504-1.125 1.125v3.75c0 .621.504 1.125 1.125 1.125M20.25 9c.621 0 1.125.504 1.125 1.125v3.75c0 .621-.504 1.125-1.125 1.125" />
        </svg>
        <!-- Phone crossed SVG when disabled -->
        <svg x-show="!hapticEnabled" class="w-5 h-5 opacity-60" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 1.5H8.25A2.25 2.25 0 006 3.75v16.5a2.25 2.25 0 002.25 2.25h7.5A2.25 2.25 0 0018 20.25V3.75a2.25 2.25 0 00-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 3l18 18" />
        </svg>
    </button>
</div>
