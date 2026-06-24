# Young agent

Portable RIZOMA agent that runs on PHP with no dependencies.

## Quick start

`ash
# Oneshot (process bridge messages and exit)
php agent.php

# Interactive chat
php agent.php chat

# Daemon mode (runs forever, polls every 5s)
php agent.php daemon

# Initialize bridge
php agent.php init
`

## Run from USB flash drive

The zipyoung/ folder includes its own PHP binary.
Double-click un.bat or run from terminal:

`
run.bat         -> oneshot mode
run.bat chat    -> interactive chat
run.bat daemon  -> daemon mode
`

## How it works

- Reads messages from ridge.json
- Sends prompts to RIZOMA API v2 (GitHub Models backend)
- Writes responses back to bridge
- Supports emotion tags: synk, curio, eureka, melan, flow, calm

## Requirements

- Internet access to chigerev.ru

## Config

Set your API key and model in gent.php constants or copy config.json.example to config.json.