/**
 * AI Chatbot - App JavaScript
 */

// Auto-resize textarea
document.addEventListener('input', (e) => {
    if (e.target.matches('.message-input')) {
        e.target.style.height = 'auto';
        e.target.style.height = Math.min(e.target.scrollHeight, 200) + 'px';
    }
});

// Scroll to bottom of messages
function scrollToBottom() {
    const container = document.getElementById('messages-container');
    if (container) {
        container.scrollTop = container.scrollHeight;
    }
}

// Watch for new messages
const observer = new MutationObserver((mutations) => {
    for (const mutation of mutations) {
        if (mutation.type === 'childList' && mutation.addedNodes.length > 0) {
            scrollToBottom();
        }
    }
});

const messagesEl = document.getElementById('messages');
if (messagesEl) {
    observer.observe(messagesEl, { childList: true, subtree: true });
}

// Update connection status
document.addEventListener('datastar-sse-connected', () => {
    const status = document.getElementById('connection-status');
    if (status) {
        status.setAttribute('data-connected', 'true');
        status.querySelector('span:last-child').textContent = 'Connected';
    }
});

document.addEventListener('datastar-sse-error', () => {
    const status = document.getElementById('connection-status');
    if (status) {
        status.setAttribute('data-connected', 'false');
        status.querySelector('span:last-child').textContent = 'Reconnecting...';
    }
});

// Pyodide integration (lazy loaded)
let pyodide = null;

window.initPyodide = async function() {
    if (pyodide) return pyodide;
    
    if (typeof loadPyodide === 'undefined') {
        // Load Pyodide script
        const script = document.createElement('script');
        script.src = 'https://cdn.jsdelivr.net/pyodide/v0.26.4/full/pyodide.js';
        document.head.appendChild(script);
        await new Promise((resolve) => script.onload = resolve);
    }
    
    pyodide = await loadPyodide();
    return pyodide;
};

window.runPythonCode = async function(code) {
    try {
        const py = await window.initPyodide();
        
        // Capture stdout
        py.runPython(`
            import sys
            from io import StringIO
            sys.stdout = StringIO()
        `);
        
        const result = await py.runPythonAsync(code);
        const stdout = py.runPython('sys.stdout.getvalue()');
        
        return {
            success: true,
            output: stdout || (result !== undefined ? String(result) : ''),
            result: result
        };
    } catch (error) {
        return {
            success: false,
            error: error.message
        };
    }
};

console.log('AI Chatbot initialized');
