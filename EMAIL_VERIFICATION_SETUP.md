# Email Verification API Setup Guide

## Free Email Verification APIs

To get the most accurate email validation, you can set up free API keys from these services:

### 1. Hunter.io (Recommended)
- **Website:** https://hunter.io/api
- **Free Tier:** 100 requests/month
- **Setup:**
  1. Go to https://hunter.io/api
  2. Sign up for a free account
  3. Get your API key from the dashboard
  4. Update `verify_email_api.php` with your key

### 2. ZeroBounce
- **Website:** https://zerobounce.net
- **Free Tier:** 100 credits/month
- **Setup:**
  1. Go to https://zerobounce.net
  2. Sign up for a free account
  3. Get your API key from the dashboard
  4. Update `verify_email_api.php` with your key

### 3. EmailValidator.net
- **Website:** https://emailvalidator.net
- **Free Tier:** 100 requests/month
- **Setup:**
  1. Go to https://emailvalidator.net
  2. Sign up for a free account
  3. Get your API key from the dashboard
  4. Update `verify_email_api.php` with your key

## How to Set Up

1. **Get API Keys:** Sign up for one or more of the services above
2. **Update the file:** Edit `public/verify_email_api.php`
3. **Replace the placeholder keys:**
   ```php
   $hunterApiKey = "your-actual-hunter-api-key";
   $zeroBounceApiKey = "your-actual-zerobounce-api-key";
   $emailValidatorApiKey = "your-actual-emailvalidator-api-key";
   ```

## Current Validation (Without APIs)

Even without API keys, the system now includes:

✅ **Enhanced Format Validation**
- Strict email format checking
- Domain extension validation
- Character validation

✅ **Disposable Email Detection**
- Blocks 20+ common disposable email services
- Prevents temporary email addresses

✅ **DNS Verification**
- Checks if domain has MX records
- Verifies domain exists

✅ **SMTP Verification**
- Actually connects to mail servers
- Verifies email can receive messages

## Testing

You can test the email verification by visiting:
`http://localhost/thesis/public/verify_email_api.php`

Or test specific emails by adding this to the file:
```php
testEmailAPIs("test@gmail.com");
testEmailAPIs("fake@nonexistent.com");
```

## Benefits of Using APIs

- **Higher Accuracy:** 95-98% accuracy vs 70-80% with basic validation
- **Real-time Verification:** Checks if email actually exists
- **Disposable Email Detection:** Blocks temporary email services
- **Better User Experience:** Immediate feedback on invalid emails

## Cost

- **Free Tier:** 100-300 free verifications per month
- **Paid Plans:** Start at $10-20/month for higher limits
- **For Development:** Free tier is usually sufficient
