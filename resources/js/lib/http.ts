/**
 * Headers for the app's own JSON endpoints (the ones called with `fetch`
 * rather than through Inertia). Laravel checks `X-XSRF-TOKEN` against the
 * cookie it set, and `X-Requested-With` keeps failures as JSON 4xx instead of
 * a redirect to the login page.
 */
export function readCookie(name: string): string {
    const match = document.cookie.match(new RegExp(`(^|;\\s*)${name}=([^;]*)`));

    return match ? decodeURIComponent(match[2]) : '';
}

export function baseHeaders(): Record<string, string> {
    return {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': readCookie('XSRF-TOKEN'),
    };
}

export function jsonHeaders(): Record<string, string> {
    return { 'Content-Type': 'application/json', ...baseHeaders() };
}
