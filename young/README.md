# Young agent

Portable RIZOMA agent that runs on PHP with no dependencies.

## Quick start

`ash
php agent.php init    # create bridge.json
php agent.php chat   # interactive mode
php agent.php daemon # background processing
php agent.php        # oneshot (process bridge messages)
`

## How it works

- Reads messages from ridge.json
- Sends prompts to RIZOMA API v2 (GitHub Models backend)
- Writes responses back to bridge
- Supports emotion tags: synk, curio, eureka, melan, flow, calm

## Requirements

- PHP 8.0+ with file_get_contents and SSL support
- Internet access to chigerev.ru

## Config

Edit config.json to change model, API key, or site directory.