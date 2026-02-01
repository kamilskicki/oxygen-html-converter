(function() {
    'use strict';

    const config = window.oxyHtmlConverter || {};

    /**
     * Show toast notification in Oxygen UI
     */
    function showToast(message, duration = 3000) {
        const parentDoc = window.parent.document;
        let toast = parentDoc.getElementById('oxy-html-converter-toast');

        if (!toast) {
            toast = parentDoc.createElement('div');
            toast.id = 'oxy-html-converter-toast';
            toast.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                z-index: 999999;
                padding: 12px 16px;
                background-color: #323232;
                color: white;
                border-radius: 6px;
                font-size: 14px;
                box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
                opacity: 0;
                transform: translateY(-10px);
                transition: opacity 0.3s ease, transform 0.3s ease;
                pointer-events: none !important;
            `;
            parentDoc.body.appendChild(toast);
        }

        toast.textContent = message;
        toast.style.opacity = '1';
        toast.style.transform = 'translateY(0)';

        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(-10px)';
        }, duration);
    }

    /**
     * Get Oxygen Vue store from parent
     */
    function getOxygenStore() {
        const el = window.parent.document.querySelector('.v-application');
        return el?.__vue__?.$store ||
               el?.__vue_app__?.config?.globalProperties?.$store ||
               null;
    }

    /**
     * Check if text looks like HTML
     */
    function isHtmlContent(text) {
        text = text.trim();
        // Check for HTML tags
        return /<[a-z][\s\S]*>/i.test(text) &&
               // Not already Oxygen JSON
               !text.startsWith('{') &&
               !text.startsWith('[');
    }

    /**
     * Convert HTML via AJAX
     */
    async function convertHtml(html) {
        const formData = new FormData();
        formData.append('action', 'oxy_html_convert');
        formData.append('nonce', config.nonce);
        formData.append('html', html);
        formData.append('wrapInContainer', 'true');

        const response = await fetch(config.ajaxUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });

        const data = await response.json();

        if (!data.success) {
            throw new Error(data.data?.message || 'Conversion failed');
        }

        return data.data;
    }

    /**
     * Handle paste event with HTML detection
     */
    async function handlePaste(event) {
        // Skip if already processing
        if (window.__oxyHtmlConverterProcessing) {
            return;
        }

        const clipboardData = event.clipboardData || window.clipboardData;
        const text = clipboardData.getData('text');

        // Skip if not HTML or is already Oxygen JSON
        if (!text || !isHtmlContent(text)) {
            return;
        }

        // Check if we're in a context where HTML paste makes sense
        // (not in a text input or code editor)
        const activeElement = document.activeElement;
        if (activeElement) {
            const tagName = activeElement.tagName.toLowerCase();
            if (['input', 'textarea'].includes(tagName)) {
                return;
            }
            if (activeElement.getAttribute('contenteditable') === 'true') {
                return;
            }
        }

        // Prevent default paste
        event.preventDefault();
        event.stopImmediatePropagation();

        window.__oxyHtmlConverterProcessing = true;

        try {
            showToast('Converting HTML...');

            const result = await convertHtml(text);

            if (result.json) {
                // Trigger paste with converted JSON
                const dt = new DataTransfer();
                dt.setData('text', result.json);

                const pasteEvent = new ClipboardEvent('paste', {
                    clipboardData: dt,
                    bubbles: true,
                    cancelable: true
                });

                // Small delay to ensure our flag is checked
                setTimeout(() => {
                    document.dispatchEvent(pasteEvent);
                    showToast('✅ HTML converted and pasted!');
                    window.__oxyHtmlConverterProcessing = false;
                }, 50);
            }
        } catch (error) {
            console.error('HTML conversion error:', error);
            showToast('❌ Conversion failed: ' + error.message, 5000);
            window.__oxyHtmlConverterProcessing = false;
        }
    }

    /**
     * Create HTML import modal
     */
    function createImportModal() {
        const parentDoc = window.parent.document;

        // Check if modal already exists
        if (parentDoc.getElementById('oxy-html-import-modal')) {
            return;
        }

        const modal = parentDoc.createElement('div');
        modal.id = 'oxy-html-import-modal';
        modal.innerHTML = `
            <div class="oxy-html-modal-overlay" style="
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0,0,0,0.5);
                z-index: 999998;
                display: none;
            ">
                <div class="oxy-html-modal-content" style="
                    position: absolute;
                    top: 50%;
                    left: 50%;
                    transform: translate(-50%, -50%);
                    background: #fff;
                    border-radius: 8px;
                    padding: 20px;
                    width: 600px;
                    max-width: 90vw;
                    max-height: 80vh;
                    overflow: auto;
                    box-shadow: 0 10px 40px rgba(0,0,0,0.3);
                ">
                    <h2 style="margin-top: 0; margin-bottom: 15px; font-size: 18px;">Import HTML</h2>
                    <textarea id="oxy-html-import-input" placeholder="Paste your HTML here..." style="
                        width: 100%;
                        height: 300px;
                        font-family: monospace;
                        font-size: 13px;
                        padding: 10px;
                        border: 1px solid #ddd;
                        border-radius: 4px;
                        resize: vertical;
                        box-sizing: border-box;
                    "></textarea>
                    <div style="margin-top: 15px; display: flex; gap: 10px; justify-content: flex-end;">
                        <button id="oxy-html-import-cancel" style="
                            padding: 8px 16px;
                            border: 1px solid #ddd;
                            background: #fff;
                            border-radius: 4px;
                            cursor: pointer;
                        ">Cancel</button>
                        <button id="oxy-html-import-submit" style="
                            padding: 8px 16px;
                            border: none;
                            background: #0073aa;
                            color: #fff;
                            border-radius: 4px;
                            cursor: pointer;
                        ">Import</button>
                    </div>
                </div>
            </div>
        `;

        parentDoc.body.appendChild(modal);

        const overlay = modal.querySelector('.oxy-html-modal-overlay');
        const cancelBtn = modal.querySelector('#oxy-html-import-cancel');
        const submitBtn = modal.querySelector('#oxy-html-import-submit');
        const input = modal.querySelector('#oxy-html-import-input');

        // Close modal
        function closeModal() {
            overlay.style.display = 'none';
            input.value = '';
        }

        // Open modal
        window.oxyHtmlConverterOpenModal = function() {
            overlay.style.display = 'block';
            input.focus();
        };

        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) closeModal();
        });

        cancelBtn.addEventListener('click', closeModal);

        submitBtn.addEventListener('click', async () => {
            const html = input.value.trim();
            if (!html) {
                showToast('Please enter some HTML');
                return;
            }

            submitBtn.disabled = true;
            submitBtn.textContent = 'Converting...';

            try {
                const result = await convertHtml(html);

                if (result.json) {
                    closeModal();

                    // Copy to clipboard and trigger paste
                    await navigator.clipboard.writeText(result.json);
                    showToast('✅ Converted! Press Ctrl+V to paste');
                }
            } catch (error) {
                showToast('❌ Error: ' + error.message, 5000);
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Import';
            }
        });

        // Keyboard shortcut: Escape to close
        parentDoc.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && overlay.style.display === 'block') {
                closeModal();
            }
        });
    }

    /**
     * Add keyboard shortcut for import modal
     */
    function setupKeyboardShortcuts() {
        // Ctrl+Shift+H to open HTML import modal
        window.parent.document.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key.toLowerCase() === 'h') {
                e.preventDefault();
                if (window.oxyHtmlConverterOpenModal) {
                    window.oxyHtmlConverterOpenModal();
                }
            }
        });
    }

    /**
     * Initialize
     */
    function init() {
        // Wait for Oxygen to be ready
        const checkReady = setInterval(() => {
            const store = getOxygenStore();
            if (store) {
                clearInterval(checkReady);

                // Set up paste handler
                document.addEventListener('paste', handlePaste, true);

                // Create import modal
                createImportModal();

                // Setup keyboard shortcuts
                setupKeyboardShortcuts();

                console.log('[Oxygen HTML Converter] Initialized. Use Ctrl+Shift+H to open import modal.');
            }
        }, 500);

        // Timeout after 30 seconds
        setTimeout(() => clearInterval(checkReady), 30000);
    }

    // Start when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
