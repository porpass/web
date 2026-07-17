<?php
/**
 * resend_verification_form.php — Partial for resending email verification.
 *
 * Included by verify.php when a token is invalid or expired. Allows the
 * user to enter their email address to receive a new verification link.
 *
 * Variables expected from parent:
 *   $resend_message (string) — success message after resend, may be empty.
 */
?>
<?php if (!empty($resend_message)): ?>
    <div class="alert alert-success">
        <?= htmlspecialchars($resend_message) ?>
    </div>
<?php else: ?>
    <div class="card shadow-sm">
        <div class="card-body p-4">
            <h5 class="card-title mb-3">Resend Verification Email</h5>
            <form method="POST" action="/auth/verify.php">
                <div class="mb-3">
                    <label for="resend_email" class="form-label">Email Address</label>
                    <input type="email"
                           class="form-control"
                           id="resend_email"
                           name="resend_email"
                           required autofocus>
                </div>
                <div class="d-grid">
                    <button type="submit" class="btn btn-primary">
                        Resend Verification Email
                    </button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>