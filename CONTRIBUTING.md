# Contributing to Flarum Migration Tool

First off, thank you for considering contributing to Flarum Migration Tool! 🎉

## Code of Conduct

This project and everyone participating in it is governed by our Code of Conduct. By participating, you are expected to uphold this code.

## How Can I Contribute?

### Reporting Bugs

Before creating bug reports, please check the existing issues to avoid duplicates. When you create a bug report, include as many details as possible:

- **Use a clear and descriptive title**
- **Describe the exact steps to reproduce the problem**
- **Provide specific examples**
- **Describe the behavior you observed and what you expected**
- **Include screenshots if applicable**
- **Include your environment details:**
  - Flarum version
  - PHP version
  - MySQL version
  - OS and web server
  - Extension version

### Suggesting Enhancements

Enhancement suggestions are tracked as GitHub issues. When creating an enhancement suggestion:

- **Use a clear and descriptive title**
- **Provide a detailed description of the suggested enhancement**
- **Explain why this enhancement would be useful**
- **List any alternatives you've considered**

### Pull Requests

1. Fork the repo and create your branch from `main`
2. If you've added code that should be tested, add tests
3. Ensure your code follows the existing code style
4. Make sure your code lints (run `composer format`)
5. Update the documentation if needed
6. Write a clear commit message

#### Development Setup

```bash
# Clone your fork
git clone https://github.com/t11n-dev/Flarum-Migration-Tool
cd flarum-migration-tool

# Install dependencies
composer install
npm install

# Build assets
npm run dev
```

#### Code Style

**PHP:**
- Follow PSR-12 coding standard
- Use type hints where possible
- Write clear, descriptive variable names

**JavaScript:**
- Use ES6+ syntax
- Follow existing code patterns
- Use meaningful variable names

#### Commit Messages

- Use the present tense ("Add feature" not "Added feature")
- Use the imperative mood ("Move cursor to..." not "Moves cursor to...")
- Limit the first line to 72 characters or less
- Reference issues and pull requests liberally after the first line

Examples:
```
Add backup compression option
Fix migration error with large databases
Update README with new installation steps
```

### Translation

We welcome translations! To add a new language:

1. Copy `locale/en.yml` to `locale/[language_code].yml`
2. Translate all strings
3. Submit a pull request

Currently supported languages:
- English (en)
- Vietnamese (vi)

## Project Structure

```
src/
├── Console/          # CLI commands
├── Api/Controller/   # API endpoints
└── Service/         # Business logic

js/src/admin/        # Frontend admin UI
resources/less/      # Styles
locale/             # Translations
```

## Testing

```bash
# Run PHP tests (when available)
composer test

# Run linting
composer lint
composer format

# Build and test frontend
npm run build
```

## Release Process

1. Update version in `composer.json` and `package.json`
2. Update `CHANGELOG.md`
3. Create a git tag
4. Push to GitHub
5. GitHub Actions will automatically publish to Packagist

## Questions?

Feel free to:
- Open an issue for discussion
- Ask on [Flarum Community](https://discuss.flarum.org)
- Contact the maintainers

## License

By contributing, you agree that your contributions will be licensed under the MIT License.

---

Thank you for contributing! 🙏
