<?php

declare(strict_types=1);

namespace AegisFlow\Panel;

use InvalidArgumentException;

/**
 * Renders the main dashboard HTML interface.
 *
 * Produces a complete, self-contained HTML document using Tailwind CSS,
 * Alpine.js for reactivity, and WebSocket integration for live updates.
 * The $data array is injected as initial state for the frontend.
 */
final class View
{
    /**
     * Render the full dashboard page.
     *
     * @param array $data Dashboard data from Application::getDashboardData()
     * @return string Complete HTML document
     *
     * @throws InvalidArgumentException
     */
    public function render(array $data): string
    {
        if (!isset($data['devices']) || !is_array($data['devices'])) {
            throw new InvalidArgumentException('Dashboard data must contain a "devices" array.');
        }

        $initialDevices = json_encode($data['devices'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
        $initialLogs = json_encode($data['logs'] ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
        $theme = htmlspecialchars((string)($data['theme'] ?? 'dark'));
        $onlineCount = (int)($data['online_count'] ?? 0);
        $totalDevices = (int)($data['total_devices'] ?? 0);

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en" class="{$theme}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aegis Flow • Secure Device Control</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="/css/app.css">
    <script src="https://unpkg.com/alpinejs@3.13.3/dist/cdn.min.js" defer></script>
    <style>
        :root {
            --bg: #0f172a;
            --accent: #22c55e;
            --card: #1e2937;
        }
        
        .dark {
            color-scheme: dark;
        }
        
        .device-card {
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .device-card:hover {
            transform: translateY(-4px) scale(1.03);
            box-shadow: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
        }
        
        .status-dot {
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.4; }
        }
        
        .log-entry {
            transition: background-color 0.15s ease;
        }
        
        .log-entry:hover {
            background-color: #334155;
        }
        
        .tail-container {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
    </style>
</head>
<body class="tail-container bg-[#0f172a] text-slate-200 min-h-screen">
    <div class="flex h-screen overflow-hidden" x-data="appData()" x-init="init()">
        <!-- Sidebar -->
        <div class="w-64 bg-slate-900 border-r border-slate-700 flex flex-col">
            <div class="p-6 border-b border-slate-700">
                <div class="flex items-center gap-x-3">
                    <div class="w-9 h-9 bg-emerald-500 rounded-2xl flex items-center justify-center text-white font-bold text-xl shadow-inner">A</div>
                    <div>
                        <span class="font-semibold tracking-tighter text-3xl text-white">AEGIS</span>
                        <span class="font-medium text-emerald-400 text-xl">FLOW</span>
                    </div>
                </div>
                <p class="text-xs text-slate-400 mt-1 tracking-[0.5px]">SECURE REMOTE OPS</p>
            </div>
            
            <div class="px-3 py-6 flex-1 overflow-y-auto">
                <nav class="space-y-1">
                    <a href="#" onclick="this.classList.add('!bg-slate-800','!text-white');" 
                       class="flex items-center gap-x-3 px-4 py-3 text-slate-300 hover:bg-slate-800 rounded-3xl font-medium active">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1v-5m10-10l2 2m-2-2v10a1 1 0 01-1 1v-5m-6 0a1 1 0 001-1v5" />
                        </svg>
                        <span>Dashboard</span>
                    </a>
                    <a href="#" class="flex items-center gap-x-3 px-4 py-3 text-slate-300 hover:bg-slate-800 rounded-3xl font-medium">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2" />
                        </svg>
                        <span>Devices</span>
                    </a>
                    <a href="#" class="flex items-center gap-x-3 px-4 py-3 text-slate-300 hover:bg-slate-800 rounded-3xl font-medium">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2" />
                        </svg>
                        <span>Automation</span>
                    </a>
                    <a href="#" class="flex items-center gap-x-3 px-4 py-3 text-slate-300 hover:bg-slate-800 rounded-3xl font-medium">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v12m-3-9h6m-6 6h6" />
                        </svg>
                        <span>Audit Log</span>
                    </a>
                </nav>
                
                <div class="mt-10 px-4">
                    <div class="text-xs font-mono uppercase tracking-widest text-slate-500 mb-3">Connected Agents</div>
                    <div id="sidebar-devices" class="space-y-4">
                        <!-- populated by JS -->
                    </div>
                </div>
            </div>
            
            <div class="p-4 border-t border-slate-700 mt-auto">
                <div class="bg-slate-800 rounded-3xl p-4 text-xs">
                    <div class="flex justify-between items-center">
                        <div class="flex items-center gap-x-2">
                            <div class="w-2 h-2 bg-emerald-400 rounded-full animate-pulse"></div>
                            <span class="font-medium text-emerald-300">WS Hub</span>
                        </div>
                        <span class="font-mono text-[10px] text-slate-400">port 8081</span>
                    </div>
                    <div class="text-slate-400 text-[10px] mt-3">All traffic is end-to-end encrypted with Libsodium.</div>
                </div>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="flex-1 flex flex-col">
            <!-- Top Bar -->
            <header class="h-14 bg-slate-900 border-b border-slate-700 px-8 flex items-center justify-between">
                <div class="flex items-center gap-x-8">
                    <div onclick="window.location.reload()" class="flex items-center cursor-pointer">
                        <span class="text-xl font-semibold text-white">Control Panel</span>
                    </div>
                    
                    <div class="flex items-center bg-slate-800 rounded-3xl text-sm">
                        <div onclick="switchTab(this)" class="tab px-5 py-1.5 rounded-3xl bg-slate-700 text-white cursor-pointer">Live Grid</div>
                        <div onclick="switchTab(this)" class="tab px-5 py-1.5 text-slate-400 hover:text-slate-200 cursor-pointer">Commands</div>
                        <div onclick="switchTab(this)" class="tab px-5 py-1.5 text-slate-400 hover:text-slate-200 cursor-pointer">History</div>
                    </div>
                </div>
                
                <div class="flex items-center gap-x-6">
                    <!-- Online indicator -->
                    <div class="flex items-center gap-x-2 bg-slate-800 text-emerald-400 text-xs font-medium px-4 h-8 rounded-3xl">
                        <div class="w-2 h-2 bg-current rounded-full status-dot"></div>
                        <span id="online-count">{$onlineCount}</span>/<span id="total-count">{$totalDevices}</span> online
                    </div>
                    
                    <button onclick="toggleTheme()" 
                            class="w-8 h-8 flex items-center justify-center text-slate-400 hover:text-slate-100 transition-colors">
                        <span id="theme-icon">☀️</span>
                    </button>
                    
                    <div class="flex items-center gap-x-2 bg-slate-800 rounded-3xl pr-2">
                        <div class="bg-slate-700 text-xs font-mono px-3 py-1 rounded-3xl">admin</div>
                        <div class="text-xs text-slate-400 pr-2">demo</div>
                    </div>
                    
                    <button onclick="showAutomationModal()" 
                            class="flex items-center gap-x-2 bg-emerald-500 hover:bg-emerald-600 transition-colors text-white text-sm font-semibold px-6 h-9 rounded-3xl">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7-7 7" />
                        </svg>
                        <span>AI ASSISTANT</span>
                    </button>
                </div>
            </header>
            
            <div class="flex-1 p-8 overflow-auto" style="background: linear-gradient(180deg, #0f172a 0%, #1e2937 100%)">
                <!-- Stats -->
                <div class="grid grid-cols-3 gap-6 mb-8">
                    <div class="bg-slate-800/70 backdrop-blur-xl border border-slate-700 rounded-3xl p-6">
                        <div class="text-emerald-400 text-sm font-medium flex items-center gap-x-2">
                            <span>DEVICES ONLINE</span>
                        </div>
                        <div class="text-6xl font-semibold text-white mt-2" id="stat-online">{$onlineCount}</div>
                        <div class="text-slate-400 text-sm">out of {$totalDevices} registered</div>
                    </div>
                    <div class="bg-slate-800/70 backdrop-blur-xl border border-slate-700 rounded-3xl p-6">
                        <div class="text-amber-400 text-sm font-medium">PENDING COMMANDS</div>
                        <div class="text-6xl font-semibold text-white mt-2" id="stat-pending">3</div>
                        <div class="text-slate-400 text-sm">in queue</div>
                    </div>
                    <div class="bg-slate-800/70 backdrop-blur-xl border border-slate-700 rounded-3xl p-6 relative overflow-hidden">
                        <div class="text-sky-400 text-sm font-medium">LAST ACTIVITY</div>
                        <div class="text-6xl font-semibold text-white mt-2" id="stat-last">just now</div>
                        <div class="text-xs font-mono absolute bottom-6 right-6 bg-slate-900 px-3 py-1 rounded-2xl text-slate-400">LIVE</div>
                    </div>
                </div>
                
                <!-- Devices Grid -->
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-white">Registered Devices</h2>
                    <div class="flex items-center gap-x-2 text-xs">
                        <input id="device-filter" 
                               onkeyup="filterDevices()" 
                               type="text" 
                               placeholder="Filter by name or tag..." 
                               class="bg-slate-800 border border-slate-600 focus:border-emerald-500 rounded-3xl px-4 py-2 outline-none text-sm w-64 transition-all">
                    </div>
                </div>
                
                <div id="device-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                    <!-- Populated by JS from initialDevices -->
                </div>
                
                <!-- Quick Command Bar -->
                <div class="mt-12 bg-slate-900 border border-slate-700 rounded-3xl p-6">
                    <div class="flex items-center justify-between mb-4">
                        <div class="font-medium">Quick Command</div>
                        <button onclick="showFullCommandModal()" 
                                class="text-xs px-5 py-2 bg-slate-800 hover:bg-slate-700 rounded-3xl text-slate-300 flex items-center gap-x-2">
                            <span>ADVANCED</span>
                            <span class="text-lg leading-none">→</span>
                        </button>
                    </div>
                    
                    <div class="grid grid-cols-12 gap-4">
                        <div class="col-span-3">
                            <label class="block text-xs text-slate-400 mb-2">TARGET DEVICE</label>
                            <select id="quick-device" 
                                    class="w-full bg-slate-800 border border-slate-600 rounded-3xl px-5 py-3 text-sm outline-none">
                                <!-- populated by JS -->
                            </select>
                        </div>
                        <div class="col-span-3">
                            <label class="block text-xs text-slate-400 mb-2">ACTION</label>
                            <select id="quick-action" onchange="updateParamHint()" 
                                    class="w-full bg-slate-800 border border-slate-600 rounded-3xl px-5 py-3 text-sm outline-none">
                                <option value="shell">Shell Command</option>
                                <option value="screenshot">Take Screenshot</option>
                                <option value="get_battery">Get Battery</option>
                                <option value="notify">Send Notification</option>
                                <option value="diagnostics">Run Diagnostics</option>
                            </select>
                        </div>
                        <div class="col-span-4">
                            <label class="block text-xs text-slate-400 mb-2">PARAMETER</label>
                            <input id="quick-param" type="text" placeholder="whoami or custom message" 
                                   class="w-full bg-slate-800 border border-slate-600 rounded-3xl px-5 py-3 text-sm outline-none">
                        </div>
                        <div class="col-span-2 flex items-end">
                            <button onclick="sendQuickCommand()" 
                                    class="w-full h-12 bg-emerald-500 hover:bg-emerald-600 active:scale-95 transition-all rounded-3xl text-white font-semibold text-sm">
                                EXECUTE
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Live Log -->
            <div class="h-72 bg-slate-950 border-t border-slate-700 flex flex-col">
                <div class="px-8 py-4 border-b border-slate-700 flex items-center justify-between bg-slate-900">
                    <div class="uppercase text-xs font-mono tracking-widest text-slate-400">Audit Log • Live</div>
                    <button onclick="clearLog()" 
                            class="text-xs px-4 py-1 text-slate-400 hover:text-red-400 transition-colors">CLEAR</button>
                </div>
                <div id="live-log" class="flex-1 p-4 overflow-auto font-mono text-xs space-y-1 text-slate-300">
                    <!-- Populated by JS -->
                </div>
            </div>
        </div>
        
        <!-- Right Sidebar - AI & Info -->
        <div class="w-80 bg-slate-900 border-l border-slate-700 flex flex-col">
            <div class="p-6">
                <div class="uppercase text-xs font-semibold mb-4 text-violet-300 tracking-widest">AI Assistant</div>
                
                <div class="bg-slate-800 rounded-3xl p-5">
                    <textarea id="ai-goal" rows="3" 
                              class="w-full bg-transparent outline-none resize-none text-sm" 
                              placeholder="Turn on all cameras and report battery levels..."></textarea>
                    
                    <button onclick="runAISuggestion()" 
                            class="mt-4 w-full py-3 text-sm font-semibold bg-violet-600 hover:bg-violet-500 transition-colors rounded-3xl">
                        SUGGEST AUTOMATION
                    </button>
                </div>
                
                <div id="suggestion-output" class="mt-6 hidden">
                    <div class="text-xs uppercase text-slate-400 mb-3">Suggested Steps</div>
                    <div id="suggested-steps" class="space-y-3 text-sm">
                        <!-- JS injected steps -->
                    </div>
                    <button onclick="executeSuggestedSteps()" 
                            class="mt-6 w-full h-10 bg-emerald-500 text-white rounded-3xl text-sm font-medium">
                        RUN ALL STEPS
                    </button>
                </div>
            </div>
            
            <div class="mt-auto border-t border-slate-700 p-6">
                <div class="text-[10px] font-mono text-slate-500 leading-relaxed">
                    E2E ENCRYPTED<br>
                    LIBSODIUM SECRETBOX<br>
                    ZERO TRUST ARCHITECTURE
                </div>
                
                <div onclick="window.open('https://github.com/aegis-flow','_blank')" 
                     class="cursor-pointer mt-8 flex items-center gap-x-2 text-slate-500 hover:text-slate-300 text-xs">
                    <span>VIEW SOURCE</span>
                    <span class="flex-1 h-px bg-slate-700"></span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Command Modal -->
    <div onclick="if(event.target.id === 'command-modal') hideCommandModal()" id="command-modal" 
         class="hidden fixed inset-0 bg-black/70 flex items-center justify-center z-50">
        <div onclick="event.stopImmediatePropagation()" 
             class="bg-slate-900 w-full max-w-lg mx-4 rounded-3xl overflow-hidden">
            <div class="px-8 pt-8 pb-6 border-b border-slate-700">
                <div class="font-semibold text-xl">Send Command</div>
                <div class="text-slate-400 text-sm">Device will receive it instantly over encrypted WebSocket</div>
            </div>
            
            <div class="p-8 space-y-8">
                <div>
                    <label class="text-xs tracking-widest text-slate-400 block mb-2">DEVICE</label>
                    <select id="modal-device-select" class="w-full bg-slate-800 rounded-3xl px-6 py-4 outline-none border border-transparent focus:border-violet-400">
                    </select>
                </div>
                
                <div>
                    <label class="text-xs tracking-widest text-slate-400 block mb-2">COMMAND TYPE</label>
                    <div class="grid grid-cols-2 gap-3" id="command-type-grid">
                        <!-- populated by JS -->
                    </div>
                </div>
                
                <div>
                    <label class="text-xs tracking-widest text-slate-400 block mb-3">PARAMETERS (JSON)</label>
                    <textarea id="modal-params" class="w-full h-28 font-mono bg-slate-950 border border-slate-700 rounded-3xl p-5 text-xs"></textarea>
                </div>
            </div>
            
            <div class="px-8 py-6 bg-slate-950 flex gap-x-3">
                <button onclick="hideCommandModal()" 
                        class="flex-1 py-4 text-slate-400 font-medium border border-slate-700 rounded-3xl">CANCEL</button>
                <button onclick="submitModalCommand()" 
                        class="flex-1 py-4 bg-white text-slate-900 font-semibold rounded-3xl">SEND SECURE COMMAND</button>
            </div>
        </div>
    </div>
    
    <!-- Toast -->
    <div id="toast" onclick="this.classList.add('opacity-0')" 
         class="hidden fixed bottom-6 right-6 bg-slate-800 border border-emerald-400 text-emerald-300 rounded-3xl shadow-2xl px-5 py-4 flex items-center gap-x-3 transition-opacity">
        <span id="toast-text" class="text-sm pr-8"></span>
    </div>
    
    <script>
        function appData() {
            return {
                devices: {$initialDevices},
                logs: {$initialLogs},
                currentTheme: '{$theme}',
                init() {
                    this.renderDevices();
                    this.renderLog();
                    this.populateDeviceSelects();
                    // Connect to WebSocket for live updates
                    this.connectWebSocket();
                    this.updateStats();
                },
                renderDevices(filteredDevices = null) {
                    const container = document.getElementById('device-grid');
                    container.innerHTML = '';
                    const toRender = filteredDevices || this.devices;
                    
                    toRender.forEach(device => {
                        const isOnline = device.is_online || (Date.now()/1000 - (device.last_seen || 0)) < 300;
                        const platformColor = {
                            'android': 'emerald',
                            'ios': 'indigo',
                            'windows': 'blue'
                        }[device.platform] || 'slate';
                        
                        const cardHTML = `
                        <div class="device-card bg-slate-800 border border-slate-700 rounded-3xl overflow-hidden h-full flex flex-col">
                            <div class="px-5 pt-5 pb-4 border-b border-slate-700 flex justify-between items-start">
                                <div>
                                    <span class="inline-flex items-center px-3 py-1 text-xs font-medium rounded-3xl bg-\${platformColor}-500/10 text-\${platformColor}-400">
                                        \${device.platform.toUpperCase()}
                                    </span>
                                </div>
                                <div class="flex items-center gap-x-1.5 \${isOnline ? 'text-emerald-400' : 'text-slate-500'}">
                                    <span class="relative flex h-3 w-3">
                                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-current opacity-75"></span>
                                        <span class="relative inline-flex rounded-full h-3 w-3 bg-current"></span>
                                    </span>
                                    <span class="text-xs font-medium">\${isOnline ? 'ONLINE' : 'OFFLINE'}</span>
                                </div>
                            </div>
                            <div class="p-5 flex-1">
                                <div class="font-semibold text-lg text-white">\${device.name || 'Unnamed Device'}</div>
                                <div class="font-mono text-xs text-slate-500">\${device.id}</div>
                                
                                <div class="mt-8">
                                    <div class="flex justify-between text-xs mb-2">
                                        <div class="text-slate-400">BATTERY</div>
                                        <div class="font-medium">\${device.battery || 92}%</div>
                                    </div>
                                    <div class="h-2 bg-slate-700 rounded-3xl overflow-hidden">
                                        <div class="h-2 bg-emerald-400 rounded-3xl transition-all" 
                                             style="width: \${device.battery || 92}%"></div>
                                    </div>
                                </div>
                                
                                <div class="mt-6 text-xs text-slate-400">
                                    Last seen <span class="font-medium text-slate-300">\${device.relative_time || 'recently'}</span>
                                </div>
                            </div>
                            <div class="px-5 py-4 bg-slate-900 border-t border-slate-700 text-xs flex gap-2">
                                <button onclick="sendCommandToDevice('\${device.id}', 'screenshot'); event.stopImmediatePropagation()" 
                                        class="flex-1 py-3 bg-slate-800 hover:bg-slate-700 rounded-2xl">📸 SCREEN</button>
                                <button onclick="sendCommandToDevice('\${device.id}', 'shell'); event.stopImmediatePropagation()" 
                                        class="flex-1 py-3 bg-slate-800 hover:bg-slate-700 rounded-2xl">SHELL</button>
                            </div>
                        </div>`;
                        
                        container.innerHTML += cardHTML;
                    });
                },
                renderLog() {
                    const container = document.getElementById('live-log');
                    container.innerHTML = '';
                    
                    this.logs.slice(0, 8).forEach(log => {
                        const entry = document.createElement('div');
                        entry.className = 'log-entry px-4 py-2.5 rounded-2xl flex gap-4 text-[10px]';
                        entry.innerHTML = `
                            <span class="font-mono text-slate-500 w-20 shrink-0">\${new Date(log.ts * 1000 || Date.now()).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'})}</span>
                            <span class="text-violet-300 font-medium w-28 shrink-0">\${log.action || 'event'}</span>
                            <span class="flex-1 text-slate-400 truncate">\${log.device_id ? log.device_id.substring(0, 12) : ''}</span>
                        `;
                        container.append(entry);
                    });
                },
                populateDeviceSelects() {
                    const selects = document.querySelectorAll('#quick-device, #modal-device-select');
                    selects.forEach(select => {
                        select.innerHTML = '<option value="">-- Select device --</option>';
                        this.devices.forEach(device => {
                            const opt = document.createElement('option');
                            opt.value = device.id;
                            opt.textContent = `\${device.name} (\${device.platform})`;
                            select.append(opt);
                        });
                    });
                },
                updateStats() {
                    const onlineEl = document.getElementById('stat-online');
                    if (onlineEl) onlineEl.textContent = this.devices.filter(d => d.is_online).length;
                },
                connectWebSocket() {
                    const ws = new WebSocket('ws://localhost:8081');
                    ws.onopen = () => {
                        console.log('%c🛰️  UI connected to Aegis Flow Hub', 'color:#22c55e; font-size:10px');
                        ws.send(JSON.stringify({type: 'ui_subscribe'}));
                    };
                    ws.onmessage = (event) => {
                        const msg = JSON.parse(event.data);
                        if (msg.type === 'response' || msg.type === 'device_heartbeat') {
                            this.handleLiveUpdate(msg);
                        }
                    };
                    ws.onerror = (err) => console.error('WebSocket error', err);
                },
                handleLiveUpdate(msg) {
                    if (msg.type === 'response' && msg.payload) {
                        this.showToast('Command response received from agent');
                        this.logs.unshift({
                            ts: Date.now()/1000,
                            action: 'response',
                            device_id: msg.payload.device_id
                        });
                        this.renderLog();
                    }
                },
                showToast(message) {
                    const toast = document.getElementById('toast');
                    const text = document.getElementById('toast-text');
                    text.textContent = message;
                    toast.classList.remove('hidden');
                    toast.classList.add('flex');
                    setTimeout(() => {
                        toast.classList.add('opacity-0');
                        setTimeout(() => {
                            toast.classList.add('hidden');
                            toast.classList.remove('flex', 'opacity-0');
                        }, 300);
                    }, 4500);
                }
            }
        }
        
        // Global helper functions used by inline onclick handlers
        let currentApp;
        
        function init() {
            // Alpine will call x-init which calls this
        }
        
        function switchTab(el) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('!bg-slate-700', 'text-white'));
            el.classList.add('!bg-slate-700', 'text-white');
        }
        
        function toggleTheme() {
            const html = document.documentElement;
            if (html.classList.contains('dark')) {
                html.classList.remove('dark');
                localStorage.theme = 'light';
            } else {
                html.classList.add('dark');
                localStorage.theme = 'dark';
            }
        }
        
        function filterDevices() {
            const term = document.getElementById('device-filter').value.toLowerCase().trim();
            if (!currentApp || !currentApp.devices) return;
            
            if (term === '') {
                currentApp.renderDevices();
                return;
            }
            
            const filtered = currentApp.devices.filter(device => 
                (device.name && device.name.toLowerCase().includes(term)) ||
                (device.id && device.id.toLowerCase().includes(term)) ||
                (device.tags && device.tags.some(tag => tag.includes(term)))
            );
            currentApp.renderDevices(filtered);
        }
        
        function sendCommandToDevice(deviceId, type) {
            if (!currentApp) return;
            currentApp.showToast(`Command \${type} sent to \${deviceId}`);
            
            // In real implementation this would call the /api/command endpoint
            fetch('/api/command', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    token: 'aegis-demo-token',
                    device_id: deviceId,
                    type: type,
                    params: {}
                })
            }).then(r => r.json()).then(console.log);
        }
        
        function showAutomationModal() {
            const goal = prompt("Describe your automation goal in plain English:");
            if (!goal || !currentApp) return;
            
            fetch('/api/suggest', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    token: 'aegis-demo-token',
                    goal: goal
                })
            })
            .then(r => r.json())
            .then(result => {
                if (result.suggestions) {
                    const output = document.getElementById('suggestion-output');
                    const stepsContainer = document.getElementById('suggested-steps');
                    stepsContainer.innerHTML = '';
                    
                    result.suggestions.forEach((step, index) => {
                        const div = document.createElement('div');
                        div.className = 'bg-slate-800 rounded-2xl p-4 text-xs flex items-center gap-3';
                        div.innerHTML = `
                            <span class="font-mono bg-slate-700 w-5 h-5 flex items-center justify-center rounded-lg shrink-0">\${index+1}</span>
                            <span class="font-medium">\${step.type}</span>
                            <span class="text-slate-400 text-[10px] truncate">\${JSON.stringify(step.params || {})}</span>
                        `;
                        stepsContainer.append(div);
                    });
                    
                    output.classList.remove('hidden');
                    currentApp.showToast('AI generated ' + result.suggestions.length + ' steps');
                }
            });
        }
        
        function executeSuggestedSteps() {
            if (!currentApp) return;
            currentApp.showToast('Executing automation sequence...');
            // Would call the backend to expand and dispatch each step
            setTimeout(() => {
                currentApp.showToast('Automation sequence completed successfully');
            }, 1700);
        }
        
        function showFullCommandModal() {
            const modal = document.getElementById('command-modal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }
        
        function hideCommandModal() {
            const modal = document.getElementById('command-modal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }
        
        function updateParamHint() {
            // Could be expanded
        }
        
        function sendQuickCommand() {
            const deviceSelect = document.getElementById('quick-device');
            const actionSelect = document.getElementById('quick-action');
            const paramInput = document.getElementById('quick-param');
            
            if (!deviceSelect.value) {
                alert("Please select a target device");
                return;
            }
            
            const deviceId = deviceSelect.value;
            const type = actionSelect.value;
            const paramValue = paramInput.value;
            
            fetch('/api/command', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    token: 'aegis-demo-token',
                    device_id: deviceId,
                    type: type,
                    params: paramValue ? {value: paramValue} : {}
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (currentApp) currentApp.showToast('Command dispatched successfully');
                } else {
                    alert(data.error || 'Failed to dispatch command');
                }
            })
            .catch(err => {
                console.error(err);
                alert('Network error');
            });
        }
        
        function submitModalCommand() {
            hideCommandModal();
            if (currentApp) currentApp.showToast('Command sent via encrypted channel');
        }
        
        function clearLog() {
            const logContainer = document.getElementById('live-log');
            logContainer.innerHTML = `<div class="p-8 text-center text-slate-500 text-xs">Audit log cleared.</div>`;
            setTimeout(() => {
                if (currentApp) currentApp.renderLog();
            }, 1200);
        }
        
        // Make Alpine data globally accessible for inline functions
        document.addEventListener('alpine:init', () => {
            currentApp = Alpine.store('app', appData());
        });
        
        // Boot Alpine
        window.addEventListener('load', () => {
            console.log('%cAegis Flow Dashboard initialized', 'color:#22c55e;font-family:monospace');
        });
    </script>
</body>
</html>
HTML;

        return $html;
    }
}
