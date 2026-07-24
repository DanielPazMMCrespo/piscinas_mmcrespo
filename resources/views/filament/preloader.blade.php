<div id="global-loader" style="position: fixed; inset: 0; background: #021a2f; z-index: 999999; display: flex; flex-direction: column; justify-content: center; align-items: center; transition: opacity 0.6s ease-in-out;">
    <img src="{{ asset('images/logo_mmcrespo_branco.png') }}" id="global-loader-logo" alt="Logo" style="height: 120px; width: auto; object-fit: contain; opacity: 0; transform: translateY(20px); transition: all 0.8s cubic-bezier(0.16, 1, 0.3, 1);">
</div>

<script>
    // Cinematic Reveal (Logo appears, waits, then loader fades out)
    document.addEventListener('DOMContentLoaded', () => {
        const loader = document.getElementById('global-loader');
        const logo = document.getElementById('global-loader-logo');
        
        if (loader && logo) {
            // 1. Reveal logo
            setTimeout(() => {
                logo.style.opacity = '1';
                logo.style.transform = 'translateY(0)';
            }, 50);

            // 2. Hide logo slightly and fade out entire loader
            setTimeout(() => {
                logo.style.opacity = '0';
                logo.style.transform = 'translateY(-20px)';
                
                setTimeout(() => {
                    loader.style.opacity = '0';
                    setTimeout(() => {
                        loader.style.display = 'none';
                    }, 600); // Wait for transition to finish
                }, 300);
            }, 1200); // Show logo for 1.2s
        }
    });
</script>
