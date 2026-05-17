import DOMPurify from 'dompurify';
import { marked } from 'marked';

let hasRegisteredChatPageData = false;
const CHAT_PENDING_STORAGE_KEY = 'ista.chat.pendingResponses.v1';
const CHAT_COMPLETED_STORAGE_KEY = 'ista.chat.completedResponses.v1';
const CHAT_HISTORY_SECTIONS_STORAGE_KEY = 'ista.chat.historySections.v1';
const MEMO_HISTORY_SECTIONS_STORAGE_KEY = 'ista.memo.historySections.v1';
const CHAT_HISTORY_SECTION_KEYS = ['seven', 'thirty', 'older'];
const MEMO_HISTORY_SECTION_KEYS = ['seven', 'thirty', 'older'];
const CHAT_PENDING_MARKER_TTL_MS = 10 * 60 * 1000;
const CHAT_PENDING_RECENT_TTL_MS = 3 * 60 * 1000;
const CHAT_PENDING_STALE_WARNING_MS = 45 * 1000;
const CHAT_MESSAGE_ACK_TIMEOUT_MS = 10 * 1000;
const CHAT_LOADING_PHASE_DELAY_MS = 2500;
const CHAT_SOURCE_REVEAL_BEFORE_REFRESH_MS = 650;
const MARKDOWN_RENDER_OPTIONS = {
    async: false,
    breaks: false,
    gfm: true,
};

const escapeHtmlForMarkdown = (value = '') => String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');

const renderSafeStreamingMarkdown = (value = '') => {
    try {
        const html = marked.parse(escapeHtmlForMarkdown(value), MARKDOWN_RENDER_OPTIONS);

        return DOMPurify.sanitize(String(html), {
            USE_PROFILES: { html: true },
        });
    } catch (_) {
        return DOMPurify.sanitize(`<p>${escapeHtmlForMarkdown(value)}</p>`, {
            USE_PROFILES: { html: true },
        });
    }
};

const createAssistantTypewriterState = (config = {}) => ({
    typewriterFullText: String(config.content || ''),
    typewriterDisplayedText: '',
    typewriterHtml: '',
    typewriterQueue: '',
    typewriterTimer: null,
    typewriterDoneCallback: null,
    typewriterMarkdownRenderFrame: null,
    typewriterSources: Array.isArray(config.sources) ? config.sources : [],
    typewriterSourcesVisible: false,
    typewriterInitialDelay: Number.isFinite(Number(config.initialDelay)) ? Number(config.initialDelay) : 80,
    typewriterEmptyInitialDelay: Number.isFinite(Number(config.emptyInitialDelay)) ? Number(config.emptyInitialDelay) : 30,

    clearAssistantTypewriterTimer() {
        if (this.typewriterTimer) {
            window.clearTimeout(this.typewriterTimer);
            this.typewriterTimer = null;
        }

        this.typewriterQueue = '';
        this.typewriterDoneCallback = null;
    },

    clearAssistantTypewriterMarkdownRender() {
        if (this.typewriterMarkdownRenderFrame) {
            window.cancelAnimationFrame(this.typewriterMarkdownRenderFrame);
            this.typewriterMarkdownRenderFrame = null;
        }
    },

    resetAssistantTypewriter(options = {}) {
        this.clearAssistantTypewriterTimer();
        this.clearAssistantTypewriterMarkdownRender();
        this.typewriterFullText = '';
        this.typewriterDisplayedText = '';
        this.typewriterHtml = '';

        if (!options.preserveSources) {
            this.typewriterSources = [];
            this.typewriterSourcesVisible = false;
        }
    },

    renderAssistantTypewriterMarkdownNow() {
        this.clearAssistantTypewriterMarkdownRender();
        this.typewriterHtml = renderSafeStreamingMarkdown(this.typewriterDisplayedText);
    },

    scheduleAssistantTypewriterMarkdownRender() {
        if (this.typewriterMarkdownRenderFrame) {
            return;
        }

        this.typewriterMarkdownRenderFrame = window.requestAnimationFrame(() => {
            this.typewriterMarkdownRenderFrame = null;
            this.typewriterHtml = renderSafeStreamingMarkdown(this.typewriterDisplayedText);

            if (typeof this.scrollToBottom === 'function') {
                this.scrollToBottom();
            }
        });
    },

    setAssistantTypewriterSources(sources) {
        this.typewriterSources = Array.isArray(sources) ? sources : [];
        this.typewriterSourcesVisible = false;
    },

    revealAssistantTypewriterSources() {
        this.typewriterSourcesVisible = Array.isArray(this.typewriterSources) && this.typewriterSources.length > 0;
    },

    queueAssistantTypewriterFinalText(finalText) {
        const nextFinalText = typeof finalText === 'string' ? finalText : '';
        if (nextFinalText === '') {
            return;
        }

        this.typewriterFullText = nextFinalText;
        const pendingDisplayText = `${this.typewriterDisplayedText}${this.typewriterQueue}`;

        if (nextFinalText === pendingDisplayText) {
            this.startAssistantTypewriter();
            return;
        }

        if (nextFinalText.startsWith(pendingDisplayText)) {
            this.typewriterQueue += nextFinalText.substring(pendingDisplayText.length);
        } else if (nextFinalText.startsWith(this.typewriterDisplayedText)) {
            this.typewriterQueue = nextFinalText.substring(this.typewriterDisplayedText.length);
        } else {
            this.typewriterDisplayedText = '';
            this.typewriterQueue = nextFinalText;
            this.renderAssistantTypewriterMarkdownNow();
        }

        this.startAssistantTypewriter();
    },

    appendAssistantTypewriterText(text) {
        const chunk = String(text || '');
        if (chunk === '') {
            return;
        }

        this.typewriterQueue += chunk;
        this.startAssistantTypewriter();
    },

    startAssistantTypewriter() {
        if (this.typewriterTimer) {
            return;
        }

        const tick = () => {
            this.typewriterTimer = null;

            if (this.typewriterQueue === '') {
                this.renderAssistantTypewriterMarkdownNow();
                this.runAssistantTypewriterDoneCallback();
                return;
            }

            const remaining = this.typewriterQueue.length;
            const chunkSize = remaining > 1600 ? 22 : (remaining > 800 ? 18 : (remaining > 320 ? 14 : 9));
            const nextChunk = this.typewriterQueue.substring(0, chunkSize);

            this.typewriterDisplayedText += nextChunk;
            this.typewriterQueue = this.typewriterQueue.substring(nextChunk.length);
            this.scheduleAssistantTypewriterMarkdownRender();

            const nextDelay = this.typewriterQueue.length > 1600 ? 2 : (this.typewriterQueue.length > 800 ? 3 : 5);
            this.typewriterTimer = window.setTimeout(tick, nextDelay);
        };

        const initialDelay = this.typewriterDisplayedText === ''
            ? this.typewriterInitialDelay
            : this.typewriterEmptyInitialDelay;
        this.typewriterTimer = window.setTimeout(tick, initialDelay);
    },

    afterAssistantTypewriterSettles(callback) {
        if (this.typewriterQueue === '' && !this.typewriterTimer) {
            callback();
            return;
        }

        this.typewriterDoneCallback = callback;
    },

    runAssistantTypewriterDoneCallback() {
        if (typeof this.typewriterDoneCallback !== 'function') {
            return;
        }

        const callback = this.typewriterDoneCallback;
        this.typewriterDoneCallback = null;
        callback();
    },
});

const normalizeWirePayload = (data) => (Array.isArray(data) ? (data[0] || {}) : (data || {}));

const normalizeConversationIds = (ids = []) => [...new Set((ids || [])
    .map((id) => Number(id))
    .filter((id) => Number.isFinite(id) && id > 0))];

const normalizeHistorySectionState = (sections = {}) => CHAT_HISTORY_SECTION_KEYS.reduce((state, section) => ({
    ...state,
    [section]: Boolean(sections?.[section]),
}), {});

const normalizeMemoHistorySectionState = (sections = {}) => MEMO_HISTORY_SECTION_KEYS.reduce((state, section) => ({
    ...state,
    [section]: Boolean(sections?.[section]),
}), {});

const loadHistorySectionState = () => {
    try {
        const raw = window.localStorage?.getItem(CHAT_HISTORY_SECTIONS_STORAGE_KEY);
        if (!raw) return {};
        const parsed = JSON.parse(raw);

        return parsed && typeof parsed === 'object' ? normalizeHistorySectionState(parsed) : {};
    } catch (_) {
        return {};
    }
};

const loadMemoHistorySectionState = () => {
    try {
        const raw = window.localStorage?.getItem(MEMO_HISTORY_SECTIONS_STORAGE_KEY);
        if (!raw) return {};
        const parsed = JSON.parse(raw);

        return parsed && typeof parsed === 'object' ? normalizeMemoHistorySectionState(parsed) : {};
    } catch (_) {
        return {};
    }
};

const saveHistorySectionState = (sections) => {
    try {
        window.localStorage?.setItem(
            CHAT_HISTORY_SECTIONS_STORAGE_KEY,
            JSON.stringify(normalizeHistorySectionState(sections)),
        );
    } catch (_) {
        // ignore storage write errors
    }
};

const saveMemoHistorySectionState = (sections) => {
    try {
        window.localStorage?.setItem(
            MEMO_HISTORY_SECTIONS_STORAGE_KEY,
            JSON.stringify(normalizeMemoHistorySectionState(sections)),
        );
    } catch (_) {
        // ignore storage write errors
    }
};

const initialHistorySectionState = (config = {}) => {
    const storedSections = loadHistorySectionState();
    const serverSections = normalizeHistorySectionState(config.openHistorySections || {});

    return CHAT_HISTORY_SECTION_KEYS.reduce((state, section) => ({
        ...state,
        [section]: Boolean(storedSections[section] || serverSections[section] || (section === 'seven' && config.showOlderChats)),
    }), {});
};

const initialMemoHistorySectionState = (config = {}) => {
    const storedSections = loadMemoHistorySectionState();
    const serverSections = normalizeMemoHistorySectionState(config.openHistorySections || {});

    return MEMO_HISTORY_SECTION_KEYS.reduce((state, section) => ({
        ...state,
        [section]: Boolean(storedSections[section] || serverSections[section]),
    }), {});
};

const loadPendingConversationMarkers = () => {
    try {
        const raw = window.localStorage?.getItem(CHAT_PENDING_STORAGE_KEY);
        if (!raw) return {};
        const parsed = JSON.parse(raw);
        return parsed && typeof parsed === 'object' ? parsed : {};
    } catch (_) {
        return {};
    }
};

const savePendingConversationMarkers = (markers) => {
    try {
        window.localStorage?.setItem(CHAT_PENDING_STORAGE_KEY, JSON.stringify(markers || {}));
    } catch (_) {
        // ignore storage write errors
    }
};

const loadFreshPendingConversationMarkerIds = () => {
    const markers = loadPendingConversationMarkers();
    const now = Date.now();
    let changed = false;
    const freshIds = [];

    Object.entries(markers).forEach(([id, marker]) => {
        const conversationId = Number(id);
        const markerAgeMs = marker?.ts ? now - Number(marker.ts) : Number.POSITIVE_INFINITY;
        const isFresh = Number.isFinite(conversationId)
            && conversationId > 0
            && markerAgeMs >= 0
            && markerAgeMs <= CHAT_PENDING_MARKER_TTL_MS;

        if (isFresh) {
            freshIds.push(conversationId);
            return;
        }

        delete markers[id];
        changed = true;
    });

    if (changed) {
        savePendingConversationMarkers(markers);
    }

    return normalizeConversationIds(freshIds);
};

const loadStoredConversationIds = (storageKey) => {
    try {
        const raw = window.localStorage?.getItem(storageKey);
        if (!raw) return [];
        const parsed = JSON.parse(raw);
        if (!Array.isArray(parsed)) return [];

        return normalizeConversationIds(parsed);
    } catch (_) {
        return [];
    }
};

const saveStoredConversationIds = (storageKey, ids) => {
    try {
        window.localStorage?.setItem(storageKey, JSON.stringify(normalizeConversationIds(ids)));
    } catch (_) {
        // ignore storage write errors
    }
};

const isDarkThemeEnabled = () => localStorage.getItem('theme') === 'dark';

const registerChatPageData = (Alpine) => {
    if (hasRegisteredChatPageData || !Alpine) {
        return;
    }

    hasRegisteredChatPageData = true;

    Alpine.data('chatLayout', (config = {}) => ({
        activeTab: config.activeTab || 'chat',
        darkMode: isDarkThemeEnabled(),
        isMobile: window.matchMedia('(max-width: 1023px)').matches,
        showLeftSidebar: !window.matchMedia('(max-width: 1023px)').matches,
        showRightSidebar: !window.matchMedia('(max-width: 1023px)').matches,
        isSwitchingConversation: false,
        isDraggingFile: false,
        dragDepth: 0,
        dropError: '',

        init() {
            this.$watch('darkMode', (value) => {
                localStorage.setItem('theme', value ? 'dark' : 'light');
                document.documentElement.classList.toggle('dark', value);
            });

            document.documentElement.classList.toggle('dark', this.darkMode);

            const mediaQuery = window.matchMedia('(max-width: 1023px)');
            const syncResponsiveState = (event) => {
                this.isMobile = event.matches;

                if (this.isMobile) {
                    this.showLeftSidebar = false;
                    this.showRightSidebar = false;

                    return;
                }

                this.showLeftSidebar = true;
                this.showRightSidebar = true;
            };

            mediaQuery.addEventListener('change', syncResponsiveState);
        },

        setTab(tab) {
            if (!['chat', 'memo'].includes(tab)) {
                return;
            }

            this.activeTab = tab;
            this.$wire.set('tab', tab);
        },

        onDragEnter(event) {
            if (!this.hasFiles(event)) {
                return;
            }

            this.dragDepth += 1;
            this.isDraggingFile = true;
        },

        onDragOver(event) {
            if (!this.hasFiles(event)) {
                return;
            }

            this.isDraggingFile = true;
        },

        onDragLeave(event) {
            if (!this.hasFiles(event)) {
                return;
            }

            this.dragDepth = Math.max(this.dragDepth - 1, 0);

            if (this.dragDepth === 0) {
                this.isDraggingFile = false;
            }
        },

        onDropFile(event) {
            this.dragDepth = 0;
            this.isDraggingFile = false;

            const files = event.dataTransfer?.files;

            if (!files || files.length === 0) {
                return;
            }

            if (files.length > 1) {
                this.showDropError('Hanya bisa upload 1 file sekaligus.');

                return;
            }

            const input = document.querySelector('[x-ref="chatAttachmentInput"]');

            if (!input) {
                return;
            }

            input.files = files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
            this.showRightSidebar = true;
        },

        hasFiles(event) {
            return Array.from(event.dataTransfer?.types || []).includes('Files');
        },

        showDropError(message) {
            this.dropError = message;
            this.$dispatch('show-drop-error', { message });

            setTimeout(() => {
                if (this.dropError === message) {
                    this.dropError = '';
                }
            }, 3500);
        },
    }));

    Alpine.data('assistantTypewriter', (config = {}) => ({
        ...createAssistantTypewriterState(config),

        init() {
            this.queueAssistantTypewriterFinalText(this.typewriterFullText);
        },
    }));

    Alpine.data('chatMessages', () => ({
        ...createAssistantTypewriterState({
            initialDelay: 30,
            emptyInitialDelay: 0,
        }),
        optimisticUserMessage: '',
        isSwitchingConversation: false,
        streaming: false,
        modelName: '',
        loadingContext: 'general',
        loadingPhase: 'AI sedang berpikir',
        loadingPhaseKey: 0,
        loadingPhaseTimeout: null,
        phase2Timeout: null,
        pendingStaleTimeout: null,
        stalePendingWarning: '',
        hasFirstAssistantChunk: false,
        phase1Done: false,
        phase2Done: false,
        shimmerActive: false,
        streamingFinalText: null,
        streamedAssistantMessageId: null,
        _messageCompleteHandler: null,
        chatMutationObserver: null,
        wireListeners: [],
        windowListeners: [],
        activeEventSources: {},
        _chatStreamHandler: null,

        get streamingText() {
            return this.typewriterDisplayedText;
        },

        set streamingText(value) {
            this.typewriterDisplayedText = String(value || '');
        },

        get streamingHtml() {
            return this.typewriterHtml;
        },

        set streamingHtml(value) {
            this.typewriterHtml = String(value || '');
        },

        get sources() {
            return this.typewriterSources;
        },

        set sources(value) {
            this.typewriterSources = Array.isArray(value) ? value : [];
        },

        get sourcesVisible() {
            return this.typewriterSourcesVisible;
        },

        set sourcesVisible(value) {
            this.typewriterSourcesVisible = Boolean(value);
        },

        getConversationMeta() {
            const metaEl = this.$el.querySelector('[data-chat-conversation-id]');
            if (!metaEl) return { conversationId: null, lastRole: '', hasPendingUserWithoutAssistant: false };
            const conversationId = Number(metaEl.dataset.chatConversationId || '');
            const lastRole = metaEl.dataset.chatLastMessageRole || '';
            const lastUserId = Number(metaEl.dataset.chatLastUserMessageId || '0');
            const lastAssistantId = Number(metaEl.dataset.chatLastAssistantMessageId || '0');
            const lastUserCreatedAt = metaEl.dataset.chatLastUserMessageCreatedAt || '';
            const lastUserCreatedAtMs = lastUserCreatedAt ? Date.parse(lastUserCreatedAt) : NaN;
            const pendingAgeMs = Number.isFinite(lastUserCreatedAtMs)
                ? Math.max(Date.now() - lastUserCreatedAtMs, 0)
                : null;

            return {
                conversationId: Number.isFinite(conversationId) ? conversationId : null,
                lastRole,
                hasPendingUserWithoutAssistant: lastUserId > 0 && (lastAssistantId === 0 || lastAssistantId < lastUserId),
                pendingAgeMs,
            };
        },

        markConversationPending(conversationId, loadingContext = 'general') {
            const id = Number(conversationId);
            if (!Number.isFinite(id) || id <= 0) return;
            const markers = loadPendingConversationMarkers();
            markers[id] = { loadingContext, ts: Date.now() };
            savePendingConversationMarkers(markers);
        },

        clearConversationPending(conversationId) {
            const id = Number(conversationId);
            if (!Number.isFinite(id) || id <= 0) return;
            const markers = loadPendingConversationMarkers();
            if (Object.prototype.hasOwnProperty.call(markers, id)) {
                delete markers[id];
                savePendingConversationMarkers(markers);
            }
        },

        maybeRestorePendingPlaceholder() {
            const { conversationId, hasPendingUserWithoutAssistant, pendingAgeMs } = this.getConversationMeta();
            if (!conversationId || !hasPendingUserWithoutAssistant) {
                return;
            }

            const markers = loadPendingConversationMarkers();
            const marker = markers[conversationId];
            const markerAgeMs = marker?.ts ? Date.now() - Number(marker.ts) : Number.POSITIVE_INFINITY;
            const hasFreshMarker = marker && markerAgeMs >= 0 && markerAgeMs <= CHAT_PENDING_MARKER_TTL_MS;
            const hasRecentPendingUser = pendingAgeMs !== null && pendingAgeMs <= CHAT_PENDING_RECENT_TTL_MS;

            if (marker && !hasFreshMarker) {
                delete markers[conversationId];
                savePendingConversationMarkers(markers);
                this.stalePendingWarning = 'Jawaban sebelumnya belum selesai atau koneksi terputus. Coba kirim ulang pertanyaan bila jawaban tidak muncul.';
            }

            if (!hasFreshMarker && !hasRecentPendingUser) {
                return;
            }

            if (pendingAgeMs !== null && pendingAgeMs > CHAT_PENDING_STALE_WARNING_MS) {
                this.stalePendingWarning = 'Jawaban AI masih belum selesai. Anda bisa menunggu sebentar atau kirim ulang pertanyaan bila diperlukan.';
            }

            const loadingContext = hasFreshMarker ? (marker.loadingContext || 'general') : 'general';
            this.markConversationPending(conversationId, loadingContext);
            this.startStreamingPlaceholder(loadingContext);
            this.scrollToBottom();
        },

        init() {
            this.$el.dataset.chatMessagesReady = 'true';
            this.scrollToBottom();

            const chatBox = this.$refs.chatBox;

            if (chatBox) {
                this.chatMutationObserver = new MutationObserver(() => this.scrollToBottom());
                this.chatMutationObserver.observe(chatBox, { childList: true, subtree: true, characterData: true });
            }

            this._messageCompleteHandler = () => this.resetStreamingState();
            window.addEventListener('message-complete', this._messageCompleteHandler);
            this.registerWindowListener('chat-mark-pending', (event) => {
                const detail = event?.detail || {};
                this.markConversationPending(detail.conversationId, detail.loadingContext || 'general');
            });
            this.registerWindowListener('user-message-rejected', (event) => {
                this.optimisticUserMessage = '';

                const detail = event?.detail || {};
                const loadingContext = detail.loadingContext || this.loadingContext || 'general';
                const { conversationId, hasPendingUserWithoutAssistant } = this.getConversationMeta();

                if (conversationId && hasPendingUserWithoutAssistant) {
                    this.startStreamingPlaceholder(loadingContext);
                    this.scrollToBottom();

                    return;
                }

                this.resetStreamingState();
            });
            this.registerWireListener('assistant-output', (data) => {
                this.handleAssistantChunk(data[0] || '');
            });
            this.registerWireListener('model-name', (data) => {
                this.modelName = data[0] || '';
            });
            this.registerWireListener('assistant-sources', (data) => {
                this.setStreamingSources(data[0] || []);
            });
            this.registerWireListener('assistant-message-persisted', (data) => {
                const payload = normalizeWirePayload(data);
                const ackConversationId = Number(payload.conversationId || 0);
                const ackMessageId = Number(payload.messageId || 0);
                const preserveStream = Boolean(payload.preserveStream);
                const { conversationId } = this.getConversationMeta();
                const targetConversationId = ackConversationId > 0 ? ackConversationId : conversationId;
                const shouldPreserveCurrentStream = preserveStream
                    && ackMessageId > 0
                    && targetConversationId
                    && this.isActiveConversation(targetConversationId)
                    && Number(this.streamedAssistantMessageId || 0) === ackMessageId;

                if (shouldPreserveCurrentStream) {
                    this.streaming = true;
                    this.streamedAssistantMessageId = ackMessageId;
                    this.revealStreamingSources();
                } else if (targetConversationId && this.isActiveConversation(targetConversationId)) {
                    this.resetStreamingState();
                }

                if (targetConversationId) this.clearConversationPending(targetConversationId);
            });
            this.registerWireListener('user-message-acked', () => {
                this.optimisticUserMessage = '';
                this.scrollToBottom();
            });

            this._chatStreamHandler = (event) => {
                const detail = event?.detail || {};
                const conversationId = Number(detail.conversationId || 0);
                const documentIds = detail.documentIds || [];
                const webSearchMode = Boolean(detail.webSearchMode);
                const loadingContext = detail.loadingContext || 'general';
                if (conversationId > 0) {
                    this.openChatStream(conversationId, documentIds, webSearchMode, loadingContext);
                }
            };
            window.addEventListener('chat-open-stream', this._chatStreamHandler);

            this.$nextTick(() => this.maybeRestorePendingPlaceholder());
        },

        registerWireListener(event, callback) {
            const cleanup = this.$wire.on(event, callback);
            if (typeof cleanup === 'function') {
                this.wireListeners.push(cleanup);
            }
        },

        registerWindowListener(event, callback) {
            window.addEventListener(event, callback);
            this.windowListeners.push(() => window.removeEventListener(event, callback));
        },

        isActiveConversation(conversationId) {
            const id = Number(conversationId || 0);
            const { conversationId: activeConversationId } = this.getConversationMeta();

            return id > 0 && Number(activeConversationId || 0) === id;
        },

        closeChatStream(conversationId = null) {
            const id = Number(conversationId || 0);

            if (id > 0) {
                if (this.activeEventSources?.[id]) {
                    this.activeEventSources[id].close();
                    delete this.activeEventSources[id];
                }

                return;
            }

            Object.values(this.activeEventSources || {}).forEach((eventSource) => {
                eventSource?.close();
            });
            this.activeEventSources = {};
        },

        ensureVisibleStreamPlaceholder(conversationId, loadingContext = 'general') {
            if (!this.isActiveConversation(conversationId)) {
                return false;
            }

            if (!this.streaming) {
                this.startStreamingPlaceholder(loadingContext);
            }

            return true;
        },

        openChatStream(conversationId, documentIds, webSearchMode, loadingContext) {
            this.closeChatStream(conversationId);

            // History tidak dikirim via query string — server reconstruct dari DB
            // untuk menghindari URL terlalu panjang (414) dan konten chat bocor ke log.
            const params = new URLSearchParams({
                document_ids: JSON.stringify(documentIds),
                web_search_mode: webSearchMode ? '1' : '0',
            });

            const url = `/chat/stream/${conversationId}?${params.toString()}`;
            const es = new EventSource(url);
            this.activeEventSources = {
                ...this.activeEventSources,
                [conversationId]: es,
            };
            const streamState = {
                finalText: null,
                hasFirstAssistantChunk: false,
                streamedAssistantMessageId: null,
            };

            es.addEventListener('chunk', (e) => {
                // Server menggunakan multi-line SSE framing — browser otomatis join dengan \n
                const text = e.data || '';
                streamState.hasFirstAssistantChunk = streamState.hasFirstAssistantChunk || text !== '';

                if (!this.ensureVisibleStreamPlaceholder(conversationId, loadingContext)) {
                    return;
                }

                this.handleAssistantChunk(text);
            });

            es.addEventListener('model-name', (e) => {
                if (!this.isActiveConversation(conversationId)) {
                    return;
                }

                this.modelName = e.data || '';
            });

            es.addEventListener('sources', (e) => {
                if (!this.isActiveConversation(conversationId)) {
                    return;
                }

                try {
                    const parsed = JSON.parse(e.data || '');
                    if (Array.isArray(parsed)) {
                        this.setStreamingSources(parsed);
                    }
                } catch (_) {
                    // ignore malformed sources
                }
            });

            es.addEventListener('message-id', (e) => {
                const messageId = Number(e.data || 0);
                streamState.streamedAssistantMessageId = Number.isFinite(messageId) && messageId > 0 ? messageId : null;

                if (this.isActiveConversation(conversationId)) {
                    this.streamedAssistantMessageId = streamState.streamedAssistantMessageId;
                }
            });

            es.addEventListener('final-content', (e) => {
                const content = e.data || '';
                streamState.finalText = content !== '' ? content : null;

                if (this.isActiveConversation(conversationId)) {
                    this.streamingFinalText = streamState.finalText;
                }
            });

            es.addEventListener('error', (e) => {
                const msg = e.data || '';
                if (msg && this.isActiveConversation(conversationId)) {
                    this.stalePendingWarning = msg;
                }
                this.closeChatStream(conversationId);
                // Polling will recover the final state from DB
            });

            es.addEventListener('done', () => {
                this.closeChatStream(conversationId);
                const streamedMessageId = streamState.streamedAssistantMessageId;

                if (this.isActiveConversation(conversationId)) {
                    if (streamState.finalText) {
                        this.queueFinalStreamingText(streamState.finalText);
                    }
                }

                // Trigger wire refresh so Livewire loads the persisted message
                // only when relevant to the visible conversation. Inactive
                // completions still clear pending/sidebar state via dispatched
                // assistant-message-persisted events.
                this.refreshPendingAfterStreamingSettles(conversationId, streamedMessageId);
            });

            es.onerror = () => {
                this.closeChatStream(conversationId);
            };
        },

        destroy() {
            this.clearLoadingPhaseTimeout();
            this.clearPhase2Timeout();
            this.clearPendingStaleTimeout();
            this.clearStreamingTypewriter();
            this.clearStreamingMarkdownRender();
            this.closeChatStream();
            if (this._chatStreamHandler) {
                window.removeEventListener('chat-open-stream', this._chatStreamHandler);
                this._chatStreamHandler = null;
            }
            if (this._messageCompleteHandler) {
                window.removeEventListener('message-complete', this._messageCompleteHandler);
                this._messageCompleteHandler = null;
            }
            if (this.chatMutationObserver) {
                this.chatMutationObserver.disconnect();
                this.chatMutationObserver = null;
            }
            if (this.wireListeners && this.wireListeners.length > 0) {
                this.wireListeners.forEach((cleanup) => {
                    if (typeof cleanup === 'function') {
                        try {
                            cleanup();
                        } catch (e) {
                            // Defensive: ignore cleanup errors
                        }
                    }
                });
                this.wireListeners = [];
            }
            if (this.windowListeners && this.windowListeners.length > 0) {
                this.windowListeners.forEach((cleanup) => {
                    if (typeof cleanup === 'function') {
                        try {
                            cleanup();
                        } catch (e) {
                            // Defensive: ignore cleanup errors
                        }
                    }
                });
                this.windowListeners = [];
            }
        },

        clearLoadingPhaseTimeout() {
            if (this.loadingPhaseTimeout) {
                window.clearTimeout(this.loadingPhaseTimeout);
                this.loadingPhaseTimeout = null;
            }
        },

        clearPhase2Timeout() {
            if (this.phase2Timeout) {
                window.clearTimeout(this.phase2Timeout);
                this.phase2Timeout = null;
            }
        },

        clearPendingStaleTimeout() {
            if (this.pendingStaleTimeout) {
                window.clearTimeout(this.pendingStaleTimeout);
                this.pendingStaleTimeout = null;
            }
        },

        clearStreamingTypewriter() {
            this.clearAssistantTypewriterTimer();
        },

        clearStreamingMarkdownRender() {
            this.clearAssistantTypewriterMarkdownRender();
        },

        renderStreamingMarkdownNow() {
            this.renderAssistantTypewriterMarkdownNow();
        },

        scheduleStreamingMarkdownRender() {
            this.scheduleAssistantTypewriterMarkdownRender();
        },

        setStreamingSources(sources) {
            this.setAssistantTypewriterSources(sources);
        },

        revealStreamingSources() {
            this.revealAssistantTypewriterSources();
        },

        queueFinalStreamingText(finalText) {
            this.streamingFinalText = typeof finalText === 'string' ? finalText : null;
            this.streaming = true;
            this.queueAssistantTypewriterFinalText(finalText);
        },

        refreshPendingAfterStreamingSettles(conversationId, streamedMessageId = null) {
            const refreshPendingState = () => {
                if (!this.$wire || typeof this.$wire.refreshPendingChatState !== 'function') {
                    return;
                }

                if (streamedMessageId) {
                    this.$wire.refreshPendingChatState(streamedMessageId, this.isActiveConversation(conversationId));
                    return;
                }

                this.$wire.refreshPendingChatState();
            };

            if (!this.isActiveConversation(conversationId)) {
                refreshPendingState();
                return;
            }

            this.afterStreamingTypewriterSettles(() => {
                this.revealStreamingSources();

                const refreshDelay = this.sourcesVisible ? CHAT_SOURCE_REVEAL_BEFORE_REFRESH_MS : 0;
                if (refreshDelay > 0) {
                    window.setTimeout(refreshPendingState, refreshDelay);
                    return;
                }

                refreshPendingState();
            });
        },

        moveToAnswerPhase() {
            this.clearLoadingPhaseTimeout();
            this.clearPhase2Timeout();
            this.phase1Done = true;
            this.phase2Done = true;
            this.shimmerActive = false;

            if (this.loadingPhase !== 'Menampilkan jawaban') {
                this.loadingPhase = 'Menampilkan jawaban';
                this.loadingPhaseKey++;
            }
        },

        handleAssistantChunk(text) {
            const chunk = String(text || '');
            if (chunk === '') {
                return;
            }

            this.appendAssistantTypewriterText(chunk);
            this.streaming = true;
            this.hasFirstAssistantChunk = true;
            this.moveToAnswerPhase();
        },

        startStreamingTypewriter() {
            this.startAssistantTypewriter();
        },

        afterStreamingTypewriterSettles(callback) {
            this.afterAssistantTypewriterSettles(callback);
        },

        runStreamingTypewriterDoneCallback() {
            this.runAssistantTypewriterDoneCallback();
        },

        // Dipanggil setelah fase 2 selesai sebagai fallback bila chunk belum
        // datang. Chunk pertama tetap memindahkan UI ke typewriter seketika.
        tryShowAnswer() {
            this.phase2Done = true;
            if (this.hasFirstAssistantChunk) {
                this.moveToAnswerPhase();
            }
        },

        startStreamingPlaceholder(context = 'general') {
            this.resetStreamingState(false);
            this.streaming = true;
            this.loadingContext = context;
            this.loadingPhaseKey++;
            this.loadingPhase = this.loadingPhaseLabels()[0];
            this.shimmerActive = true;
            this.stalePendingWarning = '';
            this.phase1Done = false;
            this.phase2Done = false;
            this.clearPendingStaleTimeout();
            this.pendingStaleTimeout = window.setTimeout(() => {
                if (this.streaming && !this.hasFirstAssistantChunk) {
                    this.stalePendingWarning = 'Jawaban memakan waktu lebih lama dari biasanya. Tetap tunggu atau kirim ulang pertanyaan jika tidak ada perubahan.';
                }
            }, CHAT_PENDING_STALE_WARNING_MS);

            const labels = this.loadingPhaseLabels();

            if (labels.length > 2) {
                // Kontekstual: fase awal tetap memberi sinyal pekerjaan,
                // tetapi chunk pertama langsung memindahkan UI ke typewriter.
                this.loadingPhaseTimeout = window.setTimeout(() => {
                    this.phase1Done = true;
                    this.loadingPhase = labels[1]; // "AI sedang berpikir"
                    this.loadingPhaseKey++;

                    this.phase2Timeout = window.setTimeout(() => {
                        this.tryShowAnswer();
                    }, CHAT_LOADING_PHASE_DELAY_MS);
                }, CHAT_LOADING_PHASE_DELAY_MS);
            } else {
                // General: langsung "AI sedang berpikir", lalu tunggu chunk.
                this.phase1Done = true;
                this.phase2Timeout = window.setTimeout(() => {
                    this.tryShowAnswer();
                }, CHAT_LOADING_PHASE_DELAY_MS);
            }
        },

        resetStreamingState(stopStreaming = true) {
            this.clearLoadingPhaseTimeout();
            this.clearPhase2Timeout();
            this.clearStreamingTypewriter();
            this.clearStreamingMarkdownRender();

            this.loadingPhaseKey = 0;
            this.hasFirstAssistantChunk = false;
            this.phase1Done = false;
            this.phase2Done = false;
            this.loadingContext = 'general';
            this.loadingPhase = 'AI sedang berpikir';
            this.shimmerActive = false;
            this.stalePendingWarning = '';
            this.streamingText = '';
            this.streamingHtml = '';
            this.streamingFinalText = null;
            this.modelName = '';
            this.sources = [];
            this.sourcesVisible = false;
            this.streamedAssistantMessageId = null;

            if (stopStreaming) {
                this.streaming = false;
            }

            const { conversationId, hasPendingUserWithoutAssistant } = this.getConversationMeta();
            if (conversationId && !hasPendingUserWithoutAssistant) {
                this.clearConversationPending(conversationId);
            }
        },

        loadingPhaseLabels() {
            if (this.loadingContext === 'web-search') {
                return ['Mencari jawaban', 'AI sedang berpikir', 'Menampilkan jawaban'];
            }

            if (this.loadingContext === 'documents') {
                return ['Sedang membaca dokumen', 'AI sedang berpikir', 'Menampilkan jawaban'];
            }

            if (this.loadingContext === 'hybrid') {
                return ['Sedang membaca dokumen + Mencari di web', 'AI sedang berpikir', 'Menampilkan jawaban'];
            }

            return ['AI sedang berpikir', 'Menampilkan jawaban'];
        },

        scrollToBottom(smooth = false) {
            this.$nextTick(() => {
                const chatBox = this.$refs.chatBox;

                if (!chatBox) {
                    return;
                }

                chatBox.scrollTo({
                    top: chatBox.scrollHeight,
                    behavior: smooth ? 'smooth' : 'auto',
                });
            });
        },
    }));

    Alpine.data('chatHistory', (config = {}) => ({
        activeConversationId: config.activeConversationId ? Number(config.activeConversationId) : null,
        openHistorySections: initialHistorySectionState(config),
        historySectionKeys: (config.historySectionKeys || CHAT_HISTORY_SECTION_KEYS).map((section) => String(section)),
        historySearch: '',
        historyTitles: (config.historyTitles || []).map((title) => String(title || '')),
        pendingConversationIds: normalizeConversationIds([
            ...(config.pendingConversationIds || []),
            ...loadFreshPendingConversationMarkerIds(),
        ]),
        completedConversationIds: loadStoredConversationIds(CHAT_COMPLETED_STORAGE_KEY),
        loadingConversationId: null,
        isNavigating: false,
        navigationToken: 0,
        pendingNavigation: null,
        awaitingMessageAck: false,
        flashMessage: '',
        _chatMarkPendingHandler: null,
        _messageSendHandler: null,
        _messageCompleteHandler: null,
        _assistantPersistedWindowHandler: null,
        _assistantPersistedCleanup: null,
        _pendingStateCleanup: null,
        _userMessageAckedCleanup: null,
        _userMessageAckedWindowHandler: null,
        _userMessageRejectedCleanup: null,
        _userMessageRejectedWindowHandler: null,
        _messageAckTimeout: null,

        init() {
            this.$nextTick(() => this.syncActiveHistoryItem());
            this.$watch('activeConversationId', () => this.syncActiveHistoryItem());
            this.markConversationRead(this.activeConversationId);
            window.chatHistoryNavigateToNewChat = (event) => this.navigateToNewChat(event);
            this._messageSendHandler = () => {
                this.startMessageAckWait();
            };
            this._messageCompleteHandler = () => {
                this.clearMessageAckWait();
            };
            window.addEventListener('message-send', this._messageSendHandler);
            window.addEventListener('message-complete', this._messageCompleteHandler);
            this._chatMarkPendingHandler = (event) => {
                const id = Number(event?.detail?.conversationId || 0);
                this.markConversationPending(id);
            };
            window.addEventListener('chat-mark-pending', this._chatMarkPendingHandler);
            this._assistantPersistedWindowHandler = (event) => {
                const id = Number(event?.detail?.conversationId || 0);
                this.markConversationComplete(id);
            };
            window.addEventListener('assistant-message-persisted', this._assistantPersistedWindowHandler);
            this._assistantPersistedCleanup = this.$wire.on('assistant-message-persisted', (data) => {
                const payload = normalizeWirePayload(data);
                const id = Number(payload.conversationId || 0);
                this.markConversationComplete(id);
            });
            this._pendingStateCleanup = this.$wire.on('chat-pending-state-updated', (data) => {
                const payload = normalizeWirePayload(data);
                this.syncPendingConversations(payload.pendingConversationIds || []);
            });
            this._userMessageAckedCleanup = this.$wire.on('user-message-acked', () => {
                this.clearMessageAckWait();
            });
            this._userMessageAckedWindowHandler = () => {
                this.clearMessageAckWait();
            };
            window.addEventListener('user-message-acked', this._userMessageAckedWindowHandler);
            this._userMessageRejectedCleanup = this.$wire.on('user-message-rejected', () => {
                this.clearMessageAckWait();
            });
            this._userMessageRejectedWindowHandler = () => {
                this.clearMessageAckWait();
            };
            window.addEventListener('user-message-rejected', this._userMessageRejectedWindowHandler);
            window.addEventListener('chat-success-toast', (event) => {
                this.flashMessage = event?.detail?.message || '';
                if (!this.flashMessage) return;
                window.setTimeout(() => {
                    if (this.flashMessage === (event?.detail?.message || '')) {
                        this.flashMessage = '';
                    }
                }, 5000);
            });
        },

        startMessageAckWait() {
            this.clearMessageAckTimeout();
            this.awaitingMessageAck = true;
            this._messageAckTimeout = window.setTimeout(() => {
                this.releaseMessageAckWait(true);
            }, CHAT_MESSAGE_ACK_TIMEOUT_MS);
        },

        clearMessageAckTimeout() {
            if (this._messageAckTimeout) {
                window.clearTimeout(this._messageAckTimeout);
                this._messageAckTimeout = null;
            }
        },

        clearMessageAckWait() {
            this.releaseMessageAckWait(false);
        },

        releaseMessageAckWait(useHardNavigationFallback = false) {
            this.clearMessageAckTimeout();
            this.awaitingMessageAck = false;

            if (useHardNavigationFallback && this.pendingNavigation?.href) {
                const fallbackHref = this.pendingNavigation.href;
                this.pendingNavigation = null;
                this.isNavigating = false;
                this.loadingConversationId = null;
                window.location.assign(fallbackHref);

                return;
            }

            this.flushPendingNavigation();
        },

        isActive(id) {
            return this.activeConversationId === Number(id);
        },

        isLoading(id) {
            return this.loadingConversationId === Number(id);
        },

        isPending(id) {
            return this.pendingConversationIds.includes(Number(id));
        },

        isCompleteUnread(id) {
            const conversationId = Number(id);

            return !this.isPending(conversationId) && this.completedConversationIds.includes(conversationId);
        },

        normalizedHistorySearch() {
            return String(this.historySearch || '').trim().toLowerCase();
        },

        isSearchingHistory() {
            return this.normalizedHistorySearch().length > 0;
        },

        isHistoryVisible(title) {
            const search = this.normalizedHistorySearch();
            if (!search) {
                return true;
            }

            return String(title || '').toLowerCase().includes(search);
        },

        hasHistorySearchResults() {
            const search = this.normalizedHistorySearch();
            if (!search) {
                return true;
            }

            return this.historyTitles.some((title) => String(title || '').toLowerCase().includes(search));
        },

        clearHistorySearch() {
            this.historySearch = '';
        },

        availableHistorySectionKeys() {
            return (this.historySectionKeys || [])
                .map((section) => String(section))
                .filter((section) => CHAT_HISTORY_SECTION_KEYS.includes(section));
        },

        allHistorySectionsOpen() {
            const sections = this.availableHistorySectionKeys();

            return sections.length > 0 && sections.every((section) => Boolean(this.openHistorySections?.[section]));
        },

        persistOpenHistorySections() {
            saveHistorySectionState(this.openHistorySections);
        },

        isHistorySectionOpen(section) {
            return this.isSearchingHistory() || Boolean(this.openHistorySections?.[section]);
        },

        toggleHistorySection(section) {
            if (!section) {
                return;
            }

            this.openHistorySections = {
                ...this.openHistorySections,
                [section]: !this.openHistorySections?.[section],
            };
            this.persistOpenHistorySections();
        },

        toggleAllHistory() {
            const shouldOpen = !this.allHistorySectionsOpen();
            const nextSections = { ...this.openHistorySections };

            this.availableHistorySectionKeys().forEach((section) => {
                nextSections[section] = shouldOpen;
            });

            this.openHistorySections = nextSections;
            this.persistOpenHistorySections();
        },

        sectionHasActivity(ids) {
            const sectionIds = (ids || []).map((id) => Number(id)).filter(Boolean);

            return sectionIds.some((id) => this.isPending(id) || this.isCompleteUnread(id));
        },

        syncPendingConversations(ids) {
            const pendingIds = normalizeConversationIds(ids);
            const pendingSet = new Set(pendingIds);
            const markers = loadPendingConversationMarkers();
            let changed = false;

            Object.keys(markers).forEach((id) => {
                if (!pendingSet.has(Number(id))) {
                    delete markers[id];
                    changed = true;
                }
            });

            if (changed) {
                savePendingConversationMarkers(markers);
            }

            this.pendingConversationIds = pendingIds;
        },

        markConversationPending(id) {
            const conversationId = Number(id);
            if (!Number.isFinite(conversationId) || conversationId <= 0) {
                return;
            }

            if (!this.pendingConversationIds.includes(conversationId)) {
                this.pendingConversationIds.push(conversationId);
            }

            this.completedConversationIds = this.completedConversationIds.filter((completedId) => completedId !== conversationId);
            saveStoredConversationIds(CHAT_COMPLETED_STORAGE_KEY, this.completedConversationIds);
        },

        markConversationComplete(id) {
            const conversationId = Number(id);
            if (!Number.isFinite(conversationId) || conversationId <= 0) {
                return;
            }

            this.pendingConversationIds = this.pendingConversationIds.filter((pendingId) => pendingId !== conversationId);

            if (this.activeConversationId === conversationId) {
                this.markConversationRead(conversationId);

                return;
            }

            if (!this.completedConversationIds.includes(conversationId)) {
                this.completedConversationIds.push(conversationId);
                saveStoredConversationIds(CHAT_COMPLETED_STORAGE_KEY, this.completedConversationIds);
            }
        },

        markConversationRead(id) {
            const conversationId = Number(id);
            if (!Number.isFinite(conversationId) || conversationId <= 0) {
                return;
            }

            const nextIds = this.completedConversationIds.filter((completedId) => completedId !== conversationId);
            if (nextIds.length !== this.completedConversationIds.length) {
                this.completedConversationIds = nextIds;
                saveStoredConversationIds(CHAT_COMPLETED_STORAGE_KEY, this.completedConversationIds);
            }
        },

        setActiveConversation(id) {
            const conversationId = id ? Number(id) : null;
            this.activeConversationId = Number.isFinite(conversationId) ? conversationId : null;
            this.syncActiveHistoryItem();
            this.$nextTick(() => this.syncActiveHistoryItem());
        },

        syncActiveHistoryItem() {
            if (!this.$root) {
                return;
            }

            this.$root.querySelectorAll('[data-chat-history-id]').forEach((button) => {
                const isActive = Number(button.dataset.chatHistoryId) === this.activeConversationId;
                button.classList.toggle('is-active', isActive);
                button.setAttribute('aria-current', isActive ? 'page' : 'false');

                if (isActive) {
                    const section = button.closest('[data-chat-history-section]')?.dataset?.chatHistorySection;
                    if (section && CHAT_HISTORY_SECTION_KEYS.includes(section) && !this.openHistorySections?.[section]) {
                        this.openHistorySections = {
                            ...this.openHistorySections,
                            [section]: true,
                        };
                        this.persistOpenHistorySections();
                    }
                }
            });
        },

        queueNavigationUntilMessageAck(navigation) {
            this.pendingNavigation = navigation;
            this.navigationToken += 1;
            this.loadingConversationId = navigation?.type === 'conversation' ? Number(navigation.id) : null;
            this.isNavigating = true;

            if (navigation?.type === 'new') {
                this.setActiveConversation(null);
                this.$dispatch('chat-new-optimistic');
            }

            this.$dispatch('conversation-loading');
        },

        flushPendingNavigation() {
            if (this.awaitingMessageAck || !this.pendingNavigation) {
                return;
            }

            const navigation = this.pendingNavigation;
            this.pendingNavigation = null;
            this.isNavigating = false;

            if (navigation.type === 'conversation') {
                this.navigateToConversationUrl(navigation.href, navigation.id, { allowWhileNavigating: true });
                return;
            }

            if (navigation.type === 'new') {
                this.navigateToNewChatUrl(navigation.href, { allowWhileNavigating: true });
            }
        },

        loadConversation(id) {
            const conversationId = Number(id);
            const previousConversationId = this.activeConversationId;

            if (previousConversationId === conversationId) {
                return Promise.resolve({ stale: false });
            }

            const navigationToken = this.navigationToken + 1;
            this.navigationToken = navigationToken;
            this.loadingConversationId = conversationId;
            this.isNavigating = true;
            this.$dispatch('conversation-loading');

            return this.$wire.loadConversation(conversationId)
                .then(() => {
                    if (this.navigationToken !== navigationToken) {
                        return { stale: true };
                    }

                    this.setActiveConversation(conversationId);

                    return { stale: false };
                })
                .catch((error) => {
                    if (this.navigationToken === navigationToken) {
                        this.setActiveConversation(previousConversationId);
                    }

                    throw error;
                })
                .finally(() => {
                    if (this.navigationToken !== navigationToken) {
                        return;
                    }

                    if (this.loadingConversationId === conversationId) {
                        this.loadingConversationId = null;
                    }

                    this.isNavigating = false;
                    this.$dispatch('conversation-loaded');
                });
        },

        navigateToConversationUrl(targetHref, id, options = {}) {
            const conversationId = Number(id);
            if (!Number.isFinite(conversationId)) {
                window.location.assign(targetHref);
                return;
            }

            if (this.awaitingMessageAck && !options.skipAckWait) {
                this.queueNavigationUntilMessageAck({
                    type: 'conversation',
                    id: conversationId,
                    href: targetHref,
                });
                return;
            }

            if (this.isNavigating && !options.allowWhileNavigating) {
                window.location.assign(targetHref);
                return;
            }

            this.loadConversation(conversationId)
                .then((result) => {
                    if (result?.stale) {
                        return;
                    }

                    this.markConversationRead(conversationId);
                    window.history.pushState({}, '', targetHref);
                })
                .catch(() => {
                    window.location.assign(targetHref);
                });
        },

        navigateToConversation(event, id) {
            if (!event?.currentTarget?.href) {
                return;
            }

            const targetHref = event.currentTarget.href;
            event.preventDefault();
            this.navigateToConversationUrl(targetHref, id);
        },

        navigateToNewChatUrl(targetHref, options = {}) {
            if (this.awaitingMessageAck && !options.skipAckWait) {
                this.queueNavigationUntilMessageAck({
                    type: 'new',
                    href: targetHref,
                });
                return;
            }

            if (this.isNavigating && !options.allowWhileNavigating) {
                window.location.assign(targetHref);
                return;
            }

            this.startNewChat()
                .then((result) => {
                    if (result?.stale) {
                        return;
                    }

                    window.history.pushState({}, '', targetHref);
                })
                .catch(() => {
                    window.location.assign(targetHref);
                });
        },

        navigateToNewChat(event) {
            if (!event?.currentTarget?.href) {
                return;
            }

            const targetHref = event.currentTarget.href;
            event.preventDefault();
            this.navigateToNewChatUrl(targetHref);
        },

        startNewChat() {
            const navigationToken = this.navigationToken + 1;
            this.navigationToken = navigationToken;
            this.setActiveConversation(null);
            this.loadingConversationId = null;
            this.isNavigating = true;
            this.$dispatch('chat-new-optimistic');
            this.$dispatch('conversation-loading');

            return this.$wire.startNewChat()
                .then(() => {
                    if (this.navigationToken !== navigationToken) {
                        return { stale: true };
                    }

                    return { stale: false };
                })
                .finally(() => {
                    if (this.navigationToken !== navigationToken) {
                        return;
                    }

                    this.isNavigating = false;
                    this.$dispatch('conversation-loaded');
                });
        },

        destroy() {
            if (window.chatHistoryNavigateToNewChat) {
                delete window.chatHistoryNavigateToNewChat;
            }
            if (this._chatMarkPendingHandler) {
                window.removeEventListener('chat-mark-pending', this._chatMarkPendingHandler);
                this._chatMarkPendingHandler = null;
            }
            if (this._messageSendHandler) {
                window.removeEventListener('message-send', this._messageSendHandler);
                this._messageSendHandler = null;
            }
            if (this._messageCompleteHandler) {
                window.removeEventListener('message-complete', this._messageCompleteHandler);
                this._messageCompleteHandler = null;
            }
            if (this._assistantPersistedWindowHandler) {
                window.removeEventListener('assistant-message-persisted', this._assistantPersistedWindowHandler);
                this._assistantPersistedWindowHandler = null;
            }
            if (typeof this._assistantPersistedCleanup === 'function') {
                this._assistantPersistedCleanup();
                this._assistantPersistedCleanup = null;
            }
            if (typeof this._pendingStateCleanup === 'function') {
                this._pendingStateCleanup();
                this._pendingStateCleanup = null;
            }
            if (typeof this._userMessageAckedCleanup === 'function') {
                this._userMessageAckedCleanup();
                this._userMessageAckedCleanup = null;
            }
            if (this._userMessageAckedWindowHandler) {
                window.removeEventListener('user-message-acked', this._userMessageAckedWindowHandler);
                this._userMessageAckedWindowHandler = null;
            }
            if (typeof this._userMessageRejectedCleanup === 'function') {
                this._userMessageRejectedCleanup();
                this._userMessageRejectedCleanup = null;
            }
            if (this._userMessageRejectedWindowHandler) {
                window.removeEventListener('user-message-rejected', this._userMessageRejectedWindowHandler);
                this._userMessageRejectedWindowHandler = null;
            }
            this.clearMessageAckTimeout();
        },
    }));

    Alpine.data('memoHistory', (config = {}) => ({
        activeMemoId: config.activeMemoId ? Number(config.activeMemoId) : null,
        openMemoSections: initialMemoHistorySectionState(config),
        memoSectionKeys: (config.memoSectionKeys || MEMO_HISTORY_SECTION_KEYS).map((section) => String(section)),
        memoSearch: '',
        memoTitles: (config.memoTitles || []).map((title) => String(title || '')),
        loadingMemoId: null,
        memoLoadToken: 0,

        init() {
            this.$nextTick(() => this.syncActiveMemoItem());
        },

        setActiveMemo(id) {
            const memoId = id ? Number(id) : null;
            const nextMemoId = Number.isFinite(memoId) ? memoId : null;

            if (this.activeMemoId === nextMemoId) {
                this.syncActiveMemoItem();
                return;
            }

            this.activeMemoId = nextMemoId;
            this.$nextTick(() => this.syncActiveMemoItem());
        },

        normalizedMemoSearch() {
            return String(this.memoSearch || '').trim().toLowerCase();
        },

        isSearchingMemoHistory() {
            return this.normalizedMemoSearch().length > 0;
        },

        isMemoVisible(title) {
            const search = this.normalizedMemoSearch();
            if (!search) {
                return true;
            }

            return String(title || '').toLowerCase().includes(search);
        },

        hasMemoSearchResults() {
            const search = this.normalizedMemoSearch();
            if (!search) {
                return true;
            }

            return this.memoTitles.some((title) => String(title || '').toLowerCase().includes(search));
        },

        isLoadingMemo(id) {
            return this.loadingMemoId === Number(id);
        },

        availableMemoSectionKeys() {
            return (this.memoSectionKeys || [])
                .map((section) => String(section))
                .filter((section) => MEMO_HISTORY_SECTION_KEYS.includes(section));
        },

        allMemoSectionsOpen() {
            const sections = this.availableMemoSectionKeys();

            return sections.length > 0 && sections.every((section) => Boolean(this.openMemoSections?.[section]));
        },

        persistOpenMemoSections() {
            saveMemoHistorySectionState(this.openMemoSections);
        },

        isMemoSectionOpen(section) {
            return this.isSearchingMemoHistory() || Boolean(this.openMemoSections?.[section]);
        },

        toggleMemoSection(section) {
            if (!section) {
                return;
            }

            this.openMemoSections = {
                ...this.openMemoSections,
                [section]: !this.openMemoSections?.[section],
            };
            this.persistOpenMemoSections();
        },

        toggleAllMemoHistory() {
            const shouldOpen = !this.allMemoSectionsOpen();
            const nextSections = { ...this.openMemoSections };

            this.availableMemoSectionKeys().forEach((section) => {
                nextSections[section] = shouldOpen;
            });

            this.openMemoSections = nextSections;
            this.persistOpenMemoSections();
        },

        syncActiveMemoItem(id = this.activeMemoId) {
            const memoId = id ? Number(id) : null;
            this.activeMemoId = Number.isFinite(memoId) ? memoId : null;

            if (!this.$root) {
                return;
            }

            this.$root.querySelectorAll('[data-memo-history-id]').forEach((button) => {
                const isActive = Number(button.dataset.memoHistoryId) === this.activeMemoId;
                button.classList.toggle('is-active', isActive);
                button.setAttribute('aria-current', isActive ? 'page' : 'false');

                if (isActive) {
                    const section = button.closest('[data-memo-history-section]')?.dataset?.memoHistorySection;
                    if (section && MEMO_HISTORY_SECTION_KEYS.includes(section) && !this.openMemoSections?.[section]) {
                        this.openMemoSections = {
                            ...this.openMemoSections,
                            [section]: true,
                        };
                        this.persistOpenMemoSections();
                    }
                }
            });
        },

        loadMemo(id) {
            const memoId = Number(id);
            const previousMemoId = this.activeMemoId;

            if (!Number.isFinite(memoId) || memoId <= 0) {
                return Promise.resolve({ stale: false });
            }

            if (previousMemoId === memoId) {
                this.syncActiveMemoItem();
                return Promise.resolve({ stale: false });
            }

            const memoLoadToken = this.memoLoadToken + 1;
            this.memoLoadToken = memoLoadToken;
            this.loadingMemoId = memoId;

            return this.$wire.loadMemo(memoId)
                .then(() => {
                    if (this.memoLoadToken !== memoLoadToken) {
                        return { stale: true };
                    }

                    this.setActiveMemo(memoId);

                    return { stale: false };
                })
                .catch((error) => {
                    if (this.memoLoadToken === memoLoadToken) {
                        this.setActiveMemo(previousMemoId);
                    }

                    throw error;
                })
                .finally(() => {
                    if (this.memoLoadToken !== memoLoadToken) {
                        return;
                    }

                    if (this.loadingMemoId === memoId) {
                        this.loadingMemoId = null;
                    }
                });
        },
    }));

    Alpine.data('chatAnswerActions', (config = {}) => ({
        messageId: Number(config.messageId || 0),
        messageIdSource: config.messageId,
        html: config.html || '',
        htmlSource: config.html,
        exportUrl: config.exportUrl || '',
        exportFileName: config.exportFileName || 'ista-ai-export',
        exportFileNameSource: config.exportFileName,
        driveUploadAvailable: Boolean(config.driveUploadAvailable),
        exportMenuOpen: false,
        driveMenuOpen: false,
        copied: false,
        exportLoading: false,
        exportError: '',
        driveLoading: false,
        driveError: '',
        driveResult: null,

        resolvedConfigValue(value, fallback = '') {
            if (typeof value === 'function') {
                return value() || fallback;
            }

            return value || fallback;
        },

        resolvedMessageId() {
            const source = typeof this.messageIdSource === 'function'
                ? this.messageIdSource()
                : this.messageId;
            const id = Number(source || 0);

            return Number.isFinite(id) && id > 0 ? id : 0;
        },

        resolvedHtml() {
            return String(this.resolvedConfigValue(this.htmlSource, this.html) || '');
        },

        resolvedExportFileName() {
            const configured = String(this.resolvedConfigValue(this.exportFileNameSource, this.exportFileName) || '').trim();

            if (configured !== '') {
                return configured;
            }

            const messageId = this.resolvedMessageId();

            return messageId > 0 ? `ista-ai-jawaban-${messageId}` : 'ista-ai-export';
        },

        plainText() {
            const wrapper = document.createElement('div');
            wrapper.innerHTML = this.resolvedHtml();

            return this.normalizePlainText(this.nodeToPlainText(wrapper));
        },

        nodeToPlainText(node) {
            const blockTags = new Set([
                'address', 'article', 'aside', 'blockquote', 'div', 'dl', 'fieldset',
                'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5',
                'h6', 'header', 'main', 'nav', 'ol', 'p', 'pre', 'section', 'ul',
            ]);

            if (node.nodeType === Node.TEXT_NODE) {
                return node.nodeValue || '';
            }

            if (node.nodeType !== Node.ELEMENT_NODE && node.nodeType !== Node.DOCUMENT_FRAGMENT_NODE) {
                return '';
            }

            const tagName = node.tagName?.toLowerCase();

            if (tagName === 'br') {
                return '\n';
            }

            if (tagName === 'hr') {
                return '\n\n';
            }

            if (tagName === 'li') {
                const itemText = Array.from(node.childNodes)
                    .map((child) => this.nodeToPlainText(child))
                    .join('');

                return `\n- ${this.normalizePlainText(itemText)}\n`;
            }

            if (tagName === 'tr') {
                const cells = Array.from(node.children)
                    .filter((child) => ['th', 'td'].includes(child.tagName?.toLowerCase()))
                    .map((cell) => this.normalizePlainText(this.nodeToPlainText(cell)).replace(/\n+/g, ' '));

                return cells.length > 0 ? `${cells.join('\t')}\n` : '';
            }

            const childrenText = Array.from(node.childNodes)
                .map((child) => this.nodeToPlainText(child))
                .join('');

            if (tagName === 'table') {
                return `\n${childrenText}\n`;
            }

            return blockTags.has(tagName) ? `\n${childrenText}\n` : childrenText;
        },

        normalizePlainText(text) {
            return (text || '')
                .replace(/\u00a0/g, ' ')
                .replace(/[ \t]+\n/g, '\n')
                .replace(/\n[ \t]+/g, '\n')
                .replace(/[ \t]{2,}/g, ' ')
                .replace(/\n{3,}/g, '\n\n')
                .split('\n')
                .map((line) => line.trim())
                .join('\n')
                .trim();
        },

        copyStatusLabel() {
            return this.copied ? 'Tersalin' : 'Salin';
        },

        driveButtonLabel() {
            if (!this.driveUploadAvailable) {
                return 'Google Drive belum tersedia untuk jawaban ini.';
            }

            if (!this.resolvedMessageId()) {
                return 'Jawaban belum tersimpan.';
            }

            return this.driveLoading ? 'Mengupload ke Google Drive' : 'Upload ke Google Drive';
        },

        toggleExportMenu() {
            if (this.exportLoading) {
                return;
            }

            this.driveMenuOpen = false;
            this.exportMenuOpen = !this.exportMenuOpen;
        },

        toggleDriveMenu() {
            if (this.driveLoading || !this.driveUploadAvailable) {
                this.driveError = 'Simpan ke Google Drive belum tersedia untuk jawaban ini.';
                return;
            }

            this.exportMenuOpen = false;
            this.driveMenuOpen = !this.driveMenuOpen;
            this.driveError = '';
        },

        async copyToClipboard() {
            const text = this.plainText();

            if (!text) {
                return;
            }

            try {
                if (navigator.clipboard?.writeText) {
                    await navigator.clipboard.writeText(text);
                } else {
                    const helper = document.createElement('textarea');
                    helper.value = text;
                    helper.setAttribute('readonly', 'true');
                    helper.style.position = 'fixed';
                    helper.style.left = '-9999px';
                    document.body.appendChild(helper);
                    helper.select();
                    document.execCommand('copy');
                    document.body.removeChild(helper);
                }

                this.copied = true;
                window.setTimeout(() => {
                    this.copied = false;
                }, 1800);
            } catch (error) {
                console.error('Gagal menyalin jawaban AI', error);
            }
        },

        shareToWhatsApp() {
            const text = this.plainText();

            if (!text) {
                return;
            }

            const shareUrl = `https://wa.me/?text=${encodeURIComponent(text)}`;
            window.open(shareUrl, '_blank', 'noopener,noreferrer');
        },

        getCsrfToken() {
            return document.querySelector('meta[name="csrf-token"]')?.content || '';
        },

        downloadBlob(blob, fileName) {
            const downloadUrl = window.URL.createObjectURL(blob);
            const anchor = document.createElement('a');
            anchor.href = downloadUrl;
            anchor.download = fileName;
            document.body.appendChild(anchor);
            anchor.click();
            anchor.remove();
            window.URL.revokeObjectURL(downloadUrl);
        },

        filenameFromResponse(response, format) {
            const disposition = response.headers.get('Content-Disposition') || '';
            const fileNameMatch = disposition.match(/filename="?([^"]+)"?/i);

            if (fileNameMatch?.[1]) {
                return fileNameMatch[1];
            }

            return `${this.resolvedExportFileName()}.${format}`;
        },

        async exportAs(format) {
            if (!this.exportUrl || this.exportLoading) {
                return;
            }

            this.exportMenuOpen = false;
            this.exportLoading = true;
            this.exportError = '';

            try {
                const response = await fetch(this.exportUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': '*/*',
                        'X-CSRF-TOKEN': this.getCsrfToken(),
                    },
                    body: JSON.stringify({
                        content_html: this.resolvedHtml(),
                        target_format: format,
                        file_name: this.resolvedExportFileName(),
                    }),
                });

                if (!response.ok) {
                    const errorText = await response.text();
                    throw new Error(errorText || 'Export gagal');
                }

                const blob = await response.blob();
                const fileName = this.filenameFromResponse(response, format);
                this.downloadBlob(blob, fileName);
            } catch (error) {
                console.error('Gagal mengekspor jawaban AI', error);
                this.exportError = 'Ekspor gagal. Coba lagi.';
            } finally {
                this.exportLoading = false;
            }
        },

        async uploadToGoogleDrive(format) {
            const messageId = this.resolvedMessageId();

            if (!messageId || this.driveLoading || !this.driveUploadAvailable) {
                return;
            }

            this.driveMenuOpen = false;
            this.driveLoading = true;
            this.driveError = '';
            this.driveResult = null;

            try {
                const result = await this.$wire.saveAnswerToGoogleDrive(messageId, format);

                if (!result?.ok) {
                    throw new Error(result?.message || 'Upload ke Google Drive gagal.');
                }

                this.driveResult = result;
            } catch (error) {
                console.error('Gagal mengupload jawaban AI ke Google Drive', error);
                this.driveError = error?.message || 'Upload ke Google Drive gagal. Coba lagi.';
            } finally {
                this.driveLoading = false;
            }
        },
    }));

    Alpine.data('chatDocumentSelector', (config = {}) => ({
        selectedDocuments: config.selectedDocuments || [],
        readyDocumentIds: (config.readyDocumentIds || []).map((id) => Number(id)),
        availableDocuments: (config.availableDocuments || []).map((document) => ({
            id: Number(document.id),
            name: document.name,
            extension: document.extension,
            status: document.status,
        })),

        init() {
            this.$watch('readyDocumentIds', () => this.syncSelectedDocuments());
            this.$watch('availableDocuments', () => {
                this.readyDocumentIds = this.availableDocuments
                    .filter((document) => document.status === 'ready')
                    .map((document) => Number(document.id));
                this.syncSelectedDocuments();
            });
        },

        normalizedSelectedDocuments() {
            const ready = new Set(this.readyDocumentIds);

            return [...new Set((this.selectedDocuments || [])
                .map((id) => Number(id))
                .filter((id) => ready.has(id)))];
        },

        isSelected(id) {
            return this.normalizedSelectedDocuments().includes(Number(id));
        },

        selectedInAvailableCount() {
            return this.normalizedSelectedDocuments().length;
        },

        allDocumentsSelected() {
            return this.readyDocumentIds.length > 0
                && this.selectedInAvailableCount() === this.readyDocumentIds.length;
        },

        toggleSelectAllDocuments() {
            this.selectedDocuments = this.allDocumentsSelected()
                ? []
                : [...this.readyDocumentIds];
        },

        syncSelectedDocuments() {
            this.selectedDocuments = this.normalizedSelectedDocuments();
            this.$wire.selectedDocuments = this.selectedDocuments;
        },

        addSelectedDocumentsToChat() {
            this.syncSelectedDocuments();
            const selected = new Set(this.selectedDocuments.map((id) => Number(id)));
            const documents = this.availableDocuments.filter((document) => selected.has(document.id));

            this.$dispatch('conversation-documents-preview', {
                ids: this.selectedDocuments,
                documents,
            });

            return this.$wire.addSelectedDocumentsToChat();
        },

        deleteSelectedDocuments() {
            if (!window.confirm('Hapus permanen dokumen terpilih? File, embedding, dan preview akan dihapus sepenuhnya dan tidak bisa dipulihkan.')) {
                return Promise.resolve();
            }

            this.syncSelectedDocuments();

            return this.$wire.deleteSelectedDocuments();
        },
    }));

    Alpine.data('chatComposer', (config = {}) => ({
        promptDraft: config.prompt || '',
        webSearchMode: config.webSearchMode || false,
        conversationDocuments: config.conversationDocuments || [],
        availableDocuments: (config.availableDocuments || []).map((document) => ({
            id: Number(document.id),
            name: document.name,
            extension: document.extension,
            status: document.status,
        })),
        isSendingMessage: false,
        sendError: '',
        messageAcked: false,
        _pendingLoadingContext: 'general',

        init() {
            if (this.promptDraft) {
                this.schedulePendingPromptSubmission();
            }

            this.$wire.on('user-message-acked', (data) => {
                this.messageAcked = true;
                this.stabilizeChatScroll();

                const payload = normalizeWirePayload(data);
                const ackConversationId = Number(payload.conversationId || this.$wire.currentConversationId || 0);
                if (ackConversationId > 0) {
                    this.markPendingConversation(ackConversationId, this._pendingLoadingContext || 'general');
                }
            });
        },

        markPendingConversation(conversationId, loadingContext = 'general') {
            const id = Number(conversationId);
            if (!Number.isFinite(id) || id <= 0) {
                return;
            }

            window.dispatchEvent(new CustomEvent('chat-mark-pending', {
                detail: {
                    conversationId: id,
                    loadingContext,
                },
            }));
        },

        previewConversationDocuments(event) {
            const ids = event.detail?.ids || [];
            const documents = event.detail?.documents || [];

            this.conversationDocuments = this.normalizedDocumentIds(ids);
            this.mergeAvailableDocuments(documents);
        },

        normalizedDocumentIds(ids = this.conversationDocuments) {
            return [...new Set((ids || []).map((id) => Number(id)).filter(Boolean))];
        },

        mergeAvailableDocuments(documents = []) {
            const existing = new Map(this.availableDocuments.map((document) => [Number(document.id), document]));

            documents.forEach((document) => {
                existing.set(Number(document.id), {
                    id: Number(document.id),
                    name: document.name,
                    extension: document.extension,
                    status: document.status,
                });
            });

            this.availableDocuments = Array.from(existing.values());
        },

        chatDocuments() {
            const byId = new Map(this.availableDocuments.map((document) => [Number(document.id), document]));

            return this.normalizedDocumentIds()
                .map((id) => byId.get(id))
                .filter(Boolean);
        },

        documentIconType(document) {
            const extension = (document.extension || '').toLowerCase();

            if (extension === 'pdf') {
                return 'pdf';
            }

            if (['xlsx', 'csv'].includes(extension)) {
                return 'xlsx';
            }

            if (extension === 'docx') {
                return 'docx';
            }

            return 'file';
        },

        removeConversationDocument(id) {
            const documentId = Number(id);
            this.conversationDocuments = this.normalizedDocumentIds()
                .filter((currentId) => currentId !== documentId);
            this.$wire.conversationDocuments = this.conversationDocuments;

            return this.$wire.removeConversationDocument(documentId);
        },

        schedulePendingPromptSubmission(attempt = 0) {
            if (!this.promptDraft.trim()) {
                return;
            }

            if (document.querySelector('[data-chat-messages-ready="true"]')) {
                this.$nextTick(() => {
                    requestAnimationFrame(() => this.submitPrompt());
                });

                return;
            }

            if (attempt >= 20) {
                return;
            }

            window.setTimeout(() => {
                this.schedulePendingPromptSubmission(attempt + 1);
            }, 50);
        },

        submitPrompt(event) {
            if (event) {
                event.preventDefault();
            }

            if (this.isSendingMessage) {
                return;
            }

            const text = this.promptDraft.trim();

            if (!text) {
                return;
            }

            this.sendError = '';
            this.messageAcked = false;
            this.isSendingMessage = true;

            const normalizedDocs = this.normalizedDocumentIds();
            const hasDocs = normalizedDocs.length > 0;
            const hasWeb = Boolean(this.webSearchMode);
            const loadingContext = hasWeb && hasDocs
                ? 'hybrid'
                : (hasWeb ? 'web-search' : (hasDocs ? 'documents' : 'general'));

            this.$dispatch('message-send', { text, loadingContext });
            this.scrollChatToBottom(true);
            this.stabilizeChatScroll();
            this._pendingLoadingContext = loadingContext;

            const currentConversationId = Number(this.$wire.currentConversationId || 0);
            if (currentConversationId > 0) {
                this.markPendingConversation(currentConversationId, loadingContext);
            }

            this.promptDraft = '';
            this.autoResizeTextarea(this.$refs.chatInput);
            this.$wire.webSearchMode = Boolean(this.webSearchMode);
            this.$wire.conversationDocuments = normalizedDocs;

            this.$wire.sendMessage(text)
                .then((response) => {
                    const conversationId = Number(response?.conversationId || this.$wire.currentConversationId || 0);

                    if (response?.rejected || !response?.messageId) {
                        if (conversationId > 0) {
                            this.markPendingConversation(conversationId, loadingContext);
                        }

                        this.promptDraft = text;
                        this.autoResizeTextarea(this.$refs.chatInput);
                        this.sendError = 'Tunggu jawaban sebelumnya selesai sebelum mengirim pesan baru.';
                        window.dispatchEvent(new CustomEvent('user-message-rejected', {
                            detail: {
                                conversationId,
                                loadingContext,
                                reason: response?.reason || 'pending_response',
                            },
                        }));

                        setTimeout(() => {
                            this.sendError = '';
                        }, 6000);

                        return;
                    }

                    if (conversationId > 0) {
                        this.markPendingConversation(conversationId, loadingContext);
                    }
                    window.dispatchEvent(new CustomEvent('user-message-acked', {
                        detail: {
                            conversationId,
                            messageId: response?.messageId || null,
                        },
                    }));

                    // Open SSE stream to receive live chunks from Python AI.
                    // History tidak dikirim — server reconstruct dari DB.
                    if (conversationId > 0) {
                        window.dispatchEvent(new CustomEvent('chat-open-stream', {
                            detail: {
                                conversationId,
                                documentIds: normalizedDocs,
                                webSearchMode: Boolean(this.webSearchMode),
                                loadingContext,
                            },
                        }));
                    }
                })
                .catch(() => {
                    this.$dispatch('user-message-acked');

                    if (!this.messageAcked) {
                        this.promptDraft = text;
                        this.sendError = 'Pesan gagal dikirim. Periksa koneksi.';
                    } else {
                        this.sendError = 'Jawaban gagal diproses. Coba kirim ulang.';
                    }

                    setTimeout(() => {
                        this.sendError = '';
                    }, 6000);

                    this.$dispatch('message-complete');
                })
                .finally(() => {
                    this.isSendingMessage = false;
                });
        },

        handleEnterKey(event) {
            if (event.isComposing) {
                return;
            }

            if (event.shiftKey) {
                window.requestAnimationFrame(() => {
                    this.autoResizeTextarea(this.$refs.chatInput);
                });

                return;
            }

            event.preventDefault();
            this.submitPrompt(event);
        },

        openAttachmentPicker() {
            this.$dispatch('open-sidebar-right');

            const input = this.$refs.chatAttachmentInput;

            if (!input) {
                return;
            }

            input.value = '';
            input.click();
        },

        openGoogleDrivePicker() {
            this.$dispatch('open-google-drive-picker');
        },

        scrollChatToBottom(smooth = false) {
            const chatBox = document.querySelector('[data-chat-box]');

            if (!chatBox) {
                return;
            }

            this.$nextTick(() => {
                chatBox.scrollTo({
                    top: chatBox.scrollHeight,
                    behavior: smooth ? 'smooth' : 'auto',
                });
            });
        },

        stabilizeChatScroll(attempts = 8) {
            const runScrollPass = (remaining) => {
                this.scrollChatToBottom(remaining === attempts);

                if (remaining <= 0) {
                    return;
                }

                window.setTimeout(() => {
                    this.$nextTick(() => runScrollPass(remaining - 1));
                }, 60);
            };

            this.$nextTick(() => runScrollPass(attempts));
        },

        autoResizeTextarea(element) {
            if (!element) {
                return;
            }

            element.style.height = 'auto';
            element.style.height = `${Math.min(Math.max(element.scrollHeight, 44), 200)}px`;
            element.style.overflowY = element.scrollHeight > 200 ? 'auto' : 'hidden';
        },
    }));

    Alpine.data('documentViewer', (config = {}) => ({
        isVisible: Boolean(config.isOpen),
        isLoading: false,
        requestToken: 0,

        open(id) {
            const documentId = Number(id);

            if (!Number.isFinite(documentId)) {
                return Promise.resolve();
            }

            const token = this.requestToken + 1;
            this.requestToken = token;
            this.isVisible = true;
            this.isLoading = true;

            return this.$wire.open(documentId)
                .catch(() => {
                    if (this.requestToken === token) {
                        this.isVisible = false;
                    }
                })
                .finally(() => {
                    if (this.requestToken === token) {
                        this.isLoading = false;
                    }
                });
        },

        close() {
            this.requestToken += 1;
            this.isVisible = false;
            this.isLoading = false;

            return this.$wire.close();
        },
    }));

    Alpine.data('memoDocumentDownloads', () => ({
        downloadLoading: null,
        downloadStatus: '',
        downloadError: '',

        async downloadMemo(url, type, fallbackName, versionId = null, forceSaveUrl = null) {
            if (this.downloadLoading) {
                return;
            }

            this.downloadLoading = type;
            this.downloadStatus = 'Menyimpan perubahan editor...';
            this.downloadError = '';

            try {
                await this.forceSaveMemo(forceSaveUrl, versionId);
                this.downloadStatus = type === 'pdf' ? 'Menyiapkan PDF...' : 'Menyiapkan DOCX...';

                const response = await fetch(this.versionedUrl(url, versionId), {
                    cache: 'no-store',
                    credentials: 'same-origin',
                    headers: {
                        'Cache-Control': 'no-cache',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (!response.ok) {
                    throw new Error('Download gagal');
                }

                const blob = await response.blob();
                this.downloadBlob(blob, this.fileNameFromDisposition(
                    response.headers.get('Content-Disposition') || '',
                    fallbackName,
                ));
            } catch (error) {
                this.downloadError = error?.message || 'Unduhan belum bisa disiapkan.';
            } finally {
                this.downloadLoading = null;
                this.downloadStatus = '';
            }
        },

        async forceSaveMemo(url, versionId = null) {
            if (!url) {
                return;
            }

            const selectedVersionId = document.getElementById('memo-version-select')?.value || versionId;
            const response = await fetch(url, {
                method: 'POST',
                cache: 'no-store',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': this.getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    version_id: selectedVersionId || null,
                }),
            });

            if (!response.ok) {
                const message = await this.errorMessage(response);
                throw new Error(message || 'Perubahan editor belum tersimpan.');
            }
        },

        getCsrfToken() {
            return document.querySelector('meta[name="csrf-token"]')?.content || '';
        },

        async errorMessage(response) {
            try {
                const data = await response.clone().json();

                return data?.message || '';
            } catch (error) {
                return await response.text();
            }
        },

        versionedUrl(url, fallbackVersionId = null) {
            const requestUrl = new URL(url, window.location.origin);
            const selectedVersionId = document.getElementById('memo-version-select')?.value || fallbackVersionId;

            if (selectedVersionId) {
                requestUrl.searchParams.set('version_id', selectedVersionId);
            }

            return requestUrl.toString();
        },

        fileNameFromDisposition(disposition, fallbackName) {
            const utfMatch = disposition.match(/filename\*=UTF-8''([^;]+)/i);

            if (utfMatch) {
                try {
                    return decodeURIComponent(utfMatch[1]);
                } catch (error) {
                    return fallbackName;
                }
            }

            const asciiMatch = disposition.match(/filename="?([^";]+)"?/i);

            return asciiMatch ? asciiMatch[1] : fallbackName;
        },

        downloadBlob(blob, fileName) {
            const downloadUrl = window.URL.createObjectURL(blob);
            const anchor = document.createElement('a');
            anchor.href = downloadUrl;
            anchor.download = fileName;
            document.body.appendChild(anchor);
            anchor.click();
            anchor.remove();
            window.URL.revokeObjectURL(downloadUrl);
        },
    }));

    Alpine.data('memoWorkspace', () => ({
        showMemoSidebar: !window.matchMedia('(max-width: 1023px)').matches,
        isMobile: window.matchMedia('(max-width: 1023px)').matches,
        memoMobilePanel: 'chat',
        memoRevisionText: '',
        memoRevisionLoading: false,
        memoLoadingPhase: 'Membuat ulang memo',
        memoLoadingPhaseKey: 0,
        memoLoadingPhaseTimeout: null,
        memoPhase2Timeout: null,
        memoPhase2Done: false,
        memoShimmerActive: false,
        memoSyncLoading: false,
        memoSyncError: '',

        init() {
            const mediaQuery = window.matchMedia('(max-width: 1023px)');
            const syncState = (event) => {
                this.isMobile = event.matches;
                this.showMemoSidebar = !event.matches;

                if (!event.matches) {
                    this.memoMobilePanel = 'chat';
                }
            };

            mediaQuery.addEventListener('change', syncState);

            // Auto-scroll memo chat on new messages
            this.$watch('$wire.memoChatMessages', () => {
                this.$nextTick(() => this.scrollMemoChatToBottom());
            });
        },

        collapseMemoSidebarForDocument() {
            this.showMemoSidebar = false;

            if (this.isMobile) {
                this.memoMobilePanel = 'document';
            }
        },

        showMemoChatPanel() {
            this.memoMobilePanel = 'chat';
            this.showMemoSidebar = false;
        },

        showMemoDocumentPanel() {
            this.memoMobilePanel = 'document';
            this.showMemoSidebar = false;
        },

        async submitMemoRevision($wire, textarea) {
            const message = (textarea?.value || '').trim();

            if (!message || this.memoRevisionLoading || $wire.isGenerating) {
                return;
            }

            this.memoSyncError = '';
            this.memoRevisionText = message;
            this.memoRevisionLoading = true;
            this.memoShimmerActive = true;
            this.memoLoadingPhase = 'Menyimpan perubahan editor';
            this.memoLoadingPhaseKey++;

            this.scrollMemoChatToBottom();

            try {
                await this.forceSaveActiveMemo($wire);
                this.startMemoLoadingPhase();

                textarea.value = '';
                textarea.style.height = 'auto';
                textarea.dispatchEvent(new Event('input', { bubbles: true }));
                this.scrollMemoChatToBottom();

                await $wire.sendMemoChat(message);
            } catch (error) {
                this.memoSyncError = error?.message || 'Perubahan editor belum tersimpan.';
            } finally {
                this.memoRevisionText = '';
                this.memoRevisionLoading = false;
                this.resetMemoLoadingPhase();
                this.scrollMemoChatToBottom();
            }
        },

        async submitMemoConfiguration($wire) {
            if (this.memoSyncLoading || $wire.isGenerating) {
                return;
            }

            this.memoSyncError = '';
            this.memoSyncLoading = true;

            try {
                await this.forceSaveActiveMemo($wire);
                await $wire.generateConfiguredMemo();
            } catch (error) {
                this.memoSyncError = error?.message || 'Perubahan editor belum tersimpan.';
            } finally {
                this.memoSyncLoading = false;
            }
        },

        async forceSaveActiveMemo($wire) {
            const memoId = Number($wire.activeMemoId || 0);

            if (!memoId) {
                return;
            }

            const baseUrl = this.$root?.dataset?.memoForceSaveBaseUrl || '/chat/memos';
            const versionId = $wire.activeMemoVersionId || document.getElementById('memo-version-select')?.value || null;
            const response = await fetch(`${baseUrl}/${memoId}/force-save`, {
                method: 'POST',
                cache: 'no-store',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': this.getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    version_id: versionId,
                }),
            });

            if (!response.ok) {
                const message = await this.errorMessage(response);
                throw new Error(message || 'Perubahan editor belum tersimpan.');
            }
        },

        getCsrfToken() {
            return document.querySelector('meta[name="csrf-token"]')?.content || '';
        },

        async errorMessage(response) {
            try {
                const data = await response.clone().json();

                return data?.message || '';
            } catch (error) {
                return await response.text();
            }
        },

        memoLoadingLabels() {
            return ['Menyiapkan revisi memo', 'AI sedang menyusun memo', 'Menampilkan hasil revisi'];
        },

        clearMemoLoadingPhaseTimeout() {
            if (this.memoLoadingPhaseTimeout) {
                window.clearTimeout(this.memoLoadingPhaseTimeout);
                this.memoLoadingPhaseTimeout = null;
            }
        },

        clearMemoPhase2Timeout() {
            if (this.memoPhase2Timeout) {
                window.clearTimeout(this.memoPhase2Timeout);
                this.memoPhase2Timeout = null;
            }
        },

        startMemoLoadingPhase() {
            this.resetMemoLoadingPhase();
            this.memoLoadingPhaseKey++;
            this.memoLoadingPhase = this.memoLoadingLabels()[0];
            this.memoShimmerActive = true;
            this.memoPhase2Done = false;

            // Fase 1 ("Membuat ulang memo") 8000ms, lalu fase 2
            // ("AI sedang berpikir") juga 8000ms — chain tidak bisa
            // di-cancel, sehingga perbandingan durasi 1:1.
            this.memoLoadingPhaseTimeout = window.setTimeout(() => {
                this.memoLoadingPhase = this.memoLoadingLabels()[1];
                this.memoLoadingPhaseKey++;

                this.memoPhase2Timeout = window.setTimeout(() => {
                    this.memoPhase2Done = true;
                    this.memoLoadingPhase = this.memoLoadingLabels()[2];
                    this.memoLoadingPhaseKey++;
                    this.memoShimmerActive = false;
                }, 8000);
            }, 8000);
        },

        resetMemoLoadingPhase() {
            this.clearMemoLoadingPhaseTimeout();
            this.clearMemoPhase2Timeout();

            this.memoLoadingPhaseKey = 0;
            this.memoPhase2Done = false;
            this.memoLoadingPhase = 'Menyiapkan revisi memo';
            this.memoShimmerActive = false;
        },

        scrollMemoChatToBottom() {
            const chatBox = document.getElementById('memo-chat-box');

            if (!chatBox) {
                return;
            }

            this.$nextTick(() => {
                chatBox.scrollTo({
                    top: chatBox.scrollHeight,
                    behavior: 'smooth',
                });
            });
        },
    }));

    Alpine.data('documentViewerExport', (config = {}) => ({
        contentUrl: config.contentUrl || '',
        extractUrl: config.extractUrl || '',
        exportUrl: config.exportUrl || '',
        fileName: config.fileName || 'ista-ai-tabel-dokumen',
        preferTableExtraction: Boolean(config.preferTableExtraction),
        driveUploadAvailable: Boolean(config.driveUploadAvailable),
        exportMenuOpen: false,
        driveMenuOpen: false,
        loadingAction: null,
        error: '',
        contentHtml: '',
        tables: null,

        isBusy() {
            return this.loadingAction !== null;
        },

        isExportLoading() {
            return this.loadingAction === 'export';
        },

        isDriveLoading() {
            return this.loadingAction === 'drive';
        },

        toggleMenu() {
            if (this.isBusy()) {
                return;
            }

            this.driveMenuOpen = false;
            this.exportMenuOpen = !this.exportMenuOpen;
            this.error = '';
        },

        exportLoadingLabel() {
            return this.isExportLoading() ? 'Menyiapkan ekspor' : 'Ekspor';
        },

        driveButtonLabel() {
            if (!this.driveUploadAvailable) {
                return 'Simpan ke Google Drive belum tersedia untuk dokumen ini.';
            }

            return this.isDriveLoading() ? 'Menyiapkan upload ke Google Drive' : 'Upload ke Google Drive Kantor';
        },

        toggleDriveMenu() {
            if (this.isBusy() || !this.driveUploadAvailable) {
                this.error = 'Simpan ke Google Drive belum tersedia untuk dokumen ini.';
                return;
            }

            this.exportMenuOpen = false;
            this.driveMenuOpen = !this.driveMenuOpen;
            this.error = '';
        },

        async exportTablesAs(format) {
            if (!this.exportUrl || this.isBusy()) {
                return;
            }

            this.exportMenuOpen = false;
            this.driveMenuOpen = false;
            this.loadingAction = 'export';
            this.error = '';

            try {
                const isTableFormat = ['xlsx', 'csv'].includes(format);
                const contentHtml = isTableFormat && this.preferTableExtraction
                    ? await this.tableExportHtml()
                    : await this.fullContentHtml();

                const response = await fetch(this.exportUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': '*/*',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.getCsrfToken(),
                    },
                    body: JSON.stringify({
                        content_html: contentHtml,
                        target_format: format,
                        file_name: this.fileName,
                    }),
                });

                if (!response.ok) {
                    throw new Error(await response.text() || 'Gagal mengekspor dokumen.');
                }

                const blob = await response.blob();
                this.downloadBlob(blob, this.filenameFromResponse(response, format));
            } catch (error) {
                console.error('Gagal mengekspor dokumen', error);
                this.error = error?.message || 'Gagal mengekspor dokumen.';
            } finally {
                this.loadingAction = null;
            }
        },

        async saveToGoogleDrive(format) {
            if (this.isBusy() || !this.driveUploadAvailable) {
                return;
            }

            this.exportMenuOpen = false;
            this.driveMenuOpen = false;
            this.loadingAction = 'drive';
            this.error = '';

            try {
                await this.$wire.saveToGoogleDrive(format);
            } catch (error) {
                console.error('Gagal menyimpan dokumen ke Google Drive', error);
                this.error = error?.message || 'Gagal menyimpan ke Google Drive.';
            } finally {
                this.loadingAction = null;
            }
        },

        async fullContentHtml() {
            if (this.contentHtml) {
                return this.contentHtml;
            }

            if (!this.contentUrl) {
                throw new Error('Konten dokumen belum tersedia untuk diekspor.');
            }

            const response = await fetch(this.contentUrl, {
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                },
            });

            let payload = null;

            try {
                payload = await response.json();
            } catch (error) {
                payload = null;
            }

            if (!response.ok) {
                throw new Error(payload?.message || payload?.detail || 'Gagal mengekstrak isi dokumen.');
            }

            this.contentHtml = String(payload?.content_html || '').trim();

            if (!this.contentHtml) {
                throw new Error('Isi dokumen kosong atau tidak bisa diekstrak.');
            }

            return this.contentHtml;
        },

        async tableExportHtml() {
            const tables = await this.extractTables();

            if (tables.length === 0) {
                throw new Error('Tidak ada tabel yang bisa diekspor.');
            }

            return this.tablesToHtml(tables);
        },

        async extractTables() {
            if (Array.isArray(this.tables)) {
                return this.tables;
            }

            if (!this.extractUrl) {
                throw new Error('Ekstraksi tabel tidak tersedia untuk dokumen ini.');
            }

            const response = await fetch(this.extractUrl, {
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                },
            });

            let payload = null;

            try {
                payload = await response.json();
            } catch (error) {
                payload = null;
            }

            if (!response.ok) {
                throw new Error(payload?.message || payload?.detail || 'Gagal mengekstrak tabel.');
            }

            this.tables = Array.isArray(payload?.tables) ? payload.tables : [];

            return this.tables;
        },

        tablesToHtml(tables) {
            const sections = tables.map((table, index) => {
                const pageLabel = table.page ? ` - Halaman ${table.page}` : '';
                const title = `Tabel ${index + 1}${pageLabel}`;
                const header = Array.isArray(table.header) ? table.header : [];
                const rows = Array.isArray(table.rows) ? table.rows : [];
                const headerHtml = header.length > 0
                    ? `<thead><tr>${header.map((cell) => `<th>${this.escapeHtml(cell)}</th>`).join('')}</tr></thead>`
                    : '';
                const bodyHtml = rows
                    .map((row) => `<tr>${(Array.isArray(row) ? row : []).map((cell) => `<td>${this.escapeHtml(cell)}</td>`).join('')}</tr>`)
                    .join('');

                return `
                    <section>
                        <h2>${this.escapeHtml(title)}</h2>
                        <table>
                            ${headerHtml}
                            <tbody>${bodyHtml}</tbody>
                        </table>
                    </section>
                `;
            }).join('');

            return `<article><h1>Ekstrak Tabel Dokumen</h1>${sections}</article>`;
        },

        escapeHtml(value) {
            const element = document.createElement('div');
            element.textContent = value == null ? '' : String(value);

            return element.innerHTML;
        },

        getCsrfToken() {
            return document.querySelector('meta[name="csrf-token"]')?.content || '';
        },

        downloadBlob(blob, fileName) {
            const downloadUrl = window.URL.createObjectURL(blob);
            const anchor = document.createElement('a');
            anchor.href = downloadUrl;
            anchor.download = fileName;
            document.body.appendChild(anchor);
            anchor.click();
            anchor.remove();
            window.URL.revokeObjectURL(downloadUrl);
        },

        filenameFromResponse(response, format) {
            const disposition = response.headers.get('Content-Disposition') || '';
            const fileNameMatch = disposition.match(/filename="?([^"]+)"?/i);

            if (fileNameMatch?.[1]) {
                return fileNameMatch[1];
            }

            return `${this.fileName}.${format}`;
        },
    }));
};

document.addEventListener('alpine:init', () => {
    registerChatPageData(window.Alpine);
});

if (window.Alpine) {
    registerChatPageData(window.Alpine);
}
