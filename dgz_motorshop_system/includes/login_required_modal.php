<?php
$loginUrl = $loginUrl ?? orderingUrl('login.php');
$registerUrl = $registerUrl ?? orderingUrl('register.php');
?>
<div class="auth-gate" id="authGateModal" hidden data-auth-gate>
    <div class="auth-gate__backdrop" data-auth-gate-close></div>
    <div class="auth-gate__dialog" role="dialog" aria-modal="true" aria-labelledby="authGateTitle">
        <button type="button" class="auth-gate__close" data-auth-gate-close aria-label="Close login prompt">
            <i class="fas fa-times"></i>
        </button>
        <h2 id="authGateTitle">Log In or Create Account</h2>
        <p class="auth-gate__description">Sign in to save your details, track your orders, and finish checking out.</p>
        <div class="auth-gate__actions">
            <a href="<?= htmlspecialchars($loginUrl) ?>" class="auth-gate__button auth-gate__button--primary">Log In</a>
            <a href="<?= htmlspecialchars($registerUrl) ?>" class="auth-gate__button">Create Account</a>
        </div>
    </div>
</div>
