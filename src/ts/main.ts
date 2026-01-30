/**
 * AI Chatbot - Main TypeScript entry point
 *
 * This file contains custom TypeScript code for the chatbot.
 * Datastar is loaded from CDN, so no import needed.
 */

// Pyodide for client-side Python execution
let pyodide: unknown = null;

declare const loadPyodide: () => Promise<{
    runPythonAsync: (code: string) => Promise<unknown>;
}>;

interface PythonResult {
    success: boolean;
    output?: unknown;
    error?: string;
}

/**
 * Initialize Pyodide runtime (lazy-loaded)
 */
async function initPyodide(): Promise<typeof pyodide> {
    if (!pyodide) {
        try {
            pyodide = await loadPyodide();
        } catch (error) {
            console.error('Failed to load Pyodide:', error);
            throw error;
        }
    }
    return pyodide;
}

/**
 * Execute Python code in the browser
 */
async function runPython(code: string): Promise<PythonResult> {
    try {
        const py = (await initPyodide()) as {
            runPythonAsync: (code: string) => Promise<unknown>;
        };
        const result = await py.runPythonAsync(code);
        return { success: true, output: result };
    } catch (error) {
        return {
            success: false,
            error: error instanceof Error ? error.message : String(error),
        };
    }
}

// Expose to window for Datastar actions
declare global {
    interface Window {
        runPythonCode: typeof runPython;
        initPyodide: typeof initPyodide;
    }
}

window.runPythonCode = runPython;
window.initPyodide = initPyodide;

/**
 * Auto-resize textarea to fit content
 */
function autoResizeTextarea(textarea: HTMLTextAreaElement): void {
    textarea.style.height = 'auto';
    textarea.style.height = `${textarea.scrollHeight}px`;
}

// Setup auto-resize for message input
document.addEventListener('DOMContentLoaded', () => {
    const messageInput = document.querySelector<HTMLTextAreaElement>(
        'textarea[data-signal="message"]'
    );
    if (messageInput) {
        messageInput.addEventListener('input', () =>
            autoResizeTextarea(messageInput)
        );
    }
});

/**
 * Scroll to bottom of messages container
 */
function scrollToBottom(selector: string): void {
    const container = document.querySelector(selector);
    if (container) {
        container.scrollTop = container.scrollHeight;
    }
}

// Expose scroll helper
(window as unknown as { scrollToBottom: typeof scrollToBottom }).scrollToBottom =
    scrollToBottom;

/**
 * Copy text to clipboard
 */
async function copyToClipboard(text: string): Promise<boolean> {
    try {
        await navigator.clipboard.writeText(text);
        return true;
    } catch {
        // Fallback for older browsers
        const textarea = document.createElement('textarea');
        textarea.value = text;
        document.body.appendChild(textarea);
        textarea.select();
        const success = document.execCommand('copy');
        document.body.removeChild(textarea);
        return success;
    }
}

// Expose copy helper
(
    window as unknown as { copyToClipboard: typeof copyToClipboard }
).copyToClipboard = copyToClipboard;

console.log('AI Chatbot initialized');
