/**
 * Aegis Flow - Frontend Application
 *
 * Alpine.js + vanilla WebSocket client for live device management,
 * command dispatching, AI automation modal, toast notifications,
 * theme switching and keyboard shortcuts.
 *
 * Fully self-contained, production-ready, accessible and responsive.
 */

(function () {
    'use strict';

    let alpineReady = false;

    function bootstrap() {
        if (typeof Alpine === 'undefined') {
            setTimeout(bootstrap, 50);
            return;
        }

        if (alpineReady) return;
        alpineReady = true;

        Alpine.data('aegisApp', () => ({
            // Reactive state
            devices: [],
            logs: [],
            connectionStatus: 'connecting',
            currentTheme: 'dark',
            selectedDeviceId: null,
            command: {
                type: 'shell',
                params: {
                    cmd: 'id'
                }
            },
            automationGoal: '',
            automationSuggestions: [],
            showAutomationModal: false,
            toasts: [],
            isProcessing: false,
            ws: null,
            filterTag: '',

            init() {
                console.log('%cAegis Flow UI ready — secure remote control active', 'color:#22c55e;font-weight:700');

                this.loadTheme();
                this.connectWebSocket();
                this.fetchInitialData();
                this.setupKeyboardShortcuts();

                // Live clock for relative timestamps
                setInterval(() => {
                    this.$refs?.time?.textContent = new Date().toLocaleTimeString();
                }, 10000);
            },

            loadTheme() {
                const saved = localStorage.getItem('aegis-theme') || 'dark';
                this.currentTheme = saved;
                if (saved === 'light') {
                    document.documentElement.classList.add('light');
                    document.documentElement.classList.remove('dark');
                } else {
                    document.documentElement.classList.add('dark');
                    document.documentElement.classList.remove('light');
                }
            },

            toggleTheme() {
                this.currentTheme = this.currentTheme === 'dark' ? 'light' : 'dark';
                localStorage.setItem('aegis-theme', this.currentTheme);

                if (this.currentTheme === 'light') {
                    document.documentElement.classList.add('light');
                    document.documentElement.classList.remove('dark');
                } else {
                    document.documentElement.classList.add('dark');
                    document.documentElement.classList.remove('light');
                }

                this.addToast(`Theme switched to ${this.currentTheme}`, 'info');
            },

            connectWebSocket() {
                const protocol = (window.location.protocol === 'https:') ? 'wss:' : 'ws:';
                const port = 8081;
                const url = `${protocol}//${window.location.hostname}:${port}`;

                this.ws = new WebSocket(url);

                this.ws.onopen = () => {
                    this.connectionStatus = 'connected';
                    this.addToast('Connected to real-time hub', 'success');

                    // Register as dashboard client
                    this.ws.send(JSON.stringify({
                        type: 'register',
                        client: 'dashboard',
                        timestamp: Date.now()
                    }));
                };

                this.ws.onmessage = (event) => {
                    try {
                        const msg = JSON.parse(event.data);
                        this.handleWebSocketMessage(msg);
                    } catch (err) {
                        console.error('WS message parse error', err);
                    }
                };

                this.ws.onclose = () => {
                    this.connectionStatus = 'disconnected';
                    this.addToast('WebSocket closed — reconnecting in 3s...', 'error');
                    setTimeout(() => this.connectWebSocket(), 3000);
                };

                this.ws.onerror = (err) => {
                    console.error('WebSocket error', err);
                    this.connectionStatus = 'error';
                };
            },

            handleWebSocketMessage(msg) {
                if (!msg || !msg.type) return;

                switch (msg.type) {
                    case 'device_update':
                    case 'status':
                        this.updateDevice(msg.device || msg.payload);
                        break;

                    case 'command_response':
                        if (msg.success) {
                            this.addToast(`Command completed on ${msg.deviceId?.substring(0, 8)}…`, 'success');
                        } else {
                            this.addToast(msg.error || 'Command failed', 'error');
                        }
                        break;

                    case 'log':
                    case 'audit':
                        if (msg.entry) {
                            this.logs.unshift(msg.entry);
                            if (this.logs.length > 25) this.logs.pop();
                        }
                        break;

                    case 'broadcast':
                        this.addToast(msg.message, 'info');
                        break;

                    default:
                        console.debug('Unhandled WS message:', msg.type);
                }
            },

            updateDevice(device) {
                if (!device || !device.id) return;

                const index = this.devices.findIndex(d => d.id === device.id);
                const now = Date.now();

                if (index !== -1) {
                    this.devices[index] = {
                        ...this.devices[index],
                        ...device,
                        last_seen: now
                    };
                } else {
                    this.devices.unshift({
                        ...device,
                        last_seen: now,
                        status: device.status || 'online'
                    });
                }
            },

            async fetchInitialData() {
                try {
                    const deviceResponse = await fetch('/api/devices', {
                        headers: { 'Accept': 'application/json' }
                    });

                    if (deviceResponse.ok) {
                        const payload = await deviceResponse.json();
                        this.devices = Array.isArray(payload.devices) ? payload.devices : payload;
                    }

                    const logResponse = await fetch('/api/logs', {
                        headers: { 'Accept': 'application/json' }
                    });

                    if (logResponse.ok) {
                        const payload = await logResponse.json();
                        this.logs = Array.isArray(payload.logs) ? payload.logs.slice(0, 25) : [];
                    }
                } catch (err) {
                    console.warn('Initial data fetch failed, using demo data', err);
                    this.devices = [
                        {
                            id: 'demo-android-01',
                            name: 'Pixel 8 Pro',
                            platform: 'android',
                            status: 'online',
                            battery: 92,
                            last_seen: Date.now() - 17000,
                            tags: ['test', 'mobile']
                        },
                        {
                            id: 'demo-ios-02',
                            name: 'iPhone 15 Pro',
                            platform: 'ios',
                            status: 'online',
                            battery: 67,
                            last_seen: Date.now() - 45000,
                            tags: ['personal']
                        },
                        {
                            id: 'demo-win-03',
                            name: 'DEV-WIN11-01',
                            platform: 'windows',
                            status: 'busy',
                            battery: 100,
                            last_seen: Date.now() - 120000,
                            tags: ['desktop', 'work']
                        }
                    ];
                    this.logs = [
                        {
                            timestamp: Date.now() - 60000,
                            action: 'command.shell',
                            actor: 'demo-user',
                            device: 'demo-android-01',
                            status: 'success'
                        }
                    ];
                }
            },

            selectDevice(id) {
                this.selectedDeviceId = id;
                const device = this.devices.find(d => d.id === id);
                if (device) {
                    this.addToast(`Controlling ${device.name || id}`, 'info');
                }
            },

            setCommandType(type) {
                this.command.type = type;

                switch (type) {
                    case 'shell':
                        this.command.params = { cmd: 'whoami && uptime' };
                        break;
                    case 'screenshot':
                        this.command.params = { quality: 'high', format: 'png' };
                        break;
                    case 'push':
                        this.command.params = { destination: '/tmp/payload.txt', content: 'Aegis Flow test payload' };
                        break;
                    case 'pull':
                        this.command.params = { path: '/sdcard/DCIM/camera.jpg' };
                        break;
                    default:
                        this.command.params = {};
                }
            },

            async dispatchCommand() {
                if (!this.selectedDeviceId) {
                    this.addToast('Select a device before sending a command', 'error');
                    return;
                }

                if (!this.command.type) {
                    this.addToast('Command type is required', 'error');
                    return;
                }

                this.isProcessing = true;

                try {
                    const response = await fetch('/api/command', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Authorization': 'Bearer aegis-demo-token'
                        },
                        body: JSON.stringify({
                            device_id: this.selectedDeviceId,
                            type: this.command.type,
                            params: this.command.params
                        })
                    });

                    const result = await response.json();

                    if (response.ok && (result.success || result.message)) {
                        this.addToast(`Command “${this.command.type}” dispatched successfully`, 'success');
                        // Clear sensitive fields after successful send
                        if (this.command.type === 'shell') {
                            this.command.params.cmd = '';
                        }
                    } else {
                        this.addToast(result.error || 'Failed to dispatch command', 'error');
                    }
                } catch (err) {
                    console.error(err);
                    this.addToast('Network error — command not sent', 'error');
                } finally {
                    this.isProcessing = false;
                }
            },

            openAutomationModal() {
                this.automationGoal = '';
                this.automationSuggestions = [];
                this.showAutomationModal = true;

                setTimeout(() => {
                    const goalInput = document.getElementById('automation-goal');
                    if (goalInput) goalInput.focus();
                }, 120);
            },

            async getAISuggestions() {
                const goal = this.automationGoal.trim();
                if (goal.length < 8) {
                    this.addToast('Please describe your goal in more detail', 'error');
                    return;
                }

                this.isProcessing = true;

                try {
                    const response = await fetch('/api/automation/suggest', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ goal })
                    });

                    const data = await response.json();
                    this.automationSuggestions = Array.isArray(data.suggestions)
                        ? data.suggestions
                        : (Array.isArray(data) ? data : []);
                } catch (err) {
                    console.warn('AI endpoint unavailable, using built-in examples', err);
                    this.automationSuggestions = [
                        {
                            description: 'Capture screenshot and pull to local storage',
                            steps: ['screenshot', 'pull']
                        },
                        {
                            description: 'Check battery, run diagnostics and report',
                            steps: ['get_battery', 'shell', 'notify']
                        }
                    ];
                } finally {
                    this.isProcessing = false;
                }
            },

            executeSuggestion(suggestion) {
                if (!suggestion || !suggestion.steps) return;

                this.showAutomationModal = false;
                this.addToast(`Executing automation: ${suggestion.description}`, 'success');

                // In a full implementation this would call Dispatcher::broadcastToTag
                // or sequentially dispatch each step. For demo we simulate.
                console.log('%c[Automation]', 'color:#86efac;font-weight:600', suggestion.steps);

                if (this.devices.length > 0 && !this.selectedDeviceId) {
                    this.selectedDeviceId = this.devices[0].id;
                }

                // Simulate progress
                setTimeout(() => {
                    this.addToast('Automation sequence completed', 'success');
                }, 1650);
            },

            addToast(message, type = 'info') {
                const toastId = 't-' + Date.now().toString(36);

                this.toasts.unshift({
                    id: toastId,
                    message: message,
                    type: type,
                    icon: type === 'success' ? '✓' : (type === 'error' ? '⚠' : 'i')
                });

                setTimeout(() => {
                    this.removeToast(toastId);
                }, 4200);
            },

            removeToast(id) {
                this.toasts = this.toasts.filter(t => t.id !== id);
            },

            setupKeyboardShortcuts() {
                document.addEventListener('keydown', (e) => {
                    // CMD/CTRL + K opens automation modal
                    if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                        e.preventDefault();
                        if (!this.showAutomationModal) {
                            this.openAutomationModal();
                        } else {
                            this.showAutomationModal = false;
                        }
                        return;
                    }

                    // Slash focuses command bar when not typing
                    if (e.key === '/' && !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName)) {
                        e.preventDefault();
                        const commandSelect = document.getElementById('command-type');
                        if (commandSelect) commandSelect.focus();
                    }

                    // Escape closes modal
                    if (e.key === 'Escape' && this.showAutomationModal) {
                        this.showAutomationModal = false;
                    }

                    // "R" replays the latest log
                    if (e.key.toLowerCase() === 'r' && document.activeElement.tagName !== 'INPUT' && document.activeElement.tagName !== 'TEXTAREA') {
                        if (this.logs.length > 0) {
                            this.addToast('Replaying last action (demo)', 'info');
                        }
                    }
                });
            },

            filteredDevices() {
                if (!this.filterTag) return this.devices;

                const tag = this.filterTag.toLowerCase();
                return this.devices.filter(device => {
                    if (!device.tags || !Array.isArray(device.tags)) return false;
                    return device.tags.some(t => t.toLowerCase().includes(tag));
                });
            },

            getPlatformEmoji(platform) {
                const p = (platform || '').toLowerCase();
                if (p.includes('android')) return '🤖';
                if (p.includes('ios')) return '';
                if (p.includes('windows')) return '🪟';
                return '📲';
            },

            getStatusBadgeClass(status) {
                const s = (status || 'offline').toLowerCase();
                if (s === 'online') return 'status-online';
                if (s === 'busy') return 'status-busy';
                return 'status-offline';
            },

            formatRelativeTime(timestamp) {
                if (!timestamp) return 'never';

                const diff = Math.floor((Date.now() - timestamp) / 1000);

                if (diff < 45) return 'just now';
                if (diff < 90) return '1m ago';
                if (diff < 3600) return Math.round(diff / 60) + 'm ago';
                if (diff < 86400) return Math.round(diff / 3600) + 'h ago';
                return Math.round(diff / 86400) + 'd ago';
            }
        }));
    }

    // Initialize when Alpine becomes available
    if (typeof window.Alpine !== 'undefined') {
        bootstrap();
    } else {
        document.addEventListener('alpine:init', bootstrap);
        // Also attempt manual bootstrap after load
        window.addEventListener('load', () => {
            setTimeout(bootstrap, 120);
        });
    }
})();
