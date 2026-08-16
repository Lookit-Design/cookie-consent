# Lookit Cookie Consent

[![License](https://img.shields.io/badge/license-GPLv2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Lint](https://github.com/Lookit-Design/cookie-consent/actions/workflows/lint.yml/badge.svg)](../../actions/workflows/lint.yml)
[![Coding Standards](https://github.com/Lookit-Design/cookie-consent/actions/workflows/coding-standards.yml/badge.svg)](../../actions/workflows/coding-standards.yml)
[![Plugin Check](https://github.com/Lookit-Design/cookie-consent/actions/workflows/plugin-check.yml/badge.svg)](../../actions/workflows/plugin-check.yml)
[![Tests](https://github.com/Lookit-Design/cookie-consent/actions/workflows/test.yml/badge.svg)](../../actions/workflows/test.yml)

A customizable cookie consent popup that records each visitor's choice to the iubenda Consent Database, without iubenda's own front-end banner.

Supports `WordPress >= 5.9` on `PHP >= 7.4`.

## Table of Contents

- [Getting Started](#getting-started)
  - [Installation](#installation)
  - [Configuration](#configuration)
- [Features](#features)
- [Security and Privacy](#security-and-privacy)
- [Development](#development)
  - [Setup](#setup)
  - [Running the Test Suite](#running-the-test-suite)
  - [Coding Standards](#coding-standards)
  - [Continuous Integration](#continuous-integration)
- [Contributing](#contributing)
- [License](#license)

## Getting Started

### Installation

This plugin is installed from GitHub, not from WordPress.org.

1. Clone or copy this repository into `/wp-content/plugins/lookit-cookie-consent`.
2. Activate **Lookit Cookie Consent** through the **Plugins** menu in WordPress.

### Configuration

1. Go to **Settings → Cookie Consent**.
2. Paste your iubenda Consent Database **Public API Key**. The field stays blank after save; leave it empty to keep the stored value.
3. Add your iubenda cookie policy ID and the popup copy.
4. Turn off iubenda's own Privacy Controls / Cookie Solution banner if that plugin is also installed.

Find the public API key in the iubenda dashboard under your site → **Consent Database → Configure**.

## Features

* Customizable body text (HTML allowed), button labels, colors, logo, and cookie duration.
* Three layouts: panel, small card, and corner pill.
* Consent is recorded to iubenda's Consent Database over REST — no iubenda front-end script required.
* Keyboard accessible (Escape rejects) and usable on small screens.
* Admin preview so you can check the popup without clearing your own consent cookie.

## Security and Privacy

* The iubenda public API key is **never** rendered back into the settings form. Submitting the field blank keeps the saved value.
* The settings option is **not autoloaded**.
* On uninstall, stored settings are **removed from the database**.

The key is used only on the server. When a visitor accepts or rejects cookies, the plugin sends their choice, preference selections, a subject identifier, and their IP address to iubenda. Nothing is sent until they interact with the popup, and nothing is sent if no key is configured.

See iubenda [terms](https://www.iubenda.com/en/terms-and-conditions) and [privacy policy](https://www.iubenda.com/privacy-policy/).

## Development

### Setup

Install the development dependencies with [Composer](https://getcomposer.org/):

```bash
composer install
```

### Running the Test Suite

The integration tests run against a real WordPress test install and a MySQL database. Install the test suite once, then run PHPUnit:

```bash
# bin/install-wp-tests.sh <db-name> <db-user> <db-pass> <db-host> <wp-version>
bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1 latest

composer test
```

### Coding Standards

This project follows the WordPress Coding Standards and checks PHP cross-version compatibility:

```bash
composer phpcs    # check coding standards
composer phpcbf   # auto-fix what can be fixed
composer compat   # check PHP 7.4+ compatibility
composer lint     # php -l syntax check on all files
```

### Continuous Integration

Every push and pull request runs the following GitHub Actions workflows:

| Workflow | Purpose |
| --- | --- |
| [Lint](../../actions/workflows/lint.yml) | `php -l` syntax check across the supported PHP versions |
| [Coding Standards](../../actions/workflows/coding-standards.yml) | WordPress Coding Standards (PHPCS) |
| [Plugin Check](../../actions/workflows/plugin-check.yml) | Official WordPress Plugin Check |
| [Test](../../actions/workflows/test.yml) | PHPUnit across a broad WordPress × PHP matrix |

A scheduled [Version Monitor](../../actions/workflows/version-monitor.yml) workflow watches for new PHP and WordPress releases so compatibility can be reviewed proactively.

## Contributing

Bug reports and pull requests are welcome on [GitHub](../../issues).

## License

This plugin is available as open source under the terms of the [GPL-2.0-or-later License](https://www.gnu.org/licenses/gpl-2.0.html).

---

_Lookit&reg; is a registered trademark of ZENOVA CORP. iubenda is a trademark of its respective owner; this plugin is an independent integration and is not affiliated with, sponsored by, or endorsed by iubenda._
