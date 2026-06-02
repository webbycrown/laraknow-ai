{{--
|─────────────────────────────────────────────────────────────────
|  resources/views/welcome.blade.php
|  Example page that extends the master layout.
|  The LaraKnow widget is automatically included via the layout —
|  you don't need to add anything extra here.
|─────────────────────────────────────────────────────────────────
--}}
@extends('laraknow::laraknow-ai.layouts.app')

@section('title', 'Welcome — LaraKnow')

{{-- Optional: page-specific CSS --}}
@section('styles')
    <style>
        .demo-hero {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 2rem;
        }

        .demo-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--lara-bg-panel);
            border: 1px solid var(--lara-border);
            border-radius: 50px;
            padding: 8px 20px;
            font-size: 0.82rem;
            color: var(--lara-accent);
            margin-bottom: 2rem;
            box-shadow: var(--lara-shadow-sm);
        }

        .demo-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #7bc47e;
            animation: demoDotPulse 2s ease infinite;
        }

        @keyframes demoDotPulse {

            0%,
            100% {
                box-shadow: 0 0 0 0 rgba(123, 196, 126, 0.4);
            }

            50% {
                box-shadow: 0 0 0 6px rgba(123, 196, 126, 0);
            }
        }

        .demo-hero h1 {
            font-family: var(--lara-font-display);
            font-size: clamp(2rem, 5vw, 3.5rem);
            color: var(--lara-text);
            margin-bottom: 1rem;
        }

        .demo-hero p {
            font-size: 1rem;
            color: var(--lara-text-muted);
            max-width: 420px;
            line-height: 1.7;
        }

        .demo-auth-action {
            margin-top: 2rem;
        }

        .demo-auth-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 108px;
            border: 1px solid var(--lara-border);
            border-radius: 8px;
            background: var(--ac-accent);
            color: #fff;
            padding: 0.75rem 1.2rem;
            font-size: 0.92rem;
            font-weight: 600;
            line-height: 1;
            text-decoration: none;
            cursor: pointer;
            box-shadow: var(--lara-shadow-sm);
            transition: border-color 0.2s ease, color 0.2s ease, transform 0.2s ease;
        }

        .demo-auth-button:hover {
            border-color: var(--lara-accent);
            transform: translateY(-1px);
        }
    </style>
@endsection

@section('content')
    <div class="demo-hero">
        <div class="demo-badge">
            <span class="demo-dot"></span>
            LaraKnow is ready for you
        </div>

        <h1>Welcome To,<br> LaraKnow AI</h1>
        <p>
            A reusable Laravel AI assistant package with safe read-only database tools, conversation history, and a polished chat UI.
            Scroll down and try now.
        </p>
    </div>

    @include('laraknow::laraknow-ai.components.laraknow', ['mode' => 'fullpage']) {{-- default mode=>'widget' --}}
@endsection

{{-- Optional: page-specific scripts --}}
@section('scripts')
    <script>
        // Any page-specific JS goes here.
        // The widget is already initialised by the LaraKnow component.
        // console.log('Welcome page loaded.');
    </script>
@endsection
