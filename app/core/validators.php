<?php

function normalize_phone(string $phone): string {
    return preg_replace('/[\s\-\(\)\.]/', '', $phone);
}

function validate_value(string $rule, string $value): bool {
    switch ($rule) {
        case 'phone_my':
            $value = normalize_phone($value);
            return preg_match('/^(?:\+?60|0)(?:1[0-9]\d{7,8}|[3-9][0-9]\d{7})$/', $value);

        case 'email':
            return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;

        case 'url':
            return filter_var($value, FILTER_VALIDATE_URL) !== false
                && preg_match('#^https?://#i', $value);

        default:
            return false;
    }
}

?>