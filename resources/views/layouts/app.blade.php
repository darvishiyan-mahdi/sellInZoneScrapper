<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Multi Scraper')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <nav style="background-color: #1f2937; color: white; padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center;">
        <div style="font-size: 1.25rem; font-weight: 600;">Multi Scraper Panel</div>
        <div style="display: flex; gap: 2rem;">
            <a href="#" style="color: white; text-decoration: none;">Scrapers</a>
            <a href="#" style="color: white; text-decoration: none;">Products</a>
            <a href="#" style="color: white; text-decoration: none;">Settings</a>
        </div>
    </nav>
    <main style="max-width: 1200px; margin: 2rem auto; padding: 0 1rem;">
        @yield('content')
    </main>
</body>
</html>

