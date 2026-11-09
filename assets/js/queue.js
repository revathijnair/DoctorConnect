/**
 * DOCTOR CONNECT - Live Queue Polling Engine
 * Meets Non-Functional Requirement: Queue board updates within <= 3 seconds
 */

class LiveQueueManager {
    constructor(options) {
        this.doctorId = options.doctorId;
        this.date = options.date;
        this.appointmentId = options.appointmentId || null;
        this.isDoctor = options.isDoctor || false;
        this.isAdmin = options.isAdmin || false;
        this.pollIntervalMs = options.pollIntervalMs || 3000;
        
        this.lastServingToken = null;
        this.userStatus = null;
        this.intervalHandle = null;
        
        this.elements = {
            currentServingEl: document.getElementById('queue-current-serving'),
            nextInLineEl: document.getElementById('queue-next-in-line'),
            waitingCountEl: document.getElementById('queue-waiting-count'),
            userStatusEl: document.getElementById('queue-user-status'),
            userPositionEl: document.getElementById('queue-user-position'),
            meetingAreaEl: document.getElementById('queue-meeting-area'),
            meetingBtnEl: document.getElementById('queue-meeting-btn'),
            queueTableBody: document.getElementById('queue-table-body'),
            lastUpdatedEl: document.getElementById('queue-last-updated')
        };
        
        this.init();
    }
    
    init() {
        this.fetchStatus();
        this.intervalHandle = setInterval(() => this.fetchStatus(), this.pollIntervalMs);
    }
    
    stop() {
        if (this.intervalHandle) {
            clearInterval(this.intervalHandle);
        }
    }
    
    async fetchStatus() {
        try {
            const url = `${window.DoctorConnectBaseUrl}/api/queue_status.php?doctor_id=${this.doctorId}&date=${this.date}` 
                + (this.appointmentId ? `&appointment_id=${this.appointmentId}` : '');
                
            const res = await fetch(url);
            if (!res.ok) return;
            const data = await res.json();
            
            if (data.success) {
                this.updateUI(data);
            }
        } catch (e) {
            console.debug('Queue poll error (network or idle):', e);
        }
    }
    
    updateUI(data) {
        const queue = data.queue;
        
        // 1. Update Serving Token
        if (this.elements.currentServingEl) {
            this.elements.currentServingEl.textContent = queue.serving_token ? `#${queue.serving_token}` : 'None';
        }
        
        // 2. Update Next Token
        if (this.elements.nextInLineEl) {
            this.elements.nextInLineEl.textContent = queue.next_token ? `#${queue.next_token}` : 'None';
        }
        
        // 3. Update Waiting Count
        if (this.elements.waitingCountEl) {
            this.elements.waitingCountEl.textContent = queue.waiting_count;
        }
        
        // 4. Check for chime on serving change
        if (this.lastServingToken !== null && queue.serving_token !== this.lastServingToken) {
            if (queue.serving_token) {
                SoundFX.playChime();
                Toast.show(`🔔 Doctor is now calling Token #${queue.serving_token}!`, 'info');
            }
        }
        this.lastServingToken = queue.serving_token;
        
        // 5. Patient specific updates (if tracking appointmentId)
        if (this.appointmentId && data.user_appointment) {
            const app = data.user_appointment;
            
            // Check status change
            if (this.userStatus && this.userStatus !== app.status) {
                if (app.status === 'serving') {
                    SoundFX.playChime();
                    Toast.show(`🎉 <strong>It's your turn!</strong> Doctor is calling your token #${app.token_number}!`, 'success', 10000);
                } else if (app.status === 'completed') {
                    Toast.show(`✓ Consultation completed.`, 'info');
                }
            }
            this.userStatus = app.status;
            
            if (this.elements.userStatusEl) {
                this.elements.userStatusEl.innerHTML = app.status_badge_html;
            }
            
            if (this.elements.userPositionEl) {
                if (app.status === 'serving') {
                    this.elements.userPositionEl.innerHTML = `<span style="color:#059669; font-weight:700;">🟢 YOUR TURN NOW - IN CONSULTATION</span>`;
                } else if (app.is_next) {
                    this.elements.userPositionEl.innerHTML = `<span style="color:#d97706; font-weight:700;">⚡ YOU ARE NEXT IN LINE (Prepare to join)</span>`;
                } else if (app.status === 'waiting') {
                    this.elements.userPositionEl.textContent = `${app.tokens_ahead} patient(s) ahead of you`;
                } else {
                    this.elements.userPositionEl.textContent = `Status: ${app.status.toUpperCase()}`;
                }
            }
            
            // Video Meeting Link Visibility Requirement 3.4
            // "Link visible to patient only when token is NEXT or SERVING"
            if (this.elements.meetingAreaEl) {
                if (app.can_access_meeting && app.meeting_link) {
                    this.elements.meetingAreaEl.style.display = 'block';
                    if (this.elements.meetingBtnEl) {
                        this.elements.meetingBtnEl.href = app.meeting_link;
                        this.elements.meetingBtnEl.innerHTML = app.status === 'serving' 
                            ? '🎥 Join Live Consultation Now &rarr;' 
                            : '🎥 Consultation Room Ready (Join)';
                    }
                } else {
                    this.elements.meetingAreaEl.style.display = 'none';
                }
            }
        }
        
        // 6. Update Doctor/Reception queue table if present
        if (this.elements.queueTableBody && data.all_appointments) {
            this.renderQueueTable(data.all_appointments);
        }
        
        if (this.elements.lastUpdatedEl) {
            const d = new Date();
            this.elements.lastUpdatedEl.textContent = `Live as of ${d.toLocaleTimeString()}`;
        }
    }
    
    renderQueueTable(appointments) {
        if (!appointments || appointments.length === 0) {
            this.elements.queueTableBody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:24px; color:#94a3b8;">No appointments booked for this date yet.</td></tr>`;
            return;
        }
        
        let html = '';
        appointments.forEach(app => {
            const isServing = app.status === 'serving';
            const rowClass = isServing ? 'highlight-row' : '';
            html += `
                <tr class="${rowClass}">
                    <td><strong>#${app.token_number}</strong></td>
                    <td>
                        <div style="font-weight:600;">${this.escape(app.patient_name)}</div>
                        <div style="font-size:12px; color:#64748b;">${this.escape(app.patient_phone || '')}</div>
                    </td>
                    <td>${app.estimated_time_formatted || '--'}</td>
                    <td><span class="status-badge badge-${app.status}">${app.status.toUpperCase()}</span></td>
                    <td>${this.escape(app.reason_for_visit || 'General')}</td>
                    <td>
                        ${this.isDoctor || this.isAdmin ? this.renderActionButtons(app) : ''}
                    </td>
                </tr>
            `;
        });
        this.elements.queueTableBody.innerHTML = html;
    }
    
    renderActionButtons(app) {
        if (app.status === 'waiting') {
            return `
                <button class="btn btn-sm btn-primary" onclick="advanceQueue(${app.id}, 'serving')">Call Next</button>
                <button class="btn btn-sm btn-secondary" onclick="advanceQueue(${app.id}, 'no_show')">No-Show</button>
            `;
        } else if (app.status === 'serving') {
            return `
                <a href="${window.DoctorConnectBaseUrl}/doctor/consult.php?appointment_id=${app.id}" class="btn btn-sm btn-success">Open Room</a>
                <button class="btn btn-sm btn-secondary" onclick="advanceQueue(${app.id}, 'completed')">Mark Served</button>
            `;
        } else if (app.status === 'completed') {
            return `<a href="${window.DoctorConnectBaseUrl}/prescription.php?appointment_id=${app.id}" target="_blank" class="btn btn-sm btn-secondary">Prescription</a>`;
        }
        return `<span style="color:#94a3b8; font-size:12px;">--</span>`;
    }
    
    escape(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
}
