<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0b0c0e">

    {{-- The `inertia` attribute lets Inertia's <Head> replace this tag on
         navigation. Without it the page emits two <title> elements and the
         browser keeps this one, so every tab reads the app name instead of
         the page title. --}}
    <title inertia>{{ config('app.name', 'Up by Radiank') }}</title>
    @if(request()->routeIs('welcome'))
    <link rel="canonical" href="https://up.example.com/">
    @endif
    {{-- A page that ships its own `meta` prop (public status pages) gets it
         rendered here, so link previews and non-JS crawlers see it. Those tags
         carry `inertia` + head-key so the client <Head> replaces them instead
         of duplicating them. Other pages keep the global defaults. --}}
    @php
        $pageMeta = $page['props']['meta'] ?? null;
        $defaultDescription = 'Open-source uptime monitoring platform. Monitor your websites, APIs, and services with real-time alerts.';
        $metaTitle = isset($pageMeta, $page['props']['statusPage']['name']) ? $page['props']['statusPage']['name'].' status' : config('app.name', 'Up by Radiank');
        $metaDescription = $pageMeta['description'] ?? $defaultDescription;
        $metaUrl = $pageMeta['canonical'] ?? config('app.url');
    @endphp
    @if ($pageMeta)
    <meta inertia="description" name="description" content="{{ $metaDescription }}">
    <link inertia="canonical" rel="canonical" href="{{ $metaUrl }}">
    <meta inertia="og:title" property="og:title" content="{{ $metaTitle }}">
    <meta inertia="og:description" property="og:description" content="{{ $metaDescription }}">
    <meta inertia="og:type" property="og:type" content="website">
    <meta inertia="og:url" property="og:url" content="{{ $metaUrl }}">
    @else
    <meta name="description" content="{{ $metaDescription }}">

    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $metaTitle }}">
    <meta property="og:description" content="{{ $metaDescription }}">
    <meta property="og:url" content="{{ $metaUrl }}">
    @endif
    <meta property="og:image" content="{{ config('app.url') }}/icons/icon-512.png">

    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="{{ $metaTitle }}">
    <meta name="twitter:description" content="{{ $metaDescription }}">

    <link rel="icon" type="image/svg+xml" href="/icons/icon.svg">
    <link rel="icon" type="image/png" sizes="192x192" href="/icons/icon-192.png">
    <link rel="apple-touch-icon" href="/icons/icon-192.png">
    <link rel="manifest" href="/manifest.json">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">

    @routes
    @inertiaHead
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
</head>
<body class="antialiased">
    @inertia
</body>
</html>
