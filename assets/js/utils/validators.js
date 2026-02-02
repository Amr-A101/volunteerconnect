// /volcon/assets/js/utils/validators.js

export const regex = {
    username: /^[A-Za-z](?!.*__)[A-Za-z0-9_.]{1,18}[A-Za-z0-9]$/,
    password: /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,16}$/,
    phoneMY: /^(?:\+?60|0)(?:1[0-9]\d{7,8}|[3-9][0-9]\d{7})$/,
    postcodeMY: /^\d{5}$/,
    email: /^[^\s@]+@[^\s@]+\.[^\s@]+$/,
    url: /^https?:\/\/.+/
};

export function normalizePhone(input) {
    return input.replace(/[\s\-().]/g, "");
}
