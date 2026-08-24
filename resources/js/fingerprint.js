// Device identification for the ThreatDetector and Fraud Engine.
// Computes a FingerprintJS visitor id once and stores it in a long-lived
// cookie ("device_fp" — kept in sync with config/security.php and the
// encryptCookies exception in bootstrap/app.php). The backend reads the
// cookie on every request; it never trusts it as authentication, only as
// a device identity signal.
import FingerprintJS from '@fingerprintjs/fingerprintjs';

const COOKIE_NAME = 'device_fp';

const hasCookie = document.cookie
    .split('; ')
    .some((cookie) => cookie.startsWith(`${COOKIE_NAME}=`));

if (!hasCookie) {
    FingerprintJS.load()
        .then((fp) => fp.get())
        .then((result) => {
            const secure = window.location.protocol === 'https:' ? '; Secure' : '';
            document.cookie = `${COOKIE_NAME}=${result.visitorId}; path=/; max-age=31536000; SameSite=Lax${secure}`;
        })
        .catch(() => {
            // Fingerprinting is best-effort; IP-based protection still applies.
        });
}
