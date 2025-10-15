/**
 * 机器人检测与指纹收集库
 * 用于收集用户指纹、行为数据并上传到后端进行评分
 */

(function(window) {
    'use strict';

    const BotDetection = {
        userId: null,
        sessionId: null,
        fingerprintHash: null,
        behaviorData: {
            mouseMovements: [],
            clickEvents: [],
            scrollEvents: [],
            keyboardEvents: []
        },
        config: {
            apiBaseUrl: window.location.origin,
            maxMouseMovements: 100,
            maxClickEvents: 50,
            maxScrollEvents: 50,
            uploadInterval: 10000
        },
        initialized: false,

        init: function(options) {
            if (this.initialized) return;

            Object.assign(this.config, options || {});

            this.sessionId = this.getSessionId();

            this.collectFingerprint().then(fingerprint => {
                this.fingerprintHash = fingerprint.hash;
                this.analyzeUser();
            });

            this.setupBehaviorTracking();
            this.setupPeriodicUpload();

            this.initialized = true;
        },

        getSessionId: function() {
            let sessionId = sessionStorage.getItem('bot_detection_session_id');
            if (!sessionId) {
                sessionId = this.generateId();
                sessionStorage.setItem('bot_detection_session_id', sessionId);
            }
            return sessionId;
        },

        generateId: function() {
            return 'sess_' + Date.now() + '_' + Math.random().toString(36).substring(2, 15);
        },

        hash: async function(str) {
            const encoder = new TextEncoder();
            const data = encoder.encode(str);
            const hashBuffer = await crypto.subtle.digest('SHA-256', data);
            const hashArray = Array.from(new Uint8Array(hashBuffer));
            return hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
        },

        collectFingerprint: async function() {
            const fingerprint = {
                canvas: this.getCanvasFingerprint(),
                webgl: this.getWebGLFingerprint(),
                audio: await this.getAudioFingerprint(),
                fonts: this.detectFonts(),
                plugins: this.getPlugins(),
                screen: {
                    width: screen.width,
                    height: screen.height,
                    colorDepth: screen.colorDepth,
                    pixelRatio: window.devicePixelRatio || 1
                },
                timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
                language: navigator.language,
                hardwareConcurrency: navigator.hardwareConcurrency || 0,
                deviceMemory: navigator.deviceMemory || 0,
                touchSupport: 'ontouchstart' in window || navigator.maxTouchPoints > 0,
                platform: navigator.platform,
                userAgent: navigator.userAgent
            };

            const fingerprintString = JSON.stringify(fingerprint);
            const hash = await this.hash(fingerprintString);

            return {
                ...fingerprint,
                hash: hash
            };
        },

        getCanvasFingerprint: function() {
            try {
                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');
                const txt = 'BotDetection:🔒🌐';
                ctx.textBaseline = 'top';
                ctx.font = '14px Arial';
                ctx.textBaseline = 'alphabetic';
                ctx.fillStyle = '#f60';
                ctx.fillRect(125, 1, 62, 20);
                ctx.fillStyle = '#069';
                ctx.fillText(txt, 2, 15);
                ctx.fillStyle = 'rgba(102, 204, 0, 0.7)';
                ctx.fillText(txt, 4, 17);
                return canvas.toDataURL();
            } catch (e) {
                return '';
            }
        },

        getWebGLFingerprint: function() {
            try {
                const canvas = document.createElement('canvas');
                const gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
                if (!gl) return '';

                const debugInfo = gl.getExtension('WEBGL_debug_renderer_info');
                if (!debugInfo) return '';

                return {
                    vendor: gl.getParameter(debugInfo.UNMASKED_VENDOR_WEBGL),
                    renderer: gl.getParameter(debugInfo.UNMASKED_RENDERER_WEBGL)
                };
            } catch (e) {
                return '';
            }
        },

        getAudioFingerprint: async function() {
            try {
                const AudioContext = window.AudioContext || window.webkitAudioContext;
                if (!AudioContext) return '';

                const context = new AudioContext();
                const oscillator = context.createOscillator();
                const analyser = context.createAnalyser();
                const gainNode = context.createGain();
                const scriptProcessor = context.createScriptProcessor(4096, 1, 1);

                gainNode.gain.value = 0;
                oscillator.type = 'triangle';
                oscillator.connect(analyser);
                analyser.connect(scriptProcessor);
                scriptProcessor.connect(gainNode);
                gainNode.connect(context.destination);

                oscillator.start(0);

                return new Promise((resolve) => {
                    scriptProcessor.onaudioprocess = function(event) {
                        const output = event.outputBuffer.getChannelData(0);
                        const hash = Array.from(output.slice(0, 30))
                            .map(v => v.toString())
                            .join('');
                        oscillator.stop();
                        scriptProcessor.disconnect();
                        resolve(hash.substring(0, 50));
                    };
                });
            } catch (e) {
                return '';
            }
        },

        detectFonts: function() {
            const baseFonts = ['monospace', 'sans-serif', 'serif'];
            const testFonts = [
                'Arial', 'Verdana', 'Times New Roman', 'Courier New', 'Georgia',
                'Palatino', 'Garamond', 'Bookman', 'Comic Sans MS', 'Trebuchet MS',
                'Impact', 'Lucida Console'
            ];

            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            const text = 'mmmmmmmmmmlli';

            const baseMeasurements = {};
            baseFonts.forEach(font => {
                ctx.font = '72px ' + font;
                baseMeasurements[font] = ctx.measureText(text).width;
            });

            const detectedFonts = [];
            testFonts.forEach(font => {
                let detected = false;
                baseFonts.forEach(baseFont => {
                    ctx.font = '72px ' + font + ',' + baseFont;
                    const width = ctx.measureText(text).width;
                    if (width !== baseMeasurements[baseFont]) {
                        detected = true;
                    }
                });
                if (detected) {
                    detectedFonts.push(font);
                }
            });

            return detectedFonts;
        },

        getPlugins: function() {
            const plugins = [];
            for (let i = 0; i < navigator.plugins.length; i++) {
                plugins.push(navigator.plugins[i].name);
            }
            return plugins;
        },

        setupBehaviorTracking: function() {
            let lastMouseMove = 0;

            document.addEventListener('mousemove', (e) => {
                const now = Date.now();
                if (now - lastMouseMove < 100) return;
                lastMouseMove = now;

                if (this.behaviorData.mouseMovements.length >= this.config.maxMouseMovements) {
                    this.behaviorData.mouseMovements.shift();
                }

                this.behaviorData.mouseMovements.push({
                    x: e.clientX,
                    y: e.clientY,
                    timestamp: now
                });
            });

            document.addEventListener('click', (e) => {
                if (this.behaviorData.clickEvents.length >= this.config.maxClickEvents) {
                    this.behaviorData.clickEvents.shift();
                }

                this.behaviorData.clickEvents.push({
                    x: e.clientX,
                    y: e.clientY,
                    button: e.button,
                    timestamp: Date.now()
                });
            });

            let lastScroll = 0;
            window.addEventListener('scroll', () => {
                const now = Date.now();
                if (now - lastScroll < 100) return;
                lastScroll = now;

                if (this.behaviorData.scrollEvents.length >= this.config.maxScrollEvents) {
                    this.behaviorData.scrollEvents.shift();
                }

                this.behaviorData.scrollEvents.push({
                    scrollY: window.scrollY,
                    scrollX: window.scrollX,
                    timestamp: now
                });
            });

            document.addEventListener('keydown', (e) => {
                this.behaviorData.keyboardEvents.push({
                    key: e.key.length === 1 ? 'char' : e.key,
                    timestamp: Date.now()
                });
            });
        },

        analyzeUser: async function() {
            try {
                const response = await fetch(this.config.apiBaseUrl + '/api/bot-detection/analyze', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        session_id: this.sessionId,
                        fingerprint_hash: this.fingerprintHash
                    })
                });

                const data = await response.json();
                if (data.success) {
                    this.userId = data.user_id;
                    console.log('[BotDetection] Analysis complete:', {
                        userId: this.userId,
                        score: data.score,
                        type: data.user_type
                    });

                    this.uploadFingerprint();
                }
            } catch (error) {
                console.error('[BotDetection] Analysis error:', error);
            }
        },

        uploadFingerprint: async function() {
            if (!this.userId) return;

            try {
                const fingerprint = await this.collectFingerprint();

                await fetch(this.config.apiBaseUrl + '/api/bot-detection/fingerprint', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        user_id: this.userId,
                        canvas: fingerprint.canvas,
                        webgl: JSON.stringify(fingerprint.webgl),
                        audio: fingerprint.audio,
                        fonts: fingerprint.fonts,
                        plugins: fingerprint.plugins,
                        touch_support: fingerprint.touchSupport,
                        hardware_concurrency: fingerprint.hardwareConcurrency,
                        device_memory: fingerprint.deviceMemory,
                        color_depth: fingerprint.screen.colorDepth,
                        fingerprint_hash: fingerprint.hash
                    })
                });
            } catch (error) {
                console.error('[BotDetection] Upload fingerprint error:', error);
            }
        },

        uploadBehavior: async function() {
            if (!this.userId) return;
            if (this.behaviorData.mouseMovements.length === 0 &&
                this.behaviorData.clickEvents.length === 0 &&
                this.behaviorData.scrollEvents.length === 0) {
                return;
            }

            try {
                const startTime = this.behaviorData.mouseMovements[0]?.timestamp ||
                                this.behaviorData.clickEvents[0]?.timestamp ||
                                this.behaviorData.scrollEvents[0]?.timestamp ||
                                Date.now();

                const timeOnPage = Math.floor((Date.now() - startTime) / 1000);
                const interactionCount = this.behaviorData.mouseMovements.length +
                                       this.behaviorData.clickEvents.length +
                                       this.behaviorData.scrollEvents.length +
                                       this.behaviorData.keyboardEvents.length;

                await fetch(this.config.apiBaseUrl + '/api/bot-detection/behavior', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        user_id: this.userId,
                        session_id: this.sessionId,
                        page_url: window.location.href,
                        mouse_movements: this.behaviorData.mouseMovements,
                        click_events: this.behaviorData.clickEvents,
                        scroll_events: this.behaviorData.scrollEvents,
                        keyboard_events: this.behaviorData.keyboardEvents,
                        time_on_page: timeOnPage,
                        interaction_count: interactionCount
                    })
                });

                this.behaviorData.mouseMovements = [];
                this.behaviorData.clickEvents = [];
                this.behaviorData.scrollEvents = [];
                this.behaviorData.keyboardEvents = [];

            } catch (error) {
                console.error('[BotDetection] Upload behavior error:', error);
            }
        },

        setupPeriodicUpload: function() {
            setInterval(() => {
                this.uploadBehavior();
            }, this.config.uploadInterval);

            window.addEventListener('beforeunload', () => {
                this.uploadBehavior();
            });
        }
    };

    window.BotDetection = BotDetection;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            BotDetection.init();
        });
    } else {
        BotDetection.init();
    }

})(window);
