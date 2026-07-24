<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - M.Marques Crespo</title>
    
    <!-- Tailwind CSS (via CDN for standalone layout sem dependencias do filament) -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800;900&family=Lato:wght@300;400;700&display=swap" rel="stylesheet">
    
    <!-- GSAP -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"></script>
    
    @livewireStyles
    
    <style>
        body, html {
            margin: 0;
            padding: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            background: #000;
        }

        .font-sans { font-family: 'Lato', sans-serif; }
        .font-logo-match { font-family: 'Montserrat', sans-serif; }

        /* Premium Abstract Animated Gradient with Logo Colors (Deep Blues/Cyans) */
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

        /* Abstract glowing orbs */
        .orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(80px);
            z-index: 1;
            opacity: 0.5;
            animation: float 20s infinite ease-in-out alternate;
        }
        
        .orb-1 { width: 400px; height: 400px; background: #5bc0be; top: -10%; left: -10%; animation-delay: 0s; }
        .orb-2 { width: 500px; height: 500px; background: #1c2541; bottom: -20%; right: -10%; animation-delay: -5s; }

        @keyframes float {
            0% { transform: translate(0, 0) scale(1); }
            100% { transform: translate(50px, 50px) scale(1.1); }
        }

        /* Floating Label Inputs */
        .input-group {
            position: relative;
            margin-bottom: 2.5rem;
        }
        
        .premium-input {
            width: 100%;
            background: transparent;
            border: none;
            border-bottom: 1px solid rgba(0,0,0,0.15);
            padding: 12px 0 8px 0;
            font-size: 1.1rem;
            color: #111;
            transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        }
        
        .premium-input:focus {
            outline: none;
            border-bottom-color: #004c8c;
        }

        .premium-label {
            position: absolute;
            top: 14px;
            left: 0;
            font-size: 1rem;
            color: rgba(0,0,0,0.4);
            transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            pointer-events: none;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            font-size: 0.75rem;
        }

        .premium-input:focus ~ .premium-label,
        .premium-input:not(:placeholder-shown) ~ .premium-label,
        .premium-input.has-value ~ .premium-label {
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
            transition: all 0.5s ease;
        }
        
        .btn-premium::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.15);
            transform: skewX(-15deg);
            transition: all 0.7s cubic-bezier(0.19, 1, 0.22, 1);
        }

        .btn-premium:hover::before {
            left: 100%;
        }
        
        .btn-premium:hover {
            box-shadow: 0 15px 30px -10px rgba(0, 76, 140, 0.4);
            transform: translateY(-2px);
            background: #003b6d;
        }
        
        .btn-premium:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
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
    </style>
</head>
<body class="font-sans antialiased text-gray-900">

    {{ $slot }}

    @livewireScripts
</body>
</html>
