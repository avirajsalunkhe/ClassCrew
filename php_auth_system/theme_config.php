<?php
// theme_config.php - Reads theme preference and generates CSS variables.

// Read theme setting from cookie (default to 'day')
$theme = $_COOKIE['theme'] ?? 'day';
?>
<style>
    /* THEME BASE CSS VARIABLES */
    :root {
        --bg-primary: #f8f8f8;          /* Light Background */
        --bg-secondary: #ffffff;        /* Card/Content Background */
        --text-color: #34495e;          /* Main Text/Headers */
        --border-color: #d3dce0;        /* Light Border */
        --header-bg: #e3e9ed;           /* Section Header Background (Light Blue/Gray) */
        --link-color: #007bff;          /* Primary Link/Action Color */
        --active-bg: #e5f1f8;           /* Hover/Active Background */
        --modal-bg: #fcfcfc;            /* Modal Background */
        --table-header-bg: #b0c4de;     /* Table Header Bar (phpMyAdmin style) */
    }

    /* NIGHT MODE OVERRIDE */
    html[data-theme='night'] {
        --bg-primary: #2c3e50;          /* Dark Background */
        --bg-secondary: #34495e;        /* Dark Card/Content Background */
        --text-color: #ecf0f1;          /* White Text */
        --border-color: #556080;        /* Darker Border */
        --header-bg: #455070;           /* Dark Header Background */
        --link-color: #2ecc71;          /* Green Link/Action Color */
        --active-bg: #405169;           /* Dark Hover/Active Background */
        --modal-bg: #2c3e50;
        --table-header-bg: #34495e;
    }

    /* GLOBAL BASE STYLES (Apply these to ensure consistency across pages) */
    body { 
        font-family: 'Consolas', monospace, 'Segoe UI', Tahoma, sans-serif; 
        padding: 15px; 
        background-color: var(--bg-primary); 
        color: var(--text-color);
        margin: 0;
        line-height: 1.4;
        font-size: 14px;
        transition: background-color 0.3s, color 0.3s;
    }
    .container {
        /* This selector is present on most content pages (profile, admin_dashboard, etc.) */
        background: var(--bg-secondary);
        border: 1px solid var(--border-color); 
        color: var(--text-color);
    }
    /* Add basic style for elements present in all pages */
    .header h1 { color: var(--text-color); }
    .header { border-bottom: 2px solid var(--border-color); }
    
</style>