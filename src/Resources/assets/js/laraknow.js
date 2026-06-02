/**
 * lara-chat.js
 * Unified JS for Lara Chat — works in BOTH widget and fullpage modes.
 * Place in: public/js/lara-chat.js
 *
 * Reads: window.LaraChatConfig  (injected by lara-chat.blade.php)
 * Requires: jQuery
 */

(function ($) {
    'use strict';

    $(function () {

        /* ─── Config ─── */
        var C = window.LaraChatConfig || {};
        var MODE          = C.mode          || 'widget';
        var USER_INITIAL  = C.userInitial   || 'U';
        var BOT_INITIAL   = C.botInitial    || 'A';
        var BOT_AVATAR    = C.botAvatar     || '';
        var USER_AVATAR   = C.userAvatar    || '';
        var ENDPOINT      = C.chatEndpoint  || '/chat';
        var CONVERSATIONS_ENDPOINT = C.conversationsEndpoint || '';
        var CREATE_CONVERSATION_ENDPOINT = C.createConversationEndpoint || '';
        var CONVERSATION_MESSAGES_ENDPOINT = C.conversationMessagesEndpoint || '';
        var DELETE_CONVERSATION_ENDPOINT = C.deleteConversationEndpoint || '';
        var CSRF          = C.csrfToken     || $('meta[name="csrf-token"]').attr('content') || '';
        var LABELS        = C.labels        || {};
        var MESSAGES      = C.messages      || {};
        var ICONS         = C.icons         || {};
        var SHOW_RAW_DATA_TABLE = C.showRawDataTable === true;

        function label(key, fallback) {
            return LABELS && LABELS[key] ? String(LABELS[key]) : fallback;
        }

        function message(key, fallback) {
            return MESSAGES && MESSAGES[key] ? String(MESSAGES[key]) : fallback;
        }

        /* ─── Shared state ─── */
        var conversationId    = localStorage.getItem('lara_conv_id') || null;
        var resetConversation = false;
        var isOpen            = false;          // widget mode only
        var deletePendingId   = null;

        /* ─── DOM refs (IDs are the same in both modes) ─── */
        var $messages  = $('#acMessages');
        var $input     = $('#acInput');
        var $sendBtn   = $('#acSend');
        var $chips     = $('#acChips');
        var $newChat   = $('#acNewChat');
        var $deleteOverlay = $('#acDeleteOverlay');
        var $deleteConfirm = $('#acDeleteConfirm');
        var $deleteCancel = $('#acDeleteCancel');

        /* Set greeting time */
        $('#acGreetTime').text(getTime());

        $deleteCancel.on('click', closeDeleteModal);
        $deleteOverlay.on('click', function (event) {
            if (event.target === this) closeDeleteModal();
        });
        $deleteConfirm.on('click', confirmDeleteConversation);

        $(document).on('keydown', function (event) {
            if (event.key === 'Escape') {
                closeDeleteModal();
                resetInlineDeleteState();
            }
        });

        /* ════════════════════════════════════════════════════
           WIDGET-ONLY: FAB toggle logic
        ════════════════════════════════════════════════════ */
        if (MODE === 'widget') {
            var $fab     = $('#acFab');
            var $fabRing = $('#acFabRing');
            var $widget  = $('#acWidget');
            var $close   = $('#acClose');

            /**
             * Open the floating chat widget.
             *
             * @return {void}
             */
            function openWidget() {
                isOpen = true;
                $widget.addClass('is-open').attr('aria-hidden', 'false');
                $fab.addClass('is-open').attr('aria-label', label('closeChat', 'Close chat'));
                $fabRing.hide();
                scrollBottom();
                setTimeout(function () { $input.focus(); }, 260);
            }

            /**
             * Close the floating chat widget.
             *
             * @return {void}
             */
            function closeWidget() {
                isOpen = false;
                $widget.removeClass('is-open').attr('aria-hidden', 'true');
                $fab.removeClass('is-open').attr('aria-label', label('openChat', 'Open LaraKnow chat'));
                $fabRing.show();
            }

            $fab.on('click', function () { isOpen ? closeWidget() : openWidget(); });
            $close.on('click', closeWidget);

            $(document).on('keydown', function (e) {
                if (e.key === 'Escape' && isOpen) closeWidget();
            });
        }

        /* ════════════════════════════════════════════════════
           FULLPAGE-ONLY: Sidebar toggle
        ════════════════════════════════════════════════════ */
        if (MODE === 'fullpage') {
            var $sidebar = $('#acSidebar');
            var $overlay = $('#acOverlay');
            var $menuBtn = $('#acMenuToggle');

            $menuBtn.on('click', function () {
                $sidebar.addClass('open');
                $overlay.show();
            });

            $overlay.on('click', function () {
                $sidebar.removeClass('open');
                $overlay.hide();
            });

            /* New conversation button in sidebar */
            $('#acNewConvBtn').on('click', startNewChat);

            loadConversationHistory();
        }

        /* ════════════════════════════════════════════════════
           NEW CHAT (shared)
        ════════════════════════════════════════════════════ */
        $newChat.on('click', startNewChat);

        /**
         * Reset local conversation state and prepare the UI for a new chat.
         *
         * @return {void}
         */
        function startNewChat() {
            conversationId    = null;
            resetConversation = true;
            localStorage.removeItem('lara_conv_id');

            $messages.html(buildMsg('bot', escHtml(message('newConversationStarted', 'New conversation started! What would you like to explore?'))));
            $('#acConvList .ac-conv-item').removeClass('active');
            $chips.show();
            $input.focus();

            /* Fullpage: add today divider */
            if (MODE === 'fullpage') {
                $messages.prepend('<div class="ac-date-divider">' + escHtml(label('today', 'Today')) + '</div>');
            }

            createBlankConversation();
        }

        /**
         * Create a persistent blank conversation when the plus button is used.
         *
         * @return {void}
         */
        function createBlankConversation() {
            if (!CREATE_CONVERSATION_ENDPOINT) return;

            $.ajax({
                url: CREATE_CONVERSATION_ENDPOINT,
                method: 'POST',
                contentType: 'application/json',
                headers: { 'X-CSRF-TOKEN': CSRF },
                data: JSON.stringify({ title: label('newConversation', 'New conversation') }),
                success: function (res) {
                    var conversation = res && res.conversation ? res.conversation : null;
                    var id = (res && res.conversation_id) || (conversation && conversation.id);

                    if (!id) return;

                    conversationId = id;
                    resetConversation = false;
                    localStorage.setItem('lara_conv_id', conversationId);

                    if (MODE === 'fullpage') {
                        addConvToSidebar(conversationId, (conversation && conversation.title) || label('newConversation', 'New conversation'));
                    }
                }
            });
        }

        /* ════════════════════════════════════════════════════
           SUGGESTION CHIPS (shared)
        ════════════════════════════════════════════════════ */
        $chips.on('click', '.ac-chip', function () {
            var text = $(this).text().trim().replace(/^[^\s]+\s/, '');
            $input.val(text);
            autoResize($input[0]);
            $input.focus();
            $chips.hide();
        });

        /* ════════════════════════════════════════════════════
           INPUT (shared)
        ════════════════════════════════════════════════════ */
        $input.on('input', function () { autoResize(this); });

        $input.on('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });

        $sendBtn.on('click', sendMessage);

        /* ════════════════════════════════════════════════════
           SEND MESSAGE (shared)
        ════════════════════════════════════════════════════ */
        /**
         * Send the current input value to the configured chat endpoint.
         *
         * @return {void}
         */
        function sendMessage() {
            var text = $input.val().trim();
            if (!text) return;

            $messages.append(buildMsg('user', escHtml(text)));
            scrollBottom();
            $input.val('').css('height', 'auto');
            $chips.hide();
            showTyping();

            $.ajax({
                url:         ENDPOINT,
                method:      'POST',
                contentType: 'application/json',
                headers:     { 'X-CSRF-TOKEN': CSRF },
                data: JSON.stringify({
                    prompt:             text,
                    conversation_id:    conversationId,
                    reset_conversation: resetConversation
                }),
                success: function (res) {
                    removeTyping();
                    resetConversation = false;

                    if (res && res.conversation_id) {
                        conversationId = res.conversation_id;
                        localStorage.setItem('lara_conv_id', conversationId);

                        /* Fullpage: add entry to sidebar conv list */
                        if (MODE === 'fullpage') {
                            addConvToSidebar(conversationId, text);
                            $('#acConvList .ac-conv-item[data-conv-id="' + conversationId + '"]').addClass('active');
                        }
                    }

                    var reply = formatAssistantReply((res && res.reply) ? res.reply : message('errorFallback', 'Something went wrong. Please try again.'));

                    if (SHOW_RAW_DATA_TABLE && res && Array.isArray(res.data) && res.data.length) {
                        reply += renderDataRows(res.data);
                    }

                    if (res && res.suggestion) {
                        reply += '<br><em style="opacity:.75;font-size:.8em;">' + escHtml(res.suggestion) + '</em>';
                    }

                    $messages.append(buildMsg('bot', reply));
                    scrollBottom();
                },
                error: function (xhr) {
                    removeTyping();
                    $messages.append(buildMsg('bot', escHtml(errorMessage(xhr))));
                    scrollBottom();
                }
            });
        }

        /* ════════════════════════════════════════════════════
           FULLPAGE: sidebar conversation history
        ════════════════════════════════════════════════════ */
        /**
         * Load logged-in user's saved conversations into the sidebar.
         *
         * @return {void}
         */
        function loadConversationHistory() {
            if (!CONVERSATIONS_ENDPOINT) return;

            $.ajax({
                url: CONVERSATIONS_ENDPOINT,
                method: 'GET',
                headers: { 'X-CSRF-TOKEN': CSRF },
                success: function (res) {
                    var conversations = res && Array.isArray(res.conversations) ? res.conversations : [];
                    renderConversationList(conversations);

                    if (!conversations.length) return;

                    var storedId = localStorage.getItem('lara_conv_id');
                    var active = conversations.find(function (conversation) {
                        return conversation.id === storedId;
                    }) || conversations[0];

                    loadConversation(active.id);
                },
                error: function (xhr) {
                    // Silently handle expected auth errors (401, 403)
                    // These occur for unauthenticated/unauthorized users and are expected
                    if (xhr && (xhr.status === 401 || xhr.status === 403)) {
                        renderConversationList([]);
                        return;
                    }

                    // For unexpected errors, log server-side instead of console
                    // This keeps production environments clean while preserving error tracking
                    if (xhr && xhr.status && xhr.status !== 0) {
                        logErrorServerSide({
                            endpoint: 'loadConversationHistory',
                            status: xhr.status,
                            type: xhr.statusText,
                            message: xhr.responseJSON && (xhr.responseJSON.message || xhr.responseJSON.reply) || 'Unknown error'
                        });
                    }
                }
            });
        }

        /**
         * Render the conversation list.
         *
         * @param {Array<Object>} conversations Saved conversations.
         * @return {void}
         */
        function renderConversationList(conversations) {
            var $list = $('#acConvList');

            if (!$list.length) return;

            if (!conversations.length) {
                $list.html('<div class="ac-conv-empty">' + escHtml(label('noSavedChats', 'No saved chats yet.')) + '</div>');
                return;
            }

            $list.empty();

            $.each(conversations, function (i, conversation) {
                $list.append(buildConversationItem(conversation));
            });
        }

        /**
         * Add a conversation shortcut to the full-page sidebar.
         *
         * @param {string} id Conversation identifier returned by the server.
         * @param {string} firstMsg First message used as the sidebar label.
         * @return {void}
         */
        function addConvToSidebar(id, firstMsg) {
            var convLabel = firstMsg.length > 30 ? firstMsg.substring(0, 30) + '…' : firstMsg;
            var existing = $('#acConvList .ac-conv-item[data-conv-id="' + id + '"]');
            if (existing.length) {
                $('#acConvList .ac-conv-item').removeClass('active');
                existing.addClass('active');
                return;
            }

            $('#acConvList .ac-conv-empty').remove();
            $('#acConvList .ac-conv-item').removeClass('active');
            $('#acConvList').prepend(buildConversationItem({
                id: id,
                title: convLabel,
                updated_at_label: label('justNow', 'Just now')
            }).addClass('active'));
        }

        /**
         * Build a sidebar conversation item.
         *
         * @param {Object} conversation Conversation data.
         * @return {jQuery}
         */
        function buildConversationItem(conversation) {
            var title = conversation && conversation.title ? conversation.title : label('untitledChat', 'Untitled chat');
            var convLabel = title.length > 42 ? title.substring(0, 42) + '…' : title;
            var meta = conversation && conversation.updated_at_label ? conversation.updated_at_label : '';
            var id = conversation && conversation.id ? conversation.id : '';
            var $item = $(
                '<button type="button" class="ac-conv-item" data-conv-id="' + escHtml(id) + '">' +
                  '<span class="ac-conv-icon" aria-hidden="true">' + conversationIcon() + '</span>' +
                  '<span class="ac-conv-copy">' +
                    '<span class="ac-conv-title">' + escHtml(convLabel) + '</span>' +
                    '<span class="ac-conv-meta">' + escHtml(meta) + '</span>' +
                  '</span>' +
                  '<span class="ac-conv-delete" role="button" tabindex="0" title="' + escHtml(label('deleteConversation', 'Delete conversation')) + '" aria-label="' + escHtml(label('deleteConversation', 'Delete conversation')) + '" data-confirm-label="' + escHtml(label('deleteConversationConfirm', 'Delete?')) + '">' + deleteIcon() + '</span>' +
                '</button>'
            );

            $item.on('click', function () {
                loadConversation($(this).data('conv-id'));
            });

            $item.on('click keydown', '.ac-conv-delete', function (event) {
                if (event.type === 'keydown' && event.key !== 'Enter' && event.key !== ' ') {
                    return;
                }

                event.preventDefault();
                event.stopPropagation();

                var removeId = $item.data('conv-id');
                var $delete = $(this);

                if (!$item.hasClass('is-delete-armed')) {
                    resetInlineDeleteState();
                    $item.addClass('is-delete-armed');
                    $delete.data('icon-html', $delete.html());
                    $delete.text($delete.attr('data-confirm-label') || label('deleteConversationConfirm', 'Delete?'));
                    return;
                }

                openDeleteModal(removeId);
            });

            return $item;
        }

        /**
         * Reset any inline delete confirmation state in the conversation list.
         *
         * @return {void}
         */
        function resetInlineDeleteState() {
            $('#acConvList .ac-conv-item.is-delete-armed').each(function () {
                var $item = $(this);
                var $delete = $item.find('.ac-conv-delete');
                var iconHtml = $delete.data('icon-html');

                if (iconHtml) {
                    $delete.html(iconHtml);
                }

                $item.removeClass('is-delete-armed');
            });
        }

        /**
         * Open the centered final delete confirmation dialog.
         *
         * @param {string} id Conversation ID.
         * @return {void}
         */
        function openDeleteModal(id) {
            if (!id) return;

            deletePendingId = id;
            $deleteOverlay.addClass('is-open').attr('aria-hidden', 'false');
            $deleteConfirm.focus();
        }

        /**
         * Close the centered delete confirmation dialog.
         *
         * @return {void}
         */
        function closeDeleteModal() {
            deletePendingId = null;
            $deleteOverlay.removeClass('is-open').attr('aria-hidden', 'true');
        }

        /**
         * Delete the pending conversation after final confirmation.
         *
         * @return {void}
         */
        function confirmDeleteConversation() {
            if (!deletePendingId || !DELETE_CONVERSATION_ENDPOINT) {
                closeDeleteModal();
                resetInlineDeleteState();
                return;
            }

            var id = deletePendingId;
            var url = DELETE_CONVERSATION_ENDPOINT.replace('__CONVERSATION_ID__', encodeURIComponent(id));

            $deleteConfirm.prop('disabled', true);

            $.ajax({
                url: url,
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': CSRF },
                success: function () {
                    removeConversationFromUi(id);
                    closeDeleteModal();
                    resetInlineDeleteState();
                },
                error: function (xhr) {
                    closeDeleteModal();
                    resetInlineDeleteState();
                    $messages.append(buildMsg('bot', escHtml(errorMessage(xhr))));
                    scrollBottom();
                },
                complete: function () {
                    $deleteConfirm.prop('disabled', false);
                }
            });
        }

        /**
         * Remove a deleted conversation from the current interface.
         *
         * @param {string} id Conversation ID.
         * @return {void}
         */
        function removeConversationFromUi(id) {
            $('#acConvList .ac-conv-item[data-conv-id="' + id + '"]').remove();

            if (conversationId === id) {
                conversationId = null;
                resetConversation = true;
                localStorage.removeItem('lara_conv_id');
                $messages.html(buildMsg('bot', escHtml(message('newConversationStarted', 'New conversation started! What would you like to explore?'))));
                $chips.show();
            }

            if (!$('#acConvList .ac-conv-item').length) {
                $('#acConvList').html('<div class="ac-conv-empty">' + escHtml(label('noSavedChats', 'No saved chats yet.')) + '</div>');
            }

            $(document).trigger('laraknow:conversation-remove', [id]);
        }

        /**
         * Load one saved conversation into the chat area.
         *
         * @param {string} id Conversation ID.
         * @return {void}
         */
        function loadConversation(id) {
            if (!id || !CONVERSATION_MESSAGES_ENDPOINT) return;

            var url = CONVERSATION_MESSAGES_ENDPOINT.replace('__CONVERSATION_ID__', encodeURIComponent(id));

            $.ajax({
                url: url,
                method: 'GET',
                headers: { 'X-CSRF-TOKEN': CSRF },
                success: function (res) {
                    var messages = res && Array.isArray(res.messages) ? res.messages : [];

                    conversationId = id;
                    resetConversation = false;
                    localStorage.setItem('lara_conv_id', conversationId);

                    $('#acConvList .ac-conv-item').removeClass('active');
                    $('#acConvList .ac-conv-item[data-conv-id="' + id + '"]').addClass('active');

                    renderConversationMessages(messages);

                    if (MODE === 'fullpage') {
                        $('#acSidebar').removeClass('open');
                        $('#acOverlay').hide();
                    }
                }
            });
        }

        /**
         * Render stored messages in the chat area.
         *
         * @param {Array<Object>} messages Stored messages.
         * @return {void}
         */
        function renderConversationMessages(messages) {
            var html = MODE === 'fullpage' ? '<div class="ac-date-divider">' + escHtml(label('today', 'Today')) + '</div>' : '';

            if (!messages.length) {
                html += buildMsg('bot', escHtml(message('emptyConversation', 'This conversation has no saved messages yet.')));
            } else {
                $.each(messages, function (i, message) {
                    var role = message.role === 'user' ? 'user' : 'bot';
                    var content = role === 'user'
                        ? escHtml(message.content || '')
                        : formatAssistantReply(message.content || '');

                    html += buildMsg(role, content, message.time || '');
                });
            }

            $messages.html(html);
            scrollBottom();
        }

        /* ════════════════════════════════════════════════════
           DOM BUILDERS (shared)
        ════════════════════════════════════════════════════ */
        /**
         * Build a single chat message row.
         *
         * @param {string} role Message role, either bot or user.
         * @param {string} html Escaped or trusted HTML content for the bubble.
         * @return {string}
         */
        function buildMsg(role, html, time) {
            var isBot    = role === 'bot';
            var rowClass = isBot ? '' : 'ac-user';
            var avClass  = isBot ? 'ac-bot' : 'ac-user';
            var bubClass = isBot ? 'ac-bot' : 'ac-user';
            var avatar   = buildAvatar(isBot, avClass);


            return '<div class="ac-msg-row ' + rowClass + '">' +
                     avatar +
                     '<div class="ac-bwrap">' +
                       '<div class="ac-bubble ' + bubClass + '">' + html + '</div>' +
                       '<span class="ac-time">' + escHtml(time || getTime()) + '</span>' +
                     '</div>' +
                   '</div>';
        }

        /**
         * Build a bot or user avatar using configured images when available.
         *
         * @param {boolean} isBot Whether the avatar belongs to the assistant.
         * @param {string} className Avatar modifier class.
         * @return {string}
         */
        function buildAvatar(isBot, className) {
            var image = isBot ? BOT_AVATAR : USER_AVATAR;
            var initial = isBot ? BOT_INITIAL : USER_INITIAL;

            if (image) {
                return '<div class="ac-avatar ' + className + '"><img src="' + escHtml(image) + '" alt=""></div>';
            }

            return '<div class="ac-avatar ' + className + '">' + escHtml(initial) + '</div>';
        }

        /**
         * Format assistant text for chat display while keeping the output safe.
         *
         * @param {string} value Assistant reply.
         * @return {string}
         */
        function formatAssistantReply(value) {
            if (containsProviderRateLimitText(value)) {
                return escHtml(message('rateLimited', 'Something went wrong.Please try again in a moment or contact admin.'));
            }

            var text = stripBubbleWrapper(String(value || '')).trim();
            var tables = [];

            text = text.replace(/<br\s*\/?>/gi, '\n');
            text = normalizeFlattenedMarkdownTables(text);

            text = text.replace(/((?:^\|.*\|\s*$\n?){2,})/gm, function (block) {
                var token = '\u0000TABLE_' + tables.length + '\u0000';
                tables.push(renderMarkdownTable(block));
                return '\n' + token + '\n';
            });

            return renderTextBlocks(text, tables);
        }

        /**
         * Repair table rows that arrive flattened into one line.
         *
         * @param {string} text Assistant reply text.
         * @return {string}
         */
        function normalizeFlattenedMarkdownTables(text) {
            return String(text || '').split(/\n/).map(function (line) {
                var trimmed = line.trim();

                if (trimmed.indexOf('|') === -1 || !/\|\s+\|/.test(trimmed)) {
                    return line;
                }

                var prefix = '';
                var tableText = trimmed;
                var firstPipe = trimmed.indexOf('|');

                if (firstPipe > 0) {
                    prefix = trimmed.slice(0, firstPipe).trim();
                    tableText = trimmed.slice(firstPipe).trim();
                }

                var repaired = tableText.replace(/\|\s+\|/g, '|\n|');
                var rows = repaired.split(/\n/).filter(function (row) {
                    return row.trim();
                });

                if (rows.length < 2 || rows[0].trim().charAt(0) !== '|') {
                    return line;
                }

                return prefix ? prefix + '\n' + repaired : repaired;
            }).join('\n');
        }

        /**
         * Remove an accidental full chat bubble wrapper from AI output.
         *
         * @param {string} value Assistant reply.
         * @return {string}
         */
        function stripBubbleWrapper(value) {
            return value.replace(/^\s*<div\s+class=(["'])ac-bubble\s+ac-bot\1\s*>([\s\S]*)<\/div>\s*$/i, '$2');
        }

        /**
         * Render paragraphs, lists, and table placeholders.
         *
         * @param {string} text Reply text with table tokens.
         * @param {Array<string>} tables Rendered table HTML.
         * @return {string}
         */
        function renderTextBlocks(text, tables) {
            var html = '';
            var paragraph = [];
            var inList = false;
            var lines = text.split(/\n/);

            function flushParagraph() {
                if (!paragraph.length) return;
                html += '<p>' + paragraph.join('<br>') + '</p>';
                paragraph = [];
            }

            function closeList() {
                if (!inList) return;
                html += '</ul>';
                inList = false;
            }

            $.each(lines, function (i, line) {
                var trimmed = line.trim();
                var tableMatch = trimmed.match(/^\u0000TABLE_(\d+)\u0000$/);
                var headingMatch = trimmed.match(/^(#{1,6})\s+(.+)$/);
                var bulletMatch = trimmed.match(/^[\u2022*-]\s+(.+)$/);
                var ruleMatch = trimmed.match(/^[-*_]{3,}$/);

                if (!trimmed) {
                    flushParagraph();
                    closeList();
                    return;
                }

                if (tableMatch) {
                    flushParagraph();
                    closeList();
                    html += tables[parseInt(tableMatch[1], 10)] || '';
                    return;
                }

                if (headingMatch) {
                    flushParagraph();
                    closeList();
                    html += '<div class="ac-response-heading ac-response-heading--' + headingMatch[1].length + '">' + renderInlineMarkdown(headingMatch[2]) + '</div>';
                    return;
                }

                if (ruleMatch) {
                    flushParagraph();
                    closeList();
                    html += '<hr class="ac-response-rule">';
                    return;
                }

                if (bulletMatch) {
                    flushParagraph();
                    if (!inList) {
                        html += '<ul>';
                        inList = true;
                    }
                    html += '<li>' + renderInlineMarkdown(bulletMatch[1]) + '</li>';
                    return;
                }

                closeList();
                paragraph.push(renderInlineMarkdown(trimmed));
            });

            flushParagraph();
            closeList();

            return html || renderInlineMarkdown(text);
        }

        /**
         * Render a GitHub-style markdown table.
         *
         * @param {string} block Markdown table block.
         * @return {string}
         */
        function renderMarkdownTable(block) {
            var lines = block.trim().split(/\n/).filter(function (line) {
                return line.trim();
            });

            if (lines.length < 2 || !/^\s*\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)+\|?\s*$/.test(lines[1])) {
                return '<p>' + renderInlineMarkdown(block.trim()) + '</p>';
            }

            var headers = splitMarkdownRow(lines[0]);
            var rows = lines.slice(2).map(splitMarkdownRow);
            var html = '<div class="ac-response-table-wrap"><table class="ac-response-table"><thead><tr>';

            $.each(headers, function (i, cell) {
                html += '<th>' + renderInlineMarkdown(cell) + '</th>';
            });

            html += '</tr></thead><tbody>';

            $.each(rows, function (i, row) {
                html += '<tr>';
                $.each(headers, function (j) {
                    html += '<td>' + renderInlineMarkdown(row[j] || '') + '</td>';
                });
                html += '</tr>';
            });

            html += '</tbody></table></div>';

            return html;
        }

        /**
         * Split a markdown table row into cells.
         *
         * @param {string} row Markdown table row.
         * @return {Array<string>}
         */
        function splitMarkdownRow(row) {
            return row.replace(/^\s*\|/, '').replace(/\|\s*$/, '').split('|').map(function (cell) {
                return cell.trim();
            });
        }

        /**
         * Render safe inline markdown.
         *
         * @param {string} value Text value.
         * @return {string}
         */
        function renderInlineMarkdown(value) {
            var text = decodeHtmlEntities(String(value || ''));
            var html = escHtml(text)
                .replace(/&lt;br\s*\/?&gt;/gi, '<br>')
                .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
                .replace(/`([^`]+)`/g, '<code>$1</code>');

            return html;
        }

        /**
         * Decode entities that may arrive inside an HTML-shaped response.
         *
         * @param {string} value Text value.
         * @return {string}
         */
        function decodeHtmlEntities(value) {
            var textarea = document.createElement('textarea');
            textarea.innerHTML = value;

            return textarea.value;
        }

        /**
         * Show the typing indicator in the message list.
         *
         * @return {void}
         */
        function showTyping() {
            $messages.append(
                '<div class="ac-typing-row" id="acTyping">' +
                  buildAvatar(true, 'ac-bot') +
                  '<div class="ac-typing-bub" aria-label="' + escHtml(label('typing', 'Assistant is typing')) + '">' +
                    '<div class="ac-dot"></div>' +
                    '<div class="ac-dot"></div>' +
                    '<div class="ac-dot"></div>' +
                  '</div>' +
                '</div>'
            );
            scrollBottom();
        }

        /**
         * Remove the typing indicator from the message list.
         *
         * @return {void}
         */
        function removeTyping() { $('#acTyping').remove(); }

        /* ════════════════════════════════════════════════════
           UTILITIES
        ════════════════════════════════════════════════════ */
        /**
         * Scroll the message container to the latest message.
         *
         * @return {void}
         */
        function scrollBottom() {
            var el = $messages[0];
            if (el) el.scrollTop = el.scrollHeight;
        }

        /**
         * Resize the textarea height based on its content.
         *
         * @param {HTMLTextAreaElement} el Textarea element to resize.
         * @return {void}
         */
        function autoResize(el) {
            el.style.height = 'auto';
            el.style.height = Math.min(el.scrollHeight, 100) + 'px';
        }

        /**
         * Render database rows into a compact HTML table.
         *
         * @param {Array<Object>} rows Data rows returned from the chat endpoint.
         * @return {string}
         */
        function renderDataRows(rows) {
            var first = rows[0] || {};
            var keys = Object.keys(first).slice(0, 6);

            if (!keys.length) return '';

            var html = '<div class="ac-response-table-wrap"><table class="ac-response-table">';
            html += '<thead><tr>';

            $.each(keys, function (i, key) {
                html += '<th>' + escHtml(formatDataHeader(key)) + '</th>';
            });

            html += '</tr></thead><tbody>';

            $.each(rows.slice(0, 10), function (i, row) {
                html += '<tr>';
                $.each(keys, function (j, key) {
                    var value = row && row[key] !== null && row[key] !== undefined ? row[key] : '';
                    html += '<td>' + escHtml(String(value)) + '</td>';
                });
                html += '</tr>';
            });

            html += '</tbody></table></div>';

            return html;
        }

        /**
         * Convert database field names into readable table headings.
         *
         * @param {string} key Raw database field name.
         * @return {string}
         */
        function formatDataHeader(key) {
            return String(key || '')
                .replace(/_/g, ' ')
                .replace(/\b\w/g, function (letter) {
                    return letter.toUpperCase();
                });
        }

        /**
         * Resolve a compact conversation icon from configured icon names.
         *
         * @return {string}
         */
        function conversationIcon() {
            var icon = ICONS && ICONS.conversation ? String(ICONS.conversation).toLowerCase() : 'chat';

            if (icon === 'sparkles') {
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8L12 3z"></path><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8L19 15z"></path></svg>';
            }

            if (icon === 'support') {
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 13a8 8 0 0 1 16 0"></path><path d="M5 13v4a2 2 0 0 0 2 2h1v-8H7a2 2 0 0 0-2 2z"></path><path d="M19 13v4a2 2 0 0 1-2 2h-1v-8h1a2 2 0 0 1 2 2z"></path></svg>';
            }

            return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4v8z"></path></svg>';
        }

        /**
         * Resolve the remove icon used by conversation history items.
         *
         * @return {string}
         */
        function deleteIcon() {
            return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18"></path><path d="M8 6V4h8v2"></path><path d="M19 6l-1 14H6L5 6"></path><path d="M10 11v5"></path><path d="M14 11v5"></path></svg>';
        }

        /**
         * Resolve a user-friendly error message from an AJAX response.
         *
         * @param {jqXHR} xhr Failed jQuery AJAX response object.
         * @return {string}
         */
        function errorMessage(xhr) {
            var fallback = message('errorFallback', 'Something went wrong. Please try again.');
            var payload = xhr && xhr.responseJSON ? xhr.responseJSON : null;
            var responseMessage = payload && (payload.reply || payload.message) ? (payload.reply || payload.message) : '';
            var exception = payload && payload.exception ? payload.exception : '';
            var rateLimitFallback = message('rateLimited', 'Something went wrong.Please try again in a moment or contact admin.');

            if (xhr && xhr.status === 429) {
                return rateLimitFallback;
            }

            if (/RateLimitedException/.test(exception) || /rate limited/i.test(responseMessage)) {
                return rateLimitFallback;
            }

            return responseMessage || fallback;
        }

        /**
         * Send client-side error to server for logging (production best practice).
         * Server logs errors while keeping browser console clean for actual issues.
         *
         * @param {Object} errorData Error details to log.
         * @return {void}
         */
        function logErrorServerSide(errorData) {
            try {
                $.ajax({
                    url: window.location.href,
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': CSRF,
                        'X-LaraKnow-Error': 'true'
                    },
                    data: JSON.stringify({
                        error: errorData,
                        timestamp: new Date().toISOString(),
                        url: window.location.href
                    }),
                    contentType: 'application/json',
                    timeout: 3000,
                    // Suppress console errors for logging requests
                    // Error handler intentionally omitted
                });
            } catch (e) {
                // Silently fail if logging itself fails
            }
        }


        /**
         * Detect provider-specific rate-limit text in normal or error responses.
         *
         * @param {string} value Response text.
         * @return {boolean}
         */
        function containsProviderRateLimitText(value) {
            var text = String(value || '').toLowerCase();

            return text.indexOf('rate limited') !== -1 ||
                text.indexOf('rate limit') !== -1 ||
                text.indexOf('ai provider') !== -1 ||
                text.indexOf('[groq]') !== -1;
        }

        /**
         * Get the current time formatted for chat message timestamps.
         *
         * @return {string}
         */
        function getTime() {
            return new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        }

        /**
         * Escape HTML special characters before inserting user content.
         *
         * @param {string} str Raw string value.
         * @return {string}
         */
        function escHtml(str) {
            return String(str)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

    }); /* end DOM ready */

})(jQuery);
