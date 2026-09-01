@php
    /** @var \App\Models\PoolClosureTask $tarefa */
    $videos = is_array($tarefa->videos) ? array_filter($tarefa->videos) : [];
    $fotos = is_array($tarefa->fotos) ? array_filter($tarefa->fotos) : [];
@endphp

<div class="space-y-6 p-2">
    @if (count($videos) > 0)
        <div class="space-y-3">
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Vídeo</h3>

            @foreach ($videos as $video)
                @php
                    $urlVideo = \App\Models\DailyRecord::getStorageUrl($video);
                @endphp

                <div class="space-y-2">
                    <video
                        controls
                        preload="metadata"
                        playsinline
                        class="w-full max-h-[60vh] rounded-lg bg-black"
                    >
                        <source src="{{ $urlVideo }}">
                    </video>

                    {{-- Um clipe HEVC gravado por iPhone nao abre em Chrome no
                         Windows: o link de descarga e a saida sempre disponivel. --}}
                    <a
                        href="{{ $urlVideo }}"
                        target="_blank"
                        rel="noopener"
                        class="text-sm text-primary-600 hover:underline dark:text-primary-400"
                    >
                        Descarregar {{ basename($video) }}
                    </a>
                </div>
            @endforeach
        </div>
    @endif

    @if (count($fotos) > 0)
        <div class="space-y-3">
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Fotografias</h3>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                @foreach ($fotos as $foto)
                    <a href="{{ \App\Models\DailyRecord::getStorageUrl($foto) }}" target="_blank" rel="noopener">
                        <img
                            src="{{ \App\Models\DailyRecord::getStorageUrl($foto) }}"
                            alt="Evidência fotográfica"
                            class="h-32 w-full rounded-lg object-cover"
                        >
                    </a>
                @endforeach
            </div>
        </div>
    @endif
</div>
