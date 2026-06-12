@php
/**
 * Blade snippet for DailyRecord list action – redirects based on device width.
 * Use in DailyRecordResource index view.
 */
@endphp
<a href="{{ route('filament.admin.resources.daily-records.show', $record) }}"
   onclick="event.preventDefault(); if(window.innerWidth < 768){ window.location='{{ route('mobile-daily-record', $record->id) }}'; } else { window.location='{{ route('filament.admin.resources.daily-records.show', $record) }}'; }"
   class="px-3 py-1 bg-mmcrespo-primary text-white rounded hover:bg-mmcrespo-accent transition">
    Ver Registo
</a>
