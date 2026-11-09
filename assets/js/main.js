/**
 * DOCTOR CONNECT - Main Client Utilities
 */

// Global Audio Chime using Web Audio API (Zero external MP3 dependency!)
const SoundFX = {
    audioCtx: null,
    
    init() {
        if (!this.audioCtx) {
            const AudioContext = window.AudioContext || window.webkitAudioContext;
            if (AudioContext) {
                this.audioCtx = new AudioContext();
            }
        }
    },
    
    playChime() {
        try {
            this.init();
            if (!this.audioCtx) return;
            if (this.audioCtx.state === 'suspended') {
                this.audioCtx.resume();
            }
            
            const now = this.audioCtx.currentTime;
            
            // Tone 1: E5 (659.25 Hz)
            const osc1 = this.audioCtx.createOscillator();
            const gain1 = this.audioCtx.createGain();
            osc1.type = 'sine';
            osc1.frequency.setValueAtTime(659.25, now);
            gain1.gain.setValueAtTime(0, now);
            gain1.gain.linearRampToValueAtTime(0.3, now + 0.05);
            gain1.gain.exponentialRampToValueAtTime(0.001, now + 0.6);
            osc1.connect(gain1);
            gain1.connect(this.audioCtx.destination);
            osc1.start(now);
            osc1.stop(now + 0.6);
            
            // Tone 2: A5 (880.00 Hz)
            const osc2 = this.audioCtx.createOscillator();
            const gain2 = this.audioCtx.createGain();
            osc2.type = 'sine';
            osc2.frequency.setValueAtTime(880.0, now + 0.18);
            gain2.gain.setValueAtTime(0, now + 0.18);
            gain2.gain.linearRampToValueAtTime(0.35, now + 0.22);
            gain2.gain.exponentialRampToValueAtTime(0.001, now + 0.9);
            osc2.connect(gain2);
            gain2.connect(this.audioCtx.destination);
            osc2.start(now + 0.18);
            osc2.stop(now + 0.9);
        } catch (e) {
            console.warn('Audio chime playback restricted by browser policy:', e);
        }
    }
};

// Toast notification container and manager
const Toast = {
    container: null,
    
    getContainer() {
        if (!this.container) {
            this.container = document.createElement('div');
            this.container.id = 'toast-container';
            this.container.style.cssText = `
                position: fixed;
                bottom: 24px;
                right: 24px;
                z-index: 9999;
                display: flex;
                flex-direction: column;
                gap: 10px;
                max-width: 360px;
                pointer-events: none;
            `;
            document.body.appendChild(this.container);
        }
        return this.container;
    },
    
    show(message, type = 'info', duration = 6000) {
        const container = this.getContainer();
        const toast = document.createElement('div');
        toast.className = `alert alert-${type}`;
        toast.style.cssText = `
            pointer-events: auto;
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.15);
            margin: 0;
            animation: slideIn 0.3s ease-out;
            cursor: pointer;
        `;
        toast.innerHTML = `<div>${message}</div>`;
        
        toast.onclick = () => toast.remove();
        container.appendChild(toast);
        
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transition = 'opacity 0.4s ease';
            setTimeout(() => toast.remove(), 400);
        }, duration);
    }
};

// Auto-dismiss alerts on page
document.addEventListener('DOMContentLoaded', () => {
    // Enable audio context on any user click
    document.addEventListener('click', () => SoundFX.init(), { once: true });
    
    // Notifications polling for authenticated users
    if (window.DoctorConnectUser) {
        let lastNotifId = 0;
        const pollNotifications = () => {
            fetch(`${window.DoctorConnectBaseUrl}/api/notifications.php?since=${lastNotifId}`)
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.notifications && data.notifications.length > 0) {
                        data.notifications.forEach(n => {
                            Toast.show(`🔔 <strong>${n.type.replace('_', ' ').toUpperCase()}:</strong> ${n.message}`, 'info');
                            SoundFX.playChime();
                            if (n.id > lastNotifId) lastNotifId = n.id;
                        });
                    }
                })
                .catch(err => console.debug('Notif poll idle:', err));
        };
        
        // Poll every 30 seconds
        setInterval(pollNotifications, 30000);
        // Initial poll after 4 seconds
        setTimeout(pollNotifications, 4000);
    }
});
