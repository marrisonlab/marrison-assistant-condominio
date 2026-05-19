/**
 * Marrison Assistant Site Agent JavaScript
 */

jQuery(document).ready(function($) {
    // Variabili globali
    let chatWindow = null;
    let chatButton = null;
    let chatMessages = null;
    let chatTextarea = null;
    let sendButton = null;
    let isOpen = false;
    let messageCount = 0;

    // Stato del flusso condominiale
    let chatState = {
        step:          'find_condominio',
        condominioId:  null,
        fornitoreId:   null,
        problema:      null,
        photoIds:      [],
        adminOnly:     false,
    };

    // Chiavi sessionStorage
    const SS_MSGS  = 'marrison_msgs';
    const SS_STATE = 'marrison_state';
    const SS_OPEN  = 'marrison_open';

    function saveSession() {
        try {
            const $clone = chatMessages.clone();
            $clone.find('.marrison-step-options').remove();
            sessionStorage.setItem(SS_MSGS,  $clone.html());
            sessionStorage.setItem(SS_STATE, JSON.stringify(chatState));
            sessionStorage.setItem(SS_OPEN,  isOpen ? '1' : '0');
        } catch(e) {}
    }

    function restoreSession() {
        try {
            const savedMsgs  = sessionStorage.getItem(SS_MSGS);
            const savedState = sessionStorage.getItem(SS_STATE);
            const savedOpen  = sessionStorage.getItem(SS_OPEN);

            if (savedMsgs) {
                chatMessages.html(savedMsgs);
                scrollToBottom();
            }
            if (savedState) {
                const st = JSON.parse(savedState);
                chatState.step         = st.step         || 'find_condominio';
                chatState.condominioId = st.condominioId || null;
                chatState.fornitoreId  = st.fornitoreId  || null;
                chatState.problema     = st.problema     || null;
                chatState.photoIds     = st.photoIds     || [];
                chatState.adminOnly    = st.adminOnly    || false;
            }
            if (savedOpen === '1' && marrisonAgent.mode !== 'inline') {
                chatWindow.addClass('open');
                isOpen = true;
            }
        } catch(e) {}
    }
    
    // Inizializzazione
    function init() {
        chatWindow = $('.marrison-chat-window');
        chatButton = $('.marrison-chat-button');
        chatMessages = $('.marrison-chat-messages');
        chatTextarea = $('#marrison-chat-textarea');
        sendButton = $('#marrison-chat-send');
        
        // Event listeners
        sendButton.on('click', sendMessage);
        chatTextarea.on('keydown', handleKeyDown);
        chatTextarea.on('input', handleInput);

        // Auto-resize textarea
        chatTextarea.on('input', autoResize);

        if (marrisonAgent.mode === 'inline') {
            // Modalità shortcode: la chat è sempre visibile, nessun bottone di toggle
            isOpen = true;
            chatTextarea.focus();
        } else {
            // Modalità flottante (legacy)
            chatButton.on('click', toggleChat);
            $('.marrison-chat-close').on('click', closeChat);

            // Nascondi badge dopo primo click
            chatButton.on('click', function() {
                $('.marrison-chat-badge').hide();
            });

            // Accessibility
            chatButton.attr('aria-label', 'Apri chat assistente');
            chatButton.attr('role', 'button');
            chatButton.attr('tabindex', '0');

            chatButton.on('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    toggleChat();
                }
            });

            // Auto-focus when chat opens (transition)
            chatWindow.on('transitionend', function() {
                if (isOpen) {
                    chatTextarea.focus();
                }
            });
        }

    }
    
    // Toggle chat window
    function toggleChat() {
        if (isOpen) {
            closeChat();
        } else {
            openChat();
        }
    }
    
    // Open chat
    var closeTimer = null;
    function openChat() {
        // Interrompe eventuale animazione di chiusura in corso
        if (closeTimer) { clearTimeout(closeTimer); closeTimer = null; }
        chatWindow.removeClass('closing');
        chatWindow.addClass('open');
        isOpen = true;
        saveSession();
        chatTextarea.focus();

        // Traccia apertura chat
        $.post(marrisonAgent.ajaxUrl, {
            action: 'marrison_site_agent_track',
            type: 'chat_open',
            nonce: marrisonAgent.nonce
        });
    }
    
    // Close chat
    function closeChat() {
        if (!isOpen) return;
        if (marrisonAgent.mode === 'inline') return; // non si chiude in modalità shortcode
        isOpen = false;
        chatWindow.addClass('closing');
        closeTimer = setTimeout(function() {
            chatWindow.removeClass('open closing');
            closeTimer = null;
            saveSession();
        }, 320); // corrisponde alla durata di chatClose
    }
    
    // ── Gestione risposta step-based dal server ──────────────────────────────
    function handleStepResponse(data) {
        if (data.message) {
            addMessage(data.message, 'bot');
        }
        // Aggiorna lo stato
        if (data.next_step)    chatState.step         = data.next_step;
        if (data.condominio_id) chatState.condominioId = data.condominio_id;
        if (data.fornitore_id) chatState.fornitoreId  = data.fornitore_id;
        if (data.problema)     chatState.problema     = data.problema;
        if (data.photo_ids)           chatState.photoIds  = data.photo_ids;
        if (data.admin_only !== undefined) chatState.adminOnly = !!data.admin_only;
        if (data.reset) {
            chatState.condominioId = null;
            chatState.fornitoreId  = null;
            chatState.problema     = null;
            chatState.photoIds     = [];
            chatState.adminOnly    = false;
        }
        // Mostra widget upload foto
        if (data.photo_upload) {
            showPhotoUploadWidget();
        }
        // Mostra bottoni di selezione
        if (data.options && data.options.length > 0) {
            showStepOptions(data.options);
        }
        saveSession();
    }

    // Mostra bottoni di selezione (es. lista condomini, sì/no)
    function showStepOptions(options) {
        $('.marrison-step-options').remove();
        const $wrap = $('<div class="marrison-step-options marrison-intent-buttons"></div>');
        options.forEach(function(opt) {
            const $btn = $('<button type="button" class="marrison-intent-btn marrison-step-btn"></button>')
                .text(opt.label)
                .attr('data-step-value', opt.value)
                .attr('data-step-label', opt.label);
            $wrap.append($btn);
        });
        chatMessages.append($wrap);
        scrollToBottom();
    }

    // ── Widget upload foto ──────────────────────────────────────────────────
    function showPhotoUploadWidget() {
        $('.marrison-photo-widget').remove();
        const $wrap = $(`
            <div class="marrison-photo-widget">
                <div class="marrison-photo-thumbs" id="marrison-photo-thumbs"></div>
                <div class="marrison-photo-actions">
                    <button type="button" id="marrison-add-photo-btn" class="marrison-photo-btn">
                        📷 Aggiungi foto
                    </button>
                    <button type="button" id="marrison-proceed-upload-btn" class="marrison-photo-btn marrison-photo-proceed">
                        ➡️ Procedi
                    </button>
                </div>
                <p class="marrison-photo-hint">Max 5 foto · Max 5MB ciascuna · JPG, PNG, WebP</p>
                <input type="file" id="marrison-photo-input" accept="image/*" multiple style="display:none">
            </div>
        `);
        chatMessages.append($wrap);
        scrollToBottom();

        $('#marrison-add-photo-btn').on('click', function() {
            if (chatState.photoIds.length >= 5) {
                return;
            }
            $('#marrison-photo-input').val('').trigger('click');
        });

        $('#marrison-photo-input').on('change', function() {
            const files = Array.from(this.files || []);
            const remaining = 5 - chatState.photoIds.length;
            files.slice(0, remaining).forEach(uploadSinglePhoto);
        });

        $('#marrison-proceed-upload-btn').on('click', function() {
            if ($('.marrison-photo-uploading').length > 0) {
                return; // still uploading
            }
            $('.marrison-photo-widget').fadeOut(150, function() { $(this).remove(); });
            addMessage('Procedi', 'user');
            sendStepRequest('proceed_upload', 'Procedi');
        });
    }

    function uploadSinglePhoto(file) {
        if (file.size > 5 * 1024 * 1024) {
            addMessage('⚠️ "' + file.name + '" supera i 5MB.', 'bot');
            return;
        }

        const tempId = 'tmp_' + Date.now() + '_' + Math.random().toString(36).substr(2, 5);
        const reader = new FileReader();
        reader.onload = function(e) {
            const $thumb = $(`
                <div class="marrison-photo-thumb marrison-photo-uploading" id="${tempId}">
                    <img src="${e.target.result}" alt="">
                    <div class="marrison-photo-thumb-overlay">⏳</div>
                    <button type="button" class="marrison-photo-remove" data-temp="${tempId}">✕</button>
                </div>
            `);
            $('#marrison-photo-thumbs').append($thumb);
            scrollToBottom();
        };
        reader.readAsDataURL(file);

        const fd = new FormData();
        fd.append('action', 'marrison_upload_photo');
        fd.append('nonce',  marrisonAgent.nonce);
        fd.append('photo',  file);

        $.ajax({
            url:         marrisonAgent.ajaxUrl,
            type:        'POST',
            data:        fd,
            processData: false,
            contentType: false,
        }).done(function(resp) {
            if (resp.success) {
                chatState.photoIds.push(resp.data.id);
                $('#' + tempId)
                    .removeClass('marrison-photo-uploading')
                    .attr('data-server-id', resp.data.id)
                    .find('.marrison-photo-thumb-overlay').text('✅');
                // Aggiorna pulsante aggiungi
                if (chatState.photoIds.length >= 5) {
                    $('#marrison-add-photo-btn').prop('disabled', true).text('Max 5 foto raggiunto');
                }
            } else {
                $('#' + tempId).remove();
                addMessage('⚠️ Upload fallito: ' + (resp.data.message || 'errore'), 'bot');
            }
        }).fail(function() {
            $('#' + tempId).remove();
            addMessage('⚠️ Errore di connessione durante l\'upload. Riprova.', 'bot');
        });
    }

    // Rimozione foto singola
    $(document).on('click', '.marrison-photo-remove', function() {
        const tempId = $(this).data('temp');
        // Nota: la foto è già sul server, rimozione lato UI soltanto;
        // verrà ignorata se non presente in chatState.photoIds al momento dell'invio.
        // Per semplicità, non eliminiamo il file server-side in questa fase.
        const $thumb = $('#' + tempId);
        const serverId = $thumb.data('server-id');
        if (serverId) {
            chatState.photoIds = chatState.photoIds.filter(id => id !== serverId);
        }
        $thumb.remove();
        if (chatState.photoIds.length < 5) {
            $('#marrison-add-photo-btn').prop('disabled', false).text('📷 Aggiungi foto');
        }
    });

    // Invio richiesta step-based al server
    function sendStepRequest(stepValue, stepLabel) {
        showTyping();
        sendButton.prop('disabled', true);
        $.post(marrisonAgent.ajaxUrl, {
            action:  'marrison_condominium_step',
            step:    chatState.step,
            input:   stepValue,
            context: JSON.stringify(chatState),
            nonce:   marrisonAgent.nonce
        })
        .done(function(response) {
            hideTyping();
            if (response.success) {
                handleStepResponse(response.data);
            } else if (response.data && response.data.code === 'rate_limited') {
                addMessage('⏳ ' + response.data.message, 'bot');
            } else {
                addMessage('Si è verificato un errore. Riprova.', 'bot');
            }
        })
        .fail(function(xhr) {
            hideTyping();
            var code = xhr.status;
            if (code === 403) {
                addMessage('Errore di sessione (403). Ricarica la pagina e riprova.', 'bot');
            } else {
                addMessage('Errore di connessione (' + code + '). Riprova più tardi.', 'bot');
            }
        })
        .always(function() {
            sendButton.prop('disabled', false);
        });
    }

    // Send message
    function sendMessage() {
        const message = chatTextarea.val().trim();
        if (!message) return;

        addMessage(message, 'user');
        chatTextarea.val('');
        autoResize();

        // Rimuovi eventuali bottoni di scelta
        $('.marrison-step-options').fadeOut(150, function() { $(this).remove(); });

        sendStepRequest(message, message);
    }
    
    // Add message to chat
    function addMessage(text, sender, time) {
        const messageClass = sender === 'user' ? 'marrison-user' : 'marrison-bot';
        const messageTime = time || getCurrentTime();
        
        // Formatta il testo: URL in link, bold markdown in HTML
        const formattedText = formatMessageText(text);
        
        const messageHtml = `
            <div class="marrison-message ${messageClass}" style="margin-bottom: 12px; display: flex; flex-direction: column; ${sender === 'user' ? 'align-items: flex-end;' : 'align-items: flex-start;'}">
                <div class="marrison-message-content" style="max-width: 85%; padding: 10px 14px; border-radius: 16px; ${sender === 'user' ? 'background: var(--marrison-button-color, #667eea); color: white; border-bottom-right-radius: 4px;' : 'background: white; color: #1e293b; border: 1px solid #e2e8f0; border-bottom-left-radius: 4px;'} box-shadow: 0 1px 3px rgba(0,0,0,0.1); word-wrap: break-word; line-height: 1.4; font-size: 14px;">${formattedText}</div>
                <div class="marrison-message-time" style="font-size: 11px; color: #64748b; margin-top: 2px; padding: 0 4px;">${messageTime}</div>
            </div>
        `;
        
        chatMessages.append(messageHtml);
        scrollToBottom();
        saveSession();

        messageCount++;
        updateBadge();
    }
    
    // Format message text: convert markdown to HTML with proper escaping
    function formatMessageText(text) {
        if (!text) return '';

        // Dividi in parti: testo normale, link markdown [text](url),
        // oppure tag <a> già formati da PHP (es. tel:/WhatsApp/email generati da make_phone_links_clickable)
        const parts = [];
        let lastIndex = 0;
        const combinedRegex = /\[([^\]]+)\]\((https?:\/\/[^\s<)]+)\)|(<a\b[^>]*>[\s\S]*?<\/a>)/gi;
        let match;

        while ((match = combinedRegex.exec(text)) !== null) {
            if (match.index > lastIndex) {
                parts.push({ type: 'text', content: text.slice(lastIndex, match.index) });
            }
            if (match[1] !== undefined) {
                // Link markdown [testo](url)
                parts.push({ type: 'link', linkText: match[1], url: match[2] });
            } else {
                // Tag <a> già formato da PHP — passa direttamente senza escape
                parts.push({ type: 'raw_html', content: match[3] });
            }
            lastIndex = match.index + match[0].length;
        }

        if (lastIndex < text.length) {
            parts.push({ type: 'text', content: text.slice(lastIndex) });
        }

        if (parts.length === 0) {
            parts.push({ type: 'text', content: text });
        }

        // Processa ogni parte
        let result = '';
        parts.forEach(part => {
            if (part.type === 'text') {
                // PRIMA: Converti markdown bold/italic su testo raw
                let formatted = part.content;
                formatted = formatted.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
                formatted = formatted.replace(/\*([^*]+)\*/g, '<em>$1</em>');

                // POI: Escape HTML nel testo (preserva i tag sicuri creati da PHP/markdown)
                const safeTagRe = /(<\/?(?:strong|em|br|p|ul|ol|li)\s*\/?>)/gi;
                const segments = formatted.split(safeTagRe);
                let escaped = '';
                segments.forEach(seg => {
                    if (safeTagRe.test(seg)) {
                        safeTagRe.lastIndex = 0;
                        escaped += seg; // passa tag sicuri senza escape
                    } else {
                        safeTagRe.lastIndex = 0;
                        escaped += escapeHtml(seg); // escape testo utente
                    }
                });

                // Converti URL plain in link
                escaped = escaped.replace(/(https?:\/\/[^\s<]+|www\.[^\s<]+)/g, function(url) {
                    let href = url;
                    if (url.startsWith('www.')) href = 'https://' + url;
                    return `<a href="${href}">${url}</a>`;
                });
                result += escaped;
            } else if (part.type === 'link') {
                // Link markdown → tag <a>
                const safeText = escapeHtml(part.linkText);
                result += `<a href="${part.url}">${safeText}</a>`;
            } else if (part.type === 'raw_html') {
                // Tag <a> generato da PHP (tel:/WhatsApp/mailto) → passa senza escape
                result += part.content;
            }
        });

        return result.replace(/\n/g, '<br>');
    }
    
    // Show typing indicator
    function showTyping() {
        const typingHtml = `
            <div class="marrison-message marrison-bot marrison-typing-message">
                <div class="marrison-typing">
                    <div class="marrison-typing-dot"></div>
                    <div class="marrison-typing-dot"></div>
                    <div class="marrison-typing-dot"></div>
                </div>
            </div>
        `;
        
        chatMessages.append(typingHtml);
        scrollToBottom();
    }
    
    // Hide typing indicator
    function hideTyping() {
        $('.marrison-typing-message').remove();
    }
    
    // Handle keyboard events
    function handleKeyDown(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    }
    
    // Handle input events
    function handleInput() {
        // Auto-send on certain conditions if needed
        // Could implement smart suggestions here
    }
    
    // Auto-resize textarea
    function autoResize() {
        chatTextarea.css('height', 'auto');
        chatTextarea.css('height', Math.min(chatTextarea[0].scrollHeight, 100) + 'px');
    }
    
    // Scroll to bottom
    function scrollToBottom() {
        const messagesContainer = chatMessages[0];
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }
    
    // Get current time
    function getCurrentTime() {
        const now = new Date();
        return now.getHours().toString().padStart(2, '0') + ':' + 
               now.getMinutes().toString().padStart(2, '0');
    }
    
    // Escape HTML
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Update badge
    function updateBadge() {
        if (messageCount > 0 && !isOpen) {
            $('.marrison-chat-badge').text(messageCount).show();
        }
    }
    
    // Keyboard shortcuts
    $(document).on('keydown', function(e) {
        // Ctrl/Cmd + K to open chat
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            if (!isOpen) {
                openChat();
            }
        }
        
        // Escape to close chat
        if (e.key === 'Escape' && isOpen) {
            closeChat();
        }
    });
    
    // Initialize when ready
    init();
    restoreSession();

    // Bottoni di selezione step (condomini, sì/no, ecc.)
    $(document).on('click', '.marrison-step-btn', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const value = $(this).data('step-value');
        const label = $(this).data('step-label') || value;

        // Rimuovi i bottoni e mostra la scelta come messaggio utente
        $('.marrison-step-options').fadeOut(150, function() { $(this).remove(); });
        addMessage(label, 'user');

        sendStepRequest(value, label);
    });
    
    // Add welcome message if needed
    if (marrisonAgent.welcome && $('.marrison-message').length === 1) {
        // Welcome message already added in HTML
    }
    
    // Focus management
    chatWindow.on('click', function(e) {
        if (!$(e.target).is('textarea, button')) {
            chatTextarea.focus();
        }
    });
    
    // Handle window resize
    $(window).on('resize', function() {
        if (isOpen) {
            scrollToBottom();
        }
    });
    
    // Add some nice animations
    chatMessages.on('scroll', function() {
        // Could implement scroll-to-bottom button logic here
    });
    
    // Session tracking
    let sessionId = sessionStorage.getItem('marrison_session_id');
    if (!sessionId) {
        sessionId = 'session_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        sessionStorage.setItem('marrison_session_id', sessionId);
    }
    
    // Track session start
    $.post(marrisonAgent.ajaxUrl, {
        action: 'marrison_site_agent_track',
        type: 'session_start',
        session_id: sessionId,
        nonce: marrisonAgent.nonce
    });
});
