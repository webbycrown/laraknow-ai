{{--
┌─────────────────────────────────────────────────────────────────────
│  LaraKnow Chat Component  —  resources/views/partials/lara-chat.blade.php
│
│  MODE 1 — Widget (floating bottom-right)
│    @include('partials.lara-chat', ['mode' => 'widget'])
│
│  MODE 2 — Fullpage (centered card in viewport)
│    @include('partials.lara-chat', ['mode' => 'fullpage'])
│
│  Default is 'widget' if $mode is not passed.
│  Assets are loaded from public/vendor/laraknow when this component renders.
└─────────────────────────────────────────────────────────────────────
--}}

@php
    try {
@endphp

@php
    $assetBase = trim((string) config('laraknow.asset_path', 'vendor/laraknow'), '/');
    $routeNamePrefix = trim((string) config('laraknow.route_name_prefix', 'laraknow-ai')) ?: 'laraknow-ai';
    $layoutCssAsset = $assetBase . '/css/layout.css';
    $chatCssAsset = $assetBase . '/css/laraknow.css';
    $chatJsAsset = $assetBase . '/js/laraknow.js';
    $assetPaths = [$layoutCssAsset, $chatCssAsset, $chatJsAsset];
    $missingAssets = array_values(array_filter($assetPaths, fn ($path) => ! file_exists(public_path($path))));

    if (! empty($missingAssets)) {
        \Illuminate\Support\Facades\Log::warning('LaraKnow AI assets are not published or cannot be found.', [
            'missing_assets' => $missingAssets,
            'expected_public_path' => public_path($assetBase),
            'publish_command' => 'php artisan vendor:publish --tag=laraknow-assets',
        ]);
    }

    $authUser = Auth::user();

    // Predict username from available fields
    $userName =
    $authUser->name ??
    (
        isset($authUser->fname, $authUser->lname)
            ? trim($authUser->fname . ' ' . $authUser->lname)
            : (
                isset($authUser->first_name, $authUser->last_name)
                    ? trim($authUser->first_name . ' ' . $authUser->last_name)
                    : (
                        $authUser->fname ??
                        $authUser->lname ??
                        $authUser->first_name ??
                        (
                            isset($authUser->email)
                                ? ucfirst(explode('@', $authUser->email)[0])
                                : 'User'
                        )
                    )
            )
    );

$userSlug =
    isset($authUser->first_name, $authUser->last_name)
        ? strtolower($authUser->first_name) . strtolower($authUser->last_name)
        : strtolower(str_replace(' ', '', $userName));

    $userEmail = $authUser->email ?? '';

    $userInitial = strtoupper(substr($userName, 0, 1));

    $chatMode = $mode ?? 'widget';

    try {
        $uiConfig = (array) config('laraknow.ui', []);
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::warning('LaraKnow AI UI config could not be loaded.', [
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);

        $uiConfig = [];
    }

    $uiValue = function (string $key, mixed $default = null) use ($uiConfig) {
        try {
            $value = data_get($uiConfig, $key, $default);

            return $value === null || $value === '' ? $default : $value;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('LaraKnow AI UI config value could not be resolved.', [
                'key' => $key,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return $default;
        }
    };

    $safeText = fn (string $key, string $default) => (string) $uiValue($key, $default);
    $theme = strtolower((string) $uiValue('theme', 'light'));
    $theme = in_array($theme, ['light', 'dark', 'auto', 'custom'], true) ? $theme : 'light';

    $botName = $safeText('brand.name', 'LaraKnow');
    $botSubtitle = $safeText('brand.subtitle', 'Always here to help');
    $statusText = $safeText('brand.status_text', 'Online');
    $statusDetail = $safeText('brand.status_detail', 'Always here to help');
    $botInitial = strtoupper(substr($safeText('brand.bot_initial', 'A'), 0, 2)) ?: 'A';
    $botAvatar = $uiValue('brand.bot_avatar');
    $userAvatar = $uiValue('brand.user_avatar');

    $label = fn (string $key, string $default) => $safeText('labels.' . $key, $default);
    $message = fn (string $key, string $default) => $safeText('messages.' . $key, $default);
    $iconName = fn (string $key, string $default) => strtolower((string) $uiValue('icons.' . $key, $default));

    $replaceMessageTokens = function (string $template) use ($userName, $botName) {
        $message = strtr($template, [
            ':user' => e($userName),
            ':bot' => e($botName),
        ]);

        return strip_tags($message, '<strong><b><em><br>');
    };

    $welcomeWidget = $replaceMessageTokens($message('welcome_widget', "Hello <strong>:user</strong>! I'm <strong>:bot</strong><br>How can I help you today?"));
    $welcomeFullpage = $replaceMessageTokens($message('welcome_fullpage', "Hello <strong>:user</strong>! I'm <strong>:bot</strong><br>I'm here to help. What would you like to explore today?"));

    $iconSvg = function (?string $name): string {
        return match ($name) {
            'sparkles' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.25 8.25 18 9.25l-.25-1a2 2 0 0 0-1-1L15.75 7l1-.25a2 2 0 0 0 1-1l.25-1 .25 1a2 2 0 0 0 1 1l1 .25-1 .25a2 2 0 0 0-1 1l.25-1 .25 1a2 2 0 0 0 1 1l1 .25-1 .25a2 2 0 0 0-1 1Z" /></svg>',
            'support' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M18 10a6 6 0 0 0-12 0v4a3 3 0 0 0 3 3h1v-6H7v-1a5 5 0 0 1 10 0v1h-3v6h1a3 3 0 0 0 3-3v-4ZM15 19c-.9 1.2-2.1 1.8-3.5 1.8" /></svg>',
            'send' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M22 2 11 13M22 2 15 22l-4-9-9-4 20-7z" /></svg>',
            'plus' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" d="M12 5v14M5 12h14" /></svg>',
            'x' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" d="M6 18 18 6M6 6l12 12" /></svg>',
            'menu' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16" /></svg>',
            default => '<svg viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm3.75 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm3.75 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0ZM21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z" /></svg>',
        };
    };

    $renderIcon = function (?string $value, string $default, string $classes = '') use ($iconSvg) {
        $value = trim((string) ($value ?? '')) ?: $default;

        if (preg_match('/^\s*</', $value)) {
            return $value;
        }

        if (preg_match('/^(?:https?:\/\/|\/|data:image\/)/i', $value)) {
            $classAttr = $classes !== '' ? ' class="'.e($classes).'"' : '';

            return '<img'.$classAttr.' src="'.e($value).'" alt="brand_icon" class="ac-sidebar-brand-icon" />';
        }

        $icon = $iconSvg($value);

        if ($classes !== '') {
            return preg_replace('/<svg(\s+)/', '<svg class="'.e($classes).'" $1', $icon, 1) ?: $icon;
        }

        return $icon;
    };

    $cssValue = function (mixed $value): ?string {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '' || preg_match('/[;{}<>]/', $value)) {
            return null;
        }

        return $value;
    };

    $cssKeys = [
        'primary' => '--ac-accent',
        'primary_hover' => '--ac-accent-hover',
        'primary_soft' => '--ac-accent-soft',
        'primary_light' => '--ac-accent-light',
        'page_bg' => '--ac-bg-page',
        'panel_bg' => '--ac-bg-panel',
        'sidebar_bg' => '--ac-bg-sidebar',
        'mist_bg' => '--ac-bg-mist',
        'bot_bubble_bg' => '--ac-bubble-bot',
        'user_bubble_bg' => '--ac-bubble-user',
        'text' => '--ac-text',
        'muted_text' => '--ac-text-muted',
        'light_text' => '--ac-text-light',
        'border' => '--ac-border',
        'online' => '--ac-online',
    ];

    $buildVars = function (string $group) use ($cssKeys, $cssValue, $uiValue) {
        $vars = [];

        foreach ($cssKeys as $key => $cssVar) {
            $value = $cssValue($uiValue($group . '.' . $key));

            if ($value !== null) {
                $vars[$cssVar] = $value;
            }
        }

        return $vars;
    };

    $lightVars = $buildVars('colors');
    $darkVars = $buildVars('dark_colors');
    $activeVars = $theme === 'dark' ? array_replace($lightVars, $darkVars) : $lightVars;

    foreach ([
        '--ac-widget-width' => $uiValue('layout.widget_width'),
        '--ac-widget-height' => $uiValue('layout.widget_height'),
        '--ac-widget-offset-x' => $uiValue('layout.widget_offset_x'),
        '--ac-widget-offset-y' => $uiValue('layout.widget_offset_y'),
        '--ac-fab-size' => $uiValue('layout.fab_size'),
        '--ac-radius-xl' => $uiValue('layout.border_radius'),
        '--ac-font' => $uiValue('layout.font_family'),
        '--ac-font-display' => $uiValue('layout.font_display'),
        '--ac-z-index' => $uiValue('layout.z_index'),
    ] as $cssVar => $value) {
        $value = $cssValue($value);

        if ($value !== null) {
            $activeVars[$cssVar] = $value;
        }
    }

    $position = strtolower((string) $uiValue('layout.position', 'bottom-right'));
    $position = in_array($position, ['bottom-right', 'bottom-left'], true) ? $position : 'bottom-right';
    $instanceId = 'laraknow-' . substr(md5((string) spl_object_id(app()) . $chatMode . $botName), 0, 10);
    $styleAttr = implode('; ', array_map(fn ($key, $value) => $key . ': ' . $value, array_keys($activeVars), $activeVars));
    $rootAttributes = 'data-laraknow-instance="' . e($instanceId) . '" data-laraknow-theme="' . e($theme) . '" data-laraknow-position="' . e($position) . '" style="' . e($styleAttr) . '"';
    $fabOpenIcon = $renderIcon($uiValue('icons.fab_open', 'chat'), 'chat', 'ac-fab-icon ac-fab-icon--chat');
    $fabCloseIcon = $renderIcon($uiValue('icons.fab_close', 'x'), 'x', 'ac-fab-icon ac-fab-icon--close');
    $closeIcon = $renderIcon($uiValue('icons.close', $uiValue('icons.fab_close', 'x')), 'x');
    $sendIcon = $renderIcon($uiValue('icons.send', 'send'), 'send');
    $newConversationIcon = $renderIcon($uiValue('icons.new_conversation', 'plus'), 'plus');
    $historyIcon = $renderIcon($uiValue('icons.history', 'menu'), 'menu');
    $botIcon = $renderIcon($uiValue('icons.bot', 'sparkles'), 'sparkles');
    $brandIcon = $renderIcon($uiValue('icons.brand', $uiValue('icons.bot', 'sparkles')), $uiValue('icons.bot', 'sparkles'));
    $jsLabels = [
        'openChat' => $label('open_chat', 'Open LaraKnow chat'),
        'closeChat' => $label('close_chat', 'Close chat'),
        'newConversation' => $label('new_conversation', 'New conversation'),
        'chatHistory' => $label('chat_history', 'Chat history'),
        'noSavedChats' => $label('no_saved_chats', 'No saved chats yet.'),
        'typing' => $label('typing', 'Assistant is typing'),
        'today' => $label('today', 'Today'),
        'untitledChat' => $label('untitled_chat', 'Untitled chat'),
        'justNow' => $label('just_now', 'Just now'),
        'deleteConversation' => $label('delete_conversation', 'Delete conversation'),
        'deleteConversationConfirm' => $label('delete_conversation_confirm', 'Delete?'),
        'deleteConversationTitle' => $label('delete_conversation_title', 'Delete this conversation?'),
        'deleteConversationMessage' => $label('delete_conversation_message', 'This will remove this conversation from your chat history.'),
        'cancel' => $label('cancel', 'Cancel'),
        'delete' => $label('delete', 'Delete'),
    ];
    $jsMessages = [
        'newConversationStarted' => $message('new_conversation_started', 'New conversation started! What would you like to explore?'),
        'emptyConversation' => $message('empty_conversation', 'This conversation has no saved messages yet.'),
        'errorFallback' => $message('error_fallback', 'Something went wrong. Please try again.'),
        'rateLimited' => $message('rate_limited', 'Something went wrong.Please try again in a moment or contact admin.'),
    ];
    $jsIcons = [
        'conversation' => $iconName('conversation', 'chat'),
    ];
@endphp

@once
    @if (file_exists(public_path($layoutCssAsset)))
        <link rel="stylesheet" href="{{ asset($layoutCssAsset) }}">
    @endif

    @if (file_exists(public_path($chatCssAsset)))
        <link rel="stylesheet" href="{{ asset($chatCssAsset) }}">
    @endif

    @if (! empty($missingAssets))
        <script>
            console.warn('LaraKnow AI assets are missing. Run: php artisan vendor:publish --tag=laraknow-assets', @json($missingAssets));
        </script>
    @endif
@endonce

@if ($theme === 'auto' && ! empty($darkVars))
    <style>
        @media (prefers-color-scheme: dark) {
            [data-laraknow-instance="{{ $instanceId }}"] {
                @foreach ($darkVars as $cssVar => $value)
                    {{ $cssVar }}: {{ $value }};
                @endforeach
            }
        }
    </style>
@endif

{{-- ── Pass config to JS ── --}}
<script>
    window.LaraChatConfig = {
        mode: '{{ $chatMode }}',
        userInitial: @json($userInitial),
        userName: @json($userName),
        botInitial: @json($botInitial),
        botName: @json($botName),
        botAvatar: @json($botAvatar),
        userAvatar: @json($userAvatar),
        labels: @json($jsLabels),
        messages: @json($jsMessages),
        icons: @json($jsIcons),
        chatEndpoint: '{{ route($routeNamePrefix.'.chat') }}',
        conversationsEndpoint: '{{ route($routeNamePrefix.'.conversations') }}',
        createConversationEndpoint: '{{ route($routeNamePrefix.'.conversations.create') }}',
        conversationMessagesEndpoint: '{{ route($routeNamePrefix.'.conversations.messages', ['conversation' => '__CONVERSATION_ID__']) }}',
        deleteConversationEndpoint: '{{ route($routeNamePrefix.'.conversations.delete', ['conversation' => '__CONVERSATION_ID__']) }}',
        showRawDataTable: @json((bool) data_get($uiConfig, 'responses.show_raw_data_table', false)),
        csrfToken: '{{ csrf_token() }}'
    };
</script>


{{-- ══════════════════════════════════════════════════════════════
     WIDGET MODE
══════════════════════════════════════════════════════════════ --}}
@if ($chatMode === 'widget')

    <div class="ac-fab-ring" id="acFabRing" aria-hidden="true" {!! $rootAttributes !!}></div>

    <button id="acFab" class="ac-fab" aria-label="{{ $label('open_chat', 'Open LaraKnow chat') }}" title="{{ $label('open_chat', 'Open LaraKnow chat') }}" {!! $rootAttributes !!}>
        {!! $fabOpenIcon !!}
        {!! $fabCloseIcon !!}
    </button>

    <div id="acWidget" class="ac-widget" role="dialog" aria-modal="true" aria-label="{{ $botName }} Chat" aria-hidden="true" {!! $rootAttributes !!}>

        {{-- Header --}}
        <div class="ac-widget__header">
            <div class="ac-widget__header-left">
                <div class="ac-widget__avatar">
                    @php
                        $brandConfigured = trim((string) ($uiValue('icons.brand') ?? '')) !== '';
                    @endphp

                    @if (is_string($botAvatar) && $botAvatar !== '')
                        <img src="{{ $botAvatar }}" alt="{{ $botName }}">
                    @elseif ($brandConfigured)
                        {{-- Prefer configured brand icon for widget avatar so widget and fullpage match --}}
                        {!! $brandIcon !!}
                    @else
                        {!! $botIcon !!}
                    @endif
                </div>
                <div>
                    <div class="ac-widget__name">{{ $botName }}</div>
                    <div class="ac-widget__status">
                        <span class="ac-widget__status-dot"></span>
                        {{ $statusText }} &middot; {{ $statusDetail }}
                    </div>
                </div>
            </div>
            <div class="ac-widget__header-actions">
                <button class="ac-icon-btn" id="acNewChat" title="{{ $label('new_conversation', 'New conversation') }}" aria-label="{{ $label('new_conversation', 'New conversation') }}">
                    {!! $newConversationIcon !!}
                </button>
                <button class="ac-icon-btn" id="acClose" title="{{ $label('close_chat', 'Close chat') }}" aria-label="{{ $label('close_chat', 'Close chat') }}">
                    {!! $closeIcon !!}
                </button>
            </div>
        </div>

        {{-- Messages --}}
        <div class="ac-messages" id="acMessages" role="log" aria-live="polite">
            <div class="ac-msg-row">
                <div class="ac-avatar ac-bot">
                    @if (is_string($botAvatar) && $botAvatar !== '')
                        <img src="{{ $botAvatar }}" alt="{{ $botName }}">
                    @else
                        {{ $botInitial }}
                    @endif
                </div>
                <div class="ac-bwrap">
                    <div class="ac-bubble ac-bot">
                        {!! $welcomeWidget !!}
                    </div>
                    <span class="ac-time" id="acGreetTime"></span>
                </div>
            </div>
        </div>

        {{-- Quick-reply chips --}}
        <div class="ac-widget__chips" id="acChips">
            @isset($chips)
                @foreach ($chips as $chip)
                    <button class="ac-chip" type="button">{{ $chip }}</button>
                @endforeach
            @endisset
        </div>

        {{-- Input --}}
        <div class="ac-input-bar">
            <div class="ac-input-wrap">
                <textarea id="acInput" class="ac-textarea" rows="1" placeholder="{{ $label('input_placeholder', 'Ask me anything...') }}"
                    aria-label="{{ $label('input_placeholder', 'Ask me anything...') }}"></textarea>
                <button class="ac-send-btn" id="acSend" aria-label="{{ $label('send', 'Send') }}">
                    {!! $sendIcon !!}
                </button>
            </div>
            <p class="ac-hint">{{ $label('disclaimer_widget', 'AI may make mistakes - verify important info.') }}</p>
        </div>

    </div>{{-- end .ac-widget --}}

@endif

@php
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error('LaraKnow AI Blade component failed; host page rendering will continue.', [
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);

        $payload = [
            'message' => 'LaraKnow AI component failed to render.',
            'error' => [
                'code' => 500,
                'type' => class_basename($e),
            ],
        ];
@endphp
        <script>
            console.error('LaraKnow AI component failed', @json($payload));
        </script>
@php
    }
@endphp

@once
    <script>
        (function () {
            var jqueryUrl = @json(config('laraknow.assets.jquery_url', 'https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js'));
            var laraknowUrl = @json(asset($chatJsAsset));
            var hasLaraKnowAsset = @json(file_exists(public_path($chatJsAsset)));

            function loadScript(src, attributes, callback) {
                var script = document.createElement('script');
                script.src = src;

                Object.keys(attributes || {}).forEach(function (key) {
                    script.setAttribute(key, attributes[key]);
                });

                script.onerror = function () {
                    console.error('LaraKnow AI could not load script:', src);
                };

                if (callback) {
                    script.onload = callback;
                }

                document.head.appendChild(script);
            }

            function loadLaraKnow() {
                if (!hasLaraKnowAsset) {
                    console.error('LaraKnow AI widget script is missing. Run: php artisan vendor:publish --tag=laraknow-assets');
                    return;
                }

                if (document.querySelector('script[data-laraknow-widget]')) {
                    return;
                }

                loadScript(laraknowUrl, { 'data-laraknow-widget': 'true' });
            }

            if (window.jQuery) {
                loadLaraKnow();
                return;
            }

            if (!jqueryUrl) {
                console.error('LaraKnow AI requires jQuery. Provide it in the host app or set laraknow.assets.jquery_url.');
                return;
            }

            loadScript(jqueryUrl, { 'data-laraknow-jquery': 'true' }, loadLaraKnow);
        })();
    </script>
@endonce

{{-- ══════════════════════════════════════════════════════════════
     FULLPAGE MODE
══════════════════════════════════════════════════════════════ --}}
@if ($chatMode === 'fullpage')

    <div class="ac-fullpage-wrapper" {!! $rootAttributes !!}>
        <div class="ac-overlay" id="acOverlay" aria-hidden="true"></div>
        <div class="ac-fullpage-shell">
            <aside class="ac-sidebar" id="acSidebar" aria-label="{{ $label('chat_history', 'Chat history') }}">
                <div class="ac-sidebar-header">
                    <div class="ac-sidebar-brand">
                        <span class="ac-sidebar-brand-icon" aria-hidden="true">
                            {!! $brandIcon !!}
                        </span>
                        <span class="ac-sidebar-brand-copy">
                            <span class="ac-sidebar-title">{{ $label('chat_history', 'Chat history') }}</span>
                            <span class="ac-sidebar-sub">{{ $label('chat_history_subtitle', 'Your saved conversations') }}</span>
                        </span>
                    </div>
                    <button class="ac-icon-btn" id="acNewConvBtn" title="{{ $label('new_conversation', 'New conversation') }}"
                        aria-label="{{ $label('new_conversation', 'New conversation') }}">
                        {!! $newConversationIcon !!}
                    </button>
                </div>
                <div class="ac-conv-list" id="acConvList">
                    <div class="ac-conv-empty">{{ $label('no_saved_chats', 'No saved chats yet.') }}</div>
                </div>
            </aside>

            <div class="ac-chat-area">

                {{-- Header --}}
                <div class="ac-chat-header">
                    <div class="ac-header-left">
                        <span class="ac-status-dot"></span>
                        <div>
                            <div class="ac-header-title">{{ $botName }}</div>
                            <div class="ac-header-sub">{{ $botSubtitle }}</div>
                        </div>
                    </div>
                    <div class="ac-header-actions">
                        <button class="ac-icon-btn ac-menu-toggle" id="acMenuToggle" title="{{ $label('chat_history', 'Chat history') }}"
                            aria-label="{{ $label('chat_history', 'Chat history') }}">
                            {!! $historyIcon !!}
                        </button>
                    </div>
                </div>

                {{-- Messages --}}
                <div class="ac-messages" id="acMessages" role="log" aria-live="polite">
                    <div class="ac-date-divider">{{ $label('today', 'Today') }}</div>
                    <div class="ac-msg-row">
                        <div class="ac-avatar ac-bot">
                            @if (is_string($botAvatar) && $botAvatar !== '')
                                <img src="{{ $botAvatar }}" alt="{{ $botName }}">
                            @else
                                {{ $botInitial }}
                            @endif
                        </div>
                        <div class="ac-bwrap">
                            <div class="ac-bubble ac-bot">
                                {!! $welcomeFullpage !!}
                            </div>
                            <span class="ac-time" id="acGreetTime"></span>
                        </div>
                    </div>
                </div>

                {{-- Input bar --}}
                <div class="ac-input-bar">
                    <div class="ac-chips-row" id="acChips">
                        @isset($chips)
                            @foreach ($chips as $chip)
                                <button class="ac-chip" type="button">{{ $chip }}</button>
                            @endforeach
                        @endisset
                    </div>
                    <div class="ac-input-wrap">
                        <textarea id="acInput" class="ac-textarea" rows="1" placeholder="{{ $label('input_placeholder', 'Ask me anything...') }}"
                            aria-label="{{ $label('input_placeholder', 'Ask me anything...') }}"></textarea>
                        <button class="ac-send-btn" id="acSend" aria-label="{{ $label('send', 'Send') }}">
                            {!! $sendIcon !!}
                        </button>
                    </div>
                    <p class="ac-hint">{{ $label('disclaimer_fullpage', 'AI may make mistakes - always verify important info.') }}</p>
                </div>

            </div>{{-- end .ac-chat-area --}}
        </div>{{-- end .ac-fullpage-shell --}}
        <div class="ac-confirm-overlay" id="acDeleteOverlay" aria-hidden="true">
            <div class="ac-confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="acDeleteTitle">
                <div class="ac-confirm-title" id="acDeleteTitle">{{ $label('delete_conversation_title', 'Delete this conversation?') }}</div>
                <div class="ac-confirm-text">{{ $label('delete_conversation_message', 'This will remove this conversation from your chat history.') }}</div>
                <div class="ac-confirm-actions">
                    <button type="button" class="ac-confirm-btn ac-confirm-btn--ghost" id="acDeleteCancel">
                        {{ $label('cancel', 'Cancel') }}
                    </button>
                    <button type="button" class="ac-confirm-btn ac-confirm-btn--danger" id="acDeleteConfirm">
                        {{ $label('delete', 'Delete') }}
                    </button>
                </div>
            </div>
        </div>
    </div>{{-- end .ac-fullpage-wrapper --}}

@endif
