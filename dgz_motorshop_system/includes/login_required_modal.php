<?php
$modalLoginUrl = $loginGateLoginUrl ?? $loginUrl ?? orderingUrl('login.php');
$modalRegisterUrl = $loginGateRegisterUrl ?? $registerUrl ?? orderingUrl('register.php');
$modalRedirectTarget = isset($loginGateRedirect) ? (string) $loginGateRedirect : '';
$modalRedirectTarget = trim($modalRedirectTarget);
$hasRedirectTarget = $modalRedirectTarget !== '';

if (!function_exists('dgzAuthGateApplyRedirect')) {
    function dgzAuthGateApplyRedirect(string $baseUrl, ?string $redirectTarget): string
    {
        $trimmedBase = trim($baseUrl);
        if ($trimmedBase === '') {
            return '';
        }

        if ($redirectTarget === null || $redirectTarget === '') {
            return $trimmedBase;
        }

        $separator = strpos($trimmedBase, '?') === false ? '?' : '&';

        return $trimmedBase . $separator . 'redirect=' . urlencode($redirectTarget);
    }
}

$modalLoginHref = dgzAuthGateApplyRedirect($modalLoginUrl, $hasRedirectTarget ? $modalRedirectTarget : null);
$modalRegisterHref = dgzAuthGateApplyRedirect($modalRegisterUrl, $hasRedirectTarget ? $modalRedirectTarget : null);
?>
<div
    class="auth-gate"
    id="authGateModal"
    hidden
    data-auth-gate
    data-auth-gate-login-base="<?= htmlspecialchars($modalLoginUrl) ?>"
    data-auth-gate-register-base="<?= htmlspecialchars($modalRegisterUrl) ?>"
    <?php if ($hasRedirectTarget): ?>data-auth-gate-default-redirect="<?= htmlspecialchars($modalRedirectTarget) ?>"<?php endif; ?>
>
    <div class="auth-gate__backdrop" data-auth-gate-close></div>
    <div class="auth-gate__dialog" role="dialog" aria-modal="true" aria-labelledby="authGateTitle">
        <button type="button" class="auth-gate__close" data-auth-gate-close aria-label="Close login prompt">
            <i class="fas fa-times"></i>
        </button>
        <h2 id="authGateTitle">Log In or Create Account</h2>
        <p class="auth-gate__description">Sign in to save your details, track your orders, and finish checking out.</p>
        <div class="auth-gate__actions">
            <a
                href="<?= htmlspecialchars($modalLoginHref) ?>"
                class="auth-gate__button auth-gate__button--primary"
                data-auth-gate-login-link
            >Log In</a>
            <a
                href="<?= htmlspecialchars($modalRegisterHref) ?>"
                class="auth-gate__button"
                data-auth-gate-register-link
            >Create Account</a>
        </div>
    </div>
</div>
