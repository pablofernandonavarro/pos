<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Configuración' }} - {{ config('app.name') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="antialiased">
    {{ $slot }}

    {{-- Livewire 4 ya incluye Alpine: cargarlo desde un CDN lo duplicaba y hacía depender
         de internet la pantalla de instalación. Ver layouts/pos.blade.php. --}}
    @livewireScripts

    <style>
        [x-cloak] { display: none !important; }
    </style>
</body>
</html>
