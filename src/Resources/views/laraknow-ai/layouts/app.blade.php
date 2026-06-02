<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', 'LaraKnow')</title>

    {{-- Page-specific styles --}}
    @yield('styles')
</head>

<body>

    {{-- ── Main page content ── --}}
    @yield('content')

    {{-- Page-specific scripts --}}
    @yield('scripts')

</body>

</html>
