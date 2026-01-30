# AI Chatbot - User Guide

A real-time AI chatbot with artifact creation capabilities, powered by Anthropic Claude and OpenAI models.

## Features

### 💬 Chat with AI

Start a conversation by typing a message in the input box at the bottom of the screen. The AI will respond in real-time with streaming text.

**Keyboard shortcuts:**
- `Enter` - Send message
- `Shift+Enter` - New line
- `Ctrl+B` - Toggle sidebar
- `Ctrl+K` - New chat
- `Esc` - Close artifact panel

### 🤖 AI Models

Select your preferred AI model from the dropdown in the input toolbar:

| Model | Provider | Best For |
|-------|----------|----------|
| Claude Sonnet 4 | Anthropic | Balanced speed & quality |
| Claude Haiku | Anthropic | Fast responses |
| GPT-4o | OpenAI | Complex reasoning |
| GPT-4o Mini | OpenAI | Quick tasks |

### 📄 Artifacts

Artifacts are interactive documents the AI can create for you. They appear in a panel on the right side of the screen.

#### Types of Artifacts

| Type | Description | Example Prompts |
|------|-------------|-----------------|
| **Code** | Programming code with syntax highlighting | "Write a Python function to sort a list" |
| **Text** | Markdown documents | "Write a blog post about AI" |
| **Sheet** | CSV/spreadsheet data | "Create a table of world capitals" |
| **Image** | SVG graphics | "Draw a simple flowchart" |

#### Creating Artifacts

Simply ask the AI to create something:

> "Write a Python script that calculates fibonacci numbers"

> "Create a markdown guide for Git commands"

> "Make a CSV table of the planets in our solar system"

> "Draw an SVG diagram of a binary tree"

The AI will automatically create an artifact when appropriate.

#### Updating Artifacts

You can ask the AI to modify an existing artifact:

> "Add error handling to the code"

> "Add a new column for population to the table"

> "Change the title to 'Updated Guide'"

The AI will update the currently open artifact.

#### Artifact Actions

- **Download** (⬇️) - Download the artifact as a file
- **Close** (✕) - Close the artifact panel
- **Edit** - For sheets, click "Edit Data" to manually edit the CSV

#### Running Python Code

Python code artifacts can be executed directly in your browser:

1. Create a Python code artifact
2. Click the **Run** button
3. See output in the panel below the code

*Powered by [Pyodide](https://pyodide.org/) - Python runs entirely in your browser!*

### 👍 Voting

Help improve the AI by voting on responses:
- 👍 **Upvote** - Good, helpful response
- 👎 **Downvote** - Unhelpful or incorrect

### 📋 Copy Messages

Click the copy icon on any message to copy its content to your clipboard.

### 📱 Responsive Design

The app works on desktop and mobile devices. On smaller screens, the sidebar collapses automatically.

## Tips & Tricks

### Getting Better Artifacts

Be specific about what you want:

❌ "Write some code"  
✅ "Write a Python function that takes a list of numbers and returns the median value"

❌ "Make a table"  
✅ "Create a CSV table with columns: Name, Age, City - and add 5 example rows"

### Iterating on Artifacts

You can have a conversation about an artifact:

1. "Create a Python function to validate email addresses"
2. "Add support for international domains"
3. "Add unit tests for the function"
4. "Add docstrings and type hints"

Each request will update the existing artifact.

### Markdown in Messages

Your messages support Markdown formatting:
- **Bold** with `**text**`
- *Italic* with `*text*`
- `Code` with backticks
- Lists with `-` or `1.`

Use the 👁️ preview button to see how your message will look.

## Account Types

### Guest Users
- Limited to 10 messages per day
- Chats are saved locally
- Can upgrade to registered account

### Registered Users
- 100 messages per day
- Chats sync across devices
- Full history access

## Keyboard Shortcuts Reference

| Shortcut | Action |
|----------|--------|
| `Enter` | Send message |
| `Shift+Enter` | New line in message |
| `Ctrl+B` | Toggle sidebar |
| `Ctrl+K` | New chat |
| `Esc` | Close artifact/modal |

## Troubleshooting

### AI not responding?
- Check your internet connection
- The AI service may be temporarily overloaded
- Try refreshing the page

### Artifact not updating?
- Make sure the artifact panel is open
- Be specific about which artifact to update
- Try "Update the current artifact to..."

### Python code not running?
- Pyodide needs to download on first use (~5MB)
- Some Python packages may not be available
- Check the browser console for errors

---

*Built with ❤️ using PHP, Swoole, and Datastar*
