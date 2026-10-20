/**
 * Device Fingerprinting Utility
 * Generates unique device fingerprint for security tracking
 */

class DeviceFingerprint {
    constructor() {
        this.fingerprint = null;
        this.components = {};
    }

    /**
     * Generate comprehensive device fingerprint
     */
    async generateFingerprint() {
        if (this.fingerprint) {
            return this.fingerprint;
        }

        try {
            // Collect browser information
            this.components.userAgent = navigator.userAgent;
            this.components.language = navigator.language;
            this.components.languages = navigator.languages?.join(',') || '';
            this.components.platform = navigator.platform;
            this.components.cookieEnabled = navigator.cookieEnabled;
            this.components.doNotTrack = navigator.doNotTrack;
            
            // Screen information
            this.components.screen = {
                width: screen.width,
                height: screen.height,
                colorDepth: screen.colorDepth,
                pixelDepth: screen.pixelDepth,
                availWidth: screen.availWidth,
                availHeight: screen.availHeight
            };

            // Window information
            this.components.window = {
                innerWidth: window.innerWidth,
                innerHeight: window.innerHeight,
                outerWidth: window.outerWidth,
                outerHeight: window.outerHeight
            };

            // Timezone information
            this.components.timezone = {
                offset: new Date().getTimezoneOffset(),
                name: Intl.DateTimeFormat().resolvedOptions().timeZone
            };

            // Browser capabilities
            this.components.capabilities = {
                localStorage: this.checkLocalStorage(),
                sessionStorage: this.checkSessionStorage(),
                indexedDB: this.checkIndexedDB(),
                webGL: this.checkWebGL(),
                canvas: this.checkCanvas(),
                audio: this.checkAudio(),
                geolocation: this.checkGeolocation(),
                camera: this.checkCamera(),
                microphone: this.checkMicrophone()
            };

            // Network information
            this.components.network = await this.getNetworkInfo();

            // Performance information
            this.components.performance = this.getPerformanceInfo();

            // Browser plugins
            this.components.plugins = this.getPluginInfo();

            // Font detection
            this.components.fonts = await this.detectFonts();

            // Generate hash
            this.fingerprint = await this.generateHash();
            
            // Store in localStorage for consistency
            this.storeFingerprint();
            
            return this.fingerprint;
        } catch (error) {
            console.error('Error generating device fingerprint:', error);
            return this.generateBasicFingerprint();
        }
    }

    /**
     * Check localStorage availability
     */
    checkLocalStorage() {
        try {
            const test = '__localStorage_test__';
            localStorage.setItem(test, test);
            localStorage.removeItem(test);
            return true;
        } catch (e) {
            return false;
        }
    }

    /**
     * Check sessionStorage availability
     */
    checkSessionStorage() {
        try {
            const test = '__sessionStorage_test__';
            sessionStorage.setItem(test, test);
            sessionStorage.removeItem(test);
            return true;
        } catch (e) {
            return false;
        }
    }

    /**
     * Check IndexedDB availability
     */
    checkIndexedDB() {
        return 'indexedDB' in window && indexedDB !== null;
    }

    /**
     * Check WebGL support
     */
    checkWebGL() {
        try {
            const canvas = document.createElement('canvas');
            const gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
            return !!gl;
        } catch (e) {
            return false;
        }
    }

    /**
     * Check Canvas support
     */
    checkCanvas() {
        try {
            const canvas = document.createElement('canvas');
            return !!(canvas.getContext && canvas.getContext('2d'));
        } catch (e) {
            return false;
        }
    }

    /**
     * Check Audio support
     */
    checkAudio() {
        return !!(window.AudioContext || window.webkitAudioContext);
    }

    /**
     * Check Geolocation support
     */
    checkGeolocation() {
        return 'geolocation' in navigator;
    }

    /**
     * Check Camera support
     */
    checkCamera() {
        return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
    }

    /**
     * Check Microphone support
     */
    checkMicrophone() {
        return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
    }

    /**
     * Get network information
     */
    async getNetworkInfo() {
        const info = {
            connection: null,
            effectiveType: null,
            downlink: null,
            rtt: null,
            saveData: null
        };

        try {
            if ('connection' in navigator) {
                const conn = navigator.connection;
                info.connection = conn.type || 'unknown';
                info.effectiveType = conn.effectiveType || 'unknown';
                info.downlink = conn.downlink || 0;
                info.rtt = conn.rtt || 0;
                info.saveData = conn.saveData || false;
            }
        } catch (e) {
            // Network API not available
        }

        return info;
    }

    /**
     * Get performance information
     */
    getPerformanceInfo() {
        const info = {
            timing: {},
            navigation: {}
        };

        try {
            if (window.performance && window.performance.timing) {
                info.timing = {
                    navigationStart: performance.timing.navigationStart,
                    loadEventEnd: performance.timing.loadEventEnd,
                    domContentLoaded: performance.timing.domContentLoadedEventEnd
                };
            }

            if (window.performance && window.performance.navigation) {
                info.navigation = {
                    type: performance.navigation.type,
                    redirectCount: performance.navigation.redirectCount
                };
            }
        } catch (e) {
            // Performance API not available
        }

        return info;
    }

    /**
     * Get plugin information
     */
    getPluginInfo() {
        const plugins = [];
        
        try {
            if (navigator.plugins) {
                for (let i = 0; i < navigator.plugins.length; i++) {
                    const plugin = navigator.plugins[i];
                    plugins.push({
                        name: plugin.name,
                        description: plugin.description,
                        filename: plugin.filename,
                        version: plugin.version
                    });
                }
            }
        } catch (e) {
            // Plugin access not available
        }

        return plugins;
    }

    /**
     * Detect installed fonts
     */
    async detectFonts() {
        const fonts = [
            'Arial', 'Arial Black', 'Comic Sans MS', 'Courier New', 'Georgia',
            'Helvetica', 'Impact', 'Lucida Console', 'Lucida Sans Unicode',
            'Palatino', 'Tahoma', 'Times New Roman', 'Trebuchet MS', 'Verdana'
        ];

        const detected = [];
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');

        for (const font of fonts) {
            try {
                ctx.font = `72px ${font}`;
                const text = 'mmmmmmmmmmlli';
                const width = ctx.measureText(text).width;
                
                ctx.font = `72px monospace`;
                const monoWidth = ctx.measureText(text).width;
                
                if (width !== monoWidth) {
                    detected.push(font);
                }
            } catch (e) {
                // Font detection failed
            }
        }

        return detected;
    }

    /**
     * Generate hash from components
     */
    async generateHash() {
        const data = JSON.stringify(this.components, Object.keys(this.components).sort());
        const encoder = new TextEncoder();
        const dataUint8Array = encoder.encode(data);
        
        try {
            const hashBuffer = await crypto.subtle.digest('SHA-256', dataUint8Array);
            const hashArray = Array.from(new Uint8Array(hashBuffer));
            return hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
        } catch (e) {
            // Fallback to simple hash
            return this.simpleHash(data);
        }
    }

    /**
     * Simple hash fallback
     */
    simpleHash(str) {
        let hash = 0;
        for (let i = 0; i < str.length; i++) {
            const char = str.charCodeAt(i);
            hash = ((hash << 5) - hash) + char;
            hash = hash & hash; // Convert to 32-bit integer
        }
        return Math.abs(hash).toString(16);
    }

    /**
     * Generate basic fingerprint (fallback)
     */
    generateBasicFingerprint() {
        const basic = {
            userAgent: navigator.userAgent,
            language: navigator.language,
            platform: navigator.platform,
            screen: `${screen.width}x${screen.height}`,
            timezone: new Date().getTimezoneOffset()
        };
        
        return this.simpleHash(JSON.stringify(basic));
    }

    /**
     * Store fingerprint in localStorage
     */
    storeFingerprint() {
        try {
            if (this.checkLocalStorage()) {
                localStorage.setItem('device_fingerprint', this.fingerprint);
                localStorage.setItem('device_fingerprint_timestamp', Date.now());
                localStorage.setItem('device_fingerprint_components', JSON.stringify(this.components));
            }
        } catch (e) {
            // Storage not available
        }
    }

    /**
     * Get stored fingerprint
     */
    getStoredFingerprint() {
        try {
            if (this.checkLocalStorage()) {
                const stored = localStorage.getItem('device_fingerprint');
                const timestamp = localStorage.getItem('device_fingerprint_timestamp');
                
                if (stored && timestamp) {
                    const age = Date.now() - parseInt(timestamp);
                    // Return stored fingerprint if less than 30 days old
                    if (age < 30 * 24 * 60 * 60 * 1000) {
                        return stored;
                    }
                }
            }
        } catch (e) {
            // Storage not available
        }
        
        return null;
    }

    /**
     * Get fingerprint with fallback
     */
    async getFingerprint() {
        // Try to get stored fingerprint first
        const stored = this.getStoredFingerprint();
        if (stored) {
            this.fingerprint = stored;
            return stored;
        }

        // Generate new fingerprint
        return await this.generateFingerprint();
    }
}

// Create singleton instance
const deviceFingerprint = new DeviceFingerprint();

// Export for use in other modules
export default deviceFingerprint;

// Auto-generate fingerprint on load
(async () => {
    try {
        await deviceFingerprint.getFingerprint();
        console.log('Device fingerprint generated successfully');
    } catch (error) {
        console.error('Failed to generate device fingerprint:', error);
    }
})();
