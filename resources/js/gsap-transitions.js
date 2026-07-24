import { gsap } from "gsap";

// Make GSAP available globally (optional, but good if we need it in inline scripts)
window.gsap = gsap;

document.addEventListener('livewire:navigated', () => {
    // Premium staggered entrance for main sections, cards, and forms
    
    // Animate Topbar and Sidebar (subtle slide down/right)
    gsap.fromTo(".fi-topbar", 
        { y: -15, opacity: 0 },
        { y: 0, opacity: 1, duration: 0.5, ease: "power2.out", clearProps: "transform" }
    );
    
    // Mobile Bottom Nav pop-up
    gsap.fromTo("#mmc-bottom-nav", 
        { y: 20, opacity: 0, scale: 0.95 },
        { y: 0, opacity: 1, scale: 1, duration: 0.5, ease: "back.out(1.2)", delay: 0.1, clearProps: "transform,scale" }
    );

    // Staggered reveal for cards and sections in the main content area
    // Targets: Filament Sections, Dashboard Widgets, Resource Cards
    const contentElements = document.querySelectorAll('.fi-main .fi-section, .fi-main .fi-card, .fi-main .fi-wi-widget');
    
    if (contentElements.length > 0) {
        gsap.fromTo(contentElements, 
            { y: 20, opacity: 0 },
            { 
                y: 0, 
                opacity: 1, 
                duration: 0.6, 
                stagger: 0.05, 
                ease: "power2.out",
                clearProps: "all" // Prevent layout issues after animation
            }
        );
    }
    
    // Special reveal for table rows (if visible)
    const tableRows = document.querySelectorAll('.fi-ta-row');
    if (tableRows.length > 0 && tableRows.length < 50) { // Limit to avoid performance hit on huge tables
        gsap.fromTo(tableRows,
            { opacity: 0, x: -10 },
            {
                opacity: 1,
                x: 0,
                duration: 0.4,
                stagger: 0.02,
                ease: "power1.out",
                clearProps: "all"
            }
        );
    }
});
