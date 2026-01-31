# Claude Development Notes

## Critical Architecture

### Self-Checkout Pattern (action.yml)
The action checks itself out to `.wp-linting-tools/` because **relative paths in composite actions resolve relative to the consumer's repo, not the action's repo**. Do NOT try to use `uses: ./.github/actions/...` patterns.

### Composer Plugin (src/class-plugin.php)
Injects scripts from `extra.shared-scripts` into consuming projects. The plugin subscribes to Composer events and modifies the root package's scripts.

## Gotchas

- **Variable naming**: Watch for typos like `$candidatesarray` vs `$candidates` - this caused a production bug
- **require vs require-dev**: Linting tools must be in `require` (not `require-dev`) so consumers can access the binaries
- **Testing action changes**: Push to main, then test via `private-plugin-test-repo` workflow dispatch

## Do NOT

- Add nested composite actions under `.github/actions/`
- Move phpcs/phpstan/parallel-lint to require-dev
- Use relative action paths like `uses: ./path/to/action`

## Testing

1. Composer plugin: `composer install` in a consuming project should show injected scripts
2. GitHub Action: Trigger workflow in `private-plugin-test-repo`
