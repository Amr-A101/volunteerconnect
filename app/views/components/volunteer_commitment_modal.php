<?php
/**
 * Improved Volunteer Commitment Agreement Modal
 */
?>

<style>
/* ================================
   Improved Commitment Modal Styles
================================ */
:root {
    --vc-primary: #0a66c2;
    --vc-primary-hover: #004182;
    --vc-bg-light: #f9fafb;
    --vc-border: #e5e7eb;
    --vc-text-main: #1f2937;
    --vc-text-muted: #4b5563;
}

.vc-commitment-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(17, 24, 39, 0.7);
    backdrop-filter: blur(4px);
    z-index: 2000;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.vc-commitment-modal {
    background: #fff;
    width: 100%;
    max-width: 600px;
    max-height: 85vh;
    border-radius: 16px;
    display: flex;
    flex-direction: column;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
    overflow: hidden;
    animation: vcSlideUp 0.3s ease-out;
}

@keyframes vcSlideUp {
    from { transform: translateY(20px); opacity: 0; }
    to   { transform: translateY(0); opacity: 1; }
}

/* Header */
.vc-commitment-header {
    padding: 20px 24px;
    background: var(--vc-bg-light);
    border-bottom: 1px solid var(--vc-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.vc-commitment-header h3 {
    margin: 0;
    font-size: 1.25rem;
    color: var(--vc-text-main);
    font-weight: 700;
}

.vc-commitment-close {
    background: #eee;
    border: none;
    font-size: 24px;
    line-height: 1;
    cursor: pointer;
    color: var(--vc-text-muted);
    width: 32px;
    height: 32px;
    border-radius: 50%;
    transition: background 0.2s;
}

.vc-commitment-close:hover {
    background: #e5e7eb;
}

/* Body Content */
.vc-commitment-body {
    padding: 24px;
    overflow-y: auto;
    font-size: 0.95rem;
    line-height: 1.6;
    color: var(--vc-text-muted);
}

.vc-intro-text {
    margin-bottom: 24px;
    padding: 12px 16px;
    background: #eff6ff;
    border-left: 4px solid var(--vc-primary);
    border-radius: 4px;
    color: #1e40af;
}

.vc-section-modal {
    margin-bottom: 6px;
    padding: 12px;
}

.vc-section-modal-title {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 700;
    color: var(--vc-text-main);
    margin-bottom: 8px;
    text-transform: uppercase;
    font-size: 0.85rem;
    letter-spacing: 0.05em;
}

.vc-section-modal-title span {
    font-size: 1.2rem;
}

.vc-section-modal ul {
    margin: 0;
    padding-left: 1.5rem;
    color: #4b5563;
}

.vc-section-modal li {
    margin-bottom: 4px;
}

/* Footer & Controls */
.vc-commitment-footer {
    padding: 20px 24px;
    background: var(--vc-bg-light);
    border-top: 1px solid var(--vc-border);
}

.vc-checkbox-container {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 12px;
    background: #fff;
    border: 1px solid var(--vc-border);
    border-radius: 8px;
    cursor: pointer;
    margin-bottom: 16px;
    transition: border-color 0.2s;
}

.vc-checkbox-container:hover {
    border-color: var(--vc-primary);
}

.vc-checkbox-container input {
    margin-top: 4px;
    transform: scale(1.2);
}

.vc-checkbox-text {
    font-size: 0.9rem;
    font-weight: 500;
    color: var(--vc-text-main);
}

.vc-actions-modal {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
}

.vc-btn-modal {
    padding: 10px 20px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 0.9rem;
    cursor: pointer;
    transition: all 0.2s;
}

.vc-btn-modal-secondary {
    background: transparent;
    border: 1px solid var(--vc-border);
    color: var(--vc-text-muted);
}

.vc-btn-modal-secondary:hover {
    background: #f3f4f6;
}

.vc-btn-modal-primary {
    background: var(--vc-primary);
    color: #fff;
    border: 1px solid var(--vc-primary);
}

.vc-btn-modal-primary:hover:not(:disabled) {
    background: var(--vc-primary-hover);
}

.vc-btn-modal-primary:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    filter: grayscale(1);
}
</style>

<div class="vc-commitment-overlay" id="commitmentOverlay">
    <div class="vc-commitment-modal">
        <div class="vc-commitment-header">
            <h3>Volunteer Commitment Agreement</h3>
            <button class="vc-commitment-close" onclick="closeCommitmentModal()">&times;</button>
        </div>

        <div class="vc-commitment-body">
            <div class="vc-intro-text">
                Please review our terms of commitment. Your agreement ensures a safe and productive environment for everyone.
            </div>

            <div class="vc-section-modal">
                <div class="vc-section-modal-title"><i class="fa-solid fa-calendar-days"></i> Attendance & Responsibility</div>
                <ul>
                    <li>I confirm that I will attend and actively participate for the full duration of the volunteering opportunity.</li>
                    <li>I understand that failure to attend without valid reason may affect my eligibility for future opportunities or recognition.</li>
                </ul>
            </div>

            <div class="vc-section-modal">
                <div class="vc-section-modal-title"><i class="fa-solid fa-handshake"></i> Conduct & Ethics</div>
                <ul>
                    <li>I agree to act responsibly, respectfully, and in accordance with the organizer’s guidelines.</li>
                    <li>I will not engage in misconduct, harassment, or unlawful activities.</li>
                </ul>
            </div>

            <div class="vc-section-modal">
                <div class="vc-section-modal-title"><i class="fa-solid fa-heart-pulse"></i> Health, Safety & Risk Awareness</div>
                <ul>
                    <li>I acknowledge that volunteering activities may involve physical, environmental, or social risks.</li>
                    <li>I confirm that I am physically and mentally fit to participate.</li>
                </ul>
            </div>

            <div class="vc-section-modal">
                <div class="vc-section-modal-title"><i class="fa-solid fa-gavel"></i> Liability Disclaimer</div>
                <ul>
                    <li>I understand that the organization and platform are not responsible for:</li>
                    <ul style="list-style-type: circle; margin-top: 4px;">
                        <li>Personal injury or illness</li>
                        <li>Loss or theft of personal belongings</li>
                        <li>Incidents caused by circumstances beyond reasonable control</li>
                    </ul>
                </ul>
            </div>

            <div class="vc-section-modal">
                <div class="vc-section-modal-title"><i class="fa-solid fa-scroll"></i> Certification & Recognition</div>
                <ul>
                    <li>I acknowledge that certificates or recognition are awarded only upon verified participation.</li>
                </ul>
            </div>

            <div class="vc-section-modal">
                <div class="vc-section-modal-title"><i class="fa-solid fa-circle-check"></i> Consent</div>
                <ul>
                    <li>I confirm that the information I provide is accurate.</li>
                    <li>I voluntarily agree to the terms stated above.</li>
                </ul>
            </div>
        </div>


        <div class="vc-commitment-footer">
            <label class="vc-checkbox-container" for="commitmentAgreeCheckbox">
                <input type="checkbox" id="commitmentAgreeCheckbox">
                <span class="vc-checkbox-text">
                    I have read, understood, and voluntarily agree to the terms of this Volunteer Commitment Agreement.
                </span>         
            </label>

            <div class="vc-actions-modal">
                <button class="vc-btn-modal vc-btn-modal-secondary" onclick="closeCommitmentModal()">
                    Go Back
                </button>
                <button class="vc-btn-modal vc-btn-modal-primary" id="commitmentConfirmBtn" disabled>
                    Agree & Continue
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let commitmentCallback = null;

function openCommitmentModal(onAgree) {
    commitmentCallback = onAgree;
    document.getElementById('commitmentAgreeCheckbox').checked = false;
    document.getElementById('commitmentConfirmBtn').disabled = true;
    document.getElementById('commitmentOverlay').style.display = 'flex';
}

function closeCommitmentModal() {
    document.getElementById('commitmentOverlay').style.display = 'none';
    commitmentCallback = null;
}

document.getElementById('commitmentAgreeCheckbox').addEventListener('change', function () {
    document.getElementById('commitmentConfirmBtn').disabled = !this.checked;
});

document.getElementById('commitmentConfirmBtn').addEventListener('click', function () {
    if (typeof commitmentCallback === 'function') {
        const cb = commitmentCallback;
        closeCommitmentModal();
        cb();
    }
});
</script>