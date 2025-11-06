<?php
// Simple email verification using external APIs
// You can get free API keys from these services:
// 1. Hunter.io - https://hunter.io/api (100 free requests/month)
// 2. ZeroBounce - https://zerobounce.net (100 free credits/month)
// 3. EmailValidator - https://emailvalidator.net (100 free requests/month)

function verifyEmailWithHunter($email, $apiKey) {
    $url = "https://api.hunter.io/v2/email-verifier?email=" . urlencode($email) . "&api_key=" . $apiKey;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        if (isset($data['data']['result'])) {
            $result = $data['data']['result'];
            return $result === 'deliverable';
        }
    }
    
    return false;
}

function verifyEmailWithZeroBounce($email, $apiKey) {
    $url = "https://api.zerobounce.net/v2/validate?api_key=" . $apiKey . "&email=" . urlencode($email);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        if (isset($data['status'])) {
            return $data['status'] === 'valid';
        }
    }
    
    return false;
}

function verifyEmailWithEmailValidator($email, $apiKey) {
    $url = "https://api.emailvalidator.net/api/verify?EmailAddress=" . urlencode($email) . "&APIKey=" . $apiKey;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        if (isset($data['status'])) {
            return $data['status'] === 'Valid';
        }
    }
    
    return false;
}

// Main verification function that tries multiple APIs
function verifyEmailWithExternalAPI($email) {
    // You can set your API keys here
    $hunterApiKey = "your-hunter-api-key"; // Get from https://hunter.io/api
    $zeroBounceApiKey = "your-zerobounce-api-key"; // Get from https://zerobounce.net
    $emailValidatorApiKey = "your-emailvalidator-api-key"; // Get from https://emailvalidator.net
    
    // Try Hunter.io first (if API key is set)
    if ($hunterApiKey !== "your-hunter-api-key") {
        $result = verifyEmailWithHunter($email, $hunterApiKey);
        if ($result !== false) {
            return $result;
        }
    }
    
    // Try ZeroBounce (if API key is set)
    if ($zeroBounceApiKey !== "your-zerobounce-api-key") {
        $result = verifyEmailWithZeroBounce($email, $zeroBounceApiKey);
        if ($result !== false) {
            return $result;
        }
    }
    
    // Try EmailValidator (if API key is set)
    if ($emailValidatorApiKey !== "your-emailvalidator-api-key") {
        $result = verifyEmailWithEmailValidator($email, $emailValidatorApiKey);
        if ($result !== false) {
            return $result;
        }
    }
    
    // If no API keys are set, fall back to basic validation
    return null;
}

// Test function to check if APIs are working
function testEmailAPIs($email) {
    echo "Testing email verification APIs for: " . $email . "\n";
    
    $hunterApiKey = "your-hunter-api-key";
    $zeroBounceApiKey = "your-zerobounce-api-key";
    $emailValidatorApiKey = "your-emailvalidator-api-key";
    
    if ($hunterApiKey !== "your-hunter-api-key") {
        echo "Hunter.io: " . (verifyEmailWithHunter($email, $hunterApiKey) ? "Valid" : "Invalid") . "\n";
    } else {
        echo "Hunter.io: API key not set\n";
    }
    
    if ($zeroBounceApiKey !== "your-zerobounce-api-key") {
        echo "ZeroBounce: " . (verifyEmailWithZeroBounce($email, $zeroBounceApiKey) ? "Valid" : "Invalid") . "\n";
    } else {
        echo "ZeroBounce: API key not set\n";
    }
    
    if ($emailValidatorApiKey !== "your-emailvalidator-api-key") {
        echo "EmailValidator: " . (verifyEmailWithEmailValidator($email, $emailValidatorApiKey) ? "Valid" : "Invalid") . "\n";
    } else {
        echo "EmailValidator: API key not set\n";
    }
}

// Usage example:
// testEmailAPIs("test@gmail.com");
?>
