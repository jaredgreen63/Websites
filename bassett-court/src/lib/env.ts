/**
 * Reading environment variables that may arrive blank.
 *
 * GitHub Actions substitutes an unset `vars.FOO` into an `env:` block as an
 * empty string, not as an absent variable. So `process.env.FOO ?? 'default'`
 * yields '' in CI — the default never applies, and the failure lands far from
 * its cause: an empty adapter name, an empty query parameter, an empty URL.
 *
 * Treat blank as absent and the defaults behave the same locally and in CI.
 */
export function env(name: string): string | undefined {
  const value = process.env[name];
  if (typeof value !== 'string') {
    return undefined;
  }
  const trimmed = value.trim();
  return trimmed === '' ? undefined : trimmed;
}

/** The value, or `fallback` when the variable is absent, empty or whitespace. */
export function envOr(name: string, fallback: string): string {
  return env(name) ?? fallback;
}
