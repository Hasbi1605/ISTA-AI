import defaultTheme from "tailwindcss/defaultTheme";
import forms from "@tailwindcss/forms";
import typography from "@tailwindcss/typography";

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: "class",
    content: [
        "./vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php",
        "./storage/framework/views/*.php",
        "./resources/views/**/*.blade.php",
    ],

    theme: {
        extend: {
            animation: {
                float: "float 6s ease-in-out infinite",
                "float-slow": "float 12s ease-in-out infinite",
                "float-reverse": "float-reverse 15s ease-in-out infinite",
                twinkle: "twinkle 4s ease-in-out infinite",
                breathe: "breathe 10s ease-in-out infinite",
                "enter-1": "fade-in-up 0.8s ease-out 0.1s both",
                "enter-2": "fade-in-up 0.8s ease-out 0.2s both",
                "enter-3": "fade-in-up 0.8s ease-out 0.3s both",
                "enter-4": "fade-in-up 0.8s ease-out 0.4s both",
            },
            keyframes: {
                float: {
                    "0%, 100%": { transform: "translateY(0)" },
                    "50%": { transform: "translateY(-20px)" },
                },
                "float-reverse": {
                    "0%, 100%": { transform: "translateY(0)" },
                    "50%": { transform: "translateY(20px)" },
                },
                twinkle: {
                    "0%, 100%": { opacity: "0.2", transform: "scale(0.8)" },
                    "50%": { opacity: "1", transform: "scale(1.2)" },
                },
                breathe: {
                    "0%, 100%": { transform: "scale(1)" },
                    "50%": { transform: "scale(1.05)" },
                },
                "fade-in-up": {
                    "0%": { opacity: "0", transform: "translateY(20px)" },
                    "100%": { opacity: "1", transform: "translateY(0)" },
                },
            },
            colors: {
                "surface-container-high": "#ece6dc",
                secondary: "#78706a",
                surface: "#faf5ee",
                "on-surface": "#3a302a",
                "inverse-on-surface": "#faf5ee",
                tertiary: "#8c3c3c",
                "secondary-container": "#eae2da",
                "surface-container-low": "#f6f0e8",
                "tertiary-fixed": "#fce0e0",
                "inverse-primary": "#f0a878",
                "tertiary-fixed-dim": "#e8a0a0",
                "primary-container": "#e08850",
                "on-primary-fixed-variant": "#8a4518",
                "surface-tint": "#c2652a",
                primary: "#c2652a",
                "surface-variant": "#ece6dc",
                "secondary-fixed-dim": "#cec6be",
                "on-secondary-fixed-variant": "#504840",
                "on-primary-container": "#fbe8d8",
                "primary-fixed-dim": "#f0a878",
                "surface-dim": "#dcd6cc",
                "surface-container-lowest": "#ffffff",
                "surface-bright": "#faf5ee",
                "on-secondary": "#ffffff",
                "inverse-surface": "#3a302a",
                "tertiary-container": "#d47070",
                "on-background": "#3a302a",
                background: "#faf5ee",
                "on-secondary-container": "#605850",
                "on-tertiary-fixed-variant": "#6e3030",
                outline: "#9a9088",
                "on-error": "#ffffff",
                "on-tertiary-fixed": "#2e1515",
                "on-error-container": "#7a1a10",
                "on-surface-variant": "#605850",
                "on-secondary-fixed": "#2a2420",
                "surface-container-highest": "#e6e0d6",
                "surface-container": "#f2ece4",
                "on-primary-fixed": "#401a08",
                "primary-fixed": "#fbe8d8",
                "on-tertiary": "#ffffff",
                "error-container": "#fce4e0",
                "secondary-fixed": "#eae2da",
                error: "#c0392b",
                "on-primary": "#ffffff",
                "outline-variant": "#d8d0c8",
                "on-tertiary-container": "#3a2020",
            },
            fontFamily: {
                sans: ["Inter", ...defaultTheme.fontFamily.sans],
                headline: ['"EB Garamond"', ...defaultTheme.fontFamily.sans],
                body: ["Inter", ...defaultTheme.fontFamily.sans],
                label: ["Inter", ...defaultTheme.fontFamily.sans],
            },
            boxShadow: {
                premium: "0 2px 16px rgba(58, 48, 42, 0.04)",
            },
        },
    },

    plugins: [forms, require("@tailwindcss/typography")],
};
