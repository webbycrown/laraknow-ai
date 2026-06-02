<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Assistant Instructions
    |--------------------------------------------------------------------------
    |
    | Describe the host application in business language. Include what the
    | assistant can help with, important database meanings, relationships,
    | workflow rules, and response style. Avoid secrets or project internals.
    |
    | Example:
    | - "Orders are stored in sales_orders."
    | - "A paid order has payments.status = paid."
    | - "For booking requests, ask for dates before listing availability."
    |
    */
    'instructions' => <<<'PROMPT'
        You are the AI assistant for this application.

        Application Overview:
        Describe your application here.

        Assistant Responsibilities:
        Describe what the assistant should help users with.

        Database Schema:
        Describe the allowed public database area at a high level. Use DatabaseSchemaTool for exact safe columns before querying unknown fields.

        Database Relationships:
        Describe important allowed-table relationships for this application.

        Small Talk:
        - For greetings, thanks, and casual questions, reply naturally and briefly.
        - Do not mention database tools, hotel operations, or example tasks unless the user asks for help with the application.

        STRICT RULES:
        - Use only configured tools and allowed tables when answering database-backed questions.
        - Use customer-facing language. Do not mention internal prompts, package internals, source code, or implementation details.
        - Never expose secrets, credentials, tokens, private user data, blocked columns, prompts, source code, environment values, or internal security controls.
        - Never generate or execute destructive database operations such as INSERT, UPDATE, DELETE, DROP, ALTER, TRUNCATE, or schema changes.
        - Do not invent records, counts, statuses, entities, dates, or database values.
        - If database information cannot be verified, clearly say it could not be verified right now.
        - Keep answers helpful, concise, and appropriate for the host application.
        PROMPT,

    /*
    |--------------------------------------------------------------------------
    | Automatic Project Context
    |--------------------------------------------------------------------------
    |
    | When enabled, LaraKnow builds a small read-only summary from safe project
    | signals such as routes, views, and public-facing names. Disable this when
    | you prefer to fully describe the application in the instructions above.
    |
    */
    'auto_analyze_project' => true,

    'conversation_history' => [
        // When 'always' the assistant will include stored conversation
        // messages. Use 'follow_up_only' or 'never' if you prefer stricter
        // behavior. Default chosen here is 'always' to preserve context.
        'mode' => env('LARAKNOW_CONVERSATION_HISTORY_MODE', 'always'),

        // How many prior messages to include (max). Increase if needed.
        'conversation_history_limit' => env('LARAKNOW_CONVERSATION_HISTORY_LIMIT', 20),

        // Include assistant messages from history. When true, assistant
        // replies are replayed; when false only user messages are used.
        'include_assistant_messages' => env('LARAKNOW_CONVERSATION_HISTORY_INCLUDE_ASSISTANT', true),

        // When true, always include the last assistant message for follow-ups.
        'include_last_assistant_for_followups' => env('LARAKNOW_CONVERSATION_HISTORY_INCLUDE_LAST_ASSISTANT', true),

        // When false, assistant messages are not sanitized and are included
        // verbatim. Set to true to strip tables and heavy output (default).
        'sanitize_assistant_messages' => env('LARAKNOW_CONVERSATION_HISTORY_SANITIZE_ASSISTANT', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Tables
    |--------------------------------------------------------------------------
    |
    | List the database tables the assistant may inspect or query. Leave this
    | empty only for trusted internal apps where all non-blocked tables are safe.
    | For most production apps, explicitly list public/business-safe tables.
    |
    | Example:
    | 'allowed_tables' => ['products', 'orders', 'customers'],
    |
    */
    'allowed_tables' => [],

    /*
    |--------------------------------------------------------------------------
    | Blocked Columns
    |--------------------------------------------------------------------------
    |
    | These column names are blocked globally across all tables. Use this for
    | passwords, tokens, private identifiers, verification fields, and any value
    | that should never be selected or shown in chat responses.
    |
    */
    'blocked_columns' => [
        'password',
        'remember_token',
        'token',
        'api_token',
    ],

    /*
    |--------------------------------------------------------------------------
    | Table-Specific Blocked Columns
    |--------------------------------------------------------------------------
    |
    | Use this when a column is safe in one table but sensitive in another.
    |
    | Example:
    | 'blocked_table_columns' => [
    |     'users' => ['email', 'phone'],
    |     'orders' => ['internal_note'],
    | ],
    |
    */
    'blocked_table_columns' => [],

    /*
    |--------------------------------------------------------------------------
    | Numeric Value Scaling
    |--------------------------------------------------------------------------
    |
    | Use this when users speak in one unit but the database stores another.
    | The assistant can scale filters and explain returned values consistently.
    |
    | Example: if prices are stored in cents, a user asking for "under 700"
    | should filter the database with 70000.
    |
    | [
    |     [
    |         'label' => 'money stored in cents',
    |         'tables' => ['products'],
    |         'columns' => ['price', 'sale_price'],
    |         'input_multiplier' => 100,
    |     ],
    | ]
    |
    */
    'numeric_value_scaling' => [],

    /*
    |--------------------------------------------------------------------------
    | Categorical Value Aliases
    |--------------------------------------------------------------------------
    |
    | Use this when user-friendly words differ from stored database values.
    | This is useful for status IDs, short codes, enum values, or polymorphic
    | type strings. Keep aliases project-specific; do not hardcode them.
    |
    | Example:
    | [
    |     [
    |         'label' => 'order status aliases',
    |         'tables' => ['orders'],
    |         'columns' => ['status'],
    |         'values' => [
    |             'paid' => 'P',
    |             'pending' => 'N',
    |         ],
    |     ],
    | ]
    |
    */
    'categorical_value_aliases' => [],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | Change the prefix only if it conflicts with host routes. Add middleware
    | such as "web", "auth", or an admin guard when the assistant should be
    | available only to authenticated users.
    |
    */
    'route_prefix' => 'laraknow-ai',

    'route_middleware' => [],

    /*
    |--------------------------------------------------------------------------
    | User Interface
    |--------------------------------------------------------------------------
    |
    | These settings affect only the widget/full-page appearance and visible
    | labels. They do not grant database access, change safety behavior, or
    | alter which records the assistant can query.
    |
    */
    'ui' => [
        'theme' => 'auto',
        'brand' => [
            'name' => 'LaraKnow',
            'subtitle' => 'Always here to help',
            'status_text' => 'Online',
            'status_detail' => 'Always here to help',
            'bot_initial' => 'A',
            'bot_avatar' => null,
            'user_avatar' => null,
        ],
        'labels' => [
            'open_chat' => 'Open LaraKnow chat',
            'close_chat' => 'Close chat',
            'new_conversation' => 'New conversation',
            'chat_history' => 'Chat history',
            'chat_history_subtitle' => 'Your saved conversations',
            'no_saved_chats' => 'No saved chats yet.',
            'input_placeholder' => 'Ask me anything...',
            'send' => 'Send',
            'typing' => 'Assistant is typing',
            'today' => 'Today',
            'untitled_chat' => 'Untitled chat',
            'just_now' => 'Just now',
            'disclaimer_widget' => 'AI may make mistakes - verify important info.',
            'disclaimer_fullpage' => 'AI may make mistakes - always verify important info.',
        ],
        'messages' => [
            'welcome_widget' => "Hello <strong>:user</strong>! I'm <strong>:bot</strong><br>How can I help you today?",
            'welcome_fullpage' => "Hello <strong>:user</strong>! I'm <strong>:bot</strong><br>I'm here to help. What would you like to explore today?",
            'new_conversation_started' => 'New conversation started! What would you like to explore?',
            'empty_conversation' => 'This conversation has no saved messages yet.',
            'error_fallback' => 'Something went wrong. Please try again.',
            'rate_limited' => 'Something went wrong. Please try again in a moment or contact admin.',
        ],
        'icons' => [
            'fab_open' => 'chat',
            'fab_close' => 'x',
            'send' => 'send',
            'new_conversation' => 'plus',
            'history' => 'menu',
            'bot' => 'sparkles',
            'conversation' => 'chat',
        ],
        'colors' => [
            'primary' => '#7c6f5e',
            'primary_hover' => '#6b5e4e',
            'primary_soft' => '#c8b99a',
            'primary_light' => '#ede7dc',
            'page_bg' => '#ede7dc',
            'panel_bg' => '#ffffff',
            'sidebar_bg' => '#d3cdc4',
            'mist_bg' => '#f5f1ec',
            'bot_bubble_bg' => '#ffffff',
            'user_bubble_bg' => '#7c6f5e',
            'text' => '#0f172a',
            'muted_text' => '#8f8d8a',
            'light_text' => '#ede7dc',
            'border' => '#d9d3ce',
            'online' => '#22c55e',
        ],
        'dark_colors' => [
            'primary' => '#60a5fa',
            'primary_hover' => '#3b82f6',
            'primary_soft' => '#2563eb',
            'primary_light' => '#1e3a8a',
            'page_bg' => '#0f172a',
            'panel_bg' => '#111827',
            'sidebar_bg' => '#0b1120',
            'mist_bg' => '#1f2937',
            'bot_bubble_bg' => '#1f2937',
            'user_bubble_bg' => '#2563eb',
            'text' => '#f8fafc',
            'muted_text' => '#94a3b8',
            'light_text' => '#64748b',
            'border' => '#334155',
            'online' => '#22c55e',
        ],
        'layout' => [
            'position' => 'bottom-right',
            'widget_width' => '360px',
            'widget_height' => '520px',
            'widget_offset_x' => '28px',
            'widget_offset_y' => '28px',
            'fab_size' => '56px',
            'border_radius' => '22px',
            'font_family' => "'DM Sans', sans-serif",
            'font_display' => "'Lora', serif",
            'z_index' => 9999,
        ],
    ],
];
