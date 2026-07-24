<div>
    <!-- Preloader -->
    <div id="loader">
        <img src="{{ asset('images/logo_mmcrespo_branco.png') }}" id="loader-logo" alt="Logo" class="h-28 md:h-36 w-auto opacity-0 object-contain">
    </div>

    <div class="flex w-full h-screen relative" id="main-content">
        
        <!-- Left Side: Abstract Cinematic Visuals -->
        <div class="hidden lg:flex flex-col justify-center items-center w-[55%] h-full relative overflow-hidden" id="visual-panel">
            
            <div class="premium-gradient"></div>
            <div class="orb orb-1"></div>
            <div class="orb orb-2"></div>
            <div class="noise-overlay"></div>
            <div class="absolute inset-0 z-10 bg-black/10"></div>
            
            <!-- Logo Area (Centered) -->
            <div class="relative z-20 text-center flex flex-col items-center justify-center p-12 logo-container">
                <!-- Logotipo Branco para Fundo Escuro -->
                <img src="{{ asset('images/logo_mmcrespo_branco.png') }}" alt="M.Marques Crespo Logo" class="h-28 w-auto mb-8 object-contain drop-shadow-2xl hover:scale-105 transition-transform duration-700">
                
                <h2 class="font-logo-match text-4xl md:text-5xl leading-tight text-white mb-6 font-bold">Manutenção de <br><span class="text-cyan-400 font-extrabold">Piscinas.</span></h2>
                <p class="font-sans text-xs uppercase tracking-[0.2em] text-white/70 font-bold max-w-[300px]">Plataforma exclusiva para administração e controlo de qualidade.</p>
            </div>
            
            <!-- Decorative corner elements -->
            <div class="absolute top-12 left-12 z-20 text-white/40">
                <span class="font-mono text-xs tracking-widest">[ AUTH.01 ]</span>
            </div>
            <div class="absolute bottom-12 right-12 z-20 text-white/40 text-right">
                <span class="font-sans text-[10px] uppercase tracking-[0.15em] font-bold block opacity-70">Desenvolvido por</span>
                <span class="font-logo-match text-sm tracking-widest mt-1 block font-bold">M.Marques Crespo&reg;</span>
            </div>
        </div>

        <!-- Right Side: The Form -->
        <div class="w-full lg:w-[45%] h-full bg-white flex flex-col justify-center px-10 sm:px-20 xl:px-32 relative" id="form-panel">
            
            <div class="max-w-md w-full mx-auto lg:mx-0">
                <div class="mb-14 form-element">
                    <!-- Mobile Logo (Logotipo Cor para Fundo Branco) -->
                    <img src="{{ asset('images/logo-mmcrespo.png') }}" alt="M.Marques Crespo Logo" class="h-16 w-auto mb-10 block lg:hidden object-contain">

                    <span class="inline-block w-12 h-1 bg-[#004c8c] mb-6 rounded-full"></span>
                    <h1 class="font-logo-match text-4xl sm:text-5xl text-[#111] mb-4 font-bold">Bem-vindo.</h1>
                    <p class="text-gray-500 font-sans text-sm tracking-wide">Inicie a sessão no painel administrativo.</p>
                </div>

                <form wire:submit="authenticate">
                    <div class="input-group form-element mb-6">
                        <input type="email" id="email" wire:model="data.email" class="premium-input font-sans @if(filled($data['email'] ?? '')) has-value @endif" placeholder=" " required>
                        <label for="email" class="premium-label font-sans">Endereço de Email</label>
                        @error('data.email')
                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    
                    <div class="input-group form-element mb-10">
                        <input type="password" id="password" wire:model="data.password" class="premium-input font-sans @if(filled($data['password'] ?? '')) has-value @endif" placeholder=" " required>
                        <label for="password" class="premium-label font-sans">Palavra-passe ou PIN</label>
                        @error('data.password')
                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex items-center justify-between mb-12 form-element">
                        <label class="flex items-center gap-2 cursor-pointer group">
                            <input type="checkbox" wire:model="data.remember" class="sr-only">
                            <div class="w-4 h-4 border border-gray-400 rounded-sm group-hover:border-[#004c8c] transition-colors flex items-center justify-center @if($data['remember'] ?? false) bg-[#004c8c] border-[#004c8c] @endif">
                                @if($data['remember'] ?? false)
                                <svg class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path></svg>
                                @endif
                            </div>
                            <span class="text-xs uppercase tracking-wider text-gray-500 group-hover:text-[#004c8c] transition-colors font-sans font-bold">Manter Sessão</span>
                        </label>
                        <a href="#" class="text-xs uppercase tracking-wider text-gray-500 hover:text-[#004c8c] transition-colors underline-offset-4 hover:underline font-sans font-bold">Recuperar</a>
                    </div>

                    <button type="submit" wire:loading.attr="disabled" class="btn-premium w-full py-4 text-sm uppercase tracking-[0.2em] font-bold form-element font-sans rounded-sm">
                        <span wire:loading.remove>Aceder ao Portal</span>
                        <span wire:loading>A Validar...</span>
                    </button>
                </form>
            </div>
            
            <!-- Mobile Footer -->
            <div class="absolute bottom-8 left-0 w-full text-center form-element lg:hidden">
                <p class="text-[10px] uppercase tracking-[0.2em] text-gray-400 font-bold font-sans">Desenvolvido por M.Marques Crespo&reg;</p>
            </div>
        </div>

    </div>

    <!-- Initialization Scripts for GSAP Animations -->
    <script>
        document.addEventListener("DOMContentLoaded", (event) => {
            const tl = gsap.timeline();

            // 1. Aparece o Logo isolado no centro
            tl.to("#loader-logo", { opacity: 1, y: -10, duration: 1, ease: "power2.out" })
              
              // 2. Fica um pequeno momento em pausa para brilhar, depois some
              .to("#loader-logo", { opacity: 0, y: -20, duration: 0.6, delay: 0.8, ease: "power2.in" })
              
              // 3. O fundo do loader sobe (slide up)
              .to("#loader", { height: 0, duration: 1, ease: "expo.inOut" })
              
              // 4. Começam as animações do resto da página
              .from("#visual-panel", { x: "-10%", opacity: 0, duration: 1.5, ease: "expo.out" }, "-=0.5")
              .from(".logo-container", { scale: 0.95, y: 20, opacity: 0, duration: 1.5, ease: "power3.out" }, "-=1")
              
              // 5. Elementos do formulário entram em cascata
              .from(".form-element", {
                  y: 30,
                  opacity: 0,
                  duration: 1,
                  stagger: 0.15,
                  ease: "power3.out"
              }, "-=1.2");
        });
    </script>
</div>
