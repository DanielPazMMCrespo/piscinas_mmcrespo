<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - M.Marques Crespo</title>
    
    {{-- Fontes e GSAP vêm do bundle Vite: zero pedidos a terceiros no arranque. --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @livewireStyles
    
    <style>
        body, html {
            margin: 0;
            padding: 0;
            width: 100%;
            min-height: 100%;
            overflow-x: hidden;
            overflow-y: auto;
            background: #021a2f;
            font-family: 'Lato', sans-serif;
            color: #111;
        }

        .font-sans { font-family: 'Lato', sans-serif; }
        .font-logo-match { font-family: 'Montserrat', sans-serif; }

        /* Utility classes (Replacing Tailwind CDN for CSP compliance) */
        .flex { display: flex; }
        .flex-col { flex-direction: column; }
        .justify-center { justify-content: center; }
        .justify-between { justify-content: space-between; }
        .items-center { align-items: center; }
        .items-start { align-items: flex-start; }
        .w-full { width: 100%; }
        .h-full { height: 100%; }
        .h-screen { height: 100vh; }
        .relative { position: relative; }
        .absolute { position: absolute; }
        .fixed { position: fixed; }
        .inset-0 { top: 0; right: 0; bottom: 0; left: 0; }
        .z-10 { z-index: 10; }
        .z-20 { z-index: 20; }
        .z-50 { z-index: 50; }
        .overflow-hidden { overflow: hidden; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .font-bold { font-weight: 700; }
        .font-extrabold { font-weight: 800; }
        .uppercase { text-transform: uppercase; }
        .text-white { color: #ffffff; }
        .text-cyan-400 { color: #22d3ee; }
        .text-gray-400 { color: #9ca3af; }
        .text-gray-500 { color: #6b7280; }
        .text-red-500 { color: #ef4444; }
        .bg-white { background-color: #ffffff; }
        .bg-black-10 { background-color: rgba(0, 0, 0, 0.1); }

        /* Premium Abstract Animated Gradient with Logo Colors */
        .premium-gradient {
            background: linear-gradient(-45deg, #021a2f, #004c8c, #0081c9, #021a2f);
            background-size: 400% 400%;
            animation: gradientBG 25s ease infinite;
            position: absolute;
            inset: 0;
            z-index: 1;
        }

        @keyframes gradientBG {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        /* SVG Noise Filter for texture */
        .noise-overlay {
            position: absolute;
            inset: 0;
            z-index: 2;
            opacity: 0.35;
            mix-blend-mode: overlay;
            pointer-events: none;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noiseFilter'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noiseFilter)'/%3E%3C/svg%3E");
        }

        /* Floating Label Inputs */
        .input-group {
            position: relative;
            margin-bottom: 2rem;
        }
        
        .premium-input {
            width: 100%;
            background: transparent;
            border: none;
            border-bottom: 1px solid rgba(0,0,0,0.15);
            padding: 12px 0 8px 0;
            font-size: 1.1rem;
            color: #111;
            box-sizing: border-box;
            transition: all 0.4s ease;
        }
        
        .premium-input:focus {
            outline: none;
            border-bottom-color: #004c8c;
        }

        .premium-label {
            position: absolute;
            top: 14px;
            left: 0;
            color: rgba(0,0,0,0.4);
            transition: all 0.4s ease;
            pointer-events: none;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            font-size: 0.75rem;
        }

        .premium-input:focus ~ .premium-label,
        .premium-input:not(:placeholder-shown) ~ .premium-label {
            top: -12px;
            font-size: 0.65rem;
            color: #004c8c;
            font-weight: 700;
        }

        /* Premium Button */
        .btn-premium {
            position: relative;
            overflow: hidden;
            background: #004c8c;
            color: #fff;
            border: none;
            border-radius: 4px;
            padding: 16px;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.2em;
            font-weight: 700;
            cursor: pointer;
            width: 100%;
            transition: all 0.4s ease;
        }
        
        .btn-premium:hover {
            background: #003b6d;
            box-shadow: 0 10px 25px -5px rgba(0, 76, 140, 0.4);
        }
        
        .btn-premium:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }

        /* Overlay loader */
        #loader {
            position: fixed;
            inset: 0;
            background: #021a2f;
            z-index: 50;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }

        #loader-logo {
            opacity: 0;
            height: 120px;
            width: auto;
            object-fit: contain;
        }

        /* Responsive Layout */
        .left-panel {
            width: 55%;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            position: relative;
            overflow: hidden;
        }

        .right-panel {
            width: 45%;
            min-height: 100vh;
            background: #fff;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 3rem 4rem 2rem 4rem;
            box-sizing: border-box;
            position: relative;
        }

        @media (max-width: 1024px) {
            .left-panel { display: none; }
            .right-panel {
                width: 100%;
                min-height: 100vh;
                padding: 2.5rem 1.75rem 1.5rem 1.75rem;
                justify-content: space-between;
            }
        }
    </style>
</head>
<body>

    {{ $slot }}

    @livewireScripts
</body>
</html>
