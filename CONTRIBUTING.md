# Contributing to Laravel-WAMP

First of all, thank you for contributing! 🎉

## How to Contribute

### Reporting Bugs

Open an issue using the **Bug Report** template and include:

- Laravel-WAMP, PHP, and Laravel versions
- Precise steps to reproduce the problem
- Expected vs. current behavior
- Stack trace, if available

### Proposing New Features

Open an issue using the **Feature Request** template before
starting development, so we can discuss the approach together.

### Submitting a Pull Request

1. Fork the repository
2. Create a descriptive branch:

```bash
git checkout -b feature/feature-name
# or
git checkout -b fix/bug-description
```

3. Write code following the project's conventions
4. Add tests for new features
5. Ensure all tests pass:

```bash
./vendor/bin/pest
./vendor/bin/phpstan analyze
./vendor/bin/pint --test
```

6. Update `CHANGELOG.md`
7. Open the PR using the provided template

## Conventions

### Code

- PHP 8.2+ (files do not declare `strict_types`; don't add it to individual files)
- PSR-12 as the code standard (enforced by Pint)
- Typed properties, return types, and parameters always declared
- Prefer `readonly` where possible
- English for PHPDoc, comments, exception messages, and test descriptions

### Commit

We use [Conventional Commits](https://www.conventionalcommits.org/):

- feat: adds support for WAMP-CRA authentication
- fix: corrects session timeout handling
- docs: updates README with PubSub examples
- test: adds tests for CborSerializer
- chore: updates dependencies

### Test

- Every new feature must have unit tests
- Use Pest with the `describe/it` syntax
- Mocks must be made with Mockery
- Minimum coverage: 80%

## Local Setup

```bash
git clone https://github.com/sparrinellodev/laravel-wamp.git
cd laravel-wamp
composer install
./vendor/bin/pest
```

## Questions?

Open a [Discussion](https://github.com/sparrinellodev/laravel-wamp/discussions)
on GitHub for any questions or concerns.
