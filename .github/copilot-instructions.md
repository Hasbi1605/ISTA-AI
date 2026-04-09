# Figma MCP Implementation Rules

Use these rules whenever implementing UI from Figma links in this repository.

## Required flow

1. Run `get_design_context` first for the exact target node.
2. If context is too large or incomplete, run `get_metadata`, split into smaller nodes, then re-run `get_design_context` per node.
3. Run `get_screenshot` for visual parity checks before finishing.
4. Implement in existing files and adapt output to Laravel + Livewire + Tailwind conventions used in this repo.

## Implementation rules

- Reuse existing Blade/Livewire components and route patterns.
- Preserve existing token system in `laravel/tailwind.config.js`.
- Preserve existing dark mode behavior in `laravel/resources/views/layouts/app.blade.php`.
- Prefer adjusting classes in existing views over creating duplicate components.
- Match spacing, sizing, and hierarchy to Figma as closely as practical.
- Validate responsive behavior for desktop and mobile.

## Tooling fallback

- If `get_design_context` is unavailable in the current client session, use `get_metadata` plus screenshots as fallback.
- After MCP config changes, reload VS Code so tool discovery refreshes.
