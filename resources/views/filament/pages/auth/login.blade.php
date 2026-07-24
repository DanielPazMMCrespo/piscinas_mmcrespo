<div>
    <!-- Preloader -->
    <div id="loader">
        <img src="{{ asset('images/logo_mmcrespo_branco.png') }}" id="loader-logo" alt="Logo">
    </div>

    <!-- Main Content (Initially hidden via visibility to prevent iOS Safari/FaceID from triggering prematurely during preloader) -->
    <div class="flex w-full h-screen relative" id="main-content" style="visibility: hidden;">
        
        <!-- Left Side: Visuals -->
        <div class="left-panel" id="visual-panel">
            <div class="premium-gradient"></div>
            <div class="noise-overlay"></div>
            <div class="absolute inset-0 z-10 bg-black-10"></div>
            
            <div class="relative z-20 text-center flex flex-col items-center justify-center logo-container" style="padding: 3rem;">
                <img src="{{ asset('images/logo_mmcrespo_branco.png') }}" alt="M.Marques Crespo Logo" style="height: 100px; width: auto; margin-bottom: 2rem; object-fit: contain;">
                <h2 class="font-logo-match text-white font-bold" style="font-size: 2.5rem; line-height: 1.2; margin-bottom: 1.5rem;">Manutenção de <br><span class="text-cyan-400 font-extrabold">Piscinas.</span></h2>
                <p class="font-sans text-white uppercase font-bold" style="font-size: 0.75rem; letter-spacing: 0.2em; opacity: 0.7; max-width: 300px;">Plataforma exclusiva para administração e controlo de qualidade.</p>
            </div>
            
            <div class="absolute z-20 text-right" style="bottom: 3rem; right: 3rem;">
                <span class="font-sans text-white uppercase font-bold" style="font-size: 10px; letter-spacing: 0.15em; opacity: 0.7; display: block;">Desenvolvido por</span>
                <span class="font-logo-match text-white font-bold" style="font-size: 0.9rem; letter-spacing: 0.1em; margin-top: 0.25rem; display: block;">M.Marques Crespo&reg;</span>
            </div>
        </div>

        <!-- Right Side: The Form -->
        <div class="right-panel" id="form-panel">
            
            <!-- Vertically Centered Form Container -->
            <div style="flex: 1; display: flex; flex-direction: column; justify-content: center; max-width: 400px; width: 100%; margin: 0 auto;">
                
                <div class="form-element" style="margin-bottom: 2.5rem;">
                    <img src="{{ asset('images/logo-mmcrespo.png') }}" alt="Logo" style="height: 52px; width: auto; margin-bottom: 1.5rem; display: block;" class="mobile-logo">
                    <span style="display: inline-block; width: 44px; height: 4px; background: #004c8c; margin-bottom: 1.25rem; border-radius: 999px;"></span>
                    <h1 class="font-logo-match font-bold" style="font-size: 2.25rem; margin: 0 0 0.5rem 0; color: #111;">Bem-vindo.</h1>
                    <p class="text-gray-500 font-sans" style="font-size: 0.9rem; margin: 0;">Inicie a sessão no painel administrativo.</p>
                </div>

                <form wire:submit="authenticate">
                    <div class="input-group form-element" style="margin-bottom: 2rem;">
                        <input type="email" id="email" wire:model.defer="data.email" class="premium-input font-sans" placeholder=" " required autocomplete="email">
                        <label for="email" class="premium-label font-sans">Endereço de Email</label>
                        @error('data.email')
                            <p class="text-red-500 font-sans" style="font-size: 0.75rem; margin-top: 0.25rem;">{{ $message }}</p>
                        @enderror
                    </div>
                    
                    <div class="input-group form-element" style="margin-bottom: 2rem;">
                        <input type="password" id="password" wire:model.defer="data.password" class="premium-input font-sans" placeholder=" " required autocomplete="current-password">
                        <label for="password" class="premium-label font-sans">Palavra-passe ou PIN</label>
                        @error('data.password')
                            <p class="text-red-500 font-sans" style="font-size: 0.75rem; margin-top: 0.25rem;">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex items-center justify-between form-element" style="margin-bottom: 2.5rem;">
                        <label class="flex items-center" style="cursor: pointer; gap: 0.5rem;">
                            <input type="checkbox" wire:model="data.remember" style="accent-color: #004c8c; width: 16px; height: 16px;">
                            <span class="text-gray-500 font-sans font-bold uppercase" style="font-size: 0.75rem; letter-spacing: 0.1em;">Manter Sessão</span>
                        </label>
                    </div>

                    <button type="submit" wire:loading.attr="disabled" class="btn-premium form-element font-sans" style="background-color: #004c8c !important; color: #ffffff !important; opacity: 1 !important; display: block !important; visibility: visible !important;">
                        <span wire:loading.remove>Aceder ao Portal</span>
                        <span wire:loading>A Validar...</span>
                    </button>
                </form>

            </div>
            
            <!-- Mobile Footer pinned cleanly at the bottom -->
            <div class="form-element" style="text-align: center; padding-top: 1rem;">
                <p class="text-gray-400 font-sans font-bold uppercase" style="font-size: 10px; letter-spacing: 0.2em; margin: 0;">Desenvolvido por M.Marques Crespo&reg;</p>
            </div>
        </div>

    </div>

    <!-- Initialization Scripts for GSAP Animations -->
    @script
    <script>
        setTimeout(() => {
            if (typeof gsap !== 'undefined') {
                const tl = gsap.timeline();

                // 1. Aparece o Logo isolado no centro
                tl.to("#loader-logo", { opacity: 1, y: -10, duration: 0.6, ease: "power2.out" })
                  
                  // 2. Pausa curta e desaparece
                  .to("#loader-logo", { opacity: 0, y: -20, duration: 0.4, delay: 0.4, ease: "power2.in" })
                  
                  // 3. Revela o conteúdo da página exatamente quando o loader começa a subir (evita que o iOS/FaceID detete os inputs antes do tempo)
                  .set("#main-content", { visibility: "visible" })
                  .to("#loader", { height: 0, duration: 0.6, ease: "expo.inOut" })
                  
                  // 4. Animação dos elementos da página
                  .from("#visual-panel", { opacity: 0, duration: 0.8, ease: "expo.out", clearProps: "all" }, "-=0.2")
                  .from(".logo-container", { y: 20, opacity: 0, duration: 0.8, ease: "power3.out", clearProps: "all" }, "-=0.5")
                  .from(".form-element", {
                      y: 20,
                      opacity: 0,
                      duration: 0.6,
                      stagger: 0.08,
                      ease: "power3.out",
                      clearProps: "all"
                  }, "-=0.6");
            } else {
                // Fallback de segurança se o GSAP não estiver disponível
                document.getElementById('main-content').style.visibility = 'visible';
                const loader = document.getElementById('loader');
                if (loader) loader.style.display = 'none';
            }
        }, 50);
    </script>
    @endscript
</div>
