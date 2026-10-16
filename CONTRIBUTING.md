# Contributing to EverShelf

Thank you for your interest in contributing! This guide will help you get started.

## 🙌 Ways to help (you do not have to write code)

EverShelf is a one-person project with real users; the bottleneck is hands, not ideas.
Any of these is a genuine contribution, and none of them requires the full setup:

| Help with | What it takes |
|---|---|
| **Translating** | one JSON file, no code, no install — see [#93](https://github.com/dadaloop82/EverShelf/issues/93) |
| **Bug reports** | a clear reproduction (version, steps, expected vs actual) — the issue form asks for exactly that |
| **Testing a PR** | check out a branch, click through the feature, say what you saw — that is the review nobody else can give |
| **Docs & FAQ** | fix a confusing sentence, add a screenshot, record the walkthrough video ([#69](https://github.com/dadaloop82/EverShelf/issues/69)) |
| **Design / UX feedback** | propose a flow, argue about a button, mock a screen — open a Discussion |
| **Code** | pick a task from the [roadmap](../docs/ROADMAP.md); `good first issue` ones are meant for a first PR |
| **Spread the word** | a star, a post, or a link to someone with a pantry to sort out |

### Where to start

1. Read the [roadmap](../docs/ROADMAP.md) — it lists what is next, the rough effort,
   and which issues are explicitly up for grabs.
2. Issues labelled **`good first issue`** are small and self-contained; **`help wanted`**
   means we want it and have no bandwidth.
3. **Say “I'd like to work on this” in the issue first.** It takes a second and stops
   two people from writing the same code.
4. Stuck? Ask *in the issue*. Questions are not noise, they are how the design improves.

## 🚀 Getting Started

1. **Fork** the repository
2. **Clone** your fork:
   ```bash
   git clone https://github.com/YOUR_USERNAME/EverShelf.git
   cd EverShelf
   ```
3. **Create a branch** from `develop`:
   ```bash
   git checkout develop
   git checkout -b feature/your-feature-name
   ```
4. **Set up** your development environment:
   ```bash
   cp .env.example .env
   # Edit .env with your API keys
   php -S localhost:8080
   ```

## 📐 Project Structure

```
├── index.html              # Single-page app (all HTML)
├── api/
│   ├── index.php           # API router + all endpoint functions
│   ├── database.php        # SQLite schema + migrations
│   └── cron_smart_shopping.php
├── assets/
│   ├── js/app.js           # All application JavaScript
│   └── css/style.css       # All styles
├── translations/           # i18n translation files
│   ├── it.json             # Italian (base language)
│   ├── en.json             # English
│   └── ...
└── data/                   # Runtime data (gitignored)
```

## 🌍 Contributing Translations

Translations are one of the easiest ways to contribute! Each language is a single JSON file in the `translations/` directory.

### Adding a new language

1. Copy `translations/it.json` (the base language)
2. Rename it to your language code (e.g., `fr.json`, `de.json`, `es.json`)
3. Translate all the values (keep the keys unchanged)
4. Submit a Pull Request

### Translation file format

```json
{
  "app.title": "EverShelf",
  "nav.dashboard": "Dashboard",
  "nav.inventory": "Inventario",
  ...
}
```

**Rules:**
- Keys are in English, dot-separated (`section.key`)
- Values are the translated strings
- Keep `{0}`, `{1}` placeholders — they are filled dynamically
- Don't translate brand names (Bring!, Gemini, etc.)
- The CI pipeline will check your file for missing keys

### Language codes

Use [ISO 639-1](https://en.wikipedia.org/wiki/List_of_ISO_639-1_codes) two-letter codes:
`it`, `en`, `de`, `fr`, `es`, `pt`, `nl`, `pl`, `ru`, `ja`, `zh`, `ko`, etc.

## 🔧 Development Guidelines

### Code Style
- **PHP**: PSR-12 compatible, use type hints where practical
- **JavaScript**: No build tools, vanilla ES6+, single-file architecture
- **CSS**: Mobile-first, use CSS custom properties from `:root`
- **Comments**: English only, concise

### Commits
- Use descriptive commit messages
- Reference issue numbers when applicable: `Fix #42: barcode scanner timeout`
- Keep commits focused on a single change

### Branching
- `main` — stable releases only
- `develop` — active development (PRs target here)
- `feature/*` — new features
- `fix/*` — bug fixes
- `i18n/*` — translation contributions

## 🧪 Testing

Before submitting a PR:

```bash
# Check PHP syntax
php -l api/index.php
php -l api/database.php

# Check JS syntax
node -c assets/js/app.js

# Validate translation files
python3 -c "import json; json.load(open('translations/it.json'))"

# Test Docker build
docker build -t evershelf-test .
```

## 📝 Pull Request Process

1. Ensure your code passes all CI checks
2. Update `CHANGELOG.md` if applicable
3. Target the `develop` branch
4. Provide a clear description of your changes
5. Link any related issues

## 🐛 Reporting Bugs

Open an issue with:
- Steps to reproduce
- Expected vs. actual behavior
- Browser/device information
- Screenshots if applicable

## 💡 Feature Requests

Open an issue with the `enhancement` label. Describe:
- The problem you're trying to solve
- Your proposed solution
- Any alternatives you've considered

## 📄 License

By contributing, you agree that your contributions will be licensed under the [MIT License](LICENSE).
