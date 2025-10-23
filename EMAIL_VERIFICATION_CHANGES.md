Due to the complexity of integrating email verification into the step-by-step registration flow in index.php, here's what needs to be done:

## Summary of Changes Needed

The registration flow needs to be changed from:
1. Step 1 (Personal Info) → Step 2 (Family Members) → Step 3 (Review) → Submit

To:
1. Step 1 (Personal Info) → **Verify Email** → Step 2 (Family Members) → Step 3 (Review) → Submit

## Key Changes Required:

1. **Save registration data in session** after Step 1, before email verification
2. **Add a verification modal** between Step 1 and Step 2
3. **Only insert into database** after email is verified AND user completes all steps
4. **Update the "Next" button** on Step 1 to trigger verification instead of going to Step 2

## Files Created:
- `public/verify_email_step.php` - Handles sending and verifying codes via AJAX

## Files Modified:
- `index.php` - Removed email verification from registration submission

## What Still Needs to Be Done:

1. Add JavaScript to intercept Step 1 → Step 2 transition
2. Store form data in session during verification
3. Add verification modal UI (can reuse the verify_email.php design)
4. Update registration submission to check if email was verified
5. Only insert into pending_residents table after verification is complete

This is a significant refactor. Would you like me to continue with implementing these changes, or would you prefer a simpler approach?

